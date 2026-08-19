<?php

/**
 * UserProfileUpdatedEvent Listener
 *
 * Listens for user profile updates from OpenRegister and syncs
 * the changed fields back to the corresponding contactpersoon object.
 *
 * @category  EventListener
 * @package   OCA\SoftwareCatalog\EventListener
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/method-decomposition/spec.md
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\EventListener;

use OCA\OpenRegister\Event\UserProfileUpdatedEvent;
use OCA\SoftwareCatalog\Service\SettingsService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Syncs user profile changes to the corresponding contactpersoon object.
 *
 * When a user updates their profile via /api/user/me, this listener finds
 * the matching contactpersoon (by username field) and updates the relevant
 * fields: voornaam, tussenvoegsel, achternaam, functie, e-mailadres.
 *
 * @template T of Event
 *
 * @implements IEventListener<T>
 */
class UserProfileUpdatedEventListener implements IEventListener {
	/**
	 * Field mapping from user profile keys to contactpersoon keys.
	 */
	private const FIELD_MAP = [
		'firstName' => 'voornaam',
		'middleName' => 'tussenvoegsel',
		'lastName' => 'achternaam',
		'role' => 'role',
		'email' => 'e-mailadres',
	];

	/**
	 * Constructor for UserProfileUpdatedEventListener.
	 *
	 * @param ContainerInterface     $container     DI container for lazy service resolution.
	 * @param ObjectServiceInterface $objectService OpenRegister's published data-access contract (ADR-084).
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Handle the UserProfileUpdatedEvent.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof UserProfileUpdatedEvent === false) {
			return;
		}

		try {
			$logger = $this->container->get(LoggerInterface::class);
			$changes = $event->getChanges();

			// Check if any of the mapped fields were changed.
			$relevantChanges = array_intersect($changes, array_keys(self::FIELD_MAP));
			if (empty($relevantChanges) === true) {
				$logger->debug(
					'[UserProfileUpdatedEventListener] No relevant field changes for contactpersoon sync',
					[
						'userId' => $event->getUserId(),
						'changes' => $changes,
					]
				);
				return;
			}

			$logger->info(
				'[UserProfileUpdatedEventListener] Syncing user profile changes to contactpersoon',
				[
					'userId' => $event->getUserId(),
					'relevantChanges' => $relevantChanges,
				]
			);

			$this->syncToContactPerson(event: $event, logger: $logger);
		} catch (\Exception $e) {
			try {
				$logger = $this->container->get(LoggerInterface::class);
				$logger->error(
					'[UserProfileUpdatedEventListener] Error syncing profile to contactpersoon',
					[
						'userId' => $event->getUserId(),
						'exception' => $e->getMessage(),
						'file' => $e->getFile(),
						'line' => $e->getLine(),
					]
				);
			} catch (\Exception $logException) {
				// Silently fail if logging fails.
			}
		}//end try
	}//end handle()

	/**
	 * Find the contactpersoon object by username (with email fallback) and update its fields.
	 *
	 * @param UserProfileUpdatedEvent $event The profile updated event.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	private function syncToContactPerson(UserProfileUpdatedEvent $event, LoggerInterface $logger): void {
		$settingsService = $this->container->get(SettingsService::class);

		$voorzieningenConfig = $settingsService->getVoorzieningenConfig();
		$register = $voorzieningenConfig['register'] ?? '';
		$contactPersonSchema = $voorzieningenConfig['contactpersoon_schema'] ?? '';

		if (empty($register) === true || empty($contactPersonSchema) === true) {
			$logger->warning(
				'[UserProfileUpdatedEventListener] Voorzieningen config missing register or contactpersoon_schema'
			);
			return;
		}

		$userId = $event->getUserId();
		$selfQuery = [
			'register' => (int)$register,
			'schema' => (int)$contactPersonSchema,
		];

		$contactPerson = $this->findContactPerson(
			objectService: $this->objectService,
			selfQuery: $selfQuery,
			userId: $userId,
			event: $event,
			logger: $logger
		);

		if ($contactPerson === null) {
			$logger->info(
				'[UserProfileUpdatedEventListener] No contactpersoon found for user',
				[
					'userId' => $userId,
					'register' => $register,
					'schema' => $contactPersonSchema,
				]
			);
			return;
		}

		$contactData = $contactPerson->getObject();
		$patch = $this->buildContactPatch(
			event:       $event,
			userId:      $userId,
			contactData: $contactData,
			contactPersonId: $contactPerson->getUuid(),
			logger:      $logger
		);

		if (empty($patch) === true) {
			$logger->debug(
				'[UserProfileUpdatedEventListener] No fields to patch on contactpersoon',
				[
					'userId' => $userId,
				]
			);
			return;
		}

		$logger->info(
			'[UserProfileUpdatedEventListener] Patching contactpersoon object',
			[
				'userId' => $userId,
				'contactpersoonId' => $contactPerson->getUuid(),
				'patch' => $patch,
			]
		);

		// Merge the patch into existing data and save without schema validation.
		// Schema validation can reject existing data with legacy values (e.g. notificaties enum).
		//
		// The merged data is PASSED to the save rather than pushed back onto the
		// entity first: ObjectEntityInterface is read-only by design (ADR-084), and
		// mutating-then-reading-back was only ever a way of smuggling the payload
		// across two statements.
		$this->persistContactPersonPatch(
			contactPerson: $contactPerson,
			objectData:    array_merge($contactData, $patch),
			logger:        $logger
		);

		$logger->info(
			'[UserProfileUpdatedEventListener] Successfully synced user profile to contactpersoon',
			[
				'userId' => $userId,
				'contactpersoonId' => $contactPerson->getUuid(),
				'patchedFields' => array_keys($patch),
			]
		);
	}//end syncToContactpersoon()

	/**
	 * Build the contactpersoon patch from a profile-updated event.
	 *
	 * Includes only fields that changed (via FIELD_MAP) and a username
	 * backfill when the contactpersoon was found via the email fallback.
	 *
	 * @param UserProfileUpdatedEvent $event The dispatched event.
	 * @param string $userId The Nextcloud user id.
	 * @param array $contactData The current contactpersoon data.
	 * @param string $contactPersonId The contactpersoon UUID (for logging).
	 * @param LoggerInterface $logger Logger for the backfill notice.
	 *
	 * @return array<string,mixed> The patch (may be empty).
	 */
	private function buildContactPatch(
		UserProfileUpdatedEvent $event,
		string $userId,
		array $contactData,
		string $contactPersonId,
		LoggerInterface $logger,
	): array {
		$newData = $event->getNewData();
		$changes = $event->getChanges();

		$patch = [];
		foreach (self::FIELD_MAP as $userField => $contactField) {
			if (in_array($userField, $changes, true) === false) {
				continue;
			}

			$newValue = $newData[$userField] ?? null;
			$patch[$contactField] = $newValue ?? '';
		}

		if (empty($contactData['username']) === true) {
			$patch['username'] = $userId;
			$logger->info(
				'[UserProfileUpdatedEventListener] Backfilling username on contactpersoon',
				[
					'userId' => $userId,
					'contactpersoonId' => $contactPersonId,
				]
			);
		}

		return $patch;
	}//end buildContactPatch()

	/**
	 * Persist the patched contactpersoon entity.
	 *
	 * @param object          $contactPerson The contactpersoon entity being patched.
	 * @param array           $objectData    The merged object body to persist.
	 * @param LoggerInterface $logger        Logger for persistence warnings.
	 *
	 * @return void
	 */
	private function persistContactPersonPatch(
		object $contactPerson,
		array $objectData,
		LoggerInterface $logger,
	): void {
		// Persist through the published contract rather than OpenRegister's Db
		// layer. The comment this replaces worried that a plain save would touch
		// "just the blob table" — it does not: ObjectService::saveObject() calls
		// metaHydrationHandler->hydrateObjectMetadata() and then
		// objectEntityMapper->update(entity:, register:, schema:), which IS the
		// magic-mapper route. This listener was hand-rolling OpenRegister's own
		// save pipeline, one layer too deep.
		//
		// That is also why the hand-rolled `_name` hydration that used to run here
		// is gone rather than merely unreachable: OpenRegister calls
		// hydrateObjectMetadata() itself from prepareObjectForCreation() and
		// prepareObjectForUpdate() (SaveObject.php:3692 and :3873), so doing it
		// again in the leaf app re-derived the same value one layer too deep — and
		// it reached MetadataHydrationHandler, an OpenRegister INTERNAL that is not
		// on the published contract and that no leaf app can load in its own tests.
		$this->objectService->saveObject(
			object: $objectData,
			register: $contactPerson->getRegister(),
			schema: $contactPerson->getSchema(),
			uuid: $contactPerson->getUuid(),
			silent: true,
			_validation: false
		);

		$logger->debug(
			'[UserProfileUpdatedEventListener] Persisted contactpersoon patch',
			[
				'contactpersoonId' => $contactPerson->getUuid(),
			]
		);

	}//end persistContactpersoonPatch()

	/**
	 * Find a contactpersoon by username, falling back to a case-insensitive email search.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param array $selfQuery The @self register/schema filter.
	 * @param string $userId The Nextcloud user ID.
	 * @param UserProfileUpdatedEvent $event The profile updated event.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return object|null The contactpersoon entity or null if not found.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 */
	private function findContactPerson(
		object $objectService,
		array $selfQuery,
		string $userId,
		UserProfileUpdatedEvent $event,
		LoggerInterface $logger,
	): ?object {
		// 1. Search by username = userId, scoped to the user's organisation (multitenancy).
		// This prevents updating a contactpersoon from a different organisation when.
		// Multiple records share the same username across orgs.
		$results = $objectService->searchObjects(
			query: ['@self' => $selfQuery, 'username' => $userId, '_limit' => 5],
			_rbac: false,
			_multitenancy: true
		);

		if (empty($results) === false && (is_array($results) === false || count($results) > 0)) {
			if (is_array($results) === true) {
				return reset($results);
			}

			return $results;
		}

		// 2. Fallback: case-insensitive email search using _search (ILIKE).
		// Try the user's email first, then the userId (which may itself be an email).
		$emailCandidates = array_filter(
			array_unique(
				[
					$event->getUser()->getEMailAddress(),
					$userId,
				]
			)
		);

		foreach ($emailCandidates as $emailCandidate) {
			if (empty($emailCandidate) === true) {
				continue;
			}

			$logger->debug(
				'[UserProfileUpdatedEventListener] Username lookup failed, trying email fallback',
				[
					'userId' => $userId,
					'emailCandidate' => $emailCandidate,
				]
			);

			// Use _search for case-insensitive matching, then verify the email field in PHP.
			// Scoped to user's organisation via multitenancy to avoid cross-org matches.
			$results = $objectService->searchObjects(
				query: ['@self' => $selfQuery, '_search' => $emailCandidate, '_limit' => 5],
				_rbac: false,
				_multitenancy: true
			);

			if (is_array($results) === true) {
				foreach ($results as $result) {
					$data = $result->getObject();
					$storedEmail = $data['e-mailadres'] ?? $data['email'] ?? '';
					if (strcasecmp($storedEmail, $emailCandidate) === 0) {
						return $result;
					}
				}
			}
		}//end foreach

		return null;
	}//end findContactpersoon()
}//end class
