<?php

/**
 * Organization Synchronization Service
 *
 * This file contains the service class for synchronizing organizations and contact persons
 * between Stackiq objects and OpenRegister entities.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/method-decomposition/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\Stackiq\Service\Stackiq\ContactPersonHandler;
use OCP\IAppConfig;
use OCP\IDBConnection;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for synchronizing organizations and contact persons.
 *
 * This service provides comprehensive synchronization between Stackiq objects
 * and OpenRegister entities, ensuring data consistency and proper user management.
 *
 * @category Service
 * @package  OCA\Stackiq\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.LongVariable)
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.MissingImport)
 * @SuppressWarnings(PHPMD.UnusedLocalVariable)
 * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
 * @SuppressWarnings(PHPMD.Superglobals)
 * @SuppressWarnings(PHPMD.CamelCaseVariableName)
 * @SuppressWarnings(PHPMD.CamelCaseParameterName)
 *
 * @spec openspec/specs/organization-sync/spec.md#requirement-the-system-shall-process-individual-organisationcontact-records-and-ensure-their-openregister-entity-req-002
 */
class OrganizationSyncService {

	/**
	 * OrganisatieService instance
	 *
	 * @var OrganisatieService The service for organization operations
	 */
	private OrganisatieService $organisationService;

	/**
	 * ContactpersoonService instance
	 *
	 * @var ContactpersoonService The service for contact person operations
	 */
	private ContactpersoonService $contactPersonService;

	/**
	 * SymfonyEmailService instance
	 *
	 * @var SymfonyEmailService The service for sending emails
	 */
	private SymfonyEmailService $emailService;

	/**
	 * Configuration service instance
	 *
	 * @var IAppConfig The Nextcloud app configuration service
	 */
	private IAppConfig $config;

	/**
	 * Settings service instance
	 *
	 * @var SettingsService The settings service for configuration
	 */
	private SettingsService $settingsService;

	/**
	 * Logger instance
	 *
	 * @var LoggerInterface The logger for sync operations
	 */
	private LoggerInterface $logger;

	/**
	 * Container instance for lazy service resolution.
	 *
	 * @var ContainerInterface
	 */
	private ContainerInterface $container;

	/**
	 * Constructor for OrganizationSyncService
	 *
	 * @param OrganisatieService $organisationService The organization service.
	 * @param ContactpersoonService $contactPersonService The contact person service.
	 * @param SymfonyEmailService $emailService The email service.
	 * @param IAppConfig $config The configuration service.
	 * @param LoggerInterface $logger The logger instance.
	 * @param SettingsService $settingsService The settings service.
	 * @param IDBConnection $db The database connection.
	 * @param ContactPersonHandler $contactpersonHandler The contact person handler.
	 * @param ContainerInterface $container The DI container.
	 * @param ObjectServiceInterface $objectService OpenRegister object access (ADR-084 contract).
	 * @param OrganisationMapper $organisationMapper Resolves the acting organisation for sync writes.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
	 *
	 * Eleven collaborators, one over the threshold. Suppressed rather than
	 * shaved because the two cheap-looking reductions both make it worse:
	 * `$container` is not dead weight — it resolves `IUserManager` and
	 * `IGroupManager` lazily — so replacing it with those two takes the count
	 * to twelve, and folding any pair behind a parameter object hides a
	 * dependency the composition root has to state anyway
	 * (`tests/Unit/AppInfo/CompositionRootArgumentsTest.php` checks it does).
	 * The real remedy is splitting this 2,700-line service, which is a
	 * refactor, not a quality-gate change. Same annotation, same reasoning as
	 * ContactpersonenController::__construct().
	 */
	public function __construct(
		OrganisatieService $organisationService,
		ContactpersoonService $contactPersonService,
		SymfonyEmailService $emailService,
		IAppConfig $config,
		LoggerInterface $logger,
		SettingsService $settingsService,
		private IDBConnection $db,
		private readonly ContactPersonHandler $contactpersonHandler,
		ContainerInterface $container,
		private readonly ObjectServiceInterface $objectService,
		private readonly OrganisationMapper $organisationMapper,
	) {
		$this->organisationService = $organisationService;
		$this->contactPersonService = $contactPersonService;
		$this->emailService = $emailService;
		$this->config = $config;
		$this->logger = $logger;
		$this->settingsService = $settingsService;
		$this->container = $container;

	}//end __construct()

	/**
	 * Centralised sync error handler.
	 *
	 * Records the error against the per-operation stats array and emits a
	 * uniformly-structured log line. Extracted from the half-dozen ad-hoc
	 * catch blocks scattered through `performOrganizationsSync()`,
	 * `performContactSync()`, and `performFullSync()` per
	 * `openspec/changes/method-decomposition/tasks.md` task 7.1.
	 *
	 * @param string $operation Short operation label (e.g. "OrganizationSync").
	 * @param string $identifier Object identifier (UUID / username) the
	 *                           failure was tied to.
	 * @param \Throwable $exception The caught throwable.
	 * @param array $stats Stats array (by reference) — receives an
	 *                     entry in `errors[]`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-7-1
	 */
	private function handleSyncError(
		string $operation,
		string $identifier,
		\Throwable $exception,
		array &$stats,
	): void {
		$error = $identifier . ': ' . $exception->getMessage();
		if (isset($stats['errors']) === true && is_array($stats['errors']) === true) {
			$stats['errors'][] = $error;
		}

		$this->logger->error(
			$operation . ': failed to process ' . $identifier,
			[
				'error' => $exception->getMessage(),
			]
		);

	}//end handleSyncError()

	/**
	 * Builds the per-run stats accumulator shared by the organisation /
	 * contactpersoon sync flows.
	 *
	 * Extracted from {@see performOrganizationsSync()} as part of task 7.1.
	 *
	 * @param int $batchSize Configured batch size.
	 * @param int $maxExecutionSeconds Configured time budget.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-7
	 */
	private function buildInitialSyncStats(int $batchSize, int $maxExecutionSeconds): array {
		return [
			'organizationsProcessed' => 0,
			'entitiesCreated' => 0,
			'entitiesUpdated' => 0,
			'errors' => [],
			'batchSize' => $batchSize,
			'maxExecutionSeconds' => $maxExecutionSeconds,
			'timeoutReached' => false,
			'totalRemaining' => 0,
			'startTime' => date('Y-m-d H:i:s'),
			'endTime' => null,
			'duration' => null,
		];
	}//end buildInitialSyncStats()

	/**
	 * Validates the voorzieningen register + organisatie_schema id pair as
	 * positive integers suitable for table-name interpolation.
	 *
	 * Returns a `[null, null]` pair (with the appropriate warning log) when
	 * either side is unset or non-positive — caller is expected to short-circuit
	 * the sync. Extracted from {@see performOrganizationsSync()} as part of
	 * task 7.1.
	 *
	 * @param mixed $register Raw register id from voorzieningen config.
	 * @param mixed $organizationSchema Raw schema id from voorzieningen config.
	 *
	 * @return array{0: int|null, 1: int|null}
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-7
	 */
	private function validateOrgSyncConfig($register, $organizationSchema): array {
		if (empty($register) === true || empty($organizationSchema) === true) {
			$this->logger->warning('OrganizationSync: voorzieningen config missing register or organisatie_schema');
			return [null, null];
		}

		$registerIdOrg = (int)$register;
		$schemaIdOrg = (int)$organizationSchema;
		if ($registerIdOrg <= 0 || $schemaIdOrg <= 0) {
			$this->logger->warning(
				'OrganizationSync: register or organisatie_schema is not a valid positive integer',
				[
					'register' => $register,
					'organizationSchema' => $organizationSchema,
				]
			);
			return [null, null];
		}

		return [$registerIdOrg, $schemaIdOrg];
	}//end validateOrgSyncConfig()

	/**
	 * Create a database-agnostic JSON extraction expression
	 *
	 * Returns the appropriate SQL function for extracting a value from a JSON column
	 * based on the database platform (MySQL vs PostgreSQL).
	 *
	 * @param string $column The column name containing JSON data
	 * @param string $path The JSON path to extract (e.g., '$.status' or 'status')
	 *
	 * @return string The SQL expression for JSON extraction
	 *
	 * @psalm-suppress UndefinedClass
	 */
	private function jsonExtract(string $column, string $path): string {
		$platform = $this->db->getDatabasePlatform();
		$isPostgres = $platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform;

		// Normalize path - remove '$.' prefix if present for PostgreSQL.
		$cleanPath = ltrim($path, '$.');

		if ($isPostgres === true) {
			// PostgreSQL: Use ->> operator for text extraction.
			// Cast to json first if needed, then extract.
			return "({$column}::json->>'{$cleanPath}')";
		}

		// MySQL/MariaDB: Use json_unquote(json_extract()).
		if (str_starts_with($path, '$.') === true) {
			$jsonPath = $path;
		} else {
			$jsonPath = '$.' . $path;
		}

		return "json_unquote(json_extract({$column}, '{$jsonPath}'))";
	}//end jsonExtract()

	/**
	 * Create a database-agnostic JSON contains expression
	 *
	 * Returns the appropriate SQL for checking if a JSON array contains a value.
	 *
	 * @param string $column The column name containing JSON array
	 * @param string $value The value to check for
	 *
	 * @return string The SQL expression for JSON contains check
	 *
	 * @psalm-suppress UndefinedClass
	 */
	private function jsonContains(string $column, string $value): string {
		$platform = $this->db->getDatabasePlatform();
		$isPostgres = $platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform;

		if ($isPostgres === true) {
			// PostgreSQL: Use @> operator with jsonb.
			return "({$column}::jsonb @> '\"{$value}\"'::jsonb)";
		}

		// MySQL/MariaDB: Use JSON_CONTAINS.
		return "json_contains({$column}, '\"{$value}\"')";
	}//end jsonContains()

	/**
	 * Perform synchronization of organizations.
	 *
	 * @param int $batchSize The batch size for processing.
	 * @param int $maxExecutionSeconds The maximum execution time in seconds.
	 *
	 * @return array The sync statistics.
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function performOrganizationsSync(int $batchSize = 50, int $maxExecutionSeconds = 45): array {
		// Check configuration.
		$voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
		$register = ($voorzieningenConfig['register'] ?? '');
		$organizationSchema = ($voorzieningenConfig['organisatie_schema'] ?? '');

		$startTime = time();
		$stats = $this->buildInitialSyncStats(batchSize: $batchSize, maxExecutionSeconds: $maxExecutionSeconds);

		[$registerIdOrg, $schemaIdOrg] = $this->validateOrgSyncConfig(
			register: $register,
			organizationSchema: $organizationSchema
		);
		if ($registerIdOrg === null || $schemaIdOrg === null) {
			return $stats;
		}

		// Build table name dynamically from config (environment-agnostic).
		// Objects live in per-schema MagicMapper tables, NOT in openregister_objects.
		$magicTableName = 'openregister_table_' . $registerIdOrg . '_' . $schemaIdOrg;

		// Count total remaining orgs without entities for progress logging.
		$countQb = $this->db->getQueryBuilder();
		$totalRemaining = (int)$countQb->select($countQb->createFunction('COUNT(*) as cnt'))
			->from($magicTableName, 'o')
			->leftJoin('o', 'openregister_organisations', 'org', 'o._uuid = org.uuid')
			->where('org.uuid IS NULL')
			->andWhere(
				$countQb->createFunction(
					'LOWER(o.status) = ' . $countQb->createNamedParameter('actief')
				)
			)
			->executeQuery()->fetchOne();

		$stats['totalRemaining'] = $totalRemaining;

		if ($totalRemaining === 0) {
			$this->logger->debug('OrganizationSync: all active orgs have entities, nothing to do');
			return $stats;
		}

		$this->logger->info(
			'OrganizationSync: ' . $totalRemaining . ' active orgs without entity, processing batch of ' . $batchSize
		);

		// Query active orgs that don't have an OpenRegister organisation entity yet.
		$qb = $this->db->getQueryBuilder();
		$qb->select('o._uuid as uuid', 'o.status')
			->from($magicTableName, 'o')
			->leftJoin('o', 'openregister_organisations', 'org', 'o._uuid = org.uuid')
			->where('org.uuid IS NULL')
			->andWhere(
				$qb->createFunction(
					'LOWER(o.status) = ' . $qb->createNamedParameter('actief')
				)
			)
			->orderBy('o._updated', 'ASC')
			->setMaxResults($batchSize);

		$rows = $qb->executeQuery()->fetchAll();

		// No resolution guard here any more. $objectService is a promoted,
		// readonly, non-nullable ObjectServiceInterface, so it cannot be
		// unresolved by the time this runs. The old check asked
		// `instanceof ObjectService` — the CONCRETE class — against a property
		// typed as the interface, so any implementation other than that one
		// class made it return $stats and log "could not resolve", silently
		// skipping the whole batch rather than syncing it.

		foreach ($rows as $row) {
			if ((time() - $startTime) >= $maxExecutionSeconds) {
				$stats['timeoutReached'] = true;
				$this->logger->info(
					'OrganizationSync: time limit reached',
					[
						'processed' => $stats['organizationsProcessed'],
						'elapsed' => (time() - $startTime),
					]
				);
				break;
			}

			try {
				$object = $this->objectService->find(
					id: $row['uuid'],
					register: $register,
					schema: $organizationSchema,
					_rbac: false,
					_multitenancy: false
				);

				$this->ensureOrganisationEntity(organisationObject: $object, run: ['stats' => &$stats, 'sendEmails' => false]);
				$stats['organizationsProcessed']++;
			} catch (\Exception $e) {
				$this->handleSyncError(operation: 'OrganizationSync', identifier: (string)$row['uuid'], exception: $e, stats: $stats);
			}
		}//end foreach

		$stats['endTime'] = date('Y-m-d H:i:s');
		$stats['duration'] = (time() - $startTime);

		$this->logger->info(
			'OrganizationSync: batch complete',
			[
				'processed' => $stats['organizationsProcessed'],
				'created' => $stats['entitiesCreated'],
				'remaining' => ($totalRemaining - $stats['organizationsProcessed']),
				'duration' => $stats['duration'] . 's',
			]
		);

		return $stats;
	}//end performOrganizationsSync()

	/**
	 * Perform synchronization of contact persons.
	 *
	 * @param int $batchSize The batch size for processing.
	 * @param int $maxExecutionSeconds The maximum execution time in seconds.
	 *
	 * @return array The sync statistics.
	 * @spec   openspec/specs/organization-sync/spec.md
	 */
	public function performContactSync(int $batchSize = 100, int $maxExecutionSeconds = 30): array {
		// Check configuration.
		$voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
		$register = ($voorzieningenConfig['register'] ?? '');
		$contactSchema = ($voorzieningenConfig['contactpersoon_schema'] ?? '');

		$startTime = time();
		$stats = [
			'contactPersonsProcessed' => 0,
			'errors' => [],
			'batchSize' => $batchSize,
			'maxExecutionSeconds' => $maxExecutionSeconds,
			'timeoutReached' => false,
			'totalRemaining' => 0,
			'startTime' => date('Y-m-d H:i:s'),
			'endTime' => null,
			'duration' => null,
		];

		if (empty($register) === true || empty($contactSchema) === true) {
			$this->logger->warning('ContactSync: voorzieningen config missing register or contactpersoon_schema');
			return $stats;
		}

		// Cast config values to integers before using in a table name to prevent injection.
		$registerIdContact = (int)$register;
		$schemaIdContact = (int)$contactSchema;
		if ($registerIdContact <= 0 || $schemaIdContact <= 0) {
			$this->logger->warning(
				'ContactSync: register or contactpersoon_schema is not a valid positive integer',
				[
					'register' => $register,
					'contactSchema' => $contactSchema,
				]
			);
			return $stats;
		}

		// Query per-schema magic table directly (NOT the empty openregister_objects blob table).
		$contactTableName = 'openregister_table_' . $registerIdContact . '_' . $schemaIdContact;

		// Find contacts without a username that DO have a matching Nextcloud account (by email).
		$qb = $this->db->getQueryBuilder();
		$qb->select('o._uuid as uuid', 'o.e_mailadres as email', 'o.organization', 'a.uid')
			->from($contactTableName, 'o')
			->innerJoin('o', 'accounts_data', 'a', 'o.e_mailadres = a.value')
			->where(
				$qb->createFunction(
					'(o.username IS NULL OR o.username = ' . $qb->createNamedParameter('') . ')'
				)
			)
			->andWhere($qb->createFunction('o.organization IS NOT NULL'))
			->orderBy('o._updated', 'ASC')
			->setMaxResults($batchSize);

		$contacts = $qb->executeQuery()->fetchAll();
		$stats['totalRemaining'] = count($contacts);

		if (empty($contacts) === true) {
			$this->logger->debug('ContactSync: no contacts to sync');
			return $stats;
		}

		$this->logger->info('ContactSync: processing ' . count($contacts) . ' contacts with existing NC accounts');

		// Same as above: the promoted readonly ObjectServiceInterface cannot be
		// unresolved, and the old `instanceof ObjectService` check against the
		// concrete class made this skip every contact instead.

		foreach ($contacts as $contact) {
			if ((time() - $startTime) >= $maxExecutionSeconds) {
				$stats['timeoutReached'] = true;
				$this->logger->info(
					'ContactSync: time limit reached',
					[
						'processed' => $stats['contactPersonsProcessed'],
					]
				);
				break;
			}

			try {
				$contactEntity = $this->objectService->find(
					id: $contact['uuid'],
					register: $register,
					schema: $contactSchema,
					_rbac: false
				);
				$contactEntityObject = $contactEntity->getObject();
				$contactEntityObject['username'] = $contact['uid'];

				// Keep organisatie in the object so it is never temporarily absent from the
				// persisted record. The schema validation warning for a UUID-string value is
				// benign compared to a data-corruption window where the field is missing.
				//
				// The full PAYLOAD is what is sent. `setObject()` is not on the
				// published ObjectEntityInterface (ADR-084) and the mutated entity
				// was never read back; `saveObject()` is PUT-semantic, so
				// $contactEntityObject already carries every unchanged field.
				$this->objectService->saveObject(
					object: $contactEntityObject,
					register: $register,
					schema: $contactSchema,
					uuid: $contactEntity->getUuid(),
					_rbac: false,
					_multitenancy: false
				);

				$stats['contactPersonsProcessed']++;
			} catch (\Exception $e) {
				$this->handleSyncError(operation: 'ContactSync', identifier: (string)$contact['uuid'], exception: $e, stats: $stats);
			}//end try
		}//end foreach

		$stats['endTime'] = date('Y-m-d H:i:s');
		$stats['duration'] = (time() - $startTime);
		return $stats;
	}//end performContactSync()

	/**
	 * Perform synchronization of users.
	 *
	 * @return array The sync statistics.
	 *
	 * @psalm-suppress UndefinedClass
	 * @spec           openspec/specs/organization-sync/spec.md
	 */
	public function performUserSync(): array {
		$voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
		$register = ($voorzieningenConfig['register'] ?? '');
		$contactSchema = ($voorzieningenConfig['contactpersoon_schema'] ?? '');

		if (empty($register) === true || empty($contactSchema) === true) {
			$this->logger->warning('UserSync: voorzieningen config missing register or contactpersoon_schema');
			return [];
		}

		// Cast to int and validate before using in a table name to prevent injection.
		$registerId = (int)$register;
		$schemaId = (int)$contactSchema;
		if ($registerId <= 0 || $schemaId <= 0) {
			$this->logger->warning(
				'UserSync: register or contactpersoon_schema is not a valid positive integer',
				[
					'register' => $register,
					'contactSchema' => $contactSchema,
				]
			);
			return [];
		}

		// Query per-schema magic table directly (NOT the empty openregister_objects blob table).
		$contactTableName = 'openregister_table_' . $registerId . '_' . $schemaId;

		// Build JSON contains check - platform-specific.
		$platform = $this->db->getDatabasePlatform();
		$isPostgres = $platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform;

		if ($isPostgres === true) {
			$jsonContainsCheck = 'NOT (oo.users::jsonb @> to_jsonb(o.username::text))';
		} else {
			$jsonContainsCheck = "JSON_CONTAINS(oo.users, CONCAT('\"', o.username, '\"')) = 0";
		}

		// Find contacts with a username whose username is NOT in their org's users array.
		$qb = $this->db->getQueryBuilder();
		$qb->select('o._uuid as uuid', 'o.username', 'o.organization', 'oo.users')
			->from($contactTableName, 'o')
			->leftJoin('o', 'openregister_organisations', 'oo', 'oo.uuid = o.organization')
			->where($qb->createFunction('o.username IS NOT NULL'))
			->andWhere($qb->createFunction('o.username <> ' . $qb->createNamedParameter('')))
			->andWhere($qb->createFunction('o.organization IS NOT NULL'))
			->andWhere($qb->createFunction($jsonContainsCheck));

		$users = $qb->executeQuery()->fetchAll();

		if (empty($users) === false) {
			$this->logger->info('UserSync: adding ' . count($users) . ' users to their org entities');
		}

		foreach ($users as $user) {
			try {
				$this->organisationService->addUsersToOrganization($user['organization'], [$user['username']]);
			} catch (\Exception $e) {
				$this->logger->error(
					'UserSync: failed to add user ' . $user['username'] . ' to org ' . $user['organization'],
					[
						'error' => $e->getMessage(),
					]
				);
			}
		}

		return [];
	}//end performUserSync()

	/**
	 * Performs comprehensive organization and contact person synchronization
	 *
	 * This method synchronizes organisatie objects that have been updated within
	 * the specified time window with organisation entities.
	 *
	 * @param int $minutesBack Number of minutes to look back for changes (0 = all objects)
	 *
	 * @return array Synchronization results and statistics
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function performFullSync(int $minutesBack = 10): array {
		if ($minutesBack === 0) {
			$syncModeValue = 'full';
		} else {
			$syncModeValue = 'incremental';
		}

		$this->logger->info(
			'OrganizationSyncService: Starting comprehensive organization synchronization',
			[
				'minutesBack' => $minutesBack,
				'syncMode' => $syncModeValue,
			]
		);

		$stats = [
			'organizationsProcessed' => 0,
			'entitiesCreated' => 0,
			'entitiesUpdated' => 0,
			'contactPersonsProcessed' => 0,
			'usersCreated' => 0,
			'usersUpdated' => 0,
			'errors' => [],
			'startTime' => date('Y-m-d H:i:s'),
			'endTime' => null,
			'duration' => null,
		];

		try {
			// Check configuration.
			$voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
			$register = ($voorzieningenConfig['register'] ?? '');
			$organizationSchema = ($voorzieningenConfig['organisatie_schema'] ?? '');
			$contactSchema = ($voorzieningenConfig['contactpersoon_schema'] ?? '');

			if (empty($register) === true || empty($organizationSchema) === true) {
				$error = 'Missing configuration: register or organization schema not configured';
				$this->logger->error('OrganizationSyncService: ' . $error);
				$stats['errors'][] = $error;
				return $stats;
			}

			// Get organisatie objects based on time window.
			$organisationObjects = $this->getOrganisationObjectsByTimeWindow(
				register: $register,
				organizationSchema: $organizationSchema,
				minutesBack: $minutesBack
			);
			$syncModeValue = 'incremental';
			if ($minutesBack === 0) {
				$syncModeValue = 'full';
			}

			$this->logger->info(
				'OrganizationSyncService: Found organisatie objects',
				[
					'count' => count($organisationObjects),
					'minutesBack' => $minutesBack,
					'syncMode' => $syncModeValue,
				]
			);

			// Process each organisatie object.
			foreach ($organisationObjects as $organisationObject) {
				try {
					$this->processOrganisationObject(
						organisationObject: $organisationObject,
						register: $register,
						contactSchema: $contactSchema,
						stats: $stats
					);
					$stats['organizationsProcessed']++;
				} catch (\Exception $e) {
					$error = 'Failed to process organisatie object ' . $organisationObject->getId() . ': ' . $e->getMessage();
					$this->logger->error(
						'OrganizationSyncService: ' . $error,
						[
							'exception' => $e,
							'objectId' => $organisationObject->getId(),
						]
					);
					$stats['errors'][] = $error;
				}
			}//end foreach

			$stats['endTime'] = date('Y-m-d H:i:s');
			$startTime = new \DateTime($stats['startTime']);
			$endTime = new \DateTime($stats['endTime']);
			$stats['duration'] = $endTime->diff($startTime)->format('%H:%I:%S');

			$this->logger->info('OrganizationSyncService: Completed comprehensive synchronization', $stats);

			return $stats;
		} catch (\Exception $e) {
			$error = 'Synchronization failed: ' . $e->getMessage();
			$this->logger->error(
				'OrganizationSyncService: ' . $error,
				['exception' => $e]
			);
			$stats['errors'][] = $error;
			$stats['endTime'] = date('Y-m-d H:i:s');
			return $stats;
		}//end try

	}//end performFullSync()

	/**
	 * Gets organisatie objects filtered by time window
	 *
	 * @param string $register The register ID
	 * @param string $organizationSchema The organization schema ID
	 * @param int $minutesBack Number of minutes to look back (0 = all objects)
	 *
	 * @return array Array of organisatie objects
	 */
	private function getOrganisationObjectsByTimeWindow(string $register, string $organizationSchema, int $minutesBack): array {
		try {
			// Build base query for register and schema. A sync tick may
			// genuinely need "all objects" (minutesBack <= 0) — bounded at a
			// documented safe ceiling rather than left unbounded.
			$query = [
				'@self' => [
					'register' => (int)$register,
					'schema' => (int)$organizationSchema,
				],
				'_limit' => 5000,
			];

			// Add time-based filtering if minutesBack > 0.
			if ($minutesBack > 0) {
				$cutoffTime = new \DateTime();
				$cutoffTime->sub(new \DateInterval('PT' . $minutesBack . 'M'));
				$cutoffTimeString = $cutoffTime->format('Y-m-d\TH:i:sP');

				// Add time filtering to the query.
				// Filter objects that were updated within the time window.
				$query['@self']['updated'] = ['gte' => $cutoffTimeString];

				$this->logger->debug(
					'OrganizationSyncService: Using searchObjects with time-based filtering',
					[
						'register' => $register,
						'schema' => $organizationSchema,
						'minutesBack' => $minutesBack,
						'cutoffTime' => $cutoffTimeString,
						'currentTime' => (new \DateTime())->format('Y-m-d\TH:i:sP'),
						'timeWindowStart' => $cutoffTimeString,
						'query' => $query,
					]
				);
			}//end if

			if ($minutesBack <= 0) {
				$this->logger->debug(
					'OrganizationSyncService: Using searchObjects for all objects',
					[
						'register' => $register,
						'schema' => $organizationSchema,
						'query' => $query,
					]
				);
			}

			// Use searchObjects method for filtering.
			$objects = $this->objectService->searchObjects(query: $query, _rbac: false, _multitenancy: false);

			$this->logger->debug(
				'OrganizationSyncService: Retrieved organisatie objects with searchObjects',
				[
					'register' => $register,
					'schema' => $organizationSchema,
					'minutesBack' => $minutesBack,
					'count' => count($objects),
				]
			);

			return $objects;
		} catch (\Exception $e) {
			$this->logger->error(
				'OrganizationSyncService: Failed to retrieve organisatie objects with searchObjects',
				[
					'register' => $register,
					'schema' => $organizationSchema,
					'minutesBack' => $minutesBack,
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);

			return [];
		}//end try

	}//end getOrganisatieObjectsByTimeWindow()

	/**
	 * Processes a single organisatie object
	 *
	 * @param object $organisationObject The organisatie object to process
	 * @param string $register The register ID
	 * @param string $contactSchema The contact schema ID
	 * @param array $stats Statistics array (passed by reference)
	 *
	 * @return void
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	private function processOrganisationObject(
		object $organisationObject,
		string $register,
		string $contactSchema,
		array &$stats,
	): void {
		$objectData = $organisationObject->getObject();
		$organisationId = ($objectData['id'] ?? $organisationObject->getId());

		$this->logger->debug(
			'OrganizationSyncService: Processing organisatie object',
			[
				'organisatieId' => $organisationId,
				'name' => ($objectData['name'] ?? 'Unknown'),
			]
		);

		// Step 1: Ensure organisation entity exists.
		$organisationEntity = $this->ensureOrganisationEntity(organisationObject: $organisationObject, run: ['stats' => &$stats, 'sendEmails' => true]);
		if ($organisationEntity === null) {
			$this->logger->warning(
				'OrganizationSyncService: Could not ensure organisation entity',
				['organisatieId' => $organisationId]
			);
			return;
		}

		// Step 2: Get all contact persons for this organisation.
		$contactPersons = $this->getContactPersonsForOrganisation(
			organisationId: $organisationId,
			register: $register,
			contactSchema: $contactSchema
		);
		$this->logger->debug(
			'OrganizationSyncService: Found contact persons',
			[
				'organisatieId' => $organisationId,
				'contactCount' => count($contactPersons),
			]
		);

		// Step 3: Process each contact person to ensure they have user accounts.
		$usernames = [];
		foreach ($contactPersons as $contactPerson) {
			$username = $this->processContactPerson(contactPerson: $contactPerson, stats: $stats);
			if (empty($username) === false) {
				$usernames[] = $username;
			}
		}

		// Step 4: Update organisation entity with all usernames.
		$this->updateOrganisationEntityUsers(organisationEntity: $organisationEntity, usernames: $usernames, stats: $stats);

	}//end processOrganisatieObject()

	/**
	 * Public wrapper for ensureOrganisationEntity.
	 *
	 * Used by ContactpersoonService for backup entity creation when org entity is missing.
	 *
	 * @param object $organisationObject The organisatie object.
	 * @param array $stats Statistics array (passed by reference).
	 * @param bool $sendEmails Whether to send registration/activation emails.
	 *
	 * @return object|null The organisation entity or null on failure.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $sendEmails is a simple notification toggle
	 * @spec                                        openspec/specs/organization-sync/spec.md
	 */
	public function ensureOrganisationEntityPublic(object $organisationObject, array &$stats, bool $sendEmails = true): ?object {
		return $this->ensureOrganisationEntity(
			organisationObject: $organisationObject,
			run: ['stats' => &$stats, 'sendEmails' => $sendEmails]
		);

	}//end ensureOrganisationEntityPublic()

	/**
	 * Ensures organisation entity exists for organisatie object.
	 *
	 * @param object $organisationObject The organisatie object.
	 * @param array  $run                The sync run: `stats` (held by reference) and `sendEmails`,
	 *                                   whether to send registration and activation e-mails.
	 *
	 * @return object|null The organisation entity or null on failure.
	 */
	private function ensureOrganisationEntity(object $organisationObject, array $run): ?object {
		try {
			// Get the full object data - the passed object might not have all fields populated.
			$organisationId = $organisationObject->getUuid();
			$organisationObject = $this->fullOrganisationObject(organisationObject: $organisationObject);
			$objectData = $organisationObject->getObject();

			$this->logger->debug(
				'Ensuring organisation entity',
				[
					'app' => 'stackiq',
					'organisatieId' => $organisationId,
					'name' => ($objectData['name'] ?? 'Unknown'),
					'status' => ($objectData['status'] ?? 'Unknown'),
				]
			);

			// Get configuration for object updates.
			$voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
			$run['organisationId'] = $organisationId;
			$run['organisationObject'] = $organisationObject;
			$run['objectData'] = $objectData;
			$run['register'] = ($voorzieningenConfig['register'] ?? '');
			$run['organizationSchema'] = ($voorzieningenConfig['organisatie_schema'] ?? '');

			// Try to find existing organisation entity.
			try {
				$organisationEntity = $this->organisationMapper->findByUuid($organisationId);
				return $this->refreshOrganisationEntity(organisationEntity: $organisationEntity, run: $run);
			} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
				if ($this->slugIsTaken(run: $run) === true) {
					return null;
				}

				return $this->createOrganisationEntity(run: $run);
			}
		} catch (\Exception $e) {
			$this->logger->error(
				'💥 ENSURE ORGANISATION ENTITY EXCEPTION',
				[
					'app' => 'stackiq',
					'organisatieId' => $organisationObject->getId(),
					'exception' => $e->getMessage(),
					'file' => $e->getFile(),
					'line' => $e->getLine(),
					'trace' => $e->getTraceAsString(),
				]
			);
			return null;
		}//end try

	}//end ensureOrganisationEntity()

	/**
	 * The organisatie object as stored, or the one given when it cannot be fetched.
	 *
	 * @param object $organisationObject The organisatie object as passed in.
	 *
	 * @return object The complete organisatie object
	 */
	private function fullOrganisationObject(object $organisationObject): object {
		$organisationId = $organisationObject->getUuid();
		try {
			$fullObject = $this->objectService->find(
				id: $organisationId,
				register: $organisationObject->getRegister(),
				schema: $organisationObject->getSchema(),
				_rbac: false,
				_multitenancy: false
			);
			if (empty($fullObject) === false) {
				return $fullObject;
			}
		} catch (\Exception $e) {
			$this->logger->warning(
				'Could not fetch full organisation object, using provided object',
				[
					'organisatieId' => $organisationId,
					'error' => $e->getMessage(),
				]
			);
		}//end try

		return $organisationObject;
	}//end fullOrganisationObject()

	/**
	 * Bring an existing organisation entity's active flag in line with the object's status,
	 * mail an organisation that became active, and make the entity the object's owner.
	 *
	 * @param object $organisationEntity The existing organisation entity.
	 * @param array  $run                The ensure run (see ensureOrganisationEntity).
	 *
	 * @return object The organisation entity
	 */
	private function refreshOrganisationEntity(object $organisationEntity, array $run): object {
		$organisationId = $run['organisationId'];

		// Entity exists - update it if needed.
		$status = strtolower(($run['objectData']['status'] ?? 'actief'));
		$shouldBeActive = in_array($status, ['actief', 'active']) === true;

		$this->logger->debug(
			'Existing entity found',
			[
				'app' => 'stackiq',
				'organisatieId' => $organisationId,
				'entityId' => $organisationEntity->getId(),
				'shouldBeActive' => $shouldBeActive,
				'needsUpdate' => $organisationEntity->getActive() !== $shouldBeActive,
			]
		);

		if ($organisationEntity->getActive() !== $shouldBeActive) {
			$this->logger->debug(
				'Updating entity status',
				[
					'app' => 'stackiq',
					'organisatieId' => $organisationId,
					'oldActive' => $organisationEntity->getActive(),
					'newActive' => $shouldBeActive,
				]
			);

			$wasActive = $organisationEntity->getActive();
			$organisationEntity->setActive($shouldBeActive);
			$this->organisationMapper->save($organisationEntity);
			$run['stats']['entitiesUpdated']++;

			// Send activation email if organization became active.
			if ($run['sendEmails'] !== false && $shouldBeActive === true && $wasActive === false) {
				$this->mailOrganisation(kind: 'activation', run: $run);
			}
		}//end if

		// Update organisatie object owner to organisation entity UUID.
		$this->makeEntityOwner(organisationEntity: $organisationEntity, run: $run);

		return $organisationEntity;
	}//end refreshOrganisationEntity()

	/**
	 * Link an organisatie to an existing OpenRegister organisation, as an administrator decides.
	 *
	 * The organisation takes the organisatie's uuid, which is what links the two,
	 * and becomes the organisatie object's owner. Refused when the organisatie is
	 * unknown or already has an organisation, when the organisation is unknown, or
	 * when the organisation is already another organisatie's.
	 *
	 * @param string $organisatieUuid The organisatie object's uuid.
	 * @param string $entityUuid      The OpenRegister organisation's uuid.
	 *
	 * @return string linked, no-organisatie, already-linked, no-organisation or organisation-in-use.
	 *
	 * @spec openspec/specs/organisatie-service/spec.md
	 */
	public function linkOrganisationEntity(string $organisatieUuid, string $entityUuid): string {
		$config = $this->settingsService->getVoorzieningenConfig();
		$run    = [
			'register'           => (string) ($config['register'] ?? ''),
			'organizationSchema' => (string) ($config['organisatie_schema'] ?? ''),
		];
		$run['organisationObject'] = $this->findOrganisatie(run: $run, uuid: $organisatieUuid);
		if ($run['organisationObject'] === null) {
			return 'no-organisatie';
		}

		$outcome = $this->linkRefusal(run: $run, uuids: [$organisatieUuid, $entityUuid]);
		if ($outcome !== null) {
			return $outcome;
		}

		$entity = $this->organisationMapper->findByUuid($entityUuid);
		$entity->setUuid($organisatieUuid);
		$this->organisationMapper->save($entity);
		$this->makeEntityOwner(organisationEntity: $entity, run: $run);
		$this->logger->info(
			'OrganizationSyncService: an administrator linked an organisatie to an OpenRegister organisation',
			['app' => 'stackiq', 'organisatieId' => $organisatieUuid, 'previousOrganisationUuid' => $entityUuid]
		);

		return 'linked';
	}//end linkOrganisationEntity()

	/**
	 * Why a link may not be made, or null when it may.
	 *
	 * @param array                       $run   The register and organisatie schema.
	 * @param array{0: string, 1: string} $uuids The organisatie uuid and the organisation uuid.
	 *
	 * @return string|null already-linked, no-organisation, organisation-in-use, or null.
	 */
	private function linkRefusal(array $run, array $uuids): ?string {
		[$organisatieUuid, $entityUuid] = $uuids;
		if ($this->organisationExists(uuid: $organisatieUuid) === true) {
			return 'already-linked';
		}

		if ($this->organisationExists(uuid: $entityUuid) === false) {
			return 'no-organisation';
		}

		if ($this->findOrganisatie(run: $run, uuid: $entityUuid) !== null) {
			return 'organisation-in-use';
		}

		return null;
	}//end linkRefusal()

	/**
	 * Whether an OpenRegister organisation has this uuid.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return bool
	 */
	private function organisationExists(string $uuid): bool {
		try {
			$this->organisationMapper->findByUuid($uuid);
			return true;
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return false;
		}
	}//end organisationExists()

	/**
	 * The organisatie object with this uuid, or null.
	 *
	 * @param array  $run  The register and organisatie schema.
	 * @param string $uuid The uuid.
	 *
	 * @return object|null
	 */
	private function findOrganisatie(array $run, string $uuid): ?object {
		try {
			return $this->objectService->find(
				id: $uuid,
				register: $run['register'],
				schema: $run['organizationSchema'],
				_rbac: false,
				_multitenancy: false
			);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return null;
		}
	}//end findOrganisatie()

	/**
	 * Whether another OpenRegister organisation already has the slug this organisatie's name gives.
	 *
	 * Such an organisation is never taken over on a name match: the organisatie is
	 * left without an entity, logged and counted, for an administrator to link
	 * explicitly (occ stackiq:organisation:link). A missing or "Unknown" name gets a
	 * uuid-based name when the entity is created, so it is not checked.
	 *
	 * @param array $run The ensure run (see ensureOrganisationEntity).
	 *
	 * @return bool True when the slug is taken and nothing may be created.
	 */
	private function slugIsTaken(array $run): bool {
		$orgName = (string) ($run['objectData']['name'] ?? '');
		if ($orgName === '' || $orgName === 'Unknown') {
			return false;
		}

		$slug = (new OrganisationSlug())->fromName(name: $orgName);
		try {
			$existing = $this->organisationMapper->findBySlug($slug);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return false;
		}

		$this->refuseSlugConflict(run: $run, existingUuid: $existing->getUuid());
		return true;
	}//end slugIsTaken()

	/**
	 * Log and count an organisatie left unlinked because its slug is taken.
	 *
	 * @param array       $run          The ensure run (see ensureOrganisationEntity).
	 * @param string|null $existingUuid The uuid of the organisation holding the slug, when known.
	 *
	 * @return void
	 */
	private function refuseSlugConflict(array $run, ?string $existingUuid): void {
		$this->logger->warning(
			'OrganizationSyncService: an organisation with this name already exists; the organisatie is left unlinked for an administrator',
			[
				'app' => 'stackiq',
				'organisatieId' => $run['organisationId'],
				'existingOrganisationUuid' => $existingUuid,
			]
		);
		$run['stats']['slugConflicts'][] = $run['organisationId'];
	}//end refuseSlugConflict()

	/**
	 * Create the organisation entity, mail the new organisation and make the entity the object's owner.
	 *
	 * @param array $run The ensure run (see ensureOrganisationEntity).
	 *
	 * @return object|null The new entity, or what creation returned when it failed
	 */
	private function createOrganisationEntity(array $run): ?object {
		$organisationId = $run['organisationId'];
		$this->logger->debug(
			'Creating new organisation entity',
			[
				'app' => 'stackiq',
				'organisatieId' => $organisationId,
				'name' => ($run['objectData']['name'] ?? 'Unknown'),
			]
		);

		try {
			$organisationEntity = $this->organisationService->createOrganisationInOpenRegister($run['objectData']);
		} catch (\RuntimeException $e) {
			if (is_a($e, OrganisationSlug::CONFLICT_EXCEPTION) === false) {
				throw $e;
			}

			$this->refuseSlugConflict(run: $run, existingUuid: null);
			return null;
		}

		if (empty($organisationEntity) === true) {
			$this->logger->error(
				'❌ ORGANISATION ENTITY CREATION FAILED',
				[
					'app' => 'stackiq',
					'organisatieId' => $organisationId,
				]
			);
			return $organisationEntity;
		}

		$run['stats']['entitiesCreated']++;
		$this->logger->debug(
			'New organisation entity created',
			[
				'app' => 'stackiq',
				'organisatieId' => $organisationId,
				'entityId' => $organisationEntity->getId(),
				'active' => $organisationEntity->getActive(),
				'name' => $organisationEntity->getName(),
			]
		);

		// Send registration email for new organization (skip for backfill).
		if (empty($run['sendEmails']) === false) {
			$this->mailOrganisation(kind: 'registration', run: $run);
		}

		// Update organisatie object owner to organisation entity UUID.
		$this->makeEntityOwner(organisationEntity: $organisationEntity, run: $run);

		return $organisationEntity;
	}//end createOrganisationEntity()

	/**
	 * Send an organisation its registration or activation e-mail and log whether it went out.
	 *
	 * @param string $kind 'registration' or 'activation'.
	 * @param array  $run  The ensure run (see ensureOrganisationEntity).
	 *
	 * @return void
	 */
	private function mailOrganisation(string $kind, array $run): void {
		$context = ['organisatieId' => $run['organisationId']];
		$this->logger->info('[FLOW] Sending organization ' . $kind . ' email', $context);
		if ($kind === 'activation') {
			$emailSent = $this->sendOrganizationActivationEmail(organizationData: $run['objectData']);
		} else {
			$emailSent = $this->sendOrganizationRegistrationEmail(organizationData: $run['objectData']);
		}

		if (empty($emailSent) === false) {
			$this->logger->info('📧 Organization ' . $kind . ' email sent successfully', $context);
			return;
		}

		$this->logger->info('📧 Organization ' . $kind . ' email not sent (disabled or not configured)', $context);
	}//end mailOrganisation()

	/**
	 * Make the organisation entity the owner and organisation of the run's organisatie object.
	 *
	 * @param object $organisationEntity The organisation entity.
	 * @param array  $run                The ensure run (see ensureOrganisationEntity).
	 *
	 * @return void
	 */
	private function makeEntityOwner(object $organisationEntity, array $run): void {
		$this->updateOrganisationObjectOwner(
			organisationObject: $run['organisationObject'],
			organisationEntity: $organisationEntity,
			register: $run['register'],
			organizationSchema: $run['organizationSchema']
		);
	}//end makeEntityOwner()

	/**
	 * Safely sends organization registration email with error handling
	 *
	 * @param array $organizationData The organization data.
	 *
	 * @return bool True if email was sent successfully, false otherwise.
	 */
	private function sendOrganizationRegistrationEmail(array $organizationData): bool {
		try {
			return $this->emailService->sendOrganizationRegistrationEmail($organizationData);
		} catch (\Exception $e) {
			$this->logger->warning(
				'OrganizationSyncService: Organization registration email failed',
				[
					'organizationId' => ($organizationData['id'] ?? 'unknown'),
					'organizationName' => ($organizationData['name'] ?? 'unknown'),
					'error' => $e->getMessage(),
				]
			);
			return false;
		}

	}//end sendOrganizationRegistrationEmail()

	/**
	 * Safely sends organization activation email with error handling
	 *
	 * @param array $organizationData The organization data.
	 *
	 * @return bool True if email was sent successfully, false otherwise.
	 */
	private function sendOrganizationActivationEmail(array $organizationData): bool {
		try {
			return $this->emailService->sendOrganizationActivationEmail($organizationData);
		} catch (\Exception $e) {
			$this->logger->warning(
				'OrganizationSyncService: Organization activation email failed',
				[
					'organizationId' => ($organizationData['id'] ?? 'unknown'),
					'organizationName' => ($organizationData['name'] ?? 'unknown'),
					'error' => $e->getMessage(),
				]
			);
			return false;
		}

	}//end sendOrganizationActivationEmail()

	/**
	 * Gets all contact persons for a specific organisation
	 *
	 * @param string $organisationId The organisation ID
	 * @param string $register The register ID
	 * @param string $contactSchema The contact schema ID
	 *
	 * @return array Array of contact person objects
	 */
	private function getContactPersonsForOrganisation(string $organisationId, string $register, string $contactSchema): array {
		if (empty($contactSchema) === true) {
			$this->logger->debug('OrganizationSyncService: No contact schema configured, skipping contact person lookup');
			return [];
		}

		try {
			// Use searchObjects for more efficient filtering on-demand.
			$query = [
				'@self' => [
					'register' => (int)$register,
					'schema' => (int)$contactSchema,
				],
				'organization' => $organisationId,
				'_limit' => 500,
			];

			$contactPersons = $this->objectService->searchObjects(query: $query, _rbac: false, _multitenancy: false);

			$this->logger->debug(
				'OrganizationSyncService: Retrieved contact persons on-demand',
				[
					'organisatieId' => $organisationId,
					'register' => $register,
					'schema' => $contactSchema,
					'count' => count($contactPersons),
				]
			);

			return $contactPersons;
		} catch (\Exception $e) {
			$this->logger->error(
				'OrganizationSyncService: Failed to get contact persons',
				[
					'organisatieId' => $organisationId,
					'register' => $register,
					'schema' => $contactSchema,
					'exception' => $e,
				]
			);
			return [];
		}//end try

	}//end getContactPersonsForOrganisation()

	/**
	 * Processes a contact person to ensure they have a user account
	 *
	 * @param object $contactPerson The contact person object
	 * @param array $stats Statistics array (passed by reference)
	 *
	 * @return string|null The username if successful, null otherwise
	 */
	private function processContactPerson(object $contactPerson, array &$stats): ?string {
		try {
			$contactData = $contactPerson->getObject();
			// Schema uses 'e-mailadres' but some data may use 'email'.
			$email = ($contactData['email'] ?? $contactData['e-mailadres'] ?? '');
			$existingUsername = ($contactData['username'] ?? '');

			if (empty($email) === true) {
				$this->logger->debug(
					'OrganizationSyncService: Contact person has no email, skipping',
					[
						'contactId' => $contactPerson->getId(),
					]
				);
				return null;
			}

			// Check if user already exists.
			$userManager = $this->container->get('OCP\IUserManager');
			// Use the stored username when available, otherwise fall back to email as username.
			if (empty($existingUsername) === false) {
				$username = $existingUsername;
			} else {
				$username = $email;
			}

			$user = $userManager->get($username);

			if ($user === null) {
				// Create user account.
				$this->logger->info(
					'OrganizationSyncService: Creating user account for contact person',
					[
						'contactId' => $contactPerson->getId(),
						'email' => $email,
						'username' => $username,
					]
				);

				$success = $this->contactPersonService->processContactpersoon($contactPerson, false);
				if (empty($success) === false) {
					$stats['usersCreated']++;
					$this->logger->info(
						'OrganizationSyncService: Successfully created user account',
						[
							'contactId' => $contactPerson->getId(),
							'username' => $username,
						]
					);
				} else {
					$this->logger->error(
						'OrganizationSyncService: Failed to create user account',
						[
							'contactId' => $contactPerson->getId(),
							'username' => $username,
						]
					);
				}
			}//end if

			// Contact exists without a stored username — record the resolved username for future syncs.
			if (empty($existingUsername) === true) {
				$this->logger->debug(
					'OrganizationSyncService: Updating contact person with username',
					[
						'contactId' => $contactPerson->getId(),
						'username' => $username,
					]
				);
				$stats['usersUpdated']++;
			}

			return $username;
		} catch (\Exception $e) {
			$this->logger->error(
				'OrganizationSyncService: Failed to process contact person',
				[
					'contactId' => $contactPerson->getId(),
					'exception' => $e,
				]
			);
			return null;
		}//end try

	}//end processContactPerson()

	/**
	 * Updates organisation entity with all usernames
	 *
	 * @param object $organisationEntity The organisation entity
	 * @param array $usernames Array of usernames to add
	 * @param array $stats Statistics array (passed by reference)
	 *
	 * @return void
	 */
	private function updateOrganisationEntityUsers(object $organisationEntity, array $usernames, array &$stats): void {
		try {
			$organisationUuid = $organisationEntity->getUuid();
			// OpenRegister is not on the analysis path, so the getUsers() call has no
			// resolvable return type and sort() below cannot be checked without
			// this. It is a list of usernames.
			// phpcs:ignore Squiz.Commenting.InlineComment.DocBlock -- PHPStan only reads @var from a /** */ block.
			/** @var array<string> $currentUsers */
			$currentUsers = ($organisationEntity->getUsers() ?? []);

			// Add admin users to ensure they're always included.
			$adminUsers = $this->getAdminUsers();
			$allUsernames = array_unique(array_merge($usernames, $adminUsers));

			// Check if users list has changed.
			$currentUsersSet = array_unique($currentUsers);
			sort($currentUsersSet);
			sort($allUsernames);

			if ($currentUsersSet !== $allUsernames) {
				$this->logger->info(
					'OrganizationSyncService: Updating organisation entity users',
					[
						'organisationUuid' => $organisationUuid,
						'currentUsers' => count($currentUsers),
						'newUsers' => count($allUsernames),
						'addedUsers' => array_diff($allUsernames, $currentUsers),
						'removedUsers' => array_diff($currentUsers, $allUsernames),
					]
				);

				$organisationEntity->setUsers($allUsernames);

				$this->organisationMapper->save($organisationEntity);

				$stats['entitiesUpdated']++;

				$this->logger->info(
					'OrganizationSyncService: Successfully updated organisation entity users',
					[
						'organisationUuid' => $organisationUuid,
						'totalUsers' => count($allUsernames),
					]
				);
			}//end if

			if ($currentUsersSet === $allUsernames) {
				$this->logger->debug(
					'OrganizationSyncService: Organisation entity users unchanged',
					[
						'organisationUuid' => $organisationUuid,
						'userCount' => count($allUsernames),
					]
				);
			}
		} catch (\Exception $e) {
			$this->logger->error(
				'OrganizationSyncService: Failed to update organisation entity users',
				[
					'organisationUuid' => $organisationEntity->getUuid(),
					'exception' => $e,
				]
			);
		}//end try

	}//end updateOrganisationEntityUsers()

	/**
	 * Gets admin users that should always be included in organizations
	 *
	 * @return array Array of admin usernames
	 */
	private function getAdminUsers(): array {
		try {
			$groupManager = $this->container->get('OCP\IGroupManager');
			$adminGroup = $groupManager->get('admin');

			if (empty($adminGroup) === false) {
				$adminUsers = $adminGroup->getUsers();
				$adminUsernames = [];
				foreach ($adminUsers as $user) {
					$adminUsernames[] = $user->getUID();
				}

				return $adminUsernames;
			}

			return [];
		} catch (\Exception $e) {
			$this->logger->error(
				'OrganizationSyncService: Failed to get admin users',
				['exception' => $e]
			);
			return [];
		}//end try

	}//end getAdminUsers()

	/**
	 * Performs a quick sync status check with prediction of objects to be processed
	 *
	 * @param int $minutesBack Number of minutes to look back for prediction (default: 10 for scheduled sync)
	 *
	 * @return array Status information about sync requirements including processing predictions
	 * @spec   openspec/specs/organization-sync/spec.md
	 */
	public function getSyncStatus(int $minutesBack = 10): array {
		try {
			// Check configuration.
			$voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
			$register = ($voorzieningenConfig['register'] ?? '');
			$organizationSchema = ($voorzieningenConfig['organisatie_schema'] ?? '');
			$contactSchema = ($voorzieningenConfig['contactpersoon_schema'] ?? '');

			if (empty($register) === true || empty($organizationSchema) === true) {
				return [
					'configured' => false,
					'message' => 'Sync not configured: missing register or organization schema',
				];
			}

			// Get total counts (all objects).
			$allOrganisationObjects = $this->getOrganisationObjectsByTimeWindow(
				register: $register,
				organizationSchema: $organizationSchema,
				minutesBack: 0
			);

			// Get incremental counts (objects to be processed in next sync).
			$incrementalOrganisationObjects = $this->getOrganisationObjectsByTimeWindow(
				register: $register,
				organizationSchema: $organizationSchema,
				minutesBack: $minutesBack
			);

			// Get organization entities count.
			$entitiesCount = 0;
			try {
				$entities = $this->organisationMapper->findAllWithUserCount();
				$entitiesCount = count($entities);
			} catch (\Exception $e) {
				// Ignore errors in count.
			}

			// Predict contact persons to be processed.
			$predictedContactPersonsToProcess = 0;
			if (empty($contactSchema) === false) {
				foreach ($incrementalOrganisationObjects as $orgObject) {
					$objectData = $orgObject->getObject();
					$organisationId = ($objectData['id'] ?? $orgObject->getId());
					$contactPersons = $this->getContactPersonsForOrganisation(
						organisationId: $organisationId,
						register: $register,
						contactSchema: $contactSchema
					);
					$predictedContactPersonsToProcess += count($contactPersons);
				}
			}

			// Calculate efficiency metrics.
			$efficiencyImprovement = 0;
			if (count($allOrganisationObjects) > 0) {
				$ratio = count($incrementalOrganisationObjects) / count($allOrganisationObjects);
				$efficiencyImprovement = round(((1 - $ratio) * 100), 1);
			}

			if ($minutesBack === 0) {
				$syncModeValue = 'full';
			} else {
				$syncModeValue = 'incremental';
			}

			$messageValue = 'No organizations to process in the current time window';
			if (count($incrementalOrganisationObjects) > 0) {
				$orgCount = $this->formatNumber(number: count($incrementalOrganisationObjects));
				$contactCount = $this->formatNumber(number: $predictedContactPersonsToProcess);
				// phpcs:ignore Generic.Files.LineLength.TooLong
				$messageValue = "Ready to process {$orgCount} organizations and {$contactCount} contact persons";
			}

			if ($minutesBack > 0) {
				$nextScheduledSyncValue = "Will process organizations modified in the last {$minutesBack} minutes (incremental sync)";
			} else {
				$nextScheduledSyncValue = 'Will process all organizations (full sync)';
			}

			return [
				'configured' => true,
				'syncMode' => $syncModeValue,
				'timeWindow' => $minutesBack,

				// Total counts.
				'totalOrganizationObjects' => count($allOrganisationObjects),
				'totalOrganizationEntities' => $entitiesCount,

				// Processing predictions.
				'organizationsToProcess' => count($incrementalOrganisationObjects),
				'contactPersonsToProcess' => $predictedContactPersonsToProcess,

				// Efficiency metrics.
				'efficiencyImprovement' => $efficiencyImprovement . '%',
				'processingReduction' => (count($allOrganisationObjects) - count($incrementalOrganisationObjects)),

				// Configuration.
				'contactSchemaConfigured' => empty($contactSchema) === false,
				'lastSyncTime' => $this->config->getValueString('stackiq', 'last_sync_time', 'Never'),

				// Email configuration status.
				'emailStatus' => $this->getEmailConfigurationStatus(),

				// Status messages.
				'message' => $messageValue,
				'nextScheduledSync' => $nextScheduledSyncValue,
			];
		} catch (\Exception $e) {
			return [
				'configured' => false,
				'message' => 'Error checking sync status: ' . $e->getMessage(),
			];
		}//end try

	}//end getSyncStatus()

	/**
	 * Gets email configuration status for sync reporting
	 *
	 * @return array Email configuration status
	 */
	private function getEmailConfigurationStatus(): array {
		try {
			return $this->emailService->isEmailSystemConfigured();
		} catch (\Exception $e) {
			$this->logger->warning(
				'OrganizationSyncService: Failed to check email configuration status',
				[
					'error' => $e->getMessage(),
				]
			);
			return [
				'configured' => false,
				'reason' => 'Error checking email configuration: ' . $e->getMessage(),
				'hasCredentials' => false,
				'hasTemplates' => false,
			];
		}

	}//end getEmailConfigurationStatus()

	/**
	 * Format numbers for better readability
	 *
	 * @param int $number The number to format
	 *
	 * @return string Formatted number
	 */
	private function formatNumber(int $number): string {
		if ($number >= 1000) {
			return number_format(($number / 1000), 1) . 'k';
		}

		return (string)$number;
	}//end formatNumber()

	/**
	 * Records the last sync time
	 *
	 * @return void
	 * @spec   openspec/specs/organization-sync/spec.md
	 */
	public function recordSyncTime(): void {
		$this->config->setValueString('stackiq', 'last_sync_time', date('Y-m-d H:i:s'));

	}//end recordSyncTime()

	/**
	 * Process a specific organization object (called from event listener)
	 *
	 * This method processes a single organization object, creating or updating
	 * the corresponding organization entity as needed.
	 *
	 * @param \OCA\OpenRegister\Db\ObjectEntity $organizationObject The organization object to process
	 *
	 * @return array Processing results
	 * @spec   openspec/specs/organization-sync/spec.md
	 */
	public function processSpecificOrganization($organizationObject): array {
		$startTime = microtime(true);
		$stats = [
			'organizationsProcessed' => 0,
			'entitiesCreated' => 0,
			'entitiesUpdated' => 0,
			'contactPersonsProcessed' => 0,
			'usersCreated' => 0,
			'errors' => [],
			'startTime' => date('Y-m-d H:i:s'),
		];

		try {
			$objectData = $organizationObject->getObject();
			$organizationUuid = $organizationObject->getUuid();

			$this->logger->info(
				'🏢 ORGANIZATION PROCESSING STARTED',
				[
					'app' => 'stackiq',
					'trigger' => 'ObjectCreatedEvent',
					'organizationId' => $organizationUuid,
					'organizationName' => ($objectData['name'] ?? 'Unknown'),
					'organizationStatus' => ($objectData['status'] ?? 'Unknown'),
					'timestamp' => date('Y-m-d H:i:s'),
					'microtime' => microtime(true),
				]
			);

			// Process organization entity.
			$this->logger->info(
				'[FLOW] Step 1: Creating/updating organisation entity',
				[
					'organizationId' => $organizationUuid,
					'action' => 'ensure_organisation_entity',
				]
			);

			$organisationEntity = $this->ensureOrganisationEntity(organisationObject: $organizationObject, run: ['stats' => &$stats, 'sendEmails' => true]);

			if (empty($organisationEntity) === false) {
				$stats['organizationsProcessed']++;

				$this->logger->info(
					'✅ ORGANISATION ENTITY CREATED/UPDATED',
					[
						'app' => 'stackiq',
						'organizationUuid' => $organizationUuid,
						'entityId' => $organisationEntity->getId(),
						'entityActive' => $organisationEntity->getActive(),
						'entitiesCreated' => $stats['entitiesCreated'],
						'entitiesUpdated' => $stats['entitiesUpdated'],
					]
				);

				// Step 1.5: Process nested contact persons (from registration form data).
				$this->logger->info(
					'[FLOW] Step 1.5: Processing nested contactpersonen from organization data',
					[
						'organizationId' => $organizationUuid,
						'action' => 'process_nested_contactpersonen',
					]
				);

				$this->processNestedContactPersons(organizationObject: $organizationObject, stats: $stats);

				// Step 2: Find and process related contactpersonen objects (separate objects, not nested).
				$this->logger->info(
					'[FLOW] Step 2: Finding related contactpersoon objects',
					[
						'organizationId' => $organizationUuid,
						'action' => 'process_related_contactpersonen',
					]
				);

				$this->processRelatedContactPersons(organizationUuid: $organizationUuid, stats: $stats);
			}//end if

			if (empty($organisationEntity) === true) {
				$this->logger->error(
					'❌ ORGANISATION ENTITY FAILED',
					[
						'app' => 'stackiq',
						'organizationUuid' => $organizationUuid,
						'error' => 'Failed to create/update organisation entity',
					]
				);
				$stats['errors'][] = 'Failed to create/update organisation entity';
			}

			$stats['endTime'] = date('Y-m-d H:i:s');
			$stats['duration'] = round((microtime(true) - $startTime), 3);

			$this->logger->info(
				'🏁 ORGANIZATION PROCESSING COMPLETED',
				[
					'app' => 'stackiq',
					'organizationId' => $organizationUuid,
					'stats' => $stats,
					'processingTime' => $stats['duration'] . 's',
				]
			);

			return $stats;
		} catch (\Exception $e) {
			$stats['errors'][] = $e->getMessage();
			$this->logger->error(
				'💥 ORGANIZATION PROCESSING EXCEPTION',
				[
					'app' => 'stackiq',
					'organizationId' => $organizationObject->getUuid(),
					'exception' => $e->getMessage(),
					'file' => $e->getFile(),
					'line' => $e->getLine(),
					'trace' => $e->getTraceAsString(),
				]
			);

			return $stats;
		}//end try

	}//end processSpecificOrganization()

	/**
	 * Process nested contact persons within an organization object
	 *
	 * @param \OCA\OpenRegister\Db\ObjectEntity $organizationObject The organization object.
	 * @param array $stats The statistics array to update.
	 *
	 * @return void
	 */
	private function processNestedContactPersons($organizationObject, array &$stats): void {
		try {
			$objectData = $organizationObject->getObject();
			$organizationUuid = $organizationObject->getUuid();

			// Check if organization has nested contact persons.
			$contactPersons = ($objectData['contactpersonen'] ?? $objectData['contactPersons'] ?? []);

			if (empty($contactPersons) === true) {
				$this->logger->info(
					'[FLOW] No nested contact persons found in organization',
					['organizationId' => $organizationUuid]
				);
				return;
			}

			$this->logger->info(
				'👥 PROCESSING NESTED CONTACT PERSONS',
				[
					'app' => 'stackiq',
					'organizationId' => $organizationUuid,
					'contactCount' => count($contactPersons),
				]
			);

			// Get configuration for contact person creation.
			$voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
			$register = ($voorzieningenConfig['register'] ?? '');
			$contactSchema = ($voorzieningenConfig['contactpersoon_schema'] ?? '');

			if (empty($register) === true || empty($contactSchema) === true) {
				$this->logger->warning(
					'[FLOW] Contact person processing skipped - configuration missing',
					[
						'organizationId' => $organizationUuid,
						'register' => $register,
						'contactSchema' => $contactSchema,
					]
				);
				return;
			}

			foreach ($contactPersons as $index => $contactData) {
				try {
					// Handle UUID references - if contactData is a string (UUID), fetch the actual object.
					if (is_string($contactData) === true) {
						$this->logger->info(
							'[FLOW] Contact person is a UUID reference, fetching object',
							[
								'organizationId' => $organizationUuid,
								'contactIndex' => $index,
								'contactUuid' => $contactData,
							]
						);

						// Fetch the contact person object using the UUID.
						$contactObject = $this->objectService->find(
							id: $contactData,
							register: $register,
							schema: $contactSchema,
							_rbac: false,
							_multitenancy: false
						);

						if ($contactObject === null) {
							$this->logger->warning(
								'[FLOW] Contact person not found by UUID',
								[
									'organizationId' => $organizationUuid,
									'contactUuid' => $contactData,
								]
							);
							continue;
						}

						// Read it through the published contract. `find()` returns
						// an ObjectEntityInterface or null — never a bare array —
						// so the `is_array()` arm this replaces could not fire, and
						// the `instanceof Db\ObjectEntity` narrowing was reaching
						// past the contract at a concrete class this app must not
						// depend on (ADR-022/ADR-084).
						$contactData = $contactObject->getObject();

						// Add the UUID if not present.
						if (isset($contactData['id']) === false) {
							$contactData['id'] = $contactObject->getUuid();
						}
					}//end if

					$this->logger->info(
						'[FLOW] Processing nested contact person',
						[
							'organizationId' => $organizationUuid,
							'contactIndex' => $index,
							'contactEmail' => ($contactData['email'] ?? $contactData['e-mailadres'] ?? 'unknown'),
						]
					);

					// Create contact person object in OpenRegister if it doesn't exist.
					$run = [
						'organizationUuid' => $organizationUuid,
						'register' => $register,
						'contactSchema' => $contactSchema,
						'stats' => &$stats,
					];
					$this->createOrUpdateContactPersonObject(contactData: $contactData, run: $run);
				} catch (\Exception $e) {
					$this->logger->error(
						'[FLOW] Failed to process nested contact person',
						[
							'organizationId' => $organizationUuid,
							'contactIndex' => $index,
							'exception' => $e->getMessage(),
						]
					);
					$stats['errors'][] = "Contact person {$index}: " . $e->getMessage();
				}//end try
			}//end foreach
		} catch (\Exception $e) {
			$this->logger->error(
				'[FLOW] Failed to process nested contact persons',
				[
					'organizationId' => $organizationObject->getUuid(),
					'exception' => $e->getMessage(),
				]
			);
			$stats['errors'][] = 'Failed to process nested contact persons: ' . $e->getMessage();
		}//end try

	}//end processNestedContactPersons()

	/**
	 * Process related contactpersoon objects that have this organization in their organisation property
	 *
	 * @param string $organizationUuid The organization UUID.
	 * @param array  $stats            The statistics array to update.
	 *
	 * @return void
	 */
	private function processRelatedContactPersons(string $organizationUuid, array &$stats): void {
		try {
			$this->logger->debug(
				'Finding related contact persons',
				[
					'app' => 'stackiq',
					'organizationId' => $organizationUuid,
				]
			);

			// Get configuration.
			$voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
			$run = [
				'organizationUuid' => $organizationUuid,
				'register' => ($voorzieningenConfig['register'] ?? ''),
				'contactSchema' => ($voorzieningenConfig['contactpersoon_schema'] ?? ''),
			];

			if (empty($run['register']) === true || empty($run['contactSchema']) === true) {
				$this->logger->warning(
					'[FLOW] Related contact processing skipped - configuration missing',
					[
						'organizationId' => $organizationUuid,
						'register' => $run['register'],
						'contactSchema' => $run['contactSchema'],
					]
				);
				return;
			}

			$relatedContacts = $this->findRelatedContacts(run: $run);
			if (empty($relatedContacts) === true) {
				return;
			}

			$this->logger->info(
				'👥 PROCESSING RELATED CONTACT PERSONS',
				[
					'app' => 'stackiq',
					'organizationId' => $organizationUuid,
					'contactCount' => count($relatedContacts),
				]
			);

			$run['stats'] = &$stats;
			foreach ($relatedContacts as $contactObject) {
				$this->processRelatedContact(contactObject: $contactObject, run: $run);
			}
		} catch (\Exception $e) {
			$this->logger->error(
				'[FLOW] Failed to process related contact persons',
				[
					'organizationId' => $organizationUuid,
					'exception' => $e->getMessage(),
					'file' => $e->getFile(),
					'line' => $e->getLine(),
				]
			);
			$stats['errors'][] = 'Failed to process related contact persons: ' . $e->getMessage();
		}//end try

	}//end processRelatedContactPersons()

	/**
	 * Find the contactpersoon objects of an organisation: by their organization field, then by
	 * their organisation field, then from the organisation's own contactpersonen array.
	 *
	 * @param array $run The sync run: `organizationUuid`, `register` and `contactSchema`.
	 *
	 * @return array The related contact objects, empty when none are found
	 */
	private function findRelatedContacts(array $run): array {
		$organizationUuid = $run['organizationUuid'];

		// Search for contactpersoon objects with this organization reference.
		// Try both 'organization' and 'organisation' field names.
		$query = [
			'@self' => [
				'register' => (int)$run['register'],
				'schema' => (int)$run['contactSchema'],
			],
			'organization' => $organizationUuid,
			'_limit' => 500,
		];

		$this->logger->info(
			'[FLOW] Searching for related contact persons with organisatie field',
			[
				'organizationId' => $organizationUuid,
				'register' => $run['register'],
				'contactSchema' => $run['contactSchema'],
				'query' => $query,
			]
		);

		$relatedContacts = $this->objectService->searchObjects($query);

		// If not found, try with 'organisation' field.
		if (empty($relatedContacts) === true) {
			$query['organisation'] = $organizationUuid;
			unset($query['organization']);

			$this->logger->info(
				'[FLOW] Retrying search with organisation field',
				[
					'organizationId' => $organizationUuid,
					'query' => $query,
				]
			);

			$relatedContacts = $this->objectService->searchObjects($query);
		}

		if (empty($relatedContacts) === false) {
			return $relatedContacts;
		}

		// Fallback: fetch contacts by UUID from the org's contactpersonen array.
		// This handles the case where contacts were linked via inversedBy (org → contact).
		// but the contact's own organisatie field was never set.
		$this->logger->info(
			'[FLOW] No contacts found by organisatie field, checking org contactpersonen array',
			['organizationId' => $organizationUuid]
		);

		$relatedContacts = $this->contactsFromOrganisationList(run: $run);
		if (empty($relatedContacts) === true) {
			$this->logger->info(
				'[FLOW] No related contact persons found (including contactpersonen fallback)',
				['organizationId' => $organizationUuid]
			);
		}

		return $relatedContacts;
	}//end findRelatedContacts()

	/**
	 * The contact objects listed in the organisation's raw contactpersonen array.
	 *
	 * @param array $run The sync run: `organizationUuid`, `register` and `contactSchema`.
	 *
	 * @return array The contact objects that could be fetched
	 */
	private function contactsFromOrganisationList(array $run): array {
		$organizationUuid = $run['organizationUuid'];
		$contacts = [];

		// Re-fetch the org object without _extend to get raw UUIDs.
		try {
			$voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
			$rawOrgObject = $this->objectService->find(
				id: $organizationUuid,
				register: ($voorzieningenConfig['register'] ?? ''),
				schema: ($voorzieningenConfig['organisatie_schema'] ?? ''),
				_rbac: false,
				_multitenancy: false
			);

			$rawOrgData = [];
			if ($rawOrgObject !== null) {
				$rawOrgData = $rawOrgObject->getObject();
			}

			$contactUuids = ($rawOrgData['contactpersonen'] ?? []);

			$this->logger->info(
				'[FLOW] Raw org contactpersonen array',
				[
					'organizationId' => $organizationUuid,
					'contactpersonen' => $contactUuids,
					'count' => count($contactUuids),
				]
			);

			foreach ($contactUuids as $contactUuid) {
				if (is_string($contactUuid) === false || empty($contactUuid) === true) {
					continue;
				}

				$contactObj = $this->fetchListedContact(contactUuid: $contactUuid, run: $run);
				if ($contactObj !== null) {
					$contacts[] = $contactObj;
				}
			}
		} catch (\Exception $orgEx) {
			$this->logger->warning(
				'[FLOW] Could not re-fetch org for contactpersonen fallback',
				[
					'organizationId' => $organizationUuid,
					'exception' => $orgEx->getMessage(),
				]
			);
		}//end try

		return $contacts;
	}//end contactsFromOrganisationList()

	/**
	 * Fetch one contact listed in an organisation's contactpersonen array.
	 *
	 * @param string $contactUuid The contact uuid.
	 * @param array  $run         The sync run: `organizationUuid`, `register` and `contactSchema`.
	 *
	 * @return object|null The contact object, or null when it cannot be fetched
	 */
	private function fetchListedContact(string $contactUuid, array $run): ?object {
		try {
			return $this->objectService->find(
				id: $contactUuid,
				register: $run['register'],
				schema: $run['contactSchema'],
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Exception $fetchEx) {
			$this->logger->warning(
				'[FLOW] Could not fetch contact from contactpersonen array',
				[
					'organizationId' => $run['organizationUuid'],
					'contactUuid' => $contactUuid,
					'exception' => $fetchEx->getMessage(),
				]
			);
			return null;
		}//end try
	}//end fetchListedContact()

	/**
	 * Process one related contact: complete it, give it its organisation, then sync it.
	 *
	 * @param object $contactObject The contact object.
	 * @param array  $run           The sync run: `organizationUuid`, `register`, `contactSchema` and,
	 *                              held by reference, `stats`.
	 *
	 * @return void
	 */
	private function processRelatedContact(object $contactObject, array $run): void {
		$organizationUuid = $run['organizationUuid'];
		try {
			$contactUuid = $contactObject->getUuid();
			$contactObject = $this->completeRelatedContact(contactObject: $contactObject, run: $run);
			$contactData = $contactObject->getObject();
			$email = ($contactData['email'] ?? $contactData['e-mailadres'] ?? '');

			// Ensure the contact has the organisatie field set before processing.
			// (may be missing when contacts were linked via inversedBy from the org side).
			if (empty($contactData['organization']) === true) {
				$contactData['organization'] = $organizationUuid;
				$contactObject->setObject($contactData);
				// Published contract instead of OpenRegister's Db layer; see
				// ContactpersonenController for the reasoning.
				$this->objectService->saveObject(
					object: $contactData,
					register: $contactObject->getRegister(),
					schema: $contactObject->getSchema(),
					uuid: $contactObject->getUuid(),
					silent: true,
					_validation: false
				);
				$this->logger->info(
					'[FLOW] Set missing organisatie field on related contact',
					[
						'contactId' => $contactUuid,
						'organizationUuid' => $organizationUuid,
					]
				);
			}//end if

			if ($email === false || $email === '' || $email === null) {
				$email = 'unknown';
			}

			$this->logger->info(
				'[FLOW] Processing related contact person',
				[
					'organizationId' => $organizationUuid,
					'contactId' => $contactUuid,
					'contactEmail' => $email,
				]
			);

			// Process the contact person through processSpecificContactPerson.
			$contactStats = $this->processSpecificContactPerson(contactObject: $contactObject);

			// Merge stats.
			$run['stats']['contactPersonsProcessed'] += ($contactStats['contactPersonsProcessed'] ?? 0);
			$run['stats']['usersCreated'] += ($contactStats['usersCreated'] ?? 0);
			$run['stats']['usersUpdated'] += ($contactStats['usersUpdated'] ?? 0);
			if (empty($contactStats['errors']) === false) {
				$run['stats']['errors'] = array_merge($run['stats']['errors'], $contactStats['errors']);
			}
		} catch (\Exception $e) {
			$this->logger->error(
				'[FLOW] Failed to process related contact person',
				[
					'organizationId' => $organizationUuid,
					'contactId' => $contactObject->getUuid(),
					'exception' => $e->getMessage(),
				]
			);
			$run['stats']['errors'][] = "Related contact {$contactObject->getUuid()}: " . $e->getMessage();
		}//end try
	}//end processRelatedContact()

	/**
	 * A related contact with its full data: one without an email is fetched again by uuid.
	 *
	 * @param object $contactObject The contact object as found.
	 * @param array  $run           The sync run: `organizationUuid`, `register` and `contactSchema`.
	 *
	 * @return object The re-fetched contact, or the one given when it is complete or cannot be fetched
	 */
	private function completeRelatedContact(object $contactObject, array $run): object {
		$contactUuid = $contactObject->getUuid();
		$contactData = $contactObject->getObject();

		// Check if contact data is complete (has email).
		$email = ($contactData['email'] ?? $contactData['e-mailadres'] ?? '');
		if (empty($email) === false || empty($contactUuid) === true) {
			return $contactObject;
		}

		// Re-fetch the full contact object if email is missing.
		$this->logger->info(
			'[FLOW] Contact data incomplete, re-fetching full object',
			[
				'organizationId' => $run['organizationUuid'],
				'contactId' => $contactUuid,
			]
		);

		try {
			$fullContactObject = $this->objectService->find(
				id: $contactUuid,
				register: $run['register'],
				schema: $run['contactSchema'],
				_rbac: false,
				_multitenancy: false
			);
			if ($fullContactObject !== null) {
				return $fullContactObject;
			}
		} catch (\Exception $e) {
			$this->logger->warning(
				'[FLOW] Failed to re-fetch contact object',
				[
					'organizationId' => $run['organizationUuid'],
					'contactId' => $contactUuid,
					'exception' => $e->getMessage(),
				]
			);
		}//end try

		return $contactObject;
	}//end completeRelatedContact()

	/**
	 * Create or update a contact person object and its user account.
	 *
	 * @param array $contactData The contact person data.
	 * @param array $run         The sync run: `organizationUuid`, `register`, `contactSchema` and, held by
	 *                           reference so every step updates the caller's counters, `stats`.
	 *
	 * @return void
	 */
	private function createOrUpdateContactPersonObject(array $contactData, array $run): void {
		try {
			$email = ($contactData['email'] ?? $contactData['e-mailadres'] ?? '');
			if (empty($email) === true) {
				$this->logger->warning(
					'[FLOW] Contact person has no email, skipping',
					[
						'organizationId' => $run['organizationUuid'],
						'contactData' => array_keys($contactData),
					]
				);
				return;
			}

			$run['email'] = $email;

			// Check if contact already exists (has id from cascading or previous creation).
			$existingContactId = ($contactData['id'] ?? $contactData['uuid'] ?? null);
			$contactObject     = $this->findExistingContactPerson(contactId: $existingContactId, run: $run);
			if ($contactObject === null) {
				$contactObject = $this->createContactPersonObject(contactData: $contactData, run: $run);
			}

			$run['contact']     = $contactObject;
			$run['wasExisting'] = empty($existingContactId) === false;
			$this->completeContactPerson(run: $run);
		} catch (\Exception $e) {
			$this->logger->error(
				'[FLOW] Failed to create/update contact person object',
				[
					'organizationId' => $run['organizationUuid'],
					'email' => ($contactData['email'] ?? $contactData['e-mailadres'] ?? 'unknown'),
					'exception' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);
			$run['stats']['errors'][] = 'Contact person creation failed: ' . $e->getMessage();
		}//end try

	}//end createOrUpdateContactPersonObject()

	/**
	 * Fetch the contact person a contact id points at, if there is one.
	 *
	 * @param mixed $contactId The id or uuid from the contact data, or null.
	 * @param array $run       The sync run (see createOrUpdateContactPersonObject()).
	 *
	 * @return object|null The contact object, or null when there is no id or the fetch fails.
	 */
	private function findExistingContactPerson(mixed $contactId, array $run): ?object {
		if (empty($contactId) === true) {
			return null;
		}

		// Contact already exists - fetch it instead of trying to re-create.
		$this->logger->info(
			'📧 FETCHING EXISTING CONTACT PERSON',
			[
				'app' => 'stackiq',
				'organizationId' => $run['organizationUuid'],
				'contactId' => $contactId,
				'email' => $run['email'],
			]
		);

		try {
			return $this->objectService->find(
				id: $contactId,
				register: $run['register'],
				schema: $run['contactSchema'],
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Exception $e) {
			$this->logger->warning(
				'[FLOW] Could not fetch existing contact, will create new',
				[
					'organizationId' => $run['organizationUuid'],
					'contactId' => $contactId,
					'exception' => $e->getMessage(),
				]
			);
		}

		return null;

	}//end findExistingContactPerson()

	/**
	 * Create a contact person object, keeping its link to the organisation.
	 *
	 * The organisation field is left out of the create (the schema expects an
	 * object, the field stores a uuid) and written back afterwards; the
	 * organisation link itself is handled by inversedBy.
	 *
	 * @param array $contactData The contact person data.
	 * @param array $run         The sync run (see createOrUpdateContactPersonObject()).
	 *
	 * @return object The created contact object.
	 */
	private function createContactPersonObject(array $contactData, array $run): object {
		$this->logger->info(
			'📧 CREATING NEW CONTACT PERSON OBJECT',
			[
				'app' => 'stackiq',
				'organizationId' => $run['organizationUuid'],
				'email' => $run['email'],
				'name' => ($contactData['voornaam'] ?? '') . ' ' . ($contactData['achternaam'] ?? ''),
			]
		);

		$savedOrganisation = $contactData['organization'] ?? null;
		unset($contactData['organization']);
		unset($contactData['id']);
		unset($contactData['uuid']);

		$contactObject = $this->objectService->saveObject(
			object: $contactData,
			register: $run['register'],
			schema: $run['contactSchema'],
			_rbac: false,
			_multitenancy: false
		);

		if ($savedOrganisation !== null) {
			$restoredData = $contactObject->getObject();
			$restoredData['organization'] = $savedOrganisation;
			$this->persistContactObject(
				contactObject: $contactObject,
				object: array_merge(
					$restoredData,
					['@self' => ['organisation' => $contactObject->getOrganisation()]]
				)
			);
		}

		return $contactObject;

	}//end createContactPersonObject()

	/**
	 * Count a ready contact person, make sure it names its organisation and
	 * give it a user account when it has none.
	 *
	 * @param array $run The sync run (see createOrUpdateContactPersonObject()) with the `contact` object and `wasExisting`.
	 *
	 * @return void
	 */
	private function completeContactPerson(array $run): void {
		$contactObject = $run['contact'];
		$run['stats']['contactPersonsProcessed']++;

		$run['contactData'] = $this->ensureContactOrganisation(run: $run);

		$this->logger->info(
			'✅ CONTACT PERSON OBJECT READY',
			[
				'app' => 'stackiq',
				'organizationId' => $run['organizationUuid'],
				'contactId' => $contactObject->getUuid(),
				'email' => $run['email'],
				'wasExisting' => $run['wasExisting'],
			]
		);

		// Create user account if username is missing AND organization is active.
		if (empty($run['contactData']['username']) === true) {
			$this->provisionContactUser(run: $run);
		}

	}//end completeContactPerson()

	/**
	 * Set the organisation field on a contact person that lacks it.
	 *
	 * @param array $run The sync run with the `contact` object.
	 *
	 * @return array The contact object data, with the organisation set when it was missing.
	 */
	private function ensureContactOrganisation(array $run): array {
		$contactObject     = $run['contact'];
		$contactObjectData = $contactObject->getObject();
		if (empty($contactObjectData['organization']) === true && empty($run['organizationUuid']) === false) {
			$contactObjectData['organization'] = $run['organizationUuid'];
			$this->persistContactObject(
				contactObject: $contactObject,
				object: array_merge(
					$contactObjectData,
					['@self' => ['organisation' => $run['organizationUuid']]]
				)
			);
			$this->logger->info(
				'[FLOW] Set missing organisatie field on contact person',
				[
					'contactId' => $contactObject->getUuid(),
					'organizationUuid' => $run['organizationUuid'],
				]
			);
		}

		return $contactObjectData;

	}//end ensureContactOrganisation()

	/**
	 * Create a user account for a contact person when its organisation is active.
	 *
	 * @param array $run The sync run with the `contact` object and its `contactData`.
	 *
	 * @return void
	 */
	private function provisionContactUser(array $run): void {
		$contactObject      = $run['contact'];
		$organisationEntity = $this->findOrganisationEntityForContact(
			contactObject: $contactObject,
			organizationUuid: $run['organizationUuid']
		);

		try {
			if ($organisationEntity !== false && $organisationEntity->getActive() === true) {
				$this->createUserForContact(run: $run);
			}

			if ($organisationEntity === false || $organisationEntity->getActive() !== true) {
				$this->logSkippedUserCreation(organisationEntity: $organisationEntity, run: $run);
			}
		} catch (\Exception $e) {
			// Organization not found in entity table = not active.
			$this->logger->info(
				'Skipping user creation - organization not found in entity table (not active)',
				[
					'contactId' => $contactObject->getUuid(),
					'organizationId' => $run['organizationUuid'],
					'reason' => 'Organization not found in entity table',
					'email' => $run['email'],
				]
			);
		}//end try

	}//end provisionContactUser()

	/**
	 * The organisation entity of a contact person's organisation, created from
	 * the organisation object when the entity is missing.
	 *
	 * @param object $contactObject    The contact object.
	 * @param string $organizationUuid The organisation uuid.
	 *
	 * @return mixed The organisation entity, or null when it is missing and cannot be created.
	 */
	private function findOrganisationEntityForContact(object $contactObject, string $organizationUuid): mixed {
		try {
			return $this->organisationMapper->findByUuid($organizationUuid);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			$this->logger->info(
				'Organisation entity missing for ' . $organizationUuid . ', creating backup entity',
				[
					'app' => 'stackiq',
					'contactId' => $contactObject->getUuid(),
				]
			);
		}

		return $this->createBackupOrganisationEntity(organizationUuid: $organizationUuid);

	}//end findOrganisationEntityForContact()

	/**
	 * Create the organisation entity for an organisation object that has none,
	 * so user creation can proceed.
	 *
	 * @param string $organizationUuid The organisation uuid.
	 *
	 * @return object|null The organisation entity, or null when the object is missing or creation fails.
	 */
	private function createBackupOrganisationEntity(string $organizationUuid): ?object {
		try {
			$voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
			$orgObject = $this->objectService->find(
				id: $organizationUuid,
				register: ($voorzieningenConfig['register'] ?? ''),
				schema: ($voorzieningenConfig['organisatie_schema'] ?? '')
			);
			if (empty($orgObject) === false) {
				$backupStats = [
					'entitiesCreated' => 0,
					'entitiesUpdated' => 0,
				];
				return $this->ensureOrganisationEntity(
					organisationObject: $orgObject,
					run: ['stats' => &$backupStats, 'sendEmails' => true]
				);
			}
		} catch (\Exception $backupEx) {
			$this->logger->error(
				'Backup org entity creation failed',
				[
					'app' => 'stackiq',
					'organizationId' => $organizationUuid,
					'error' => $backupEx->getMessage(),
				]
			);
		}//end try

		return null;

	}//end createBackupOrganisationEntity()

	/**
	 * Create the user account of a contact person and link it to the contact
	 * and the organisation.
	 *
	 * @param array $run The sync run with the `contact` object and its `contactData`.
	 *
	 * @return void
	 */
	private function createUserForContact(array $run): void {
		$contactObject = $run['contact'];

		// Determine if this is the first contact for the organization.
		$isFirstContact = $this->contactpersonHandler->isFirstContactForOrganization(
			$contactObject,
			$run['contactData']
		);

		$this->logger->info(
			'Creating user account for contact person (org is active)',
			[
				'app' => 'stackiq',
				'contactId' => $contactObject->getUuid(),
				'organizationId' => $run['organizationUuid'],
				'email' => $run['email'],
				'isFirstContact' => $isFirstContact,
			]
		);

		$user = $this->contactpersonHandler->createUserAccount($contactObject, $isFirstContact);
		if (empty($user) === false) {
			$run['stats']['usersCreated']++;
			$this->linkCreatedUser(user: $user, run: $run);
		}

		if (empty($user) === true) {
			$this->logger->error(
				'User account creation failed',
				[
					'app' => 'stackiq',
					'contactId' => $contactObject->getUuid(),
					'email' => $run['email'],
				]
			);
			$run['stats']['errors'][] = "Failed to create user account for {$run['email']}";
		}

	}//end createUserForContact()

	/**
	 * Store a new user's uid on its contact person, add the user to the
	 * organisation and make the user the contact's owner.
	 *
	 * @param object $user The created user.
	 * @param array  $run  The sync run with the `contact` object.
	 *
	 * @return void
	 */
	private function linkCreatedUser(object $user, array $run): void {
		$contactObject = $run['contact'];

		// Read the contact again: createUserAccount() changes the entity in
		// place, and the data read before it would overwrite those changes.
		$contactObjectData = $contactObject->getObject();
		$contactObjectData['username'] = $user->getUID();
		$this->saveContactUsername(contactObject: $contactObject, contactObjectData: $contactObjectData);

		$this->contactpersonHandler->addUserToOrganizationEntity(
			$contactObject,
			$user->getUID(),
			$run['organizationUuid']
		);

		$this->updateContactPersonObjectOwner(
			contactObject: $contactObject,
			owner: [
				'userUID' => $user->getUID(),
				'register' => $run['register'],
				'contactSchema' => $run['contactSchema'],
				'organizationUuidOverride' => $run['organizationUuid'],
			]
		);

		$this->logger->info(
			'User account created',
			[
				'app' => 'stackiq',
				'contactId' => $contactObject->getUuid(),
				'username' => $user->getUID(),
			]
		);

	}//end linkCreatedUser()

	/**
	 * Save a contact person with its new username, without validation or
	 * events, so the organisation field (a uuid where the schema expects an
	 * object) is kept and no update cascade re-enters the sync.
	 *
	 * @param object $contactObject     The contact object.
	 * @param array  $contactObjectData The contact data with `username` set.
	 *
	 * @return void
	 */
	private function saveContactUsername(object $contactObject, array $contactObjectData): void {
		$this->logger->debug(
			'Saving contact with username via direct mapper update',
			[
				'app' => 'stackiq',
				'contactId' => $contactObject->getUuid(),
				'username' => $contactObjectData['username'],
				'hasOrganisatie' => isset($contactObjectData['organization']) === true,
			]
		);

		try {
			$this->persistContactObject(
				contactObject: $contactObject,
				object: array_merge(
					$contactObjectData,
					['@self' => ['organisation' => $contactObject->getOrganisation()]]
				)
			);
			$this->logger->info(
				'Contact saved with username',
				[
					'app' => 'stackiq',
					'contactId' => $contactObject->getUuid(),
					'username' => $contactObjectData['username'],
				]
			);
		} catch (\Exception $saveEx) {
			$this->logger->warning(
				'Failed to save username to contact object (user was created)',
				[
					'app' => 'stackiq',
					'contactId' => $contactObject->getUuid(),
					'username' => $contactObjectData['username'],
					'error' => $saveEx->getMessage(),
				]
			);
		}//end try

	}//end saveContactUsername()

	/**
	 * Log why no user account was created for a contact person.
	 *
	 * @param mixed $organisationEntity The organisation entity, or null when there is none.
	 * @param array $run                The sync run with the `contact` object.
	 *
	 * @return void
	 */
	private function logSkippedUserCreation(mixed $organisationEntity, array $run): void {
		$organizationActiveValue = false;
		if ($organisationEntity !== null) {
			$organizationActiveValue = $organisationEntity->getActive();
		}

		$this->logger->info(
			'Skipping user creation - organization not active or not found in entity table',
			[
				'contactId' => $run['contact']->getUuid(),
				'organizationId' => $run['organizationUuid'],
				'organizationFound' => $organisationEntity !== null,
				'organizationActive' => $organizationActiveValue,
				'email' => $run['email'],
			]
		);

	}//end logSkippedUserCreation()

	/**
	 * Save a contact person object as it is, without validation and without events.
	 *
	 * @param object $contactObject The contact object, for its register, schema and uuid.
	 * @param array  $object        The object data to save.
	 *
	 * @return void
	 */
	private function persistContactObject(object $contactObject, array $object): void {
		// Published contract instead of OpenRegister's Db layer: the payload is
		// passed explicitly, as `setObject()` is not on ObjectEntityInterface (ADR-084).
		$this->objectService->saveObject(
			object: $object,
			register: $contactObject->getRegister(),
			schema: $contactObject->getSchema(),
			uuid: $contactObject->getUuid(),
			silent: true,
			_validation: false
		);

	}//end persistContactObject()

	/**
	 * Process a specific contact person object (called from event listener)
	 *
	 * This method processes a single contact person object, creating user accounts
	 * and updating the contact person object as needed.
	 *
	 * @param \OCA\OpenRegister\Db\ObjectEntity $contactObject The contact person object to process
	 *
	 * @return array Processing results
	 * @spec   openspec/specs/organization-sync/spec.md
	 */
	public function processSpecificContactPerson($contactObject): array {
		$stats = [
			'contactPersonsProcessed' => 0,
			'usersCreated' => 0,
			'usersUpdated' => 0,
			'errors' => [],
			'startTime' => date('Y-m-d H:i:s'),
		];

		try {
			$voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
			$register = ($voorzieningenConfig['register'] ?? '');
			$contactSchema = ($voorzieningenConfig['contactpersoon_schema'] ?? '');

			$this->logger->info(
				'[EVENT] OrganizationSyncService: Processing specific contact person',
				[
					'contactId' => $contactObject->getUuid(),
					'trigger' => 'event_listener',
				]
			);

			/*
			 * @var array<string, mixed> $contactEntityObject
			 */

			$contactEntityObject = $contactObject->getObject();

			// Skip if no organization reference.
			$organizationUuid = $contactEntityObject['organization'] ?? null;
			if (empty($organizationUuid) === true) {
				$this->logger->warning(
					'[EVENT] OrganizationSyncService: Contact person has no organization reference',
					[
						'contactId' => $contactObject->getUuid(),
					]
				);
				return $stats;
			}

			// Create user account if username is missing AND organization is active.
			if (empty($contactEntityObject['username']) === true) {
				// Check if organization exists in organisation entity table.
				$organisationEntity = null;
				try {
					$organisationEntity = $this->organisationMapper->findByUuid($organizationUuid);
				} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
					// Backup: org entity missing — create it now so user creation can proceed.
					$this->logger->info(
						'[EVENT] Organisation entity missing for ' . $organizationUuid . ', creating backup entity',
						[
							'app' => 'stackiq',
							'contactId' => $contactObject->getUuid(),
						]
					);
					try {
						$voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
						$orgObject = $this->objectService->find(
							id: $organizationUuid,
							register: ($voorzieningenConfig['register'] ?? ''),
							schema: ($voorzieningenConfig['organisatie_schema'] ?? '')
						);
						if (empty($orgObject) === false) {
							$backupStats = [
								'entitiesCreated' => 0,
								'entitiesUpdated' => 0,
							];
							$organisationEntity = $this->ensureOrganisationEntity(
								organisationObject: $orgObject,
								run: ['stats' => &$backupStats, 'sendEmails' => true]
							);
						}
					} catch (\Exception $backupEx) {
						$this->logger->error(
							'Backup org entity creation failed',
							[
								'app' => 'stackiq',
								'organizationId' => $organizationUuid,
								'error' => $backupEx->getMessage(),
							]
						);
					}//end try
				}//end try

				try {
					if ($organisationEntity !== false && $organisationEntity->getActive() === true) {
						// Determine if this is the first contact for the organization.
						$isFirstContact = $this->contactpersonHandler->isFirstContactForOrganization(
							$contactObject,
							$contactEntityObject
						);

						$contactEmail = ($contactEntityObject['email'] ?? $contactEntityObject['e-mailadres'] ?? 'unknown');
						$this->logger->info(
							'[EVENT] OrganizationSyncService: Creating user account for contact person (org is active)',
							[
								'contactId' => $contactObject->getUuid(),
								'organizationId' => $organizationUuid,
								'orgActive' => true,
								'email' => $contactEmail,
								'isFirstContact' => $isFirstContact,
							]
						);

						$user = $this->contactpersonHandler->createUserAccount($contactObject, $isFirstContact);
						// Check if user was created successfully (can be null if no email).
						if ($user !== null) {
							$contactEntityObject['username'] = $user->getUID();

							// Temporarily remove organisatie field to avoid validation error.
							// (it's stored as UUID string but schema expects object type).
							// Save the value so we can restore it after the save.
							$savedOrganisation = $contactEntityObject['organization'] ?? null;
							unset($contactEntityObject['organization']);

							// Update the contact object with the username (using RBAC bypass).
							// Wrapped in its own try/catch because saveObject triggers event listeners.
							// that may fail — but the user was already created successfully above.
							try {
								// The PAYLOAD is sent — `setObject()` is not on
								// ObjectEntityInterface (ADR-084), and `saveObject()`
								// is PUT-semantic so $contactEntityObject already
								// carries every unchanged field.
								$this->objectService->saveObject(
									object: $contactEntityObject,
									register: $register,
									schema: $contactSchema,
									uuid: $contactObject->getUuid(),
									_rbac: false,
									_multitenancy: false
								);
							} catch (\Exception $saveEx) {
								$this->logger->warning(
									'[EVENT] Failed to save username to contact object (user was created)',
									[
										'contactId' => $contactObject->getUuid(),
										'username' => $user->getUID(),
										'error' => $saveEx->getMessage(),
									]
								);
							}

							// Add user to organization entity in database.
							$this->contactpersonHandler->addUserToOrganizationEntity(
								$contactObject,
								$user->getUID(),
								$organizationUuid
							);

							// Update contactpersoon object owner to user UID.
							$owner = ['userUID' => $user->getUID(), 'register' => $register, 'contactSchema' => $contactSchema];
							$owner['organizationUuidOverride'] = $organizationUuid;
							$this->updateContactPersonObjectOwner(contactObject: $contactObject, owner: $owner);

							$stats['usersCreated']++;
						}//end if

						if ($user === null) {
							$this->logger->debug(
								'[EVENT] Skipping contact - user account creation failed (likely no email)',
								[
									'app' => 'stackiq',
									'contactId' => $contactObject->getUuid(),
								]
							);
						}
					}//end if

					if ($organisationEntity === false || $organisationEntity->getActive() !== true) {
						if ($organisationEntity !== null) {
							$organizationActiveValue = $organisationEntity->getActive();
						} else {
							$organizationActiveValue = false;
						}

						$skipEmail = ($contactEntityObject['email'] ?? $contactEntityObject['e-mailadres'] ?? 'unknown');
						$this->logger->info(
							'[EVENT] OrganizationSyncService: Skipping user creation - org not active or entity not found',
							[
								'contactId' => $contactObject->getUuid(),
								'organizationId' => $organizationUuid,
								'orgFound' => $organisationEntity !== null,
								'orgActive' => $organizationActiveValue,
								'email' => $skipEmail,
							]
						);
					}
				} catch (\Exception $e) {
					$this->logger->error(
						'[EVENT] User creation failed for contact',
						[
							'contactId' => $contactObject->getUuid(),
							'organizationId' => $organizationUuid,
							'error' => $e->getMessage(),
						]
					);
				}//end try
			}//end if

			$stats['contactPersonsProcessed']++;
			$stats['endTime'] = date('Y-m-d H:i:s');
			$endTs = (new \DateTime($stats['endTime']))->getTimestamp();
			$startTs = (new \DateTime($stats['startTime']))->getTimestamp();
			$stats['duration'] = ($endTs - $startTs);

			$this->logger->info(
				'[EVENT] OrganizationSyncService: Specific contact person processing completed',
				[
					'contactId' => $contactObject->getUuid(),
					'stats' => $stats,
				]
			);

			return $stats;
		} catch (\Exception $e) {
			$stats['errors'][] = $e->getMessage();
			$this->logger->error(
				'[EVENT] OrganizationSyncService: Failed to process specific contact person',
				[
					'contactId' => $contactObject->getUuid(),
					'exception' => $e->getMessage(),
				]
			);

			return $stats;
		}//end try

	}//end processSpecificContactPerson()

	/**
	 * Performs optimized manual synchronization for large datasets
	 *
	 * This method processes organizations in multiple batches to handle large numbers
	 * (800+) efficiently while staying under timeout limits.
	 *
	 * @param int $maxRounds Maximum number of batch rounds to execute (default: 10)
	 * @param int $batchSize Number of items to process per batch (default: 100)
	 *
	 * @return array Comprehensive synchronization results
	 * @spec   openspec/specs/organization-sync/spec.md
	 */
	public function performOptimizedManualSync(int $maxRounds = 10, int $batchSize = 100): array {
		$totalStartTime = time();
		$allResults = [
			'totalRounds' => 0,
			'organizationsProcessed' => 0,
			'contactPersonsProcessed' => 0,
			'entitiesCreated' => 0,
			'entitiesUpdated' => 0,
			'usersCreated' => 0,
			'usersUpdated' => 0,
			'totalExecutionTime' => 0,
			'timeoutReached' => false,
			'roundsCompleted' => [],
			'errors' => [],
			'startTime' => date('Y-m-d H:i:s'),
		];

		$this->logger->info(
			'[MANUAL] OrganizationSyncService: Starting optimized manual sync',
			[
				'maxRounds' => $maxRounds,
				'batchSize' => $batchSize,
				'trigger' => 'manual',
			]
		);

		for ($round = 1; $round <= $maxRounds; $round++) {
			$roundStartTime = time();

			// Process organizations batch.
			$orgResults = $this->performOrganizationsSync(batchSize: $batchSize, maxExecutionSeconds: 45);

			// Process contacts batch.
			$contactResults = $this->performContactSync(batchSize: $batchSize, maxExecutionSeconds: 15);

			// Accumulate results.
			$allResults['organizationsProcessed'] += $orgResults['organizationsProcessed'];
			$allResults['contactPersonsProcessed'] += $contactResults['contactPersonsProcessed'];
			$allResults['entitiesCreated'] += $orgResults['entitiesCreated'];
			$allResults['entitiesUpdated'] += $orgResults['entitiesUpdated'];
			$allResults['usersCreated'] += $contactResults['usersCreated'] ?? 0;
			$allResults['usersUpdated'] += $contactResults['usersUpdated'] ?? 0;

			$roundTime = (time() - $roundStartTime);
			$allResults['roundsCompleted'][] = [
				'round' => $round,
				'organizationsProcessed' => $orgResults['organizationsProcessed'],
				'contactPersonsProcessed' => $contactResults['contactPersonsProcessed'],
				'duration' => $roundTime,
				'orgTimeoutReached' => $orgResults['timeoutReached'] ?? false,
				'contactTimeoutReached' => $contactResults['timeoutReached'] ?? false,
			];

			// If no items were processed in this round, we're done.
			if ($orgResults['organizationsProcessed'] === 0 && $contactResults['contactPersonsProcessed'] === 0) {
				$this->logger->info(
					'[MANUAL] OrganizationSyncService: No more items to process, stopping',
					[
						'round' => $round,
						'totalProcessed' => ($allResults['organizationsProcessed'] + $allResults['contactPersonsProcessed']),
					]
				);
				break;
			}

			$allResults['totalRounds'] = $round;

			// No sleep between rounds — this runs in an HTTP worker;
			// blocking the worker for up to 10 s degrades concurrency.
		}//end for

		// Final user sync.
		$this->performUserSync();
		$this->recordSyncTime();

		$allResults['totalExecutionTime'] = (time() - $totalStartTime);
		$allResults['endTime'] = date('Y-m-d H:i:s');

		$this->logger->info('[MANUAL] OrganizationSyncService: Optimized manual sync completed', $allResults);

		return $allResults;
	}//end performOptimizedManualSync()

	/**
	 * Performs scheduled synchronization with comprehensive logging
	 *
	 * This method is designed to be called by the background job and includes
	 * all necessary logging, error handling, and status tracking.
	 * Uses default full sync (0 minutes) to process all organizations.
	 *
	 * @param int $minutesBack Number of minutes to look back for changes (default: 0 = full sync)
	 *
	 * @return array Synchronization results with detailed logging information
	 * @spec   openspec/specs/organization-sync/spec.md
	 */
	public function performScheduledSync(int $minutesBack = 0): array {
		if ($minutesBack === 0) {
			$syncModeValue = 'full';
		} else {
			$syncModeValue = 'incremental';
		}

		$this->logger->info(
			'[CRONJOB] OrganizationSyncService: Starting scheduled synchronization',
			[
				'minutesBack' => $minutesBack,
				'syncMode' => $syncModeValue,
				'trigger' => 'cronjob',
			]
		);

		try {
			// Perform optimized batch synchronization.
			// Use smaller batches for scheduled sync to ensure it completes within time limits.
			$orgBatchSize = 25;
			// Conservative batch size for organizations.
			$contactBatchSize = 50;
			// Larger batch size for contacts (faster processing).
			$maxOrgTime = 30;
			// 30 seconds max for organizations.
			$maxContactTime = 15;
			// 15 seconds max for contacts.
			$syncResults = $this->performOrganizationsSync(batchSize: $orgBatchSize, maxExecutionSeconds: $maxOrgTime);

			$contactResults = $this->performContactSync(batchSize: $contactBatchSize, maxExecutionSeconds: $maxContactTime);
			$syncResults = array_merge($contactResults, $syncResults);

			$this->performUserSync();
			// Record the sync time.
			$this->recordSyncTime();

			// Log summary results.
			$this->logger->info(
				'[CRONJOB] OrganizationSyncService: Scheduled synchronization completed',
				[
					'organizationsProcessed' => $syncResults['organizationsProcessed'] ?? 0,
					'entitiesCreated' => $syncResults['entitiesCreated'] ?? 0,
					'entitiesUpdated' => $syncResults['entitiesUpdated'] ?? 0,
					'contactPersonsProcessed' => $syncResults['contactPersonsProcessed'] ?? 0,
					'errorCount' => count($syncResults['errors'] ?? []),
					'duration' => $syncResults['duration'] ?? 0,
				]
			);

			// Log errors if any occurred.
			if (empty($syncResults['errors']) === false) {
				$this->logger->warning(
					'OrganizationSyncService: Scheduled synchronization completed with errors',
					[
						'errors' => $syncResults['errors'],
					]
				);
			}

			return $syncResults;
		} catch (\Exception $e) {
			$this->logger->error(
				'[CRONJOB] OrganizationSyncService: Scheduled synchronization failed',
				[
					'exception' => $e->getMessage(),
					'file' => $e->getFile(),
					'line' => $e->getLine(),
					'trace' => $e->getTraceAsString(),
				]
			);

			return [
				'organizationsProcessed' => 0,
				'entitiesCreated' => 0,
				'entitiesUpdated' => 0,
				'contactPersonsProcessed' => 0,
				'usersCreated' => 0,
				'usersUpdated' => 0,
				'errors' => ['Scheduled synchronization failed: ' . $e->getMessage()],
				'startTime' => date('Y-m-d H:i:s'),
				'endTime' => date('Y-m-d H:i:s'),
				'duration' => '00:00:00',
			];
		}//end try

	}//end performScheduledSync()

	/**
	 * Performs manual synchronization with API-specific logging
	 *
	 * This method is designed to be called by the API controller and includes
	 * all necessary logging, error handling, and response formatting.
	 * Uses full sync (minutesBack = 0) for manual triggers by default.
	 *
	 * @param int $minutesBack Number of minutes to look back for changes (default: 0 for full sync)
	 *
	 * @return array Synchronization results formatted for API response
	 * @spec   openspec/specs/organization-sync/spec.md
	 */
	public function performManualSync(int $minutesBack = 0): array {
		if ($minutesBack === 0) {
			$syncModeValue = 'full';
		} else {
			$syncModeValue = 'incremental';
		}

		$this->logger->info(
			'OrganizationSyncService: Manual organization synchronization started via API',
			[
				'minutesBack' => $minutesBack,
				'syncMode' => $syncModeValue,
			]
		);

		try {
			$syncResults = $this->performOrganizationsSync();

			$syncResults = array_merge($this->performContactSync(), $syncResults);

			$this->performUserSync();

			// Record the sync time for consistency with scheduled sync.
			$this->recordSyncTime();

			$this->logger->info(
				'OrganizationSyncService: Manual organization synchronization completed via API',
				[
					'organizationsProcessed' => $syncResults['organizationsProcessed'],
					'entitiesCreated' => $syncResults['entitiesCreated'],
					'entitiesUpdated' => $syncResults['entitiesUpdated'],
					'usersCreated' => $syncResults['usersCreated'],
					'errorCount' => count($syncResults['errors']),
				]
			);

			return [
				'success' => true,
				'results' => $syncResults,
				'message' => 'Synchronization completed successfully',
			];
		} catch (\Exception $e) {
			$this->logger->error(
				'OrganizationSyncService: Manual organization synchronization failed via API',
				[
					'exception' => $e->getMessage(),
					'file' => $e->getFile(),
					'line' => $e->getLine(),
				]
			);

			return [
				'success' => false,
				'message' => 'Synchronization failed: ' . $e->getMessage(),
				'trace' => $e->getTraceAsString(),
			];
		}//end try

	}//end performManualSync()

	/**
	 * Gets synchronization status with error handling
	 *
	 * This method provides status information with proper error handling
	 * for API responses.
	 *
	 * @param int $minutesBack The number of minutes to look back.
	 *
	 * @return array Status information with error handling.
	 * @spec   openspec/specs/organization-sync/spec.md
	 */
	public function getSyncStatusWithErrorHandling(int $minutesBack = 10): array {
		try {
			$status = $this->getSyncStatus(minutesBack: $minutesBack);
			return $status;
		} catch (\Exception $e) {
			$this->logger->error(
				'OrganizationSyncService: Failed to get sync status',
				[
					'minutesBack' => $minutesBack,
					'exception' => $e->getMessage(),
				]
			);

			if ($minutesBack === 0) {
				$syncModeValue = 'full';
			} else {
				$syncModeValue = 'incremental';
			}

			return [
				'configured' => false,
				'syncMode' => $syncModeValue,
				'timeWindow' => $minutesBack,
				'message' => 'Error getting sync status: ' . $e->getMessage(),
			];
		}//end try

	}//end getSyncStatusWithErrorHandling()

	/**
	 * Updates the organisatie object's @self metadata to set owner to the organisation entity UUID
	 *
	 * @param object $organisationObject The organisatie object to update.
	 * @param object $organisationEntity The organisation entity.
	 * @param string $register The register ID.
	 * @param string $organizationSchema The organization schema ID.
	 *
	 * @return void
	 */
	private function updateOrganisationObjectOwner(
		object $organisationObject,
		object $organisationEntity,
		string $register,
		string $organizationSchema,
	): void {
		try {
			$organisationId = $organisationObject->getUuid();
			$organisationEntityUuid = $organisationEntity->getUuid();

			$this->logger->info(
				'OrganizationSyncService: Updating organisatie object owner and organisation',
				[
					'organisatieId' => $organisationId,
					'organisationEntityUuid' => $organisationEntityUuid,
					'register' => $register,
					'schema' => $organizationSchema,
				]
			);

			// Get the current object data.
			$currentObject = $organisationObject->jsonSerialize();

			// Get current @self metadata or create new.
			$selfMetadata = ($currentObject['@self'] ?? []);

			// Check if both owner and organisation are already set correctly.
			$ownerAlreadySet = ($selfMetadata['owner'] ?? null) === $organisationEntityUuid;
			$organisationAlreadySet = ($currentObject['organisation'] ?? null) === $organisationEntityUuid;

			if ($ownerAlreadySet !== false && $organisationAlreadySet === true) {
				$this->logger->debug(
					'OrganizationSyncService: Owner and organisation already set correctly, skipping update',
					[
						'organisatieId' => $organisationId,
						'organisationEntityUuid' => $organisationEntityUuid,
					]
				);
				return;
			}

			// Update the owner field in @self metadata to the organisation entity UUID.
			$selfMetadata['owner'] = $organisationEntityUuid;

			// Update the organisation field in @self metadata (so organisation owns itself).
			$selfMetadata['organisation'] = $organisationEntityUuid;

			// Update the organisation property to the organisation entity UUID (so organisation owns itself).
			$currentObject['organisation'] = $organisationEntityUuid;

			// Update the object with the new @self metadata.
			$currentObject['@self'] = $selfMetadata;
			$organisationObject->setObject($currentObject);

			// Also update the entity's owner and organisation fields directly.
			// These are separate from the object data and control multi-tenancy.
			$organisationObject->setOwner($organisationEntityUuid);
			$organisationObject->setOrganisation($organisationEntityUuid);

			// Save using MagicMapper directly to bypass validation and ensure metadata is persisted.
			// Published contract instead of OpenRegister's Db layer.
			$this->objectService->saveObject(
				object: array_merge(
					$organisationObject->getObject(),
					['@self' => ['organisation' => $organisationObject->getOrganisation()]]
				),
				register: $organisationObject->getRegister(),
				schema: $organisationObject->getSchema(),
				uuid: $organisationObject->getUuid(),
				silent: true,
				_validation: false
			);

			$this->logger->info(
				'OrganizationSyncService: Successfully updated organisatie object owner and organisation',
				[
					'organisatieId' => $organisationId,
					'organisationEntityUuid' => $organisationEntityUuid,
					'ownerSet' => $selfMetadata['owner'],
					'organisationSet' => $currentObject['organisation'],
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'OrganizationSyncService: Failed to update organisatie object owner and organisation',
				[
					'organisatieId' => $organisationObject->getUuid(),
					'organisationEntityUuid' => $organisationEntity->getUuid(),
					'exception' => $e->getMessage(),
					'file' => $e->getFile(),
					'line' => $e->getLine(),
				]
			);
		}//end try

	}//end updateOrganisatieObjectOwner()

	/**
	 * Make a user the owner of a contactpersoon object and set its organisation.
	 *
	 * @param object $contactObject The contactpersoon object to update.
	 * @param array  $owner         `userUID` (the new owner), `register` and `contactSchema` (logged) and the
	 *                              optional `organizationUuidOverride` (used before the object's own organisation).
	 *
	 * @return void
	 */
	private function updateContactPersonObjectOwner(object $contactObject, array $owner): void {
		try {
			$owner['contactId'] = $contactObject->getUuid();
			$owner['organizationUuidOverride'] = ($owner['organizationUuidOverride'] ?? null);

			$this->logger->info(
				'OrganizationSyncService: Updating contactpersoon object owner',
				[
					'contactId' => $owner['contactId'],
					'userUID' => $owner['userUID'],
					'register' => $owner['register'],
					'schema' => $owner['contactSchema'],
					'organizationUuidOverride' => $owner['organizationUuidOverride'],
				]
			);

			$selfMetadata = $this->stageContactOwner(contactObject: $contactObject, owner: $owner);

			// Save directly, bypassing validation, so the metadata is persisted.
			$this->persistContactObject(
				contactObject: $contactObject,
				object: array_merge(
					$contactObject->getObject(),
					['@self' => ['organisation' => $contactObject->getOrganisation()]]
				)
			);

			$this->logger->info(
				'OrganizationSyncService: Successfully updated contactpersoon object owner and organisation',
				[
					'contactId' => $owner['contactId'],
					'userUID' => $owner['userUID'],
					'ownerSet' => $selfMetadata['owner'],
					'organisationSet' => ($selfMetadata['organisation'] ?? 'not set'),
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'OrganizationSyncService: Failed to update contactpersoon object owner',
				[
					'contactId' => $contactObject->getUuid(),
					'userUID' => $owner['userUID'],
					'exception' => $e->getMessage(),
					'file' => $e->getFile(),
					'line' => $e->getLine(),
				]
			);
		}//end try

	}//end updateContactPersonObjectOwner()

	/**
	 * Set the owner and organisation on a contactpersoon entity: in its @self
	 * metadata, in its organisation field when that is empty, and on the entity.
	 *
	 * @param object $contactObject The contactpersoon object.
	 * @param array  $owner         `contactId`, `userUID` and `organizationUuidOverride`.
	 *
	 * @return array The @self metadata that was set.
	 */
	private function stageContactOwner(object $contactObject, array $owner): array {
		$currentObject = $contactObject->getObject();

		$selfMetadata = ($currentObject['@self'] ?? []);
		$selfMetadata['owner'] = $owner['userUID'];

		$organizationUuid = $this->ownerOrganisation(currentObject: $currentObject, owner: $owner);
		if (empty($organizationUuid) === false) {
			$selfMetadata['organisation'] = $organizationUuid;
		}

		// Restore the organisatie field if it was removed during username save.
		// This is the safety net that ensures the contact→org link is never lost.
		if (empty($organizationUuid) === false && empty($currentObject['organization']) === true) {
			$currentObject['organization'] = $organizationUuid;
			$this->logger->info(
				'OrganizationSyncService: Restored missing organisatie field on contact person',
				[
					'contactId' => $owner['contactId'],
					'organizationUuid' => $organizationUuid,
				]
			);
		}

		$currentObject['@self'] = $selfMetadata;
		$contactObject->setObject($currentObject);

		// The entity's owner and organisation fields control multi-tenancy.
		$contactObject->setOwner($owner['userUID']);
		if (empty($organizationUuid) === false) {
			$contactObject->setOrganisation($organizationUuid);
		}

		return $selfMetadata;

	}//end stageContactOwner()

	/**
	 * The organisation a contactpersoon's owner belongs to: the override, else
	 * the object's own organisation field.
	 *
	 * @param array $currentObject The contactpersoon object data.
	 * @param array $owner         `contactId` and `organizationUuidOverride`.
	 *
	 * @return mixed The organisation uuid, empty when there is none.
	 */
	private function ownerOrganisation(array $currentObject, array $owner): mixed {
		$orgUuid = $currentObject['organisation'] ?? $currentObject['organization'] ?? '';
		$organizationUuid = ($owner['organizationUuidOverride'] ?? $orgUuid);
		if (empty($organizationUuid) === false) {
			$sourceValue = 'object';
			if (empty($owner['organizationUuidOverride']) === false) {
				$sourceValue = 'override';
			}

			$this->logger->info(
				'OrganizationSyncService: Setting @self.organisation metadata',
				[
					'contactId' => $owner['contactId'],
					'organizationUuid' => $organizationUuid,
					'source' => $sourceValue,
				]
			);
		}

		return $organizationUuid;

	}//end ownerOrganisation()
}//end class
