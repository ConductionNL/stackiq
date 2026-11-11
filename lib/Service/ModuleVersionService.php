<?php

/**
 * Module Version Service
 *
 * Ensures every module has at least one version (moduleVersie).
 * If no versions exist, creates a default 1.0.0 version using
 * the module's name and description.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: 1.0.0
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/method-decomposition/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for ensuring modules always have at least one version.
 *
 * Decomposed per `openspec/changes/method-decomposition/tasks.md` task 9.5:
 * the previously long `ensureDefaultVersion()` is now an orchestrator that
 * delegates to small, single-responsibility private helpers
 * (`fetchVersionData()`, `compareVersions()`, `updateVersionRecord()`).
 *
 * @category Service
 * @package  OCA\Stackiq\Service
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-9-5
 */
class ModuleVersionService {
	/**
	 * Constructor for ModuleVersionService
	 *
	 * @param ContainerInterface $container The DI container
	 * @param SettingsService $settingsService The settings service
	 * @param LoggerInterface $logger The logger instance
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Ensures a module has at least one version.
	 *
	 * Thin orchestrator: resolves config + existing versions via
	 * `fetchVersionData()`, decides whether action is needed via
	 * `compareVersions()`, then writes through `updateVersionRecord()`.
	 *
	 * @param object $moduleObject The module object entity
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-9-5
	 */
	public function ensureDefaultVersion(object $moduleObject): void {
		$moduleUuid = $moduleObject->getUuid();

		$this->logger->info(
			'ModuleVersionService: Checking if module has versions',
			['moduleUuid' => $moduleUuid]
		);

		try {
			$context = $this->fetchVersionData(moduleObject: $moduleObject);
			if ($context === null) {
				return;
			}

			if ($this->compareVersions(context: $context) === true) {
				$this->logger->info(
					'ModuleVersionService: Module already has versions, skipping',
					[
						'moduleUuid' => $moduleUuid,
						'versionCount' => $context['versionCount'],
					]
				);
				return;
			}

			$this->updateVersionRecord(context: $context);
		} catch (\Exception $e) {
			$this->logger->error(
				'ModuleVersionService: Failed to ensure default version',
				[
					'moduleUuid' => $moduleUuid,
					'exception' => $e->getMessage(),
					'file' => $e->getFile(),
					'line' => $e->getLine(),
				]
			);
		}//end try
	}//end ensureDefaultVersion()

	/**
	 * Resolve all data needed to decide on a default version.
	 *
	 * Returns a structured context with module identity, schema/register
	 * coordinates and the currently existing versions. Returns null when
	 * the necessary configuration is missing — the caller treats that as
	 * a no-op.
	 *
	 * @param object $moduleObject The module object entity
	 *
	 * @return array{moduleUuid:string,moduleData:array,registerId:int,schemaId:int,existingVersions:array,versionCount:int}|null
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-9-5
	 */
	private function fetchVersionData(object $moduleObject): ?array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			$this->logger->error('ModuleVersionService: ObjectService not available');
			return null;
		}

		$lookup = $this->versionLookup(moduleObject: $moduleObject);
		if ($lookup === null) {
			return null;
		}

		$lookup['objectService'] = $objectService;
		return $this->withExistingVersions(moduleObject: $moduleObject, lookup: $lookup);
	}//end fetchVersionData()

	/**
	 * Resolve the module UUID and the moduleVersion schema and register to search in.
	 *
	 * @param object $moduleObject The module object entity
	 *
	 * @return array{moduleUuid:string,registerId:int,schemaId:int}|null Null when the schema or register is not configured.
	 */
	private function versionLookup(object $moduleObject): ?array {
		$versionSchemaId = $this->settingsService->getSchemaIdForObjectType('moduleVersion');
		$voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
		$registerId = $voorzieningenConfig['register'] ?? null;

		if ($versionSchemaId === null || $registerId === null) {
			$this->logMissingVersionConfig(versionSchemaId: $versionSchemaId, registerId: $registerId);
			return null;
		}

		return [
			'moduleUuid' => $moduleObject->getUuid(),
			'registerId' => (int)$registerId,
			'schemaId' => (int)$versionSchemaId,
		];
	}//end versionLookup()

	/**
	 * Log that the moduleVersion schema or the register is not configured.
	 *
	 * @param int|null $versionSchemaId The moduleVersion schema id, if any
	 * @param mixed    $registerId      The register id, if any
	 *
	 * @return void
	 */
	private function logMissingVersionConfig(?int $versionSchemaId, mixed $registerId): void {
		$this->logger->warning(
			'ModuleVersionService: moduleVersie schema or register not configured',
			[
				'moduleVersieSchemaId' => $versionSchemaId,
				'registerId' => $registerId,
			]
		);
	}//end logMissingVersionConfig()

	/**
	 * Complete the version context with the module's data and its existing versions.
	 *
	 * @param object $moduleObject The module object entity
	 * @param array  $lookup       The module UUID, register, schema and ObjectService
	 *
	 * @return array{moduleUuid:string,moduleData:array,registerId:int,schemaId:int,existingVersions:array,versionCount:int}
	 */
	private function withExistingVersions(object $moduleObject, array $lookup): array {
		$existingVersionList = $this->existingVersionList(lookup: $lookup);

		return [
			'moduleUuid' => $lookup['moduleUuid'],
			'moduleData' => $moduleObject->getObject(),
			'registerId' => $lookup['registerId'],
			'schemaId' => $lookup['schemaId'],
			'existingVersions' => $existingVersionList,
			'versionCount' => count($existingVersionList),
			'objectService' => $lookup['objectService'],
		];
	}//end withExistingVersions()

	/**
	 * Search the existing versions of the module, system-wide.
	 *
	 * @param array $lookup The module UUID, register, schema and ObjectService
	 *
	 * @return array The existing versions; empty when the search gives no list.
	 */
	private function existingVersionList(array $lookup): array {
		$existingVersions = $lookup['objectService']->searchObjects(
			query: $this->versionQuery(lookup: $lookup),
			_rbac: false,
			_multitenancy: false
		);

		if (is_array($existingVersions) === true) {
			return $existingVersions;
		}

		return [];
	}//end existingVersionList()

	/**
	 * The search for the module's versions in the moduleVersion schema.
	 *
	 * @param array $lookup The module UUID, register and schema
	 *
	 * @return array The search query.
	 */
	private function versionQuery(array $lookup): array {
		return [
			'@self' => [
				'schema' => $lookup['schemaId'],
				'register' => $lookup['registerId'],
			],
			'module' => $lookup['moduleUuid'],
			'_limit' => 200,
		];
	}//end versionQuery()

	/**
	 * Decide whether the module already has at least one version.
	 *
	 * Pure decision helper — true means "skip, no default needed".
	 *
	 * @param array $context Result of fetchVersionData()
	 *
	 * @return bool True if a version already exists; false if a default
	 *              version must be created.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-9-5
	 */
	private function compareVersions(array $context): bool {
		return ($context['versionCount'] ?? 0) > 0;
	}//end compareVersions()

	/**
	 * Persist a default 1.0.0 version record for the module.
	 *
	 * @param array $context Result of fetchVersionData() — must include
	 *                       moduleUuid, moduleData, registerId, schemaId,
	 *                       and the ObjectService handle.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-9-5
	 */
	private function updateVersionRecord(array $context): void {
		$moduleData = $context['moduleData'];
		$moduleName = $moduleData['voorkeurnaam'] ?? $moduleData['name'] ?? 'Onbekende applicatie';
		$moduleDescription = $moduleData['shortDescription'] ?? '';

		$versionData = [
			'module' => $context['moduleUuid'],
			'version' => '1.0.0',
			'shortDescription' => $moduleDescription,
			'longDescription' => '',
			'status' => 'in use',
		];

		$savedVersion = $context['objectService']->saveObject(
			object: $versionData,
			register: $context['registerId'],
			schema: $context['schemaId'],
			_rbac: false,
			_multitenancy: false
		);

		$this->logger->info(
			'ModuleVersionService: Created default version 1.0.0',
			[
				'moduleUuid' => $context['moduleUuid'],
				'moduleName' => $moduleName,
				'versionUuid' => $savedVersion->getUuid(),
			]
		);
	}//end updateVersionRecord()

	/**
	 * Get the object service from the DI container.
	 *
	 * @return ObjectServiceInterface|null The object service or null if not available
	 */
	private function getObjectService(): ?ObjectServiceInterface {
		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Exception $e) {
			$this->logger->error(
				'ModuleVersionService: Failed to get ObjectService',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}//end getObjectService()
}//end class
