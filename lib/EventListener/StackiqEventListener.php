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
	 * The log lines for a contact person (or contactgegevens) update or deletion:
	 * matched, done and failed.
	 */
	private const CONTACT_MESSAGES = [
		'update'   => [
			'contactPerson'   => [
				'Stackiq: Matched contactpersoon schema - processing update',
				'Stackiq: Successfully processed contactpersoon update',
				'Stackiq: Failed to process contactpersoon update',
			],
			'contactgegevens' => [
				'Stackiq: Matched contactgegevens schema - processing update (backward compatibility)',
				'Stackiq: Successfully processed contactgegevens update (as contactpersoon)',
				'Stackiq: Failed to process contactgegevens update',
			],
		],
		'deletion' => [
			'contactPerson'   => [
				'Stackiq: Matched contactpersoon schema - processing deletion',
				'Stackiq: Successfully processed contactpersoon deletion',
				'Stackiq: Failed to process contactpersoon deletion',
			],
			'contactgegevens' => [
				'Stackiq: Matched contactgegevens schema - processing deletion (backward compatibility)',
				'Stackiq: Successfully processed contactgegevens deletion',
				'Stackiq: Failed to process contactgegevens deletion',
			],
		],
	];

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
				services: ['contactSvc' => $contactSvc, 'settings' => $settingsService, 'logger' => $logger]
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
	 * @param Event $event The event to dispatch.
	 * @param array<string, mixed> $services The contactSvc, settings and logger services.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-6
	 */
	private function dispatchEvent(Event $event, array $services): void {
		if ($event instanceof ObjectCreatedEvent) {
			$this->handleObjectCreated(event: $event, services: $services);
			return;
		}

		if ($event instanceof ObjectUpdatedEvent) {
			$this->handleObjectUpdated(event: $event, services: $services);
			return;
		}

		if ($event instanceof ObjectDeletedEvent) {
			$this->handleObjectDeleted(event: $event, services: $services);
			return;
		}

		if ($event instanceof ObjectLockedEvent
			|| $event instanceof ObjectUnlockedEvent
			|| $event instanceof ObjectRevertedEvent
		) {
			$services['logger']->debug(
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
	 * The facts every handler reads off an object, with the services.
	 *
	 * @param \OCA\OpenRegister\Db\ObjectEntity $object The event's object.
	 * @param array<string, mixed> $services The contactSvc, settings and logger services.
	 *
	 * @return array<string, mixed> The object, its id, schema (raw and as int) and register, and the services.
	 */
	private function objectContext(\OCA\OpenRegister\Db\ObjectEntity $object, array $services): array {
		$schemaId = $object->getSchema();
		return $services + [
			'object'      => $object,
			'objectId'    => $object->getUuid(),
			'schemaId'    => $schemaId,
			'schemaIdInt' => (int)$schemaId,
			'registerId'  => $object->getRegister(),
		];
	}//end objectContext()

	/**
	 * Look the catalog schemas up one by one, until one matches the object's schema.
	 *
	 * @param array<string, mixed> $ctx   The object context.
	 * @param array<int, string>   $kinds The catalog object types, in lookup order.
	 *
	 * @return array{kind: string|null, ids: array<string, int|null>} The matching type and the ids looked up.
	 */
	private function firstMatchingSchema(array $ctx, array $kinds): array {
		$ids = [];
		foreach ($kinds as $kind) {
			$ids[$kind] = $ctx['settings']->getSchemaIdForObjectType(objectType: $kind);
			if ($this->matchesSchema(objectSchemaIdInt: $ctx['schemaIdInt'], configured: $ids[$kind]) === true) {
				return ['kind' => $kind, 'ids' => $ids];
			}
		}

		return ['kind' => null, 'ids' => $ids];
	}//end firstMatchingSchema()

	/**
	 * Handles object creation events
	 *
	 * @param ObjectCreatedEvent $event The creation event
	 * @param array<string, mixed> $services The contactSvc, settings and logger services.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	private function handleObjectCreated(ObjectCreatedEvent $event, array $services): void {
		$object = $event->getObject();
		if ($object === null) {
			$services['logger']->warning('Stackiq: ObjectCreatedEvent received with null object');
			return;
		}

		$ctx    = $this->objectContext(object: $object, services: $services);
		$logger = $ctx['logger'];
		$logger->info(
			'Stackiq: Processing object creation',
			[
				'objectId' => $ctx['objectId'],
				'schemaId' => $ctx['schemaId'],
				'schemaIdInt' => $ctx['schemaIdInt'],
				'registerId' => $ctx['registerId'],
				'objectData' => json_encode($object->getObject()),
			]
		);

		$ids = $this->resolveCatalogSchemaIds(settingsService: $ctx['settings']);
		$logger->debug(
			'Stackiq: Configuration lookup results',
			[
				'organisatieSchemaId' => $ids['organization'],
				'contactpersoonSchemaId' => $ids['contactPerson'],
				'contactgegevensSchemaId' => $ids['contactgegevens'],
				'gebruikSchemaId' => $ids['usage'],
				'objectSchemaId' => $ctx['schemaIdInt'],
			]
		);

		$kind = null;
		foreach ($ids as $type => $configured) {
			if ($kind === null && $this->matchesSchema(objectSchemaIdInt: $ctx['schemaIdInt'], configured: $configured) === true) {
				$kind = $type;
			}
		}

		match ($kind) {
			'organization' => $this->onOrganisationCreated(ctx: $ctx),
			'contactPerson' => $this->onContactPersonCreated(ctx: $ctx),
			'contactgegevens' => $logger->info('Stackiq: Processing contactgegevens creation (deprecated)', ['objectId' => $ctx['objectId']]),
			'usage' => $this->onUsageCreated(ctx: $ctx),
			default => $logger->debug(
				'Stackiq: Object creation not handled - not a supported object type',
				[
					'objectId' => $ctx['objectId'],
					'schemaId' => $ctx['schemaIdInt'],
					'registerId' => $ctx['registerId'],
					'supportedSchemas' => $ids,
				]
			),
		};
	}//end handleObjectCreated()

	/**
	 * A created organisation is synced when it is active.
	 *
	 * @param array<string, mixed> $ctx The object context.
	 *
	 * @return void
	 */
	private function onOrganisationCreated(array $ctx): void {
		$status = strtolower($ctx['object']->getObject()['status'] ?? '');
		if ($this->isActiveStatus(status: $status) === false) {
			$ctx['logger']->debug(
				'Stackiq: Skipping non-active organization creation',
				[
					'objectId' => $ctx['objectId'],
					'status' => $status,
				]
			);
			return;
		}

		$ctx['logger']->info(
			'Stackiq: Processing active organization creation',
			[
				'objectId' => $ctx['objectId'],
				'status' => $status,
			]
		);

		$this->runOrganizationSync(object: $ctx['object'], phase: 'creation', logger: $ctx['logger']);
	}//end onOrganisationCreated()

	/**
	 * A created contact person gets processed.
	 *
	 * @param array<string, mixed> $ctx The object context.
	 *
	 * @return void
	 */
	private function onContactPersonCreated(array $ctx): void {
		$ctx['logger']->info('Stackiq: Processing contactpersoon creation', ['objectId' => $ctx['objectId']]);
		$ctx['contactSvc']->processContactpersoon($ctx['object']);
	}//end onContactPersonCreated()

	/**
	 * A created usage gets synced.
	 *
	 * @param array<string, mixed> $ctx The object context.
	 *
	 * @return void
	 */
	private function onUsageCreated(array $ctx): void {
		$ctx['logger']->info('Stackiq: Processing gebruik creation', ['objectId' => $ctx['objectId']]);
		$this->runGebruikSync(object: $ctx['object'], phase: 'creation', logger: $ctx['logger']);
	}//end onUsageCreated()

	/**
	 * Handles object update events
	 *
	 * @param ObjectUpdatedEvent $event The update event
	 * @param array<string, mixed> $services The contactSvc, settings and logger services.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	private function handleObjectUpdated(ObjectUpdatedEvent $event, array $services): void {
		$object = $event->getNewObject();
		if ($object === null) {
			$services['logger']->warning('Stackiq: ObjectUpdatedEvent received with null object');
			return;
		}

		$ctx = $this->objectContext(object: $object, services: $services) + ['oldObject' => $event->getOldObject()];
		$ctx['logger']->info(
			'Stackiq: Processing object update',
			[
				'objectId' => $ctx['objectId'],
				'schemaId' => $ctx['schemaId'],
				'schemaIdInt' => $ctx['schemaIdInt'],
				'registerId' => $ctx['registerId'],
				'hasOldObject' => $ctx['oldObject'] !== null,
			]
		);

		if ($this->isOrganisationUpdate(ctx: $ctx) === true) {
			$this->onOrganisationUpdated(ctx: $ctx);
			return;
		}

		$match = $this->firstMatchingSchema(ctx: $ctx, kinds: ['contactPerson', 'contactgegevens', 'usage']);
		if ($match['kind'] === 'contactPerson' || $match['kind'] === 'contactgegevens') {
			$this->runContactAction(ctx: $ctx, action: ['phase' => 'update', 'kind' => $match['kind'], 'configured' => $match['ids'][$match['kind']]]);
			return;
		}

		if ($match['kind'] === 'usage') {
			$ctx['logger']->info(
				'Stackiq: Matched gebruik schema - processing update',
				[
					'objectId' => $ctx['objectId'],
					'schemaId' => $ctx['schemaId'],
					'configuredSchemaId' => $match['ids']['usage'],
				]
			);

			$this->runGebruikSync(object: $object, phase: 'update', logger: $ctx['logger']);
			return;
		}

		$ctx['logger']->debug(
			'Stackiq: Object update not handled - focusing only on organisatie, contactpersonen, and gebruik',
			[
				'objectId' => $ctx['objectId'],
				'schemaId' => $ctx['schemaId'],
				'schemaIdInt' => $ctx['schemaIdInt'],
				'schemaIdType' => gettype($ctx['schemaId']),
				'registerId' => $ctx['registerId'],
				'handledSchemas' => ['organization' => $ctx['organisationSchemaId']] + $match['ids'],
			]
		);
	}//end handleObjectUpdated()

	/**
	 * Whether an updated object is an organisation; logs the schema check.
	 *
	 * @param array<string, mixed> $ctx The object context; gets the organisation schema id.
	 *
	 * @return bool True when the object's schema is the organisation schema.
	 */
	private function isOrganisationUpdate(array &$ctx): bool {
		$organisationSchemaId        = $ctx['settings']->getSchemaIdForObjectType(objectType: 'organization');
		$ctx['organisationSchemaId'] = $organisationSchemaId;
		$orgSchemaIdInt = (int)$organisationSchemaId;

		$ctx['logger']->debug(
			'Got organisation schema ID',
			[
				'app' => 'stackiq',
				'organisatieSchemaId' => $organisationSchemaId,
				'organisatieSchemaIdInt' => $orgSchemaIdInt,
			]
		);

		$ctx['logger']->debug(
			'Organization schema check',
			[
				'app' => 'stackiq',
				'objectSchemaId' => $ctx['schemaId'],
				'objectSchemaIdInt' => $ctx['schemaIdInt'],
				'organisatieSchemaId' => $organisationSchemaId,
				'organisatieSchemaIdInt' => $orgSchemaIdInt,
				'matches' => ($ctx['schemaIdInt'] === $orgSchemaIdInt),
			]
		);

		return $this->matchesSchema(objectSchemaIdInt: $ctx['schemaIdInt'], configured: $organisationSchemaId);
	}//end isOrganisationUpdate()

	/**
	 * An updated organisation that just became active is refetched with its contact persons and synced.
	 *
	 * @param array<string, mixed> $ctx The object context, with the old object.
	 *
	 * @return void
	 */
	private function onOrganisationUpdated(array $ctx): void {
		$status    = strtolower($ctx['object']->getObject()['status'] ?? '');
		$oldStatus = '';
		if ($ctx['oldObject'] !== null) {
			$oldStatus = strtolower($ctx['oldObject']->getObject()['status'] ?? '');
		}

		$becameActive = $this->isActiveStatus(status: $status) === true && $status !== $oldStatus;
		$ctx['logger']->debug(
			'Organization status check',
			[
				'app' => 'stackiq',
				'objectId' => $ctx['objectId'],
				'status' => $status,
				'oldStatus' => $oldStatus,
				'statusChanged' => ($status !== $oldStatus),
				'isActief' => $this->isActiveStatus(status: $status),
				'willProcess' => $becameActive,
			]
		);

		$facts = [
			'objectId' => $ctx['objectId'],
			'status' => $status,
			'schemaId' => $ctx['schemaId'],
		];
		if ($becameActive === false) {
			$ctx['logger']->debug('Stackiq: Skipping non-active organization update', $facts);
			return;
		}

		$ctx['logger']->info('Stackiq: Processing active organization update', $facts);
		$orgWithContacts = $this->refetchOrganizationWithContactpersonen(
			objectId: $ctx['objectId'],
			settingsService: $ctx['settings'],
			logger: $ctx['logger']
		);
		if ($orgWithContacts !== null) {
			$this->runOrganizationSync(object: $orgWithContacts, phase: 'update', logger: $ctx['logger']);
		}
	}//end onOrganisationUpdated()

	/**
	 * Hand an updated or deleted contact person (or contactgegevens) to the contact person service.
	 *
	 * @param array<string, mixed>                                         $ctx    The object context.
	 * @param array{phase: string, kind: string, configured: int|null} $action The phase, the matched type and its configured schema id.
	 *
	 * @return void
	 */
	private function runContactAction(array $ctx, array $action): void {
		[$matched, $done, $failed] = self::CONTACT_MESSAGES[$action['phase']][$action['kind']];
		$ctx['logger']->info(
			$matched,
			[
				'objectId' => $ctx['objectId'],
				'schemaId' => $ctx['schemaId'],
				'configuredSchemaId' => $action['configured'],
			]
		);

		$call = fn () => $ctx['contactSvc']->handleContactDeletion($ctx['object']);
		if ($action['phase'] === 'update') {
			$call = fn () => $ctx['contactSvc']->handleContactpersoonUpdate(
				contactPersonObject: $ctx['object'],
				oldContactPersonObject: $ctx['oldObject']
			);
		}

		try {
			$call();

			$ctx['logger']->info(
				$done,
				[
					'objectId' => $ctx['objectId'],
					'timestamp' => date('Y-m-d H:i:s'),
				]
			);
		} catch (\Exception $e) {
			$ctx['logger']->error(
				$failed,
				[
					'objectId' => $ctx['objectId'],
					'exception' => $e->getMessage(),
					'file' => $e->getFile(),
					'line' => $e->getLine(),
					'trace' => $e->getTraceAsString(),
				]
			);
		}//end try
	}//end runContactAction()

	/**
	 * Handles object deletion events
	 *
	 * @param ObjectDeletedEvent $event The deletion event
	 * @param array<string, mixed> $services The contactSvc, settings and logger services.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	private function handleObjectDeleted(ObjectDeletedEvent $event, array $services): void {
		$object = $event->getObject();
		if ($object === null) {
			$services['logger']->warning('Stackiq: ObjectDeletedEvent received with null object');
			return;
		}

		$ctx = $this->objectContext(object: $object, services: $services);
		$ctx['logger']->info(
			'Stackiq: Processing object deletion',
			[
				'objectId' => $ctx['objectId'],
				'schemaId' => $ctx['schemaId'],
				'registerId' => $ctx['registerId'],
				'objectData' => $object->getObject(),
			]
		);

		$match = $this->firstMatchingSchema(ctx: $ctx, kinds: ['organization', 'contactPerson', 'contactgegevens', 'usage']);
		match ($match['kind']) {
			'organization' => $this->onOrganisationDeleted(ctx: $ctx),
			'contactPerson', 'contactgegevens' => $this->runContactAction(
				ctx: $ctx,
				action: ['phase' => 'deletion', 'kind' => $match['kind'], 'configured' => $match['ids'][$match['kind']]]
			),
			'usage' => $this->onUsageDeleted(ctx: $ctx, configured: $match['ids']['usage']),
			default => $ctx['logger']->debug(
				'Stackiq: Object deletion not handled - focusing only on organisatie, contactpersonen, and gebruik',
				[
					'objectId' => $ctx['objectId'],
					'schemaId' => $ctx['schemaId'],
					'registerId' => $ctx['registerId'],
					'handledSchemas' => $match['ids'],
				]
			),
		};
	}//end handleObjectDeleted()

	/**
	 * A deleted organisation gets synced.
	 *
	 * @param array<string, mixed> $ctx The object context.
	 *
	 * @return void
	 */
	private function onOrganisationDeleted(array $ctx): void {
		$ctx['logger']->info('Stackiq: Processing organization deletion', ['objectId' => $ctx['objectId']]);
		$this->runOrganizationSync(object: $ctx['object'], phase: 'deletion', logger: $ctx['logger']);
	}//end onOrganisationDeleted()

	/**
	 * A deleted usage is only logged; it needs no clean-up.
	 *
	 * @param array<string, mixed> $ctx        The object context.
	 * @param int|null             $configured The configured usage schema id.
	 *
	 * @return void
	 */
	private function onUsageDeleted(array $ctx, ?int $configured): void {
		$objectData = $ctx['object']->getObject();
		$ctx['logger']->info(
			'Stackiq: Matched gebruik schema - processing deletion',
			[
				'objectId' => $ctx['objectId'],
				'schemaId' => $ctx['schemaId'],
				'configuredSchemaId' => $configured,
				'consumer' => $objectData['consumer']['name'] ?? 'Unknown',
				'product' => $objectData['product']['name'] ?? 'Unknown',
			]
		);

		$ctx['logger']->info(
			'Stackiq: Gebruik object deleted - no specific cleanup required',
			[
				'objectId' => $ctx['objectId'],
				'timestamp' => date('Y-m-d H:i:s'),
			]
		);
	}//end onUsageDeleted()
}//end class
