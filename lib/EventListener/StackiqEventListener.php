<?php

/**
 * Stackiq Event Listener
 *
 * This file contains the listener class for handling events from OpenRegister
 * specific to the Stackiq application.
 *
 * @category  EventListener
 * @package   OCA\Stackiq\EventListener
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/integriq
 *
 * @spec openspec/specs/method-decomposition/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\EventListener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectLockedEvent;
use OCA\OpenRegister\Event\ObjectRevertedEvent;
use OCA\OpenRegister\Event\ObjectUnlockedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Stackiq\Service\ContactpersoonService;
use OCA\Stackiq\Service\GebruikSyncService;
use OCA\Stackiq\Service\SettingsService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Event listener for handling software catalog specific events.
 *
 * This listener handles organization, contact, and user (gebruiker) related events
 * in the software catalog, including user management, email notifications, and
 * user blocking/unblocking functionality.
 *
 * @category EventListener
 * @package  OCA\Stackiq\EventListener
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://github.com/ConductionNL/integriq
 * @todo     This listener should be moved to the software catalog app.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class StackiqEventListener implements IEventListener {
	/**
	 * Constructor for StackiqEventListener
	 *
	 * @param ContainerInterface $container DI container for lazy service resolution.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Handles events related to software catalog objects
	 *
	 * DISABLED: All processing is now handled by cron-based OrganizationSyncService
	 * to avoid race conditions and ensure consistent processing.
	 *
	 * @param Event $event The event to handle
	 *
	 * @return void
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function handle(Event $event): void {
		try {
			$logger = $this->container->get(LoggerInterface::class);
			$contactSvc = $this->container->get(ContactpersoonService::class);
			$settingsService = $this->container->get(SettingsService::class);

			$logger->info(
				'Stackiq: Processing event',
				[
					'eventType' => get_class($event),
					'timestamp' => date('Y-m-d H:i:s'),
				]
			);

			$this->dispatchEvent(
				event: $event,
				contactSvc: $contactSvc,
				settingsService: $settingsService,
				logger: $logger
			);
		} catch (\Exception $e) {
			try {
				$logger = $this->container->get(LoggerInterface::class);
				$logger->error(
					'Stackiq: Error in event handler',
					[
						'eventType' => get_class($event),
						'exception' => $e->getMessage(),
						'file' => $e->getFile(),
						'line' => $e->getLine(),
						'trace' => $e->getTraceAsString(),
					]
				);
			} catch (\Exception $logException) {
				// Silently fail if logging fails - better than breaking the event system.
			}
		}//end try
	}//end handle()

	/**
	 * Dispatches the supplied event to the matching per-lifecycle handler.
	 *
	 * Extracted from {@see handle()} as part of the task 6.1 decomposition so the
	 * outer method retains only the try/catch envelope and the logging shell.
	 *
	 * @param Event $event The event to dispatch.
	 * @param ContactpersoonService $contactSvc Contact-person service handle.
	 * @param SettingsService $settingsService Settings service handle.
	 * @param LoggerInterface $logger Logger handle.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-6
	 */
	private function dispatchEvent(
		Event $event,
		ContactpersoonService $contactSvc,
		SettingsService $settingsService,
		LoggerInterface $logger,
	): void {
		if ($event instanceof ObjectCreatedEvent) {
			$this->handleObjectCreated(
				event: $event,
				contactSvc: $contactSvc,
				settingsService: $settingsService,
				logger: $logger
			);
			return;
		}

		if ($event instanceof ObjectUpdatedEvent) {
			$this->handleObjectUpdated(
				event: $event,
				contactSvc: $contactSvc,
				settingsService: $settingsService,
				logger: $logger
			);
			return;
		}

		if ($event instanceof ObjectDeletedEvent) {
			$this->handleObjectDeleted(
				event: $event,
				contactSvc: $contactSvc,
				settingsService: $settingsService,
				logger: $logger
			);
			return;
		}

		if ($event instanceof ObjectLockedEvent
			|| $event instanceof ObjectUnlockedEvent
			|| $event instanceof ObjectRevertedEvent
		) {
			$logger->debug(
				'Stackiq: Ignoring object lifecycle event',
				[
					'eventType' => get_class($event),
				]
			);
		}
	}//end dispatchEvent()

	/**
	 * Resolves the catalog schema-id lookup table for the supplied settings service.
	 *
	 * Replaces the four inline `getSchemaIdForObjectType()` calls that previously
	 * lived at the top of each per-lifecycle handler. Returning a normalised
	 * `int|null` map keeps callers from having to repeat the `(int)` cast and the
	 * null guard.
	 *
	 * @param SettingsService $settingsService The settings service handle.
	 *
	 * @return array{organization:int|null, contactPerson:int|null, contactgegevens:int|null, usage:int|null}
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-6
	 */
	private function resolveCatalogSchemaIds(SettingsService $settingsService): array {
		$cast = static function ($raw): ?int {
			if ($raw === null || $raw === '') {
				return null;
			}

			return (int)$raw;
		};

		return [
			'organization' => $cast($settingsService->getSchemaIdForObjectType(objectType: 'organization')),
			'contactPerson' => $cast($settingsService->getSchemaIdForObjectType(objectType: 'contactPerson')),
			'contactgegevens' => $cast($settingsService->getSchemaIdForObjectType(objectType: 'contactgegevens')),
			'usage' => $cast($settingsService->getSchemaIdForObjectType(objectType: 'usage')),
		];
	}//end resolveCatalogSchemaIds()

	/**
	 * Returns true when the supplied status string is the active state (Dutch or English).
	 *
	 * @param string $status The status, lower-cased by caller.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-6
	 */
	private function isActiveStatus(string $status): bool {
		return in_array(needle: $status, haystack: ['actief', 'active'], strict: true) === true;
	}//end isActiveStatus()

	/**
	 * Returns true when the supplied object schema matches the configured catalog
	 * schema id (either side may be null).
	 *
	 * @param int $objectSchemaIdInt The object's schema id (already cast).
	 * @param int|null $configured The configured schema id from settings.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-6
	 */
	private function matchesSchema(int $objectSchemaIdInt, ?int $configured): bool {
		return $configured !== null && $objectSchemaIdInt === $configured;
	}//end matchesSchema()

	/**
	 * Runs OrganizationSyncService::processSpecificOrganization for the supplied
	 * object, capturing both the success log entry and the structured failure log.
	 *
	 * Extracted from the three lifecycle handlers as part of task 6.4 so the
	 * organisation-sync invocation no longer ships three near-identical try/catch
	 * blocks.
	 *
	 * @param \OCA\OpenRegister\Db\ObjectEntity $object The organisation object.
	 * @param string $phase "creation"/"update"/"deletion".
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-6
	 */
	private function runOrganizationSync(
		\OCA\OpenRegister\Db\ObjectEntity $object,
		string $phase,
		LoggerInterface $logger,
	): void {
		$objectId = $object->getUuid();
		try {
			$orgSyncService = $this->container->get('OCA\Stackiq\Service\OrganizationSyncService');
			$result = $orgSyncService->processSpecificOrganization($object);
			$logger->info(
				'Stackiq: Successfully processed organization ' . $phase,
				[
					'objectId' => $objectId,
					'processResult' => $result,
				]
			);
		} catch (\Exception $e) {
			$logger->error(
				'Stackiq: Failed to process organization ' . $phase,
				[
					'objectId' => $objectId,
					'exception' => $e->getMessage(),
					'file' => $e->getFile(),
					'line' => $e->getLine(),
				]
			);
		}//end try
	}//end runOrganizationSync()

	/**
	 * Runs GebruikSyncService::processSpecificGebruik for the supplied object,
	 * capturing success + failure logs.
	 *
	 * Extracted from the create/update lifecycle handlers as part of task 6.4
	 * so the gebruik-sync invocation no longer ships two near-identical
	 * try/catch blocks.
	 *
	 * @param \OCA\OpenRegister\Db\ObjectEntity $object The gebruik object.
	 * @param string $phase "creation"/"update".
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-6
	 */
	private function runGebruikSync(
		\OCA\OpenRegister\Db\ObjectEntity $object,
		string $phase,
		LoggerInterface $logger,
	): void {
		$objectId = $object->getUuid();
		try {
			$gebruikSyncService = $this->container->get(GebruikSyncService::class);
			$result = $gebruikSyncService->processSpecificGebruik($object);
			$logger->info(
				'Stackiq: Successfully processed gebruik ' . $phase,
				[
					'objectId' => $objectId,
					'processResult' => $result,
				]
			);
		} catch (\Exception $e) {
			$logger->error(
				'Stackiq: Failed to process gebruik ' . $phase,
				[
					'objectId' => $objectId,
					'exception' => $e->getMessage(),
					'file' => $e->getFile(),
					'line' => $e->getLine(),
				]
			);
		}//end try
	}//end runGebruikSync()

	/**
	 * Refetches an organisation by uuid, expanding contactpersonen so that
	 * the downstream OrganizationSyncService sees the full contact data.
	 *
	 * Returns null when the lookup fails so the caller can early-skip without
	 * tripping the sync helper. Extracted from {@see handleObjectUpdated()} as
	 * part of task 6.3 — the inline ObjectService::find + log block previously
	 * lived in the middle of the lifecycle handler.
	 *
	 * @param string $objectId The organisation uuid.
	 * @param SettingsService $settingsService Settings handle for the register/schema lookup.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return \OCA\OpenRegister\Db\ObjectEntity|null
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-6
	 */
	private function refetchOrganizationWithContactpersonen(
		string $objectId,
		SettingsService $settingsService,
		LoggerInterface $logger,
	): ?\OCA\OpenRegister\Db\ObjectEntity {
		try {
			$voorzieningenConfig = $settingsService->getVoorzieningenConfig();
			$register = $voorzieningenConfig['register'] ?? '';
			$organizationSchema = $voorzieningenConfig['organisatie_schema'] ?? '';

			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$orgWithContacts = $objectService->find(
				id: $objectId,
				register: $register,
				schema: $organizationSchema,
				_extend: ['contactpersonen'],
				_rbac: false,
				_multitenancy: false
			);

			$logger->info(
				'Stackiq: Refetched organization with contactpersonen',
				[
					'objectId' => $objectId,
					'contactpersonenCount' => count(
						$orgWithContacts->getObject()['contactpersonen'] ?? []
					),
				]
			);

			return $orgWithContacts;
		} catch (\Exception $e) {
			$logger->error(
				'Stackiq: Failed to refetch organization with contactpersonen',
				[
					'objectId' => $objectId,
					'exception' => $e->getMessage(),
					'file' => $e->getFile(),
					'line' => $e->getLine(),
				]
			);
			return null;
		}//end try
	}//end refetchOrganizationWithContactpersonen()

	/**
	 * Handles object creation events
	 *
	 * @param ObjectCreatedEvent $event The creation event
	 * @param ContactpersoonService $contactSvc The contact person service
	 * @param SettingsService $settingsService The settings service
	 * @param LoggerInterface $logger The logger instance
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	private function handleObjectCreated(
		ObjectCreatedEvent $event,
		ContactpersoonService $contactSvc,
		SettingsService $settingsService,
		LoggerInterface $logger,
	): void {
		$object = $event->getObject();
		if ($object === null) {
			$logger->warning('Stackiq: ObjectCreatedEvent received with null object');
			return;
		}

		$objectSchemaId = $object->getSchema();
		$objectId = $object->getUuid();
		$objectRegisterId = $object->getRegister();

		// Convert schema ID to integer for consistent comparison.
		$objectSchemaIdInt = (int)$objectSchemaId;

		$logger->info(
			'Stackiq: Processing object creation',
			[
				'objectId' => $objectId,
				'schemaId' => $objectSchemaId,
				'schemaIdInt' => $objectSchemaIdInt,
				'registerId' => $objectRegisterId,
				'objectData' => json_encode($object->getObject()),
			]
		);

		// Get configuration for different object types.
		$catalogSchemaIds = $this->resolveCatalogSchemaIds(settingsService: $settingsService);
		$organisationSchemaId = $catalogSchemaIds['organization'];
		$contactSchemaId = $catalogSchemaIds['contactPerson'];
		$contactInfoSchemaId = $catalogSchemaIds['contactgegevens'];
		$gebruikSchemaId = $catalogSchemaIds['usage'];

		$logger->debug(
			'Stackiq: Configuration lookup results',
			[
				'organisatieSchemaId' => $organisationSchemaId,
				'contactpersoonSchemaId' => $contactSchemaId,
				'contactgegevensSchemaId' => $contactInfoSchemaId,
				'gebruikSchemaId' => $gebruikSchemaId,
				'objectSchemaId' => $objectSchemaIdInt,
			]
		);

		// Check if this is an organization object.
		if ($this->matchesSchema(objectSchemaIdInt: $objectSchemaIdInt, configured: $organisationSchemaId) === true) {
			$objectData = $object->getObject();
			$status = strtolower($objectData['status'] ?? '');

			// Only process active organizations.
			if ($this->isActiveStatus(status: $status) === false) {
				$logger->debug(
					'Stackiq: Skipping non-active organization creation',
					[
						'objectId' => $objectId,
						'status' => $status,
					]
				);
				return;
			}

			$logger->info(
				'Stackiq: Processing active organization creation',
				[
					'objectId' => $objectId,
					'status' => $status,
				]
			);

			$this->runOrganizationSync(object: $object, phase: 'creation', logger: $logger);
			return;
		}//end if

		// Check if this is a contactpersoon object.
		if ($this->matchesSchema(objectSchemaIdInt: $objectSchemaIdInt, configured: $contactSchemaId) === true) {
			$logger->info('Stackiq: Processing contactpersoon creation', ['objectId' => $objectId]);
			$contactSvc->processContactpersoon($object);
			return;
		}

		// Check if this is a contactgegevens object (deprecated - use contactpersoon instead).
		if ($this->matchesSchema(objectSchemaIdInt: $objectSchemaIdInt, configured: $contactInfoSchemaId) === true) {
			$logger->info('Stackiq: Processing contactgegevens creation (deprecated)', ['objectId' => $objectId]);
			// Contactgegevens is deprecated, use contactpersoon instead.
			return;
		}

		// Check if this is a gebruik object.
		if ($this->matchesSchema(objectSchemaIdInt: $objectSchemaIdInt, configured: $gebruikSchemaId) === true) {
			$logger->info('Stackiq: Processing gebruik creation', ['objectId' => $objectId]);
			$this->runGebruikSync(object: $object, phase: 'creation', logger: $logger);
			return;
		}//end if

		// Log unhandled object types.
		$logger->debug(
			'Stackiq: Object creation not handled - not a supported object type',
			[
				'objectId' => $objectId,
				'schemaId' => $objectSchemaIdInt,
				'registerId' => $objectRegisterId,
				'supportedSchemas' => [
					'organization' => $organisationSchemaId,
					'contactPerson' => $contactSchemaId,
					'contactgegevens' => $contactInfoSchemaId,
					'usage' => $gebruikSchemaId,
				],
			]
		);
	}//end handleObjectCreated()

	/**
	 * Handles object update events
	 *
	 * @param ObjectUpdatedEvent $event The update event
	 * @param ContactpersoonService $contactSvc The contact person service
	 * @param SettingsService $settingsService The settings service
	 * @param LoggerInterface $logger The logger instance
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	private function handleObjectUpdated(
		ObjectUpdatedEvent $event,
		ContactpersoonService $contactSvc,
		SettingsService $settingsService,
		LoggerInterface $logger,
	): void {
		$object = $event->getNewObject();
		$oldObject = $event->getOldObject();

		if ($object === null) {
			$logger->warning('Stackiq: ObjectUpdatedEvent received with null object');
			return;
		}

		$objectSchemaId = $object->getSchema();
		$objectId = $object->getUuid();
		$objectRegisterId = $object->getRegister();

		// Convert schema ID to integer for consistent comparison.
		$objectSchemaIdInt = (int)$objectSchemaId;

		$logger->info(
			'Stackiq: Processing object update',
			[
				'objectId' => $objectId,
				'schemaId' => $objectSchemaId,
				'schemaIdInt' => $objectSchemaIdInt,
				'registerId' => $objectRegisterId,
				'hasOldObject' => $oldObject !== null,
			]
		);

		// Check if this is an organization update.
		$organisationSchemaId = $settingsService->getSchemaIdForObjectType(objectType: 'organization');
		$orgSchemaIdInt = (int)$organisationSchemaId;

		$logger->debug(
			'Got organisation schema ID',
			[
				'app' => 'stackiq',
				'organisatieSchemaId' => $organisationSchemaId,
				'organisatieSchemaIdInt' => $orgSchemaIdInt,
			]
		);

		$logger->debug(
			'Organization schema check',
			[
				'app' => 'stackiq',
				'objectSchemaId' => $objectSchemaId,
				'objectSchemaIdInt' => $objectSchemaIdInt,
				'organisatieSchemaId' => $organisationSchemaId,
				'organisatieSchemaIdInt' => $orgSchemaIdInt,
				'matches' => ($objectSchemaIdInt === $orgSchemaIdInt),
			]
		);

		if ($organisationSchemaId !== null && $objectSchemaIdInt === $orgSchemaIdInt) {
			$objectData = $object->getObject();
			$status = strtolower($objectData['status'] ?? '');

			$oldStatus = '';
			if ($oldObject !== null) {
				$oldData = $oldObject->getObject();
				$oldStatus = strtolower($oldData['status'] ?? '');
			}

			$logger->debug(
				'Organization status check',
				[
					'app' => 'stackiq',
					'objectId' => $objectId,
					'status' => $status,
					'oldStatus' => $oldStatus,
					'statusChanged' => ($status !== $oldStatus),
					'isActief' => $this->isActiveStatus(status: $status),
					'willProcess' => ($this->isActiveStatus(status: $status) === true
						&& $status !== $oldStatus),
				]
			);

			// Only process active organizations.
			if ($this->isActiveStatus(status: $status) === true && $status !== $oldStatus) {
				$logger->info(
					'Stackiq: Processing active organization update',
					[
						'objectId' => $objectId,
						'status' => $status,
						'schemaId' => $objectSchemaId,
					]
				);

				$orgWithContacts = $this->refetchOrganizationWithContactpersonen(
					objectId: $objectId,
					settingsService: $settingsService,
					logger: $logger
				);
				if ($orgWithContacts !== null) {
					$this->runOrganizationSync(object: $orgWithContacts, phase: 'update', logger: $logger);
				}
			}//end if

			if ($this->isActiveStatus(status: $status) === false || $status === $oldStatus) {
				$logger->debug(
					'Stackiq: Skipping non-active organization update',
					[
						'objectId' => $objectId,
						'status' => $status,
						'schemaId' => $objectSchemaId,
					]
				);
			}

			return;
		}//end if

		// Handle contactpersoon updates.
		$contactSchemaId = $settingsService->getSchemaIdForObjectType(objectType: 'contactPerson');
		$cntSchemaIdInt = (int)$contactSchemaId;

		if ($contactSchemaId !== null && $objectSchemaIdInt === $cntSchemaIdInt) {
			$logger->info(
				'Stackiq: Matched contactpersoon schema - processing update',
				[
					'objectId' => $objectId,
					'schemaId' => $objectSchemaId,
					'configuredSchemaId' => $contactSchemaId,
				]
			);

			try {
				$contactSvc->handleContactpersoonUpdate(
					contactPersonObject: $object,
					oldContactPersonObject: $oldObject
				);

				$logger->info(
					'Stackiq: Successfully processed contactpersoon update',
					[
						'objectId' => $objectId,
						'timestamp' => date('Y-m-d H:i:s'),
					]
				);
			} catch (\Exception $e) {
				$logger->error(
					'Stackiq: Failed to process contactpersoon update',
					[
						'objectId' => $objectId,
						'exception' => $e->getMessage(),
						'file' => $e->getFile(),
						'line' => $e->getLine(),
						'trace' => $e->getTraceAsString(),
					]
				);
			}//end try

			return;
		}//end if

		// Handle contactgegevens updates (backward compatibility).
		$contactInfoSchemaId = $settingsService->getSchemaIdForObjectType(objectType: 'contactgegevens');
		$infoSchemaIdInt = (int)$contactInfoSchemaId;

		if ($contactInfoSchemaId !== null && $objectSchemaIdInt === $infoSchemaIdInt) {
			$logger->info(
				'Stackiq: Matched contactgegevens schema - processing update (backward compatibility)',
				[
					'objectId' => $objectId,
					'schemaId' => $objectSchemaId,
					'configuredSchemaId' => $contactInfoSchemaId,
				]
			);

			try {
				// Handle contactgegevens as contactpersoon (backward compatibility).
				$contactSvc->handleContactpersoonUpdate(
					contactPersonObject: $object,
					oldContactPersonObject: $oldObject
				);

				$logger->info(
					'Stackiq: Successfully processed contactgegevens update (as contactpersoon)',
					[
						'objectId' => $objectId,
						'timestamp' => date('Y-m-d H:i:s'),
					]
				);
			} catch (\Exception $e) {
				$logger->error(
					'Stackiq: Failed to process contactgegevens update',
					[
						'objectId' => $objectId,
						'exception' => $e->getMessage(),
						'file' => $e->getFile(),
						'line' => $e->getLine(),
						'trace' => $e->getTraceAsString(),
					]
				);
			}//end try

			return;
		}//end if

		// Handle gebruik updates.
		$gebruikSchemaId = $settingsService->getSchemaIdForObjectType(objectType: 'usage');
		$gebruikSchemaIdInt = (int)$gebruikSchemaId;

		if ($gebruikSchemaId !== null && $objectSchemaIdInt === $gebruikSchemaIdInt) {
			$logger->info(
				'Stackiq: Matched gebruik schema - processing update',
				[
					'objectId' => $objectId,
					'schemaId' => $objectSchemaId,
					'configuredSchemaId' => $gebruikSchemaId,
				]
			);

			$this->runGebruikSync(object: $object, phase: 'update', logger: $logger);
			return;
		}//end if

		// Log if we don't handle this schema type.
		$logger->debug(
			'Stackiq: Object update not handled - focusing only on organisatie, contactpersonen, and gebruik',
			[
				'objectId' => $objectId,
				'schemaId' => $objectSchemaId,
				'schemaIdInt' => $objectSchemaIdInt,
				'schemaIdType' => gettype($objectSchemaId),
				'registerId' => $objectRegisterId,
				'handledSchemas' => [
					'organization' => $organisationSchemaId,
					'contactPerson' => $contactSchemaId,
					'contactgegevens' => $contactInfoSchemaId,
					'usage' => $gebruikSchemaId,
				],
			]
		);
	}//end handleObjectUpdated()

	/**
	 * Handles object deletion events
	 *
	 * @param ObjectDeletedEvent $event The deletion event
	 * @param ContactpersoonService $contactSvc The contact person service
	 * @param SettingsService $settingsService The settings service
	 * @param LoggerInterface $logger The logger instance
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	private function handleObjectDeleted(
		ObjectDeletedEvent $event,
		ContactpersoonService $contactSvc,
		SettingsService $settingsService,
		LoggerInterface $logger,
	): void {
		$object = $event->getObject();
		if ($object === null) {
			$logger->warning('Stackiq: ObjectDeletedEvent received with null object');
			return;
		}

		$objectSchemaId = $object->getSchema();
		$objectId = $object->getUuid();
		$objectRegisterId = $object->getRegister();

		$logger->info(
			'Stackiq: Processing object deletion',
			[
				'objectId' => $objectId,
				'schemaId' => $objectSchemaId,
				'registerId' => $objectRegisterId,
				'objectData' => $object->getObject(),
			]
		);

		// Check if this is an organization deletion.
		$organisationSchemaId = $settingsService->getSchemaIdForObjectType(objectType: 'organization');
		$orgSchemaIdInt = (int)$organisationSchemaId;
		$objectSchemaIdInt = (int)$objectSchemaId;

		if ($organisationSchemaId !== null && $objectSchemaIdInt === $orgSchemaIdInt) {
			$logger->info('Stackiq: Processing organization deletion', ['objectId' => $objectId]);
			$this->runOrganizationSync(object: $object, phase: 'deletion', logger: $logger);
			return;
		}//end if

		// Handle contactpersoon deletion.
		$contactSchemaId = $settingsService->getSchemaIdForObjectType(objectType: 'contactPerson');
		$cntSchemaIdInt = (int)$contactSchemaId;

		if ($contactSchemaId !== null && $objectSchemaIdInt === $cntSchemaIdInt) {
			$logger->info(
				'Stackiq: Matched contactpersoon schema - processing deletion',
				[
					'objectId' => $objectId,
					'schemaId' => $objectSchemaId,
					'configuredSchemaId' => $contactSchemaId,
				]
			);

			try {
				$contactSvc->handleContactDeletion($object);

				$logger->info(
					'Stackiq: Successfully processed contactpersoon deletion',
					[
						'objectId' => $objectId,
						'timestamp' => date('Y-m-d H:i:s'),
					]
				);
			} catch (\Exception $e) {
				$logger->error(
					'Stackiq: Failed to process contactpersoon deletion',
					[
						'objectId' => $objectId,
						'exception' => $e->getMessage(),
						'file' => $e->getFile(),
						'line' => $e->getLine(),
						'trace' => $e->getTraceAsString(),
					]
				);
			}//end try

			return;
		}//end if

		// Handle contactgegevens deletion (backward compatibility).
		$contactInfoSchemaId = $settingsService->getSchemaIdForObjectType(objectType: 'contactgegevens');
		$infoSchemaIdInt = (int)$contactInfoSchemaId;

		if ($contactInfoSchemaId !== null && $objectSchemaIdInt === $infoSchemaIdInt) {
			$logger->info(
				'Stackiq: Matched contactgegevens schema - processing deletion (backward compatibility)',
				[
					'objectId' => $objectId,
					'schemaId' => $objectSchemaId,
					'configuredSchemaId' => $contactInfoSchemaId,
				]
			);

			try {
				$contactSvc->handleContactDeletion($object);

				$logger->info(
					'Stackiq: Successfully processed contactgegevens deletion',
					[
						'objectId' => $objectId,
						'timestamp' => date('Y-m-d H:i:s'),
					]
				);
			} catch (\Exception $e) {
				$logger->error(
					'Stackiq: Failed to process contactgegevens deletion',
					[
						'objectId' => $objectId,
						'exception' => $e->getMessage(),
						'file' => $e->getFile(),
						'line' => $e->getLine(),
						'trace' => $e->getTraceAsString(),
					]
				);
			}//end try

			return;
		}//end if

		// Handle gebruik deletion.
		$gebruikSchemaId = $settingsService->getSchemaIdForObjectType(objectType: 'usage');
		$gebruikSchemaIdInt = (int)$gebruikSchemaId;

		if ($gebruikSchemaId !== null && $objectSchemaIdInt === $gebruikSchemaIdInt) {
			$objectData = $object->getObject();

			$logger->info(
				'Stackiq: Matched gebruik schema - processing deletion',
				[
					'objectId' => $objectId,
					'schemaId' => $objectSchemaId,
					'configuredSchemaId' => $gebruikSchemaId,
					'consumer' => $objectData['consumer']['name'] ?? 'Unknown',
					'product' => $objectData['product']['name'] ?? 'Unknown',
				]
			);

			// For deletions, we mainly log the event since the object is being removed.
			// No specific cleanup needed for gebruik objects currently.
			$logger->info(
				'Stackiq: Gebruik object deleted - no specific cleanup required',
				[
					'objectId' => $objectId,
					'timestamp' => date('Y-m-d H:i:s'),
				]
			);
			return;
		}//end if

		// Log if we don't handle this schema type.
		$logger->debug(
			'Stackiq: Object deletion not handled - focusing only on organisatie, contactpersonen, and gebruik',
			[
				'objectId' => $objectId,
				'schemaId' => $objectSchemaId,
				'registerId' => $objectRegisterId,
				'handledSchemas' => [
					'organization' => $organisationSchemaId,
					'contactPerson' => $contactSchemaId,
					'contactgegevens' => $contactInfoSchemaId,
					'usage' => $gebruikSchemaId,
				],
			]
		);
	}//end handleObjectDeleted()
}//end class
