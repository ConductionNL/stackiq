<?php

/**
 * Catalogue Locator for Stackiq
 *
 * Where the catalogue lives in OpenRegister, moved out of the general
 * settings service: the OpenRegister services, the configured voorzieningen
 * and AMEF registers, and the register and schema id of each object type,
 * cached per request.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/settings-service/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\Settings;

use OCA\Stackiq\AppInfo\AppIdentity;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Locates the catalogue's objects in OpenRegister.
 *
 * Not final, like the other settings handlers in this directory, so the
 * services that depend on it can be tested against a mock.
 *
 * @spec openspec/specs/settings-service/spec.md
 */
class CatalogueLocator implements ObjectTypeLocatorInterface {

	/**
	 * The unique identifier for the OpenRegister application
	 *
	 * @var string The ID of the OpenRegister app
	 */
	private const OPENREGISTER_APP_ID = 'openregister';

	/**
	 * The minimum version of the OpenRegister application required
	 *
	 * @var string The minimum required version of OpenRegister
	 */
	public const MIN_OPENREGISTER_VERSION = '0.1.7';

	/**
	 * Object type => the app-config key its schema id is actually stored under.
	 *
	 * The key was always derived from the schema SLUG. Eight slugs are now
	 * English while the stored keys are deliberately still Dutch — renaming that
	 * family is a migration across ~40 sites in three shapes, and doing a SUBSET
	 * resolves some types while leaving others silently "not configured", which
	 * is how the ratings feature died once already.
	 *
	 * So slug and key diverge, and every place that DERIVES the key from the
	 * object type (`$objectType . '_schema'`) has to come through here. Missing
	 * one is not an error: the lookup finds nothing and the caller reads it as
	 * "not configured". `getRegisterIdForObjectType()` did exactly that.
	 *
	 * @var array<string, string>
	 */
	public const LEGACY_SCHEMA_KEY = [
		'software-review' => 'beoordeeling_schema',
		// Not a Dutch-to-English legacy key like the rest, but the same
		// mechanism: `contract` collided with shillinq's and pipelinq's, all
		// three carrying `contractNumber`, so shillinq owns the contract and
		// this became the catalogue facet. The config KEY stays
		// `contract_schema` because it is live persisted state, and without
		// this entry the default `<type>_schema` rule would look for
		// `catalogContract_schema` and resolve nothing.
		'catalogContract' => 'contract_schema',
		'bioMeasure' => 'bioMaatregel_schema',
		'connection' => 'koppeling_schema',
		'contactPerson' => 'contactpersoon_schema',
		'moduleVersion' => 'moduleVersie_schema',
		'organization' => 'organisatie_schema',
		'catalogService' => 'dienst_schema',
		'usage' => 'gebruik_schema',
		'vulnerability' => 'kwetsbaarheid_schema',
	];

	/**
	 * OpenRegister's shared organisation projection.
	 *
	 * `nc-` prefixed by OpenRegister so it cannot collide with a leaf app's own
	 * `organization`, which has to keep working until every app has migrated.
	 */
	private const SHARED_ORGANISATION_SCHEMA = 'nc-organisation';

	/**
	 * Cache for schema IDs by object type to avoid repeated database queries
	 *
	 * @var array<string, int|null>
	 */
	private array $schemaIdCache = [];

	/**
	 * Cache for register IDs by object type to avoid repeated database queries
	 *
	 * @var array<string, int|null>
	 */
	private array $registerIdCache = [];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig         $config     The app config holding the register configuration.
	 * @param IAppManager        $appManager Tells whether and which OpenRegister is installed.
	 * @param ContainerInterface $container  Resolves the OpenRegister services and ArchiMateService.
	 * @param LoggerInterface    $logger     The logger.
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Checks if OpenRegister is installed and meets version requirements
	 *
	 * @param string|null $minVersion Minimum required version
	 *
	 * @return bool True if OpenRegister is installed and meets version requirements
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function isOpenRegisterInstalled(?string $minVersion = self::MIN_OPENREGISTER_VERSION): bool {
		if ($this->appManager->isInstalled(appId: self::OPENREGISTER_APP_ID) === false) {
			return false;
		}

		if ($minVersion === null) {
			return true;
		}

		$currentVersion = $this->appManager->getAppVersion(self::OPENREGISTER_APP_ID);
		return version_compare($currentVersion, $minVersion, '>=');
	}//end isOpenRegisterInstalled()

	/**
	 * Checks if OpenRegister is enabled
	 *
	 * @return bool True if OpenRegister is enabled
	 *
	 * @spec openspec/specs/settings-service/spec.md
	 */
	public function isOpenRegisterEnabled(): bool {
		return $this->appManager->isEnabledForUser(self::OPENREGISTER_APP_ID);
	}//end isOpenRegisterEnabled()

	/**
	 * Attempts to retrieve the OpenRegister service from the container
	 *
	 * @return \OCA\OpenRegister\Contract\ObjectServiceInterface|null The OpenRegister service if available
	 *
	 * @throws \RuntimeException If the service is not available
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getObjectService(): ?\OCA\OpenRegister\Contract\ObjectServiceInterface {
		if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		}

		throw new \RuntimeException('OpenRegister service is not available.');
	}//end getObjectService()

	/**
	 * Get the OpenRegister RegisterService.
	 *
	 * @return \OCA\OpenRegister\Service\RegisterService|null The RegisterService instance or null if not available.
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getRegisterService(): ?\OCA\OpenRegister\Service\RegisterService {
		if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
			return $this->container->get('OCA\OpenRegister\Service\RegisterService');
		}

		throw new \RuntimeException('OpenRegister RegisterService is not available.');
	}//end getRegisterService()

	/**
	 * Attempts to retrieve the Configuration service from the container
	 *
	 * @return \OCA\OpenRegister\Service\ConfigurationService|null The Configuration service if available
	 *
	 * @throws \RuntimeException If the service is not available
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getConfigurationService(): ?\OCA\OpenRegister\Service\ConfigurationService {
		if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
			return $this->container->get('OCA\OpenRegister\Service\ConfigurationService');
		}

		throw new \RuntimeException('Configuration service is not available.');
	}//end getConfigurationService()

	/**
	 * Attempts to retrieve the RegisterResolverService from the container.
	 *
	 * The resolver centralises `<context>_register` / `<context>_schema` / `<context>_property`
	 * config-key reads so per-install admin overrides (and request-scoped caching + tenant
	 * awareness) behave identically across consumer apps. Returns `null` when OpenRegister
	 * is not installed, or when the OR version pre-dates the resolver class (graceful
	 * fallback — callers must consult their existing `getValueString` path in that case).
	 *
	 * @return \OCA\OpenRegister\Service\RegisterResolverService|null The resolver or null.
	 *
	 * @spec openspec/changes/softwarecatalog-adopt-or-abstractions/tasks.md#phase-2
	 */
	public function getRegisterResolverService(): ?\OCA\OpenRegister\Service\RegisterResolverService {
		if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === false) {
			return null;
		}

		if (class_exists('\OCA\OpenRegister\Service\RegisterResolverService') === false) {
			// OR is installed but pre-dates the resolver (shipped on OR development 2026-06-12,
			// commit 50a6a0afc). Caller falls back to the legacy IAppConfig path.
			return null;
		}

		try {
			return $this->container->get('OCA\OpenRegister\Service\RegisterResolverService');
		} catch (\Throwable $e) {
			// Container resolution may fail in test contexts where OR services are not wired.
			return null;
		}
	}//end getRegisterResolverService()

	/**
	 * Gets the configured schema ID for a specific object type
	 *
	 * @param string $objectType The object type (organization, contact, gebruiker, contactpersoon)
	 *
	 * @return int|null The schema ID or null if not configured
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getSchemaIdForObjectType(string $objectType): ?int {
		// Check cache first for performance optimization.
		if (array_key_exists($objectType, $this->schemaIdCache) === true) {
			$cachedValue = $this->schemaIdCache[$objectType];
			$this->logger->debug(
				'SettingsService: Schema ID retrieved from cache',
				[
					'objectType' => $objectType,
					'cachedValue' => $cachedValue,
					'fromCache' => true,
				]
			);
			return $cachedValue;
		}

		$startTime = microtime(true);
		$result = null;

		$voorzieningenConfig = $this->getVoorzieningenConfig();

		$this->logger->debug(
			'SettingsService: Starting schema ID lookup (cache miss)',
			[
				'objectType' => $objectType,
				'timestamp' => date('Y-m-d H:i:s'),
			]
		);

		// First try register-specific configuration.
		// Check for AMEF register specific schemas from JSON config.
		$amefConfig = $this->config->getValueString(AppIdentity::APP_ID, 'amef_config', '{}');
		if (empty($amefConfig) === false && $amefConfig !== '{}') {
			$decodedAmefConfig = json_decode($amefConfig, true);
			if (is_array($decodedAmefConfig) === true) {
				// Map object types to their corresponding AMEF config keys.
				$amefKeyMap = [
					'model' => 'model_schema',
					'element' => 'element_schema',
					'relationship' => 'relation_schema',
					// Note: relation vs relationship.
					'view' => 'view_schema',
					'property_definition' => 'property_definition_schema',
					// Property definitions are root-level AMEF objects.
					'organization' => 'organization_schema',
					// NOTE: 'property' mapping removed - properties are never
					// root-level AMEF objects, only nested within other elements.
				];

				$amefKey = $amefKeyMap[$objectType] ?? null;

				if (isset($decodedAmefConfig[$amefKey]) === true) {
					$schemaId = $decodedAmefConfig[$amefKey];
					if (empty($schemaId) === false) {
						$result = (int)$schemaId;
						$this->logger->debug(
							'SettingsService: Found schema ID in AMEF JSON config',
							[
								'objectType' => $objectType,
								'amefKey' => $amefKey,
								'schemaId' => $result,
							]
						);
					}
				}
			}//end if
		}//end if

		$voorzieningenKeyMap = [
			'module' => 'module_schema',
			'compliancy' => 'compliancy_schema',
			'moduleVersion' => 'moduleVersie_schema',
			'sbomComponent' => 'sbomComponent_schema',
			// Every catalog object type stored in the voorzieningen register must
			// be mapped here, or getSchemaIdForObjectType() returns null for it
			// even though its `<type>_schema` id is present in the config — which
			// silently killed the ratings feature (`beoordeeling` was unmapped, so
			// ReviewService/ReviewAggregateService read "not configured" forever).
			// The object type on the LEFT is the schema slug and moves with it.
			// The config KEY on the right is a stored app-config key and does NOT:
			// renaming it is a data migration across roughly forty sites in this
			// class alone, including differently-prefixed families
			// (`voorzieningen_contactpersoon_schema`) and compound keys
			// (`koppeling_gebruik_schema`). Renaming a subset would resolve some
			// types and silently leave others reporting "not configured", which is
			// how the ratings feature died once already. Tracked as its own change.
			'software-review' => 'beoordeeling_schema',
			'catalogService' => 'dienst_schema',
			'usage' => 'gebruik_schema',
			'catalogContract' => 'contract_schema',
			'connection' => 'koppeling_schema',
			'suite' => 'suite_schema',
			'vulnerability' => 'kwetsbaarheid_schema',
			'sector' => 'sector_schema',
			// Planned maintenance on a product (lifecycle-maintenance-and-supplier-roadmap).
			'maintenanceWindow' => 'maintenanceWindow_schema',
		];

		// Only check voorzieningen config if object type exists in the key map.
		if ($result === null && isset($voorzieningenKeyMap[$objectType]) === true) {
			$voorzieningenKey = $voorzieningenKeyMap[$objectType];
			if (isset($voorzieningenConfig[$voorzieningenKey]) === true) {
				$result = (int)$voorzieningenConfig[$voorzieningenKey];
			}
		}

		// `organisatie` and `organization` used to be two object types resolving
		// to two schemas. They are one now, so the two branches that handled them
		// separately are one branch — and the ORDER inverted with the merge:
		// `amef_organization_schema` points at the ArchiMate schema, which is the
		// one that was absorbed and is now retired, holding no rows. Preferring
		// it would resolve every organisation lookup to an empty schema, so the
		// merged voorzieningen schema wins and AMEF is the last resort.
		if ($result === null && $objectType === 'organization') {
			$schemaId = ($voorzieningenConfig['organisatie_schema'] ?? '');

			if (empty($schemaId) === true) {
				$schemaId = $this->config->getValueString(AppIdentity::APP_ID, 'amef_organization_schema', '');
			}

			if (empty($schemaId) === false) {
				$result = (int)$schemaId;
			}
		}

		// LAST, deliberately. The organisation is OpenRegister's now — a schema
		// slug is global per organisation, so this app shipping its own
		// `organization` is what collided with opencatalogi's — and
		// `nc-organisation` is the projection every app shares.
		//
		// But it resolves only when nothing local did. Putting it FIRST was the
		// obvious shape and the wrong one: the projection always exists, so it
		// would win on an instance that has not yet run
		// `openregister:organisations:adopt`, and that instance's rows still
		// live in the local schema. Every picker would come back empty with
		// nothing reporting why.
		//
		// This ordering needs no operator timing. An un-migrated instance keeps
		// resolving locally; a fresh install has no local schema and lands on the
		// projection; and once `prune-retired` has removed the local schema, so
		// does a migrated one.
		if ($result === null && $objectType === 'organization') {
			$result = $this->sharedOrganisationSchemaId();
		}

		if ($objectType === 'contactPerson' && $result === null) {
			$schemaId = $voorzieningenConfig['contactpersoon_schema'];
			if (empty($schemaId) === false) {
				$result = (int)$schemaId;
			}
		}

		// Fall back to generic configuration for backward compatibility.
		// @spec openspec/changes/softwarecatalog-adopt-or-abstractions/tasks.md#phase-2
		// Prefer the OR RegisterResolverService when available so per-install admin
		// overrides go through the same `<context>_schema` resolution pipeline used by
		// every other Conduction app (request-scoped caching + tenant-aware). Falls back
		// to the bare IAppConfig read when OR is absent or the resolver pre-dates W21-B.
		if ($result === null) {
			$resolver = $this->getRegisterResolverService();
			$schemaId = '';

			if ($resolver !== null) {
				try {
					$schemaId = $resolver->lookupId(
						lookup: new \OCA\OpenRegister\Service\ConfigLookup(
							appId: AppIdentity::APP_ID,
							configKey: "{$objectType}_schema",
							default: '',
						)
					);
				} catch (\Throwable $e) {
					// MissingConfigException or transient resolver failure — fall through to legacy read.
					$schemaId = '';
				}
			}

			if ($schemaId === '') {
				$schemaId = $this->config->getValueString(AppIdentity::APP_ID, "{$objectType}_schema", '');
			}

			if (empty($schemaId) === false) {
				$result = (int)$schemaId;
			}
		}//end if

		// Cache the result (even if null) to avoid repeated lookups.
		$this->schemaIdCache[$objectType] = $result;

		$lookupTime = round((microtime(true) - $startTime) * 1000, 2);

		if ($result !== null) {
			$this->logger->info(
				'SettingsService: Found schema ID and cached result',
				[
					'objectType' => $objectType,
					'schemaId' => $result,
					'lookupTime' => $lookupTime . 'ms',
					'fromCache' => false,
				]
			);
		} else {
			$this->logger->debug(
				'SettingsService: No schema ID found, cached null result',
				[
					'objectType' => $objectType,
					'lookupTime' => $lookupTime . 'ms',
					'fromCache' => false,
				]
			);
		}

		return $result;
	}//end getSchemaIdForObjectType()

	/**
	 * The id of OpenRegister's shared organisation projection, or null.
	 *
	 * `nc-organisation` is a read-only virtual schema OpenRegister serves from
	 * its always-available `directory` register, alongside `nc-user` and
	 * `nc-group`. Every app can reference it, which is the point: this app
	 * shipping its own `organization` schema is what collided with
	 * opencatalogi's, since a schema slug is global per organisation.
	 *
	 * Resolved lazily and failing to null, because an OpenRegister too old to
	 * carry the projection must fall through to this app's own schema rather
	 * than resolving organisations to nothing.
	 *
	 * @return int|null The schema id, or null when it cannot be resolved.
	 *
	 * @spec openspec/changes/stackiq-uses-the-shared-organisation/specs/shared-organisation/spec.md#requirement-the-organisation-is-openregisters-req-sso-101
	 */
	private function sharedOrganisationSchemaId(): ?int {
		if ($this->isOpenRegisterInstalled() === false) {
			return null;
		}

		try {
			$schemaMapper = $this->container->get('OCA\\OpenRegister\\Db\\SchemaMapper');
			$schema = $schemaMapper->find(self::SHARED_ORGANISATION_SCHEMA);
		} catch (\Throwable $e) {
			$this->logger->debug(
				'SettingsService: the shared organisation projection is unavailable',
				['error' => $e->getMessage()]
			);
			return null;
		}

		if ($schema === null) {
			return null;
		}

		return (int)$schema->getId();
	}//end sharedOrganisationSchemaId()

	/**
	 * The app-config key an object type's schema id is stored under.
	 *
	 * Slug and stored key diverged when the slugs were translated, so never
	 * build the key as `$objectType . '_schema'` directly — see LEGACY_SCHEMA_KEY.
	 *
	 * @param string $objectType The object type (schema slug).
	 *
	 * @return string The app-config key.
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function voorzieningenSchemaKey(string $objectType): string {
		return (self::LEGACY_SCHEMA_KEY[$objectType] ?? $objectType . '_schema');
	}//end voorzieningenSchemaKey()

	/**
	 * Gets the configured register ID for a specific object type
	 *
	 * @param string $objectType The object type (organization, contact, gebruiker)
	 *
	 * @return int|null The register ID or null if not configured
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getRegisterIdForObjectType(string $objectType): ?int {
		// Check cache first for performance optimization.
		if (array_key_exists($objectType, $this->registerIdCache) === true) {
			$cachedValue = $this->registerIdCache[$objectType];
			$this->logger->debug(
				'SettingsService: Register ID retrieved from cache',
				[
					'objectType' => $objectType,
					'cachedValue' => $cachedValue,
					'fromCache' => true,
				]
			);
			return $cachedValue;
		}

		$result = null;

		// Check AMEF register for organization.
		if ($objectType === 'organization') {
			$amefConfig = $this->getAmefConfig();
			if (isset($amefConfig['register']) === true && empty($amefConfig['register']) === false) {
				$result = (int)$amefConfig['register'];
			}
		}

		// Check Voorzieningen register for every catalog object type. All catalog
		// schemas (organisatie, contactpersoon, module, gebruik, contract,
		// koppeling, beoordeeling, suite, kwetsbaarheid, sector, …) live in the one
		// voorzieningen register, but this method previously mapped it only for
		// organisatie/contactpersoon — so a caller resolving e.g. `beoordeeling`
		// got null and the feature read as "not configured" (ratings submit,
		// aggregate and moderation were all dead this way). Mirror the schema-side
		// key map: any type with a `<type>_schema` in the voorzieningen config
		// belongs to the voorzieningen register.
		$voorzieningenConfig = $this->getVoorzieningenConfig();
		$isVoorzieningenType = in_array($objectType, ['organization', 'contactPerson', 'contact'], true);
		if ($isVoorzieningenType === false) {
			$isVoorzieningenType = isset($voorzieningenConfig[$this->voorzieningenSchemaKey(objectType: $objectType)]);
		}

		if ($result === null && $isVoorzieningenType === true) {
			if (isset($voorzieningenConfig['register']) === true && empty($voorzieningenConfig['register']) === false) {
				$result = (int)$voorzieningenConfig['register'];
			}
		}

		// @spec openspec/changes/softwarecatalog-adopt-or-abstractions/tasks.md#phase-2
		// Fallback to legacy per-object-type register config — route through the OR
		// RegisterResolverService when available so per-install admin overrides flow
		// through the same `<context>_register` resolution pipeline used by every
		// other Conduction app (request-scoped caching + tenant-aware). Falls back
		// to the bare IAppConfig read when OR is absent or the resolver pre-dates W21-B.
		if ($result === null) {
			$resolver = $this->getRegisterResolverService();
			$registerId = '';

			if ($resolver !== null) {
				try {
					$registerId = $resolver->lookupId(
						lookup: new \OCA\OpenRegister\Service\ConfigLookup(
							appId: AppIdentity::APP_ID,
							configKey: "{$objectType}_register",
							default: '',
						)
					);
				} catch (\Throwable $e) {
					// MissingConfigException or transient resolver failure — fall through to legacy read.
					$registerId = '';
				}
			}

			if ($registerId === '') {
				$registerId = $this->config->getValueString(AppIdentity::APP_ID, "{$objectType}_register", '');
			}

			$result = null;
			if (empty($registerId) === false) {
				$result = (int)$registerId;
			}
		}//end if

		// Cache the result (even if null) to avoid repeated lookups.
		$this->registerIdCache[$objectType] = $result;

		$this->logger->debug(
			'SettingsService: Register ID looked up and cached',
			[
				'objectType' => $objectType,
				'result' => $result,
				'fromCache' => false,
			]
		);

		return $result;
	}//end getRegisterIdForObjectType()

	/**
	 * Clear cached schema and register IDs
	 *
	 * This method should be called when configuration changes to ensure
	 * cached values don't become stale.
	 *
	 * @return void
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function clearConfigurationCache(): void {
		$this->logger->debug(
			'SettingsService: Clearing configuration cache',
			[
				'cached_schema_ids' => count($this->schemaIdCache),
				'cached_register_ids' => count($this->registerIdCache),
			]
		);

		$this->schemaIdCache = [];
		$this->registerIdCache = [];

		$this->logger->info('SettingsService: Configuration cache cleared');
	}//end clearConfigurationCache()

	/**
	 * Gets the configured register ID for the voorzieningen register
	 *
	 * @return int|null The register ID or null if not configured
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getVoorzieningenRegisterId(): ?int {
		$startTime = microtime(true);

		$this->logger->debug(
			'SettingsService: Starting voorzieningen register ID lookup',
			[
				'timestamp' => date('Y-m-d H:i:s'),
			]
		);

		// Try voorzieningen-specific configuration first.
		$this->logger->debug(
			'SettingsService: Checking voorzieningen organisatie register',
			[
				'configKey' => 'voorzieningen_organisatie_register',
			]
		);

		$registerId = $this->config->getValueString(AppIdentity::APP_ID, 'voorzieningen_organisatie_register', '');

		$this->logger->debug(
			'SettingsService: Voorzieningen organisatie register result',
			[
				'configKey' => 'voorzieningen_organisatie_register',
				'rawValue' => $registerId,
				'isEmpty' => empty($registerId) === true,
			]
		);

		if (empty($registerId) === false) {
			$result = (int)$registerId;
			$this->logger->info(
				'SettingsService: Found voorzieningen organisatie register',
				[
					'registerId' => $result,
					'lookupTime' => round((microtime(true) - $startTime) * 1000, 2) . 'ms',
				]
			);
			return $result;
		}

		// Also try contactpersoon as fallback.
		$this->logger->debug(
			'SettingsService: Checking voorzieningen contactpersoon register',
			[
				'configKey' => 'voorzieningen_contactpersoon_register',
			]
		);

		$registerId = $this->config->getValueString(AppIdentity::APP_ID, 'voorzieningen_contactpersoon_register', '');

		$this->logger->debug(
			'SettingsService: Voorzieningen contactpersoon register result',
			[
				'configKey' => 'voorzieningen_contactpersoon_register',
				'rawValue' => $registerId,
				'isEmpty' => empty($registerId) === true,
			]
		);

		if (empty($registerId) === false) {
			$result = (int)$registerId;
			$this->logger->info(
				'SettingsService: Found voorzieningen contactpersoon register',
				[
					'registerId' => $result,
					'lookupTime' => round((microtime(true) - $startTime) * 1000, 2) . 'ms',
				]
			);
			return $result;
		}

		// Fall back to organization register for backward compatibility.
		$this->logger->debug(
			'SettingsService: Checking organization register for backward compatibility',
			[
				'configKey' => 'organization_register',
			]
		);

		$result = $this->getRegisterIdForObjectType(objectType: 'organization');

		if ($result !== null) {
			$this->logger->info(
				'SettingsService: Found organization register for backward compatibility',
				[
					'registerId' => $result,
					'lookupTime' => round((microtime(true) - $startTime) * 1000, 2) . 'ms',
				]
			);
			return $result;
		}

		$this->logger->warning(
			'SettingsService: No register ID found for voorzieningen',
			[
				'checkedConfigurations' => [
					'voorzieningen_organisatie_register' => true,
					'voorzieningen_contactpersoon_register' => true,
					'organization_register' => true,
				],
				'lookupTime' => round((microtime(true) - $startTime) * 1000, 2) . 'ms',
			]
		);

		return null;
	}//end getVoorzieningenRegisterId()

	/**
	 * Get Voorzieningen configuration as JSON object
	 *
	 * @return array The voorzieningen configuration
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getVoorzieningenConfig(): array {
		$config = $this->config->getValueString(AppIdentity::APP_ID, 'voorzieningen_config', '{}');
		$decoded = json_decode($config, true);

		// Backward compatibility: build minimal structure from legacy scalar keys.
		if (is_array($decoded) === false) {
			$decoded = [
				'register' => $this->config->getValueString(
					AppIdentity::APP_ID,
					'voorzieningen_register',
					''
				),
				'organisatie_schema' => $this->config->getValueString(
					AppIdentity::APP_ID,
					'voorzieningen_organisatie_schema',
					''
				),
				'contactpersoon_schema' => $this->config->getValueString(
					AppIdentity::APP_ID,
					'voorzieningen_contactpersoon_schema',
					''
				),
			];
		}

		// Normalize to the new, clean structure: no *_source or *_register keys,.
		// include all known schema keys, and accept legacy 'voorzieningen_*_schema' fallbacks.
		return (new VoorzieningenConfigNormalizer())->normalize(input: $decoded);
	}//end getVoorzieningenConfig()

	/**
	 * Set Voorzieningen configuration as JSON object
	 *
	 * @param array $config The voorzieningen configuration.
	 *
	 * @return void
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function setVoorzieningenConfig(array $config): void {
		// Clear cache since voorzieningen config affects schema and register IDs.
		$this->clearConfigurationCache();

		// Persist only normalized structure.
		$normalized = (new VoorzieningenConfigNormalizer())->normalize(input: $config);
		$jsonConfig = json_encode($normalized, JSON_PRETTY_PRINT);
		$this->config->setValueString(AppIdentity::APP_ID, 'voorzieningen_config', $jsonConfig);
	}//end setVoorzieningenConfig()

	/**
	 * Gets AMEF configuration using ArchiMateService to avoid code duplication
	 *
	 * This method delegates to ArchiMateService's getAmefConfig method
	 * to ensure consistency and avoid code duplication.
	 *
	 * @return array The AMEF configuration
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getAmefConfig(): array {
		try {
			// Get ArchiMateService from container to avoid circular dependency.
			$archiMateService = $this->container->get(\OCA\Stackiq\Service\ArchiMateService::class);

			// Use reflection to access the private getAmefConfig method.
			// setAccessible() is unnecessary on PHP 8.1+ — private methods are
			// already invokable via reflection without it.
			$reflection = new \ReflectionClass($archiMateService);
			$method = $reflection->getMethod('getAmefConfig');

			return $method->invoke($archiMateService);
		} catch (\Exception $e) {
			$this->logger->error(
				'SettingsService: Failed to get AMEF config from ArchiMateService',
				[
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);

			// Fallback to direct config access if ArchiMateService is not available.
			$config = $this->config->getValueString(AppIdentity::APP_ID, 'amef_config', '{}');
			$decoded = json_decode($config, true);

			if (is_array($decoded) === false) {
				// Fallback to individual config values for backward compatibility.
				$decoded = [
					'register_id' => $this->config->getValueString(
						AppIdentity::APP_ID,
						'amef_register_id',
						''
					),
					'organizations_schema' => $this->config->getValueString(
						AppIdentity::APP_ID,
						'amef_organizations_schema',
						''
					),
					'elements_schema' => $this->config->getValueString(
						AppIdentity::APP_ID,
						'amef_elements_schema',
						''
					),
					'relationships_schema' => $this->config->getValueString(
						AppIdentity::APP_ID,
						'amef_relationships_schema',
						''
					),
					'views_schema' => $this->config->getValueString(
						AppIdentity::APP_ID,
						'amef_views_schema',
						''
					),
					'models_schema' => $this->config->getValueString(
						AppIdentity::APP_ID,
						'amef_models_schema',
						''
					),
				];
			}//end if

			return $decoded;
		}//end try
	}//end getAmefConfig()

	/**
	 * Set AMEF configuration as JSON object
	 *
	 * @param array $config The AMEF configuration.
	 *
	 * @return void
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function setAmefConfig(array $config): void {
		$jsonConfig = json_encode($config, JSON_PRETTY_PRINT);
		$this->config->setValueString(AppIdentity::APP_ID, 'amef_config', $jsonConfig);

		// Clear configuration cache when AMEF config is updated.
		$this->clearConfigurationCache();

		$this->logger->debug(
			'SettingsService: AMEF configuration updated and cache cleared',
			[
				'config_keys' => array_keys($config),
				'cache_cleared' => true,
			]
		);
	}//end setAmefConfig()
}//end class
