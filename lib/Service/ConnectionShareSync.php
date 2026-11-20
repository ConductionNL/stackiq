<?php

/**
 * Keeps the participant organisations of shared usages on the connections they list.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-the-connections-list-says-with-whom-a-connection-is-shared-req-dlg-011
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * OpenRegister cannot match a read rule on the connection schema through
 * `usage.koppelingen`. So a connection carries `sharedWithOrganisations`: the
 * participants of every usage with participants that lists it. The connection
 * schema's read rule matches that field (register.d/
 * shared-usage-visible-to-participants-connections.json), so a participant
 * lists and opens the connection, and its update right stays as it was.
 *
 * The field is derived. It is written here only, with RBAC off because the
 * person saving a usage need not own the connections it lists, and only that
 * field changes.
 *
 * @spec openspec/changes/shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-the-connections-list-says-with-whom-a-connection-is-shared-req-dlg-011
 * @SuppressWarnings(PHPMD.StaticAccess) RelationRefs reads relation values; SearchBounds::bounded() is the shared query guard.
 */
class ConnectionShareSync {

	/**
	 * The connection field the read rule matches.
	 *
	 * @var string
	 */
	public const FIELD = 'sharedWithOrganisations';

	/**
	 * Constructor.
	 *
	 * @param OpenRegisterLookup         $lookup The OpenRegister object service.
	 * @param ObjectTypeLocatorInterface $types  The configured registers and schemas.
	 * @param LoggerInterface            $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly OpenRegisterLookup $lookup,
		private readonly ObjectTypeLocatorInterface $types,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The connections whose sharing a usage save can change: every connection
	 * the usage lists now or listed before, when it has or had participants.
	 *
	 * @param array<string, mixed> $usage    The usage as saved (empty when deleted and no new state).
	 * @param array<string, mixed> $previous The usage before the save (empty on create).
	 *
	 * @return list<string> Connection uuids.
	 *
	 * @spec openspec/changes/shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-the-connections-list-says-with-whom-a-connection-is-shared-req-dlg-011
	 */
	public static function affectedConnections(array $usage, array $previous): array {
		$affected = [];
		foreach ([$usage, $previous] as $state) {
			if (RelationRefs::refs(values: ($state['participants'] ?? [])) === []) {
				continue;
			}

			$affected = array_merge($affected, RelationRefs::refs(values: ($state['koppelingen'] ?? [])));
		}

		return array_values(array_unique($affected));
	}//end affectedConnections()

	/**
	 * The participant organisations of the usages that list a connection,
	 * each once, in a stable order.
	 *
	 * @param array<int, mixed> $usages     Usage records.
	 * @param string            $connection The connection uuid.
	 *
	 * @return list<string> Organisation uuids.
	 *
	 * @spec openspec/changes/shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-the-connections-list-says-with-whom-a-connection-is-shared-req-dlg-011
	 */
	public static function participantsFor(array $usages, string $connection): array {
		$organisations = [];
		foreach ($usages as $usage) {
			if (is_array($usage) === false
				|| in_array($connection, RelationRefs::refs(values: ($usage['koppelingen'] ?? [])), true) === false
			) {
				continue;
			}

			$organisations = array_merge($organisations, RelationRefs::refs(values: ($usage['participants'] ?? [])));
		}

		$organisations = array_values(array_unique($organisations));
		sort($organisations);

		return $organisations;
	}//end participantsFor()

	/**
	 * The change to a connection's stored field, or [] when it already holds those organisations.
	 *
	 * @param array<string, mixed> $connection    The connection record.
	 * @param list<string>         $organisations The organisations it is shared with.
	 *
	 * @return array<string, list<string>>
	 *
	 * @spec openspec/changes/shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-the-connections-list-says-with-whom-a-connection-is-shared-req-dlg-011
	 */
	public static function patch(array $connection, array $organisations): array {
		$stored = $connection[self::FIELD] ?? [];
		if (is_array($stored) === false) {
			$stored = [];
		}

		$stored = array_values(array_unique(array_filter($stored, 'is_string')));
		sort($stored);
		if ($stored === $organisations && array_key_exists(self::FIELD, $connection) === true) {
			return [];
		}

		if ($organisations === [] && array_key_exists(self::FIELD, $connection) === false) {
			return [];
		}

		return [self::FIELD => $organisations];
	}//end patch()

	/**
	 * Recompute the field on the given connections from the usages that list them.
	 *
	 * @param list<string> $connections  Connection uuids.
	 * @param string|null  $deletedUsage The uuid of a usage being deleted, left out of the count.
	 *
	 * @return int The number of connections rewritten.
	 *
	 * @spec openspec/changes/shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-the-connections-list-says-with-whom-a-connection-is-shared-req-dlg-011
	 */
	public function sync(array $connections, ?string $deletedUsage=null): int {
		$written = 0;
		foreach ($connections as $connection) {
			$usages = $this->usagesListing(connection: $connection, deletedUsage: $deletedUsage);
			if ($usages === null) {
				continue;
			}

			if ($this->write(connection: $connection, organisations: self::participantsFor(usages: $usages, connection: $connection)) === true) {
				$written++;
			}
		}

		return $written;
	}//end sync()

	/**
	 * Fill the field on every connection a usage with participants lists
	 * (existing data, before this field existed).
	 *
	 * @return int The number of connections rewritten.
	 *
	 * @spec openspec/changes/shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-the-connections-list-says-with-whom-a-connection-is-shared-req-dlg-011
	 */
	public function backfill(): int {
		$target = $this->target(objectType: 'usage');
		if ($target === null) {
			return 0;
		}

		$usages = $this->search(query: ['@self' => $target]);
		if ($usages === null) {
			return 0;
		}

		$connections = [];
		foreach ($usages as $usage) {
			$connections = array_merge($connections, self::affectedConnections(usage: $usage, previous: []));
		}

		$written = 0;
		foreach (array_values(array_unique($connections)) as $connection) {
			if ($this->write(connection: $connection, organisations: self::participantsFor(usages: $usages, connection: $connection)) === true) {
				$written++;
			}
		}

		return $written;
	}//end backfill()

	/**
	 * The usages that list a connection, without a usage being deleted; null when the search fails.
	 *
	 * @param string      $connection   The connection uuid.
	 * @param string|null $deletedUsage The usage being deleted.
	 *
	 * @return list<array<string, mixed>>|null
	 */
	private function usagesListing(string $connection, ?string $deletedUsage): ?array {
		$target = $this->target(objectType: 'usage');
		if ($target === null) {
			return null;
		}

		$usages = $this->search(query: ['@self' => $target, 'koppelingen' => $connection]);
		if ($usages === null || $deletedUsage === null) {
			return $usages;
		}

		return array_values(array_filter(
			$usages,
			static fn (array $usage): bool => ($usage['id'] ?? $usage['@self']['id'] ?? null) !== $deletedUsage
		));
	}//end usagesListing()

	/**
	 * Write the field on one connection when it changes.
	 *
	 * @param string       $connection    The connection uuid.
	 * @param list<string> $organisations The organisations it is shared with.
	 *
	 * @return bool True when the connection was rewritten.
	 */
	private function write(string $connection, array $organisations): bool {
		$target = $this->target(objectType: 'connection');
		if ($target === null) {
			return false;
		}

		try {
			$objects = $this->lookup->objectService();
			$entity  = $objects->find(
				id: $connection,
				options: new ObjectFindOptions(register: $target['register'], schema: $target['schema'], extend: [], render: false, audit: false),
				_rbac: false,
				_multitenancy: false
			);
			if ($entity === null) {
				return false;
			}

			$record = (array)$entity->getObject();
			$patch  = self::patch(connection: $record, organisations: $organisations);
			if ($patch === []) {
				return false;
			}

			$objects->saveObject(
				object: array_merge($record, $patch),
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: $target['register'], schema: $target['schema']),
					uuid: $connection,
					extend: []
				),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			$this->logger->error(
				'ConnectionShareSync: could not write the organisations a connection is shared with',
				['connection' => $connection, 'exception' => $exception->getMessage()]
			);
			return false;
		}//end try

		return true;
	}//end write()

	/**
	 * Search objects with RBAC and multitenancy off, as plain arrays; null when the search fails.
	 *
	 * @param array<string, mixed> $query The query.
	 *
	 * @return list<array<string, mixed>>|null
	 */
	private function search(array $query): ?array {
		try {
			$found = $this->lookup->objectService()->searchObjects(
				query: SearchBounds::bounded(query: $query),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			$this->logger->error('ConnectionShareSync: could not read the usages', ['exception' => $exception->getMessage()]);
			return null;
		}

		if (is_array($found) === false) {
			return [];
		}

		$records = [];
		foreach (($found['results'] ?? $found) as $object) {
			if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
				$object = $object->jsonSerialize();
			}

			if (is_array($object) === true) {
				$records[] = $object;
			}
		}

		return $records;
	}//end search()

	/**
	 * The configured register and schema of an object type, or null when either is missing.
	 *
	 * @param string $objectType The object type.
	 *
	 * @return array{register: int, schema: int}|null
	 */
	private function target(string $objectType): ?array {
		$register = $this->types->getRegisterIdForObjectType($objectType);
		$schema   = $this->types->getSchemaIdForObjectType($objectType);
		if ($register === null || $schema === null) {
			return null;
		}

		return ['register' => $register, 'schema' => $schema];
	}//end target()
}//end class
