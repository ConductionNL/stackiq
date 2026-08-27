<?php

/**
 * SBOM Import Service.
 *
 * Orchestrates importing a parsed SBOM (see {@see SbomParserService}) for a
 * single `moduleVersie`: resolves the target version, soft-deletes its
 * previous (non-deleted) `sbomComponent` set, bulk-saves the newly parsed
 * set in bounded batches, reports progress for larger imports via the
 * existing `progress-tracking` capability, and records import provenance on
 * the `moduleVersie` itself. Persistence runs entirely through
 * OpenRegister's `ObjectService` (ADR-022) — no app-local database table
 * (ADR-001).
 *
 * Re-import is idempotent by replacement (design Decision 3): the previous
 * LIVE component set is soft-deleted before the new set is created. OR's
 * list/search calls already exclude `_deleted` rows by default, so a prior
 * replace's freshly-trashed rows are never re-queried or re-deleted on a
 * later import. Steps are not wrapped in a single transaction (OpenRegister
 * exposes no cross-object transaction primitive to app-local code) — if the
 * create step fails partway through, the version is left with NO component
 * set rather than a mixed old/new set; re-running the import starts clean.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/sbom-import/spec.md#requirement-re-import-replaces-the-previous-component-set-and-is-soft-delete-aware
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use DateTime;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Imports a parsed SBOM's components as `sbomComponent` objects scoped to a
 * `moduleVersie`, replacing any previous set.
 *
 * @spec openspec/specs/sbom-import/spec.md
 */
class SbomImportService {
	/**
	 * Maximum objects per OR bulk create/delete call — bounds a single
	 * unbounded bulk-save call for large SBOMs (design Decision 4 /
	 * non-functional performance requirement: batches of ~100).
	 */
	private const BATCH_SIZE = 100;

	/**
	 * Component count above which a `progress-tracking` operation is
	 * started (design Decision 4 / spec "Large imports run in bounded
	 * batches with progress reporting").
	 */
	private const PROGRESS_THRESHOLD = 50;

	/**
	 * Supported SBOM upload formats.
	 *
	 * @var array<int,string>
	 */
	public const SUPPORTED_FORMATS = ['cyclonedx-json', 'spdx-json'];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container (lazy OR lookup).
	 * @param SettingsService $settingsService Resolves register/schema ids.
	 * @param SbomParserService $parser The pure SBOM parser.
	 * @param ProgressTracker $progressTracker Progress reporting for large imports.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SettingsService $settingsService,
		private readonly SbomParserService $parser,
		private readonly ProgressTracker $progressTracker,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Import an uploaded SBOM for a `moduleVersie`: parse, replace the
	 * previous component set, and record provenance.
	 *
	 * @param string $moduleVersieUuid The target moduleVersie's uuid.
	 * @param string $rawJson The raw uploaded file contents.
	 * @param string $format `cyclonedx-json` or `spdx-json`.
	 * @param string $fileName The uploaded file's original name.
	 *
	 * @return array<string, mixed> Import result summary.
	 *
	 * @throws \OCA\Stackiq\Exception\UnsupportedSbomFormatException When the
	 *                                                               document's format/version is not supported.
	 *                                                               No component is written in that case.
	 * @throws RuntimeException When the target `moduleVersie` cannot be
	 *                          resolved, or required configuration is missing.
	 *
	 * @spec openspec/specs/sbom-import/spec.md#requirement-imported-components-persist-as-openregister-objects-scoped-to-a-moduleversie
	 */
	public function importForModuleVersie(
		string $moduleVersieUuid,
		string $rawJson,
		string $format,
		string $fileName,
	): array {
		// 1. Parse — pure, no OR/HTTP call. Throws on unsupported format;
		// nothing has been written yet at this point.
		$parsed = $this->parseUpload(rawJson: $rawJson, format: $format);
		$components = $parsed['components'];
		$vexPairs = $parsed['vulnerabilities'];

		$coordinates = $this->resolveCoordinates();
		$objectService = $coordinates['objectService'];

		$moduleVersion = $objectService->find(
			id: $moduleVersieUuid,
			register: $coordinates['registerId'],
			schema: $coordinates['moduleVersieSchemaId'],
			_rbac: false,
			_multitenancy: false
		);

		if ($moduleVersion === null) {
			throw new RuntimeException('moduleVersie not found: ' . $moduleVersieUuid);
		}

		$componentCount = count($components);
		$trackProgress = $componentCount > self::PROGRESS_THRESHOLD;
		$operationId = null;

		if ($trackProgress === true) {
			$operationId = $this->progressTracker->startOperation(
				'sbom-import',
				['total_items' => $componentCount]
			);
			$this->progressTracker->setPhase('processing_elements');
		}

		$previousUuids = $this->replacePreviousComponentSet(
			objectService: $objectService,
			registerId: $coordinates['registerId'],
			componentSchemaId: $coordinates['sbomComponentSchemaId'],
			moduleVersieUuid: $moduleVersieUuid
		);

		$createdCount = $this->createComponentSet(
			objectService: $objectService,
			registerId: $coordinates['registerId'],
			componentSchemaId: $coordinates['sbomComponentSchemaId'],
			moduleVersieUuid: $moduleVersieUuid,
			components: $components,
			vexPairs: $vexPairs,
			trackProgress: $trackProgress
		);

		$this->recordProvenance(
			objectService: $objectService,
			registerId: $coordinates['registerId'],
			versionSchemaId: $coordinates['moduleVersieSchemaId'],
			moduleVersion: $moduleVersion,
			format: $format,
			fileName: $fileName
		);

		if ($trackProgress === true) {
			$this->progressTracker->completeOperation(
				[
					'componentsCreated' => $createdCount,
					'previousCount' => count($previousUuids),
				]
			);
		}

		$this->logger->info(
			'SbomImportService: import completed',
			[
				'moduleVersieUuid' => $moduleVersieUuid,
				'format' => $format,
				'componentCount' => $createdCount,
				'previousCount' => count($previousUuids),
			]
		);

		return [
			'success' => true,
			'operationId' => $operationId,
			'moduleVersieUuid' => $moduleVersieUuid,
			'componentCount' => $createdCount,
			'previousComponentCount' => count($previousUuids),
			'distinctLicenseCount' => $this->countDistinctLicenses(components: $components),
			'vulnerabilityPairCount' => count($vexPairs),
			'sbomFormat' => $format,
			'sbomFileName' => $fileName,
		];
	}//end importForModuleVersie()

	/**
	 * Select and invoke the parser matching the uploaded `format` (design
	 * Decision 1 — explicit format selection, never content-sniffed).
	 *
	 * @param string $rawJson The raw uploaded file contents.
	 * @param string $format `cyclonedx-json` or `spdx-json`.
	 *
	 * @return array{components: array<int, array<string, mixed>>, vulnerabilities: array<int, array{cveId: string, componentBomRef: string}>}
	 *
	 * @throws \OCA\Stackiq\Exception\UnsupportedSbomFormatException When
	 *                                                               the document's format/version is not supported.
	 *
	 * @spec openspec/specs/sbom-import/spec.md#requirement-cyclonedx-sbom-files-are-parsed-into-a-normalized-component-list
	 */
	private function parseUpload(string $rawJson, string $format): array {
		if ($format === 'spdx-json') {
			return $this->parser->parseSpdx(json: $rawJson);
		}

		return $this->parser->parse(json: $rawJson);
	}//end parseUpload()

	/**
	 * Resolve the parent `module` uuid of a `moduleVersie` — used by the
	 * controller's manage-ACL authorization guard, which needs to know which
	 * module the caller must be allowed to manage before any write happens.
	 *
	 * @param string $moduleVersieUuid The moduleVersie uuid.
	 *
	 * @return string|null The parent module uuid, or null when not resolvable.
	 *
	 * @spec openspec/specs/sbom-import/spec.md#requirement-uploaded-sbom-files-are-bounded-in-size-and-json-only
	 */
	public function resolveParentModuleUuid(string $moduleVersieUuid): ?string {
		$coordinates = $this->resolveCoordinates();
		$moduleVersion = $coordinates['objectService']->find(
			id: $moduleVersieUuid,
			register: $coordinates['registerId'],
			schema: $coordinates['moduleVersieSchemaId'],
			_rbac: false,
			_multitenancy: false
		);

		if ($moduleVersion === null) {
			return null;
		}

		return $this->resolveRelationUuid(relation: $moduleVersion->getObject()['module'] ?? null);
	}//end resolveParentModuleUuid()

	/**
	 * Whether the current OR request context can resolve (read) a `module`
	 * object under RBAC — used as the "manage-ACL on the target module"
	 * check alongside a role-group membership check in the controller.
	 *
	 * @param string $moduleUuid The module uuid.
	 *
	 * @return bool True when the module resolves under RBAC for the acting user.
	 *
	 * @spec openspec/specs/sbom-import/spec.md#requirement-uploaded-sbom-files-are-bounded-in-size-and-json-only
	 */
	public function userCanReadModule(string $moduleUuid): bool {
		$objectService = $this->getObjectService();
		$registerId = $this->settingsService->getVoorzieningenConfig()['register'] ?? null;
		$moduleSchemaId = $this->settingsService->getSchemaIdForObjectType('module');

		if ($objectService === null || $registerId === null || $moduleSchemaId === null) {
			return false;
		}

		$module = $objectService->find(
			id: $moduleUuid,
			register: (int)$registerId,
			schema: (int)$moduleSchemaId,
			_rbac: true,
			_multitenancy: true
		);

		return $module !== null;
	}//end userCanReadModule()

	/**
	 * Read SBOM import provenance + optional progress for a `moduleVersie`.
	 *
	 * @param string $moduleVersieUuid The moduleVersie uuid.
	 * @param string|null $operationId Optional progress-tracking operation id.
	 *
	 * @return array<string, mixed> `{sbomLastImportedAt, sbomFormat, sbomFileName, progress}`.
	 *
	 * @spec openspec/specs/sbom-import/spec.md#requirement-moduleversie-records-sbom-import-provenance
	 */
	public function getStatus(string $moduleVersieUuid, ?string $operationId = null): array {
		$coordinates = $this->resolveCoordinates();
		$moduleVersion = $coordinates['objectService']->find(
			id: $moduleVersieUuid,
			register: $coordinates['registerId'],
			schema: $coordinates['moduleVersieSchemaId'],
			_rbac: false,
			_multitenancy: false
		);

		$data = [];
		if ($moduleVersion !== null) {
			$data = $moduleVersion->getObject();
		}

		$progress = null;
		if ($operationId !== null) {
			$progress = $this->progressTracker->getProgress(operationId: $operationId);
		}

		return [
			'sbomLastImportedAt' => $data['sbomLastImportedAt'] ?? null,
			'sbomFormat' => $data['sbomFormat'] ?? null,
			'sbomFileName' => $data['sbomFileName'] ?? null,
			'progress' => $progress,
		];
	}//end getStatus()

	/**
	 * Soft-delete the previous LIVE `sbomComponent` set for a `moduleVersie`,
	 * in bounded batches. OR's search already excludes `_deleted` rows by
	 * default, so a prior replace's trashed rows are never re-queried.
	 *
	 * @param ObjectServiceInterface $objectService The OR object service.
	 * @param int $registerId The voorzieningen register id.
	 * @param int $componentSchemaId The sbomComponent schema id.
	 * @param string $moduleVersieUuid The target moduleVersie uuid.
	 *
	 * @return array<int,string> The uuids that were soft-deleted.
	 *
	 * @spec openspec/specs/sbom-import/spec.md#requirement-re-import-replaces-the-previous-component-set-and-is-soft-delete-aware
	 */
	private function replacePreviousComponentSet(
		ObjectServiceInterface $objectService,
		int $registerId,
		int $componentSchemaId,
		string $moduleVersieUuid,
	): array {
		$previous = $objectService->searchObjects(
			[
				'@self' => [
					'schema' => $componentSchemaId,
					'register' => $registerId,
				],
				'moduleVersion' => $moduleVersieUuid,
				'_limit' => 1000,
			],
			_rbac: false,
			_multitenancy: false
		);

		if (is_array($previous) === false) {
			$previous = [];
		}

		$previousUuids = [];
		foreach ($previous as $object) {
			$previousUuids[] = $object->getUuid();
		}

		foreach (array_chunk($previousUuids, self::BATCH_SIZE) as $batch) {
			$objectService->deleteObjects($batch, _rbac: false, _multitenancy: false);
		}

		return $previousUuids;
	}//end replacePreviousComponentSet()

	/**
	 * Bulk-save the newly parsed component set in bounded batches, reporting
	 * progress per batch when tracking is active.
	 *
	 * @param ObjectServiceInterface $objectService The OR object service.
	 * @param int $registerId The voorzieningen register id.
	 * @param int $componentSchemaId The sbomComponent schema id.
	 * @param string $moduleVersieUuid The target moduleVersie uuid.
	 * @param array<int,array<string,mixed>> $components The parsed component DTOs.
	 * @param array<int,array{cveId:string,componentBomRef:string}> $vexPairs The parsed VEX cveId/bom-ref pairs.
	 * @param bool $trackProgress Whether a progress operation is active.
	 *
	 * @return int The number of components created.
	 *
	 * @spec openspec/specs/sbom-import/spec.md#requirement-large-imports-run-in-bounded-batches-with-progress-reporting
	 */
	private function createComponentSet(
		ObjectServiceInterface $objectService,
		int $registerId,
		int $componentSchemaId,
		string $moduleVersieUuid,
		array $components,
		array $vexPairs,
		bool $trackProgress,
	): int {
		$created = 0;
		$vexCveIdsByRef = $this->groupVexCveIdsByBomRef(vexPairs: $vexPairs);

		foreach (array_chunk($components, self::BATCH_SIZE) as $batch) {
			$payload = [];
			foreach ($batch as $component) {
				$payload[] = $this->componentToObjectData(
					component: $component,
					moduleVersieUuid: $moduleVersieUuid,
					vexCveIdsByRef: $vexCveIdsByRef
				);
			}

			$objectService->saveObjects(
				objects: $payload,
				register: $registerId,
				schema: $componentSchemaId,
				_rbac: false,
				_multitenancy: false
			);

			$created += count($batch);

			if ($trackProgress === true) {
				$this->progressTracker->updateProgress(processedItems: $created);
				$this->progressTracker->updateStatistics(['componentsCreated' => $created]);
			}
		}//end foreach

		return $created;
	}//end createComponentSet()

	/**
	 * Record import provenance on the `moduleVersie` — PUT-semantic OR save,
	 * so the FULL current object data is carried forward and only the three
	 * provenance fields are changed (an omitted field would otherwise be
	 * nulled by `saveObject()`).
	 *
	 * @param ObjectServiceInterface $objectService The OR object service.
	 * @param int $registerId The voorzieningen register id.
	 * @param int $versionSchemaId The moduleVersie schema id.
	 * @param object $moduleVersion The current moduleVersie entity.
	 * @param string $format The import format.
	 * @param string $fileName The uploaded file's original name.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/sbom-import/spec.md#requirement-moduleversie-records-sbom-import-provenance
	 */
	private function recordProvenance(
		ObjectServiceInterface $objectService,
		int $registerId,
		int $versionSchemaId,
		object $moduleVersion,
		string $format,
		string $fileName,
	): void {
		$data = $moduleVersion->getObject();
		$data['sbomLastImportedAt'] = (new DateTime())->format(DateTime::ATOM);
		$data['sbomFormat'] = $format;
		$data['sbomFileName'] = $fileName;

		$objectService->saveObject(
			object: $data,
			register: $registerId,
			schema: $versionSchemaId,
			uuid: $moduleVersion->getUuid(),
			_rbac: false,
			_multitenancy: false
		);
	}//end recordProvenance()

	/**
	 * Map a normalized component DTO to the `sbomComponent` OR object data
	 * bag, including its required `moduleVersie` relation and any raw
	 * VEX-extracted CVE ids for its `bomRef` (a fact from the source
	 * document — NOT a stored match; the frontend still computes the
	 * confirmed match against `kwetsbaarheid` at render time).
	 *
	 * @param array<string,mixed> $component The normalized component DTO.
	 * @param string $moduleVersieUuid The target moduleVersie uuid.
	 * @param array<string,array<int,string>> $vexCveIdsByRef bomRef => [cveId, ...].
	 *
	 * @return array<string,mixed> The `sbomComponent` object data bag.
	 */
	private function componentToObjectData(array $component, string $moduleVersieUuid, array $vexCveIdsByRef): array {
		$bomRef = $component['bomRef'] ?? '';

		$vexCveIds = [];
		if ($bomRef !== '') {
			$vexCveIds = $vexCveIdsByRef[$bomRef] ?? [];
		}

		return [
			'moduleVersion' => $moduleVersieUuid,
			'name' => $component['name'] ?? '',
			'version' => $component['version'] ?? '',
			'purl' => $component['purl'] ?? '',
			'licenses' => $component['licenses'] ?? [],
			'type' => $component['type'] ?? '',
			'hashes' => $component['hashes'] ?? [],
			'bomRef' => $bomRef,
			'vexCveIds' => $vexCveIds,
		];
	}//end componentToObjectData()

	/**
	 * Group VEX cveId/bom-ref pairs by bom-ref, so each component's raw
	 * VEX-derived CVE ids can be attached in one pass.
	 *
	 * @param array<int,array{cveId:string,componentBomRef:string}> $vexPairs The parsed VEX pairs.
	 *
	 * @return array<string,array<int,string>> bomRef => [cveId, ...].
	 */
	private function groupVexCveIdsByBomRef(array $vexPairs): array {
		$grouped = [];
		foreach ($vexPairs as $pair) {
			$ref = $pair['componentBomRef'];
			if ($ref === '') {
				continue;
			}

			$grouped[$ref][] = $pair['cveId'];
		}

		return $grouped;
	}//end groupVexCveIdsByBomRef()

	/**
	 * Count the distinct, non-empty licenses across a component list.
	 *
	 * @param array<int,array<string,mixed>> $components The parsed component DTOs.
	 *
	 * @return int The distinct license count.
	 */
	private function countDistinctLicenses(array $components): int {
		$licenses = [];
		foreach ($components as $component) {
			foreach (($component['licenses'] ?? []) as $license) {
				if (is_string($license) === true && $license !== '') {
					$licenses[$license] = true;
				}
			}
		}

		return count($licenses);
	}//end countDistinctLicenses()

	/**
	 * Resolve a relation value (string uuid, or array/object carrying a
	 * `uuid`/`id`) to a plain uuid string.
	 *
	 * @param mixed $relation The raw relation value.
	 *
	 * @return string|null The resolved uuid, or null when not resolvable.
	 */
	private function resolveRelationUuid(mixed $relation): ?string {
		if (is_string($relation) === true && $relation !== '') {
			return $relation;
		}

		if (is_array($relation) === true) {
			return $relation['uuid'] ?? ($relation['id'] ?? null);
		}

		if (is_object($relation) === true) {
			return $relation->uuid ?? ($relation->id ?? null);
		}

		return null;
	}//end resolveRelationUuid()

	/**
	 * Resolve the register/schema coordinates + ObjectService this service
	 * needs for every operation.
	 *
	 * The `objectService` key carries the PUBLISHED contract. It said
	 * `ObjectService` before — an unimported name, so it resolved inside this
	 * app's own namespace to a class that does not exist. Every `find()` on the
	 * value went unchecked, and each of the three helpers it is handed to
	 * reported a parameter-type mismatch it did not have.
	 *
	 * @return array{objectService: ObjectServiceInterface, registerId: int, moduleVersieSchemaId: int, sbomComponentSchemaId: int}
	 *
	 * @throws RuntimeException When ObjectService or required schema/register
	 *                          configuration is not available.
	 */
	private function resolveCoordinates(): array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('ObjectService not available');
		}

		$voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
		$registerId = $voorzieningenConfig['register'] ?? null;

		$versionSchemaId = $this->settingsService->getSchemaIdForObjectType('moduleVersion');
		$componentSchemaId = $this->settingsService->getSchemaIdForObjectType('sbomComponent');

		if ($registerId === null || $versionSchemaId === null || $componentSchemaId === null) {
			throw new RuntimeException(
				'sbom-import: voorzieningen register or moduleVersie/sbomComponent schema not configured'
			);
		}

		return [
			'objectService' => $objectService,
			'registerId' => (int)$registerId,
			'moduleVersieSchemaId' => (int)$versionSchemaId,
			'sbomComponentSchemaId' => (int)$componentSchemaId,
		];
	}//end resolveCoordinates()

	/**
	 * Get the OpenRegister ObjectService from the DI container.
	 *
	 * @return ObjectServiceInterface|null The object service, or null if not available.
	 */
	private function getObjectService(): ?ObjectServiceInterface {
		try {
			// Ask for the CONTRACT (ADR-084); the app's composition root aliases
			// it onto OpenRegister's concrete service.
			return $this->container->get(ObjectServiceInterface::class);
		} catch (\Exception $e) {
			$this->logger->error(
				'SbomImportService: Failed to get ObjectService',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}//end getObjectService()
}//end class
