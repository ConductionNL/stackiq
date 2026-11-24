<?php

/**
 * Legacy catalogue OpenRegister access
 *
 * What the migration from the previous catalogue needs from OpenRegister: the right to create usages, the
 * candidate finder on the configured register and schemas, the mapping engine and the import profile.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service\LegacyCatalogue
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\LegacyCatalogue;

use OCA\Stackiq\Exception\CmdbImportException;
use OCA\Stackiq\Service\Cmdb\CmdbImportProfile;
use OCA\Stackiq\Service\Cmdb\OpenRegisterInstance;
use OCA\Stackiq\Service\SettingsService;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * OpenRegister, as the migration from the previous catalogue reads it. Every lookup first checks that OpenRegister is
 * installed and fails closed.
 *
 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-2
 */
class LegacyCatalogueOpenRegister {

	/**
	 * The import profile of the previous catalogue's export.
	 */
	public const PROFILE_FILE = 'vng-swc-profile.json';

	/**
	 * OpenRegister's permission handler (not a public contract).
	 */
	public const PERMISSIONS = 'OCA\OpenRegister\Service\Object\PermissionHandlerInterface';

	/**
	 * OpenRegister's schema mapper.
	 */
	public const SCHEMA_MAPPER = 'OCA\OpenRegister\Db\SchemaMapper';

	/**
	 * OpenRegister's mapping engine (ADR-031).
	 */
	public const ENGINE_CLASS = 'OCA\OpenRegister\Service\MigrationPack\MappingEngine';

	/**
	 * The import profile, built on first use.
	 *
	 * @var CmdbImportProfile|null
	 */
	private ?CmdbImportProfile $profile = null;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container       Resolves the permission handler, the schema mapper and the engine.
	 * @param IAppManager        $appManager      Tells whether OpenRegister is installed.
	 * @param SettingsService    $settingsService The register, its schemas and OpenRegister's object service.
	 * @param LoggerInterface    $logger          Logs a failed permission check.
	 *
	 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-2
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether the caller may create objects in the usage schema, by OpenRegister's permission handler.
	 *
	 * @return bool False when OpenRegister, the schema or the check is not available.
	 *
	 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-2
	 */
	public function mayCreateUsages(): bool {
		$schemaId = (int)($this->settingsService->getSchemaIdForObjectType(objectType: 'usage') ?? 0);
		if ($schemaId <= 0 || $this->appManager->isInstalled('openregister') === false) {
			return false;
		}

		try {
			$schema = $this->container->get(self::SCHEMA_MAPPER)->find($schemaId);
			return $this->container->get(self::PERMISSIONS)->hasPermission(schema: $schema, action: 'create') === true;
		} catch (Throwable $e) {
			$this->logger->warning('LegacyCatalogueOpenRegister: the permission check failed', ['exception' => $e->getMessage()]);
			return false;
		}
	}//end mayCreateUsages()

	/**
	 * The candidate finder on the configured register and schemas.
	 *
	 * @return LegacyCatalogueCandidates
	 *
	 * @throws CmdbImportException NOT_CONFIGURED.
	 *
	 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-2
	 */
	public function candidates(): LegacyCatalogueCandidates {
		$objectService = $this->settingsService->getObjectService();
		$register = (int)($this->settingsService->getVoorzieningenRegisterId() ?? 0);
		$schemas = [];
		foreach (['organization', 'module', 'moduleVersion'] as $type) {
			$schemas[$type] = (int)($this->settingsService->getSchemaIdForObjectType(objectType: $type) ?? 0);
		}

		if ($objectService === null || $register <= 0 || in_array(0, $schemas, true) === true) {
			throw new CmdbImportException(
				errorCode: CmdbImportException::NOT_CONFIGURED,
				message: 'OpenRegister or the stackiq register and schemas are not configured'
			);
		}

		return new LegacyCatalogueCandidates(objectService: $objectService, register: $register, schemas: $schemas);
	}//end candidates()

	/**
	 * OpenRegister's mapping engine.
	 *
	 * @return object The engine, with `mapRow(array, array, int): array`.
	 *
	 * @throws CmdbImportException MAPPING_UNAVAILABLE.
	 *
	 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-2
	 */
	public function engine(): object {
		$engine = null;
		if ($this->appManager->isInstalled('openregister') === true && class_exists(static::ENGINE_CLASS) === true) {
			$engine = (new OpenRegisterInstance(container: $this->container))->build(class: static::ENGINE_CLASS);
		}

		if (is_object($engine) === false || method_exists($engine, 'mapRow') === false) {
			throw new CmdbImportException(errorCode: CmdbImportException::MAPPING_UNAVAILABLE, message: 'OpenRegister MappingEngine is not available');
		}

		return $engine;
	}//end engine()

	/**
	 * The import profile of the previous catalogue's export.
	 *
	 * @return CmdbImportProfile
	 *
	 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-2
	 */
	public function profile(): CmdbImportProfile {
		if ($this->profile === null) {
			$this->profile = new CmdbImportProfile(container: $this->container, profileFile: self::PROFILE_FILE);
		}

		return $this->profile;
	}//end profile()
}//end class
