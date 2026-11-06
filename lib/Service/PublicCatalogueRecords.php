<?php

/**
 * The published applications, as a public record list for the portal.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/portal-public-software-catalogue/specs/portal-contribution/spec.md#requirement-req-port-005-stackiq-offers-the-published-applications-as-a-public-record-list
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use DateTimeImmutable;
use OCP\IL10N;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Reads the published applications for a visitor without a session.
 *
 * What a visitor sees is decided here: only published applications, only plain
 * text. Portaliq reads it through the public record list the portal
 * contribution declares.
 *
 * @spec openspec/changes/portal-public-software-catalogue/specs/portal-contribution/spec.md#requirement-req-port-005-stackiq-offers-the-published-applications-as-a-public-record-list
 */
class PublicCatalogueRecords {
	/**
	 * The most applications the list reads.
	 */
	public const LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Resolves the register and schema ids.
	 * @param ContainerInterface $container Lazy OpenRegister lookups.
	 * @param PortfolioReportDerivation $relations Normalises results and relation ids.
	 * @param LoggerInterface $logger Logger.
	 * @param IL10N $l10n Translates the record's labels.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly ContainerInterface $container,
		private readonly PortfolioReportDerivation $relations,
		private readonly LoggerInterface $logger,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The published applications as `{id, title, subtitle}`.
	 *
	 * @return list<array<string, string>> The entries.
	 *
	 * @spec openspec/changes/portal-public-software-catalogue/specs/portal-contribution/spec.md#requirement-req-port-005-stackiq-offers-the-published-applications-as-a-public-record-list
	 */
	public function entries(): array {
		$now = new DateTimeImmutable();
		$modules = array_values(array_filter(
			$this->modules(filter: []),
			fn (array $module): bool => $this->isPublished(module: $module, now: $now)
		));
		$supplierIds = array_map(fn (array $module): string => $this->relations->resolveRelationId(value: $module['provider'] ?? null), $modules);
		$suppliers = $this->names(ids: $supplierIds, schemaKey: 'organisatie_schema', amef: false);

		$entries = [];
		foreach ($modules as $module) {
			$id = $this->objectId(object: $module);
			$title = trim((string)($module['name'] ?? ''));
			if ($id === '' || $title === '') {
				continue;
			}

			$entries[] = [
				'id' => $id,
				'title' => $title,
				'subtitle' => $this->subtitle(module: $module, suppliers: $suppliers),
			];
		}

		return $entries;
	}//end entries()

	/**
	 * One published application, or null when it is not published.
	 *
	 * @param string $id The application id.
	 *
	 * @return array<string, mixed>|null `{title, subtitle, summary, columns, rows}`.
	 *
	 * @spec openspec/changes/portal-public-software-catalogue/specs/portal-contribution/spec.md#requirement-req-port-005-stackiq-offers-the-published-applications-as-a-public-record-list
	 */
	public function record(string $id): ?array {
		$module = $this->modules(filter: ['id' => [$id]])[0] ?? null;
		if ($module === null || $this->isPublished(module: $module, now: new DateTimeImmutable()) === false) {
			return null;
		}

		$supplierId = $this->relations->resolveRelationId(value: $module['provider'] ?? null);
		$suppliers = $this->names(ids: [$supplierId], schemaKey: 'organisatie_schema', amef: false);
		$componentIds = array_map(
			fn (mixed $value): string => $this->relations->resolveRelationId(value: $value),
			(array)($module['referenceComponents'] ?? [])
		);
		$components = $this->names(ids: $componentIds, schemaKey: 'element_schema', amef: true);

		$rows = [];
		foreach ($componentIds as $componentId) {
			if (isset($components[$componentId]) === true) {
				$rows[] = ['component' => $components[$componentId]];
			}
		}

		return [
			'title' => (string)($module['name'] ?? ''),
			'subtitle' => (string)($module['shortDescription'] ?? ''),
			'summary' => [
				['label' => $this->l10n->t('Supplier'), 'value' => $suppliers[$supplierId] ?? ''],
				['label' => $this->l10n->t('Licence'), 'value' => $this->licence(module: $module)],
				['label' => $this->l10n->t('Website'), 'value' => (string)($module['website'] ?? '')],
			],
			'columns' => [['key' => 'component', 'label' => $this->l10n->t('Reference component')]],
			'rows' => $rows,
		];
	}//end record()

	/**
	 * Whether an application is published now.
	 *
	 * @param array<string, mixed> $module The application.
	 * @param DateTimeImmutable $now The moment.
	 *
	 * @return bool True when the publication date has come and no depublication date has.
	 *
	 * @spec openspec/changes/portal-public-software-catalogue/specs/portal-contribution/spec.md#requirement-req-port-005-stackiq-offers-the-published-applications-as-a-public-record-list
	 */
	public function isPublished(array $module, DateTimeImmutable $now): bool {
		$published = $this->date(value: $module['publicationDate'] ?? null);
		if ($published === null || $published > $now) {
			return false;
		}

		$withdrawn = $this->date(value: $module['depublicationDate'] ?? null);

		return $withdrawn === null || $withdrawn > $now;
	}//end isPublished()

	/**
	 * The modules, bounded, RBAC off: the publication rule above is the gate.
	 *
	 * @param array<string, mixed> $filter Extra query filters.
	 *
	 * @return list<array<string, mixed>> The modules, empty when the register is not configured or unreadable.
	 */
	private function modules(array $filter): array {
		$config = $this->settingsService->getVoorzieningenConfig();
		$registerId = (int)($config['register'] ?? 0);
		$schemaId = (int)($config['module_schema'] ?? 0);
		if ($registerId === 0 || $schemaId === 0) {
			return [];
		}

		return $this->search(query: ['@self' => ['register' => $registerId, 'schema' => $schemaId], '_limit' => self::LIMIT] + $filter);
	}//end modules()

	/**
	 * The names of related objects, by id.
	 *
	 * @param array<int, string> $ids The ids.
	 * @param string $schemaKey The config key of their schema.
	 * @param bool $amef Whether they live in the GEMMA register.
	 *
	 * @return array<string, string> Names by id.
	 */
	private function names(array $ids, string $schemaKey, bool $amef): array {
		$ids = array_values(array_unique(array_filter($ids)));
		$config = $this->settingsService->getVoorzieningenConfig();
		if ($amef === true) {
			$config = $this->settingsService->getAmefConfig();
		}

		$registerId = (int)($config['register'] ?? 0);
		$schemaId = (int)($config[$schemaKey] ?? 0);
		if ($ids === [] || $registerId === 0 || $schemaId === 0) {
			return [];
		}

		$names = [];
		foreach ($this->search(query: ['@self' => ['register' => $registerId, 'schema' => $schemaId], 'id' => $ids, '_limit' => count($ids)]) as $object) {
			$names[$this->objectId(object: $object)] = (string)($object['name'] ?? '');
		}

		return $names;
	}//end names()

	/**
	 * One bounded search; a failure reads as nothing found.
	 *
	 * @param array<string, mixed> $query The query.
	 *
	 * @return list<array<string, mixed>> The normalised results.
	 */
	private function search(array $query): array {
		try {
			$result = $this->container->get('OCA\OpenRegister\Service\ObjectService')
				->searchObjectsPaginated(query: $query, _rbac: false, _multitenancy: false);
		} catch (\Throwable $e) {
			$this->logger->warning('PublicCatalogueRecords: search failed', ['error' => $e->getMessage()]);
			return [];
		}

		return array_values($this->relations->normalizeResults(results: $result['results'] ?? []));
	}//end search()

	/**
	 * The line under an application's name: supplier and licence.
	 *
	 * @param array<string, mixed> $module The application.
	 * @param array<string, string> $suppliers Supplier names by id.
	 *
	 * @return string The subtitle.
	 */
	private function subtitle(array $module, array $suppliers): string {
		$supplier = $suppliers[$this->relations->resolveRelationId(value: $module['provider'] ?? null)] ?? '';

		return implode(' · ', array_filter([$supplier, $this->licence(module: $module)]));
	}//end subtitle()

	/**
	 * The open source licence, or the licence type.
	 *
	 * @param array<string, mixed> $module The application.
	 *
	 * @return string The licence.
	 */
	private function licence(array $module): string {
		$licence = trim((string)($module['licence'] ?? ''));
		if ($licence !== '') {
			return $licence;
		}

		return trim((string)($module['licentietype'] ?? ''));
	}//end licence()

	/**
	 * An object's id.
	 *
	 * @param array<string, mixed> $object The object.
	 *
	 * @return string The id.
	 */
	private function objectId(array $object): string {
		return $this->relations->resolveRelationId(value: $object['id'] ?? ($object['@self']['id'] ?? null));
	}//end objectId()

	/**
	 * A date value, or null.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return DateTimeImmutable|null The date.
	 */
	private function date(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (\Exception $e) {
			return null;
		}
	}//end date()
}//end class
