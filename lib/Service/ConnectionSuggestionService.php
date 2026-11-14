<?php

/**
 * Connection suggestions for a usage.
 *
 * Derives, for one usage, the connections the catalogue already knows that
 * the organisation has not recorded yet: the connections of the usage's
 * application whose other end the organisation also uses, or a national
 * provision (REQ-DCN-001), and the connections of the usage it replaces
 * (REQ-DCN-002). Suggestions are computed on every request and never stored;
 * accepting one writes it into the usage's connections, dismissing one
 * records its id on the usage.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/connections-derived-dependencies/specs/derived-connections/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use RuntimeException;

/**
 * Suggests, accepts and dismisses derived connections of a usage.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Both suggestion sources and accept/dismiss share one set of queries.
 * @SuppressWarnings(PHPMD.StaticAccess) RelationRefs is a pure reader of relation values.
 *
 * @spec openspec/changes/connections-derived-dependencies/specs/derived-connections/spec.md
 */
class ConnectionSuggestionService {
	/**
	 * Reason: the other end is in the organisation's landscape.
	 *
	 * @var string
	 */
	public const REASON_SHARED_LANDSCAPE = 'shared-landscape';

	/**
	 * Reason: the connection belonged to the usage this one replaces.
	 *
	 * @var string
	 */
	public const REASON_CARRY_OVER = 'carry-over';

	/**
	 * Id prefix of a draft connection that does not exist yet.
	 *
	 * @var string
	 */
	public const DRAFT_PREFIX = 'draft:';

	/**
	 * The most usages or connections one query reads.
	 *
	 * @var integer
	 */
	private const LIMIT = 500;

	/**
	 * The fields a draft connection copies from the connection it carries over.
	 *
	 * @var list<string>
	 */
	private const DRAFT_FIELDS = ['name', 'type', 'dataExchangeDirection', 'nonMunicipalProvision', 'integrationType', 'moduleA', 'moduleB'];

	/**
	 * Constructor.
	 *
	 * @param RegisterConfigReaderInterface $config Locates the usage and connection schemas and OpenRegister.
	 */
	public function __construct(
		private readonly RegisterConfigReaderInterface $config,
	) {
	}//end __construct()

	/**
	 * A usage, or null when it does not exist or the caller may not read it.
	 *
	 * @param string $usageUuid The usage.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/connections-derived-dependencies/specs/derived-connections/spec.md#requirement-req-dcn-003-only-the-organisation-that-owns-the-usage-accepts-or-dismisses-its-suggestions
	 */
	public function findUsage(string $usageUuid): ?array {
		try {
			$usage = $this->objects()->find(
				id: $usageUuid,
				register: $this->config->getRegisterIdForObjectType('usage'),
				schema: $this->config->getSchemaIdForObjectType('usage')
			);
		} catch (\Throwable) {
			return null;
		}

		if ($usage === null) {
			return null;
		}

		return ['id' => (string)$usage->getUuid()] + $usage->getObject();
	}//end findUsage()

	/**
	 * Whether an organisation may change a usage: it is the usage's consumer or one of its participants.
	 *
	 * @param array<string, mixed> $usage        The usage.
	 * @param string|null          $organisation The caller's active organisation.
	 *
	 * @return boolean
	 *
	 * @spec openspec/changes/connections-derived-dependencies/specs/derived-connections/spec.md#requirement-req-dcn-003-only-the-organisation-that-owns-the-usage-accepts-or-dismisses-its-suggestions
	 */
	public function mayChange(array $usage, ?string $organisation): bool {
		if ($organisation === null || $organisation === '') {
			return false;
		}

		if (RelationRefs::ref(value: ($usage['consumer'] ?? null)) === $organisation) {
			return true;
		}

		return in_array($organisation, RelationRefs::refs(values: ($usage['participants'] ?? [])), true);
	}//end mayChange()

	/**
	 * The suggestions for a usage, each `{id, connection, name, otherModule,
	 * nationalProvision, type, direction, reason, draft, from}`.
	 *
	 * @param array<string, mixed> $usage The usage, as findUsage() returns it.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/connections-derived-dependencies/specs/derived-connections/spec.md#requirement-req-dcn-001-stackiq-suggests-the-known-connections-of-an-application-inside-the-organisations-landscape
	 */
	public function suggestFor(array $usage): array {
		$module = RelationRefs::ref(value: ($usage['module'] ?? null));
		$organisation = RelationRefs::ref(value: ($usage['consumer'] ?? null));
		if ($module === null || $organisation === null) {
			return [];
		}

		$others = array_values(
			array_filter(
				$this->search(type: 'usage', filters: ['consumer' => $organisation]),
				static fn (array $other): bool => ($other['id'] ?? null) !== ($usage['id'] ?? null)
			)
		);

		$skip = array_flip(
			array_merge(
				RelationRefs::refs(values: ($usage['koppelingen'] ?? [])),
				RelationRefs::refs(values: ($usage['dismissedConnectionSuggestions'] ?? []))
			)
		);

		$suggestions = [];
		$candidates = array_merge(
			$this->landscapeSuggestions(module: $module, others: $others),
			$this->carryOverSuggestions(usage: $usage, module: $module, others: $others)
		);
		foreach ($candidates as $suggestion) {
			if (isset($skip[$suggestion['id']]) === false && isset($suggestions[$suggestion['id']]) === false) {
				$suggestions[$suggestion['id']] = $suggestion;
			}
		}

		return array_values($suggestions);
	}//end suggestFor()

	/**
	 * Accept suggestions: existing connections join the usage's connections; a
	 * draft is created first, with status `in development`.
	 *
	 * @param array<string, mixed> $usage The usage.
	 * @param list<string>         $ids   The suggestion ids to accept; ids that are not current suggestions are ignored.
	 *
	 * @return list<string> The connection uuids added to the usage.
	 *
	 * @spec openspec/changes/connections-derived-dependencies/specs/derived-connections/spec.md#requirement-req-dcn-002-a-replacing-usage-is-offered-the-connections-of-the-usage-it-replaces
	 */
	public function accept(array $usage, array $ids): array {
		$added = [];
		foreach ($this->chosen(usage: $usage, ids: $ids) as $suggestion) {
			$connection = $suggestion['connection'];
			if ($suggestion['draft'] === true) {
				$connection = $this->createDraft(draft: $suggestion['draftObject']);
			}

			$added[] = $connection;
		}

		if ($added !== []) {
			$usage['koppelingen'] = array_values(array_unique(array_merge(RelationRefs::refs(values: ($usage['koppelingen'] ?? [])), $added)));
			$this->saveUsage(usage: $usage);
		}

		return $added;
	}//end accept()

	/**
	 * Dismiss suggestions: their ids are kept on the usage so they are not suggested again.
	 *
	 * @param array<string, mixed> $usage The usage.
	 * @param list<string>         $ids   The suggestion ids to dismiss; ids that are not current suggestions are ignored.
	 *
	 * @return list<string> The ids dismissed.
	 *
	 * @spec openspec/changes/connections-derived-dependencies/specs/derived-connections/spec.md#requirement-req-dcn-001-stackiq-suggests-the-known-connections-of-an-application-inside-the-organisations-landscape
	 */
	public function dismiss(array $usage, array $ids): array {
		$dismissed = array_map(static fn (array $suggestion): string => $suggestion['id'], $this->chosen(usage: $usage, ids: $ids));
		if ($dismissed !== []) {
			$usage['dismissedConnectionSuggestions'] = array_values(
				array_unique(array_merge(RelationRefs::refs(values: ($usage['dismissedConnectionSuggestions'] ?? [])), $dismissed))
			);
			$this->saveUsage(usage: $usage);
		}

		return $dismissed;
	}//end dismiss()

	/**
	 * The connections of the application whose other end the organisation uses, or that end at a national provision.
	 *
	 * @param string                     $module The usage's application.
	 * @param list<array<string, mixed>> $others The organisation's other usages.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function landscapeSuggestions(string $module, array $others): array {
		$landscape = [];
		foreach ($others as $other) {
			$landscape[(string)RelationRefs::ref(value: ($other['module'] ?? null))] = true;
		}

		unset($landscape['']);
		$suggestions = [];
		foreach ($this->connectionsOf(module: $module) as $connection) {
			$otherEnd = self::otherEnd(connection: $connection, module: $module);
			$national = ($otherEnd === null && RelationRefs::ref(value: ($connection['nonMunicipalProvision'] ?? null)) !== null);
			if ($national === true || ($otherEnd !== null && isset($landscape[$otherEnd]) === true)) {
				$suggestions[] = self::suggestion(connection: $connection, otherEnd: $otherEnd, reason: self::REASON_SHARED_LANDSCAPE, from: null);
			}
		}

		return $suggestions;
	}//end landscapeSuggestions()

	/**
	 * The connections of the organisation's usages this one replaces: the same
	 * application at another version, or an application whose planned
	 * replacement is this one, offered as drafts from the successor.
	 *
	 * @param array<string, mixed>       $usage  The usage.
	 * @param string                     $module The usage's application.
	 * @param list<array<string, mixed>> $others The organisation's other usages.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/connections-derived-dependencies/specs/derived-connections/spec.md#requirement-req-dcn-002-a-replacing-usage-is-offered-the-connections-of-the-usage-it-replaces
	 */
	private function carryOverSuggestions(array $usage, string $module, array $others): array {
		$version = RelationRefs::ref(value: ($usage['moduleVersion'] ?? null));
		$suggestions = [];
		foreach ($others as $other) {
			$sameApplication = (RelationRefs::ref(value: ($other['module'] ?? null)) === $module
				&& RelationRefs::ref(value: ($other['moduleVersion'] ?? null)) !== $version);
			$replaced = (RelationRefs::ref(value: ($other['plannedReplacement'] ?? null)) === $module);
			if ($sameApplication === false && $replaced === false) {
				continue;
			}

			$replacedModule = (string)RelationRefs::ref(value: ($other['module'] ?? null));
			$successor = $module;
			if ($sameApplication === true) {
				$successor = null;
			}

			foreach ($this->connectionsByUuid(uuids: RelationRefs::refs(values: ($other['koppelingen'] ?? []))) as $connection) {
				$carried = $this->carried(
					connection: $connection,
					replaced: $replacedModule,
					successor: $successor,
					from: (string)($other['id'] ?? '')
				);
				if ($carried !== null) {
					$suggestions[] = $carried;
				}
			}
		}

		return $suggestions;
	}//end carryOverSuggestions()

	/**
	 * One carried-over connection: as it is for a new version of the same
	 * application, or as a draft in which the successor takes the replaced
	 * application's end. Null when the connection does not touch the replaced application.
	 *
	 * @param array<string, mixed> $connection The original connection.
	 * @param string               $replaced   The application of the usage it comes from.
	 * @param string|null          $successor  The successor application, or null when the application is the same.
	 * @param string               $from       The usage it comes from.
	 *
	 * @return array<string, mixed>|null
	 */
	private function carried(array $connection, string $replaced, ?string $successor, string $from): ?array {
		$otherEnd = self::otherEnd(connection: $connection, module: $replaced);
		if ($successor === null) {
			return self::suggestion(connection: $connection, otherEnd: $otherEnd, reason: self::REASON_CARRY_OVER, from: $from);
		}

		$end = null;
		foreach (['moduleA', 'moduleB'] as $side) {
			if ($end === null && RelationRefs::ref(value: ($connection[$side] ?? null)) === $replaced) {
				$end = $side;
			}
		}

		if ($end === null) {
			return null;
		}

		$draft = array_intersect_key($connection, array_flip(self::DRAFT_FIELDS));
		$draft[$end] = $successor;
		$draft['status'] = 'in development';
		$draft['longDescription'] = 'Carried over from connection ' . (string)($connection['name'] ?? '') . ' (' . (string)($connection['id'] ?? '') . ').';

		$suggestion = self::suggestion(connection: $connection, otherEnd: $otherEnd, reason: self::REASON_CARRY_OVER, from: $from);
		$suggestion['id'] = self::DRAFT_PREFIX . (string)($connection['id'] ?? '');
		$suggestion['connection'] = null;
		$suggestion['draft'] = true;
		$suggestion['draftObject'] = $draft;
		return $suggestion;
	}//end carried()

	/**
	 * The current suggestions with the requested ids.
	 *
	 * @param array<string, mixed> $usage The usage.
	 * @param list<string>         $ids   The requested ids.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function chosen(array $usage, array $ids): array {
		$wanted = array_flip(array_map('strval', $ids));
		$chosen = [];
		foreach ($this->suggestFor(usage: $usage) as $suggestion) {
			if (isset($wanted[$suggestion['id']]) === true) {
				$chosen[] = $suggestion;
			}
		}

		return $chosen;
	}//end chosen()

	/**
	 * The suggestion shape.
	 *
	 * @param array<string, mixed> $connection The connection.
	 * @param string|null          $otherEnd   The other application, or null.
	 * @param string               $reason     Why it is suggested.
	 * @param string|null          $from       The usage it carries over from, or null.
	 *
	 * @return array<string, mixed>
	 */
	private static function suggestion(array $connection, ?string $otherEnd, string $reason, ?string $from): array {
		return [
			'id' => (string)($connection['id'] ?? ''),
			'connection' => (string)($connection['id'] ?? ''),
			'name' => (string)($connection['name'] ?? ''),
			'otherModule' => $otherEnd,
			'nationalProvision' => RelationRefs::ref(value: ($connection['nonMunicipalProvision'] ?? null)),
			'type' => ($connection['type'] ?? null),
			'direction' => ($connection['dataExchangeDirection'] ?? null),
			'reason' => $reason,
			'draft' => false,
			'from' => $from,
		];
	}//end suggestion()

	/**
	 * The application at the other end of a connection, or null.
	 *
	 * @param array<string, mixed> $connection The connection.
	 * @param string               $module     This end.
	 *
	 * @return string|null
	 */
	private static function otherEnd(array $connection, string $module): ?string {
		$moduleA = RelationRefs::ref(value: ($connection['moduleA'] ?? null));
		if ($moduleA === $module) {
			return RelationRefs::ref(value: ($connection['moduleB'] ?? null));
		}

		return $moduleA;
	}//end otherEnd()

	/**
	 * The readable connections with this application at either end.
	 *
	 * @param string $module The application.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function connectionsOf(string $module): array {
		$byId = [];
		foreach (['moduleA', 'moduleB'] as $end) {
			foreach ($this->search(type: 'connection', filters: [$end => $module]) as $connection) {
				$byId[(string)($connection['id'] ?? '')] = $connection;
			}
		}

		return array_values($byId);
	}//end connectionsOf()

	/**
	 * The readable connections with these uuids.
	 *
	 * @param list<string> $uuids The uuids.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function connectionsByUuid(array $uuids): array {
		$connections = [];
		foreach ($uuids as $uuid) {
			try {
				$connection = $this->objects()->find(
					id: $uuid,
					register: $this->config->getRegisterIdForObjectType('connection'),
					schema: $this->config->getSchemaIdForObjectType('connection')
				);
			} catch (\Throwable) {
				continue;
			}

			if ($connection !== null) {
				$connections[] = ['id' => (string)$connection->getUuid()] + $connection->getObject();
			}
		}

		return $connections;
	}//end connectionsByUuid()

	/**
	 * Objects of one type matching the filters, as arrays with their uuid as `id`, under the caller's RBAC.
	 *
	 * @param string               $type    `usage` or `connection`.
	 * @param array<string, mixed> $filters Property filters.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function search(string $type, array $filters): array {
		$query = $filters + [
			'@self' => [
				'register' => $this->config->getRegisterIdForObjectType($type),
				'schema' => $this->config->getSchemaIdForObjectType($type),
			],
			'_limit' => self::LIMIT,
		];
		$results = ($this->objects()->searchObjectsPaginated(query: $query)['results'] ?? []);

		$rows = [];
		foreach ($results as $result) {
			$row = $result;
			if (is_array($row) === false) {
				$row = $result->jsonSerialize();
			}

			$row['id'] = (string)($row['id'] ?? $row['@self']['id'] ?? $row['uuid'] ?? '');
			$rows[] = $row;
		}

		return $rows;
	}//end search()

	/**
	 * Create a draft connection and answer its uuid.
	 *
	 * @param array<string, mixed> $draft The connection.
	 *
	 * @return string
	 */
	private function createDraft(array $draft): string {
		$created = $this->objects()->saveObject(
			object: $draft,
			register: $this->config->getRegisterIdForObjectType('connection'),
			schema: $this->config->getSchemaIdForObjectType('connection')
		);

		return (string)$created->getUuid();
	}//end createDraft()

	/**
	 * Save the usage.
	 *
	 * @param array<string, mixed> $usage The usage, with its uuid as `id`.
	 *
	 * @return void
	 */
	private function saveUsage(array $usage): void {
		$uuid = (string)$usage['id'];
		unset($usage['id']);
		$this->objects()->saveObject(
			object: $usage,
			register: $this->config->getRegisterIdForObjectType('usage'),
			schema: $this->config->getSchemaIdForObjectType('usage'),
			uuid: $uuid
		);
	}//end saveUsage()

	/**
	 * OpenRegister's object service.
	 *
	 * @return ObjectServiceInterface
	 *
	 * @throws RuntimeException When OpenRegister is not available.
	 */
	private function objects(): ObjectServiceInterface {
		$objects = $this->config->getObjectService();
		if ($objects === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		return $objects;
	}//end objects()
}//end class
