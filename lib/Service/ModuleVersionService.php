<?php

/**
 * Module Version Service
 *
 * Ensures every module has at least one version (moduleVersie).
 * If no versions exist, creates a default 1.0.0 version using
 * the module's name and description.
 *
 * @category  Service
 * @package   OCA\SoftwareCatalog\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: 1.0.0
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/specs/method-decomposition/spec.md
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Service;

use OCA\OpenRegister\Service\ObjectService;
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
 * @package  OCA\SoftwareCatalog\Service
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
		$moduleUuid = $moduleObject->getUuid();

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			$this->logger->error('ModuleVersionService: ObjectService not available');
			return null;
		}

		$versionSchemaId = $this->settingsService->getSchemaIdForObjectType('moduleVersion');
		$voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
		$registerId = $voorzieningenConfig['register'] ?? null;

		if ($versionSchemaId === null || $registerId === null) {
			$this->logger->warning(
				'ModuleVersionService: moduleVersie schema or register not configured',
				[
					'moduleVersieSchemaId' => $versionSchemaId,
					'registerId' => $registerId,
				]
			);
			return null;
		}

		$query = [
			'@self' => [
				'schema' => (int)$versionSchemaId,
				'register' => (int)$registerId,
			],
			'module' => $moduleUuid,
			'_limit' => 200,
		];

		$existingVersions = $objectService->searchObjects(
			query: $query,
			_rbac: false,
			_multitenancy: false
		);

		$versionCount = 0;
		$existingVersionList = [];
		if (is_array($existingVersions) === true) {
			$versionCount = count($existingVersions);
			$existingVersionList = $existingVersions;
		}

		return [
			'moduleUuid' => $moduleUuid,
			'moduleData' => $moduleObject->getObject(),
			'registerId' => (int)$registerId,
			'schemaId' => (int)$versionSchemaId,
			'existingVersions' => $existingVersionList,
			'versionCount' => $versionCount,
			'objectService' => $objectService,
		];
	}//end fetchVersionData()

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
	 * @return ObjectService|null The object service or null if not available
	 */
	private function getObjectService(): ?ObjectService {
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
