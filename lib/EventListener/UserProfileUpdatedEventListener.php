<?php

/**
 * UserProfileUpdatedEvent Listener
 *
 * Listens for user profile updates from OpenRegister and syncs
 * the changed fields back to the corresponding contactpersoon object.
 *
 * @category  EventListener
 * @package   OCA\Stackiq\EventListener
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/method-decomposition/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\EventListener;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Event\UserProfileUpdatedEvent;
use OCA\Stackiq\Service\SettingsService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

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
	 * @param ContainerInterface $container DI container for lazy service resolution.
	 * @param ObjectServiceInterface $objectService OpenRegister object access (ADR-084 contract).
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
		$sync = $this->startContactPersonSync(event: $event, logger: $logger);
		if ($sync === null) {
			return;
		}

		$contactPerson = $this->findContactPerson(sync: $sync);
		if ($contactPerson === null) {
			$this->logNoContactPerson(sync: $sync);
			return;
		}

		$this->patchContactPerson(sync: $sync, contactPerson: $contactPerson);
	}//end syncToContactpersoon()

	/**
	 * Start a sync from the voorzieningen configuration, or stop when its register or schema is missing.
	 *
	 * @param UserProfileUpdatedEvent $event The profile updated event.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return ProfileContactSync|null The sync, or null when the configuration is incomplete.
	 */
	private function startContactPersonSync(UserProfileUpdatedEvent $event, LoggerInterface $logger): ?ProfileContactSync {
		$settingsService = $this->container->get(SettingsService::class);

		$sync = new ProfileContactSync();
		$sync->voorzieningenConfig = $settingsService->getVoorzieningenConfig();
		if (empty($sync->register()) === true || empty($sync->schema()) === true) {
			$logger->warning(
				'[UserProfileUpdatedEventListener] Voorzieningen config missing register or contactpersoon_schema'
			);
			return null;
		}

		$sync->event = $event;
		$sync->logger = $logger;
		$sync->userId = $event->getUserId();
		return $sync;
	}//end startContactPersonSync()

	/**
	 * Log that no contactpersoon matches the user.
	 *
	 * @param ProfileContactSync $sync The sync.
	 *
	 * @return void
	 */
	private function logNoContactPerson(ProfileContactSync $sync): void {
		$sync->logger->info(
			'[UserProfileUpdatedEventListener] No contactpersoon found for user',
			[
				'userId' => $sync->userId,
				'register' => $sync->register(),
				'schema' => $sync->schema(),
			]
		);
	}//end logNoContactPerson()

	/**
	 * Patch the changed profile fields onto the contactpersoon, when there are any.
	 *
	 * @param ProfileContactSync $sync The sync.
	 * @param object $contactPerson The contactpersoon entity.
	 *
	 * @return void
	 */
	private function patchContactPerson(ProfileContactSync $sync, object $contactPerson): void {
		$sync->contactData = $contactPerson->getObject();
		$sync->patch = $this->buildContactPatch(
			event:       $sync->event,
			userId:      $sync->userId,
			contactData: $sync->contactData,
			contactPersonId: $contactPerson->getUuid(),
			logger:      $sync->logger
		);

		if (empty($sync->patch) === true) {
			$this->logNothingToPatch(sync: $sync);
			return;
		}

		$this->storeContactPatch(sync: $sync, contactPerson: $contactPerson);
	}//end patchContactPerson()

	/**
	 * Log that the contactpersoon has no fields to patch.
	 *
	 * @param ProfileContactSync $sync The sync.
	 *
	 * @return void
	 */
	private function logNothingToPatch(ProfileContactSync $sync): void {
		$sync->logger->debug(
			'[UserProfileUpdatedEventListener] No fields to patch on contactpersoon',
			[
				'userId' => $sync->userId,
			]
		);
	}//end logNothingToPatch()

	/**
	 * Store the patch merged into the full contactpersoon payload.
	 *
	 * @param ProfileContactSync $sync The sync, with the contact data and the patch.
	 * @param object $contactPerson The contactpersoon entity.
	 *
	 * @return void
	 */
	private function storeContactPatch(ProfileContactSync $sync, object $contactPerson): void {
		$this->logContactPatch(sync: $sync, contactPerson: $contactPerson);

		// Merge the patch into the existing payload. `saveObject()` is
		// PUT-semantic, so the FULL payload must be carried forward, not only
		// the changed keys. Validation is skipped on the save because schema
		// validation can reject pre-existing legacy values (e.g. notificaties
		// enum) that this listener never touched.
		$mergedObject = array_merge($sync->contactData, $sync->patch);

		$this->persistContactPersonPatch(
			contactPerson: $contactPerson,
			object:        $mergedObject
		);

		$this->logContactPatched(sync: $sync, contactPerson: $contactPerson);
	}//end storeContactPatch()

	/**
	 * Log the patch about to be written.
	 *
	 * @param ProfileContactSync $sync The sync, with the patch.
	 * @param object $contactPerson The contactpersoon entity.
	 *
	 * @return void
	 */
	private function logContactPatch(ProfileContactSync $sync, object $contactPerson): void {
		$sync->logger->info(
			'[UserProfileUpdatedEventListener] Patching contactpersoon object',
			[
				'userId' => $sync->userId,
				'contactpersoonId' => $contactPerson->getUuid(),
				'patch' => $sync->patch,
			]
		);
	}//end logContactPatch()

	/**
	 * Log the patch written.
	 *
	 * @param ProfileContactSync $sync The sync, with the patch.
	 * @param object $contactPerson The contactpersoon entity.
	 *
	 * @return void
	 */
	private function logContactPatched(ProfileContactSync $sync, object $contactPerson): void {
		$sync->logger->info(
			'[UserProfileUpdatedEventListener] Successfully synced user profile to contactpersoon',
			[
				'userId' => $sync->userId,
				'contactpersoonId' => $contactPerson->getUuid(),
				'patchedFields' => array_keys($sync->patch),
			]
		);
	}//end logContactPatched()

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
	 * Persist the patched contactpersoon payload through the published
	 * OpenRegister contract.
	 *
	 * `_name` metadata is NOT regenerated here. This method used to load the
	 * schema through `SchemaMapper` and call
	 * `MetadataHydrationHandler::hydrateObjectMetadata()` itself — both of them
	 * OpenRegister internals that ADR-084's published contract deliberately does
	 * not expose, and neither of which a leaf app can load in its own unit
	 * tests. It was also redundant: `ObjectService::saveObject()` calls
	 * `hydrateObjectMetadata()` on both its create and its update path before
	 * handing the entity to `objectEntityMapper`, so the hydration happens
	 * exactly once either way — it was simply being done twice, one layer too
	 * deep.
	 *
	 * @param object $contactPerson The contactpersoon entity (read for its coordinates).
	 * @param array $object The full merged payload to store (PUT-semantic).
	 *
	 * @return void
	 */
	private function persistContactPersonPatch(
		object $contactPerson,
		array $object,
	): void {
		$this->objectService->saveObject(
			object: $object,
			register: $contactPerson->getRegister(),
			schema: $contactPerson->getSchema(),
			uuid: $contactPerson->getUuid(),
			silent: true,
			_validation: false
		);

	}//end persistContactPersonPatch()

	/**
	 * Find a contactpersoon by username, falling back to a case-insensitive email search.
	 *
	 * @param ProfileContactSync $sync The sync.
	 *
	 * @return object|null The contactpersoon entity or null if not found.
	 */
	private function findContactPerson(ProfileContactSync $sync): ?object {
		$contactPerson = $this->findContactPersonByUsername(sync: $sync);
		if ($contactPerson !== null) {
			return $contactPerson;
		}

		return $this->findContactPersonByEmail(sync: $sync);
	}//end findContactpersoon()

	/**
	 * Search the contactpersoon whose username is the user id.
	 *
	 * @param ProfileContactSync $sync The sync.
	 *
	 * @return object|null The first contactpersoon found, or null.
	 */
	private function findContactPersonByUsername(ProfileContactSync $sync): ?object {
		// 1. Search by username = userId, scoped to the user's organisation (multitenancy).
		// This prevents updating a contactpersoon from a different organisation when.
		// Multiple records share the same username across orgs.
		$results = $this->objectService->searchObjects(
			query: ['@self' => $sync->selfQuery(), 'username' => $sync->userId, '_limit' => 5],
			_rbac: false,
			_multitenancy: true
		);

		if (empty($results) === true) {
			return null;
		}

		if (is_array($results) === true) {
			return reset($results);
		}

		return $results;
	}//end findContactPersonByUsername()

	/**
	 * Search the contactpersoon by e-mail: the user's address first, then the user id.
	 *
	 * @param ProfileContactSync $sync The sync.
	 *
	 * @return object|null The first contactpersoon whose stored address matches, or null.
	 */
	private function findContactPersonByEmail(ProfileContactSync $sync): ?object {
		// 2. Fallback: case-insensitive email search using _search (ILIKE).
		foreach ($this->emailCandidates(sync: $sync) as $emailCandidate) {
			$contactPerson = $this->findContactPersonWithEmail(sync: $sync, emailCandidate: $emailCandidate);
			if ($contactPerson !== null) {
				return $contactPerson;
			}
		}

		return null;
	}//end findContactPersonByEmail()

	/**
	 * The addresses to try: the user's email first, then the userId (which may itself be an email).
	 *
	 * @param ProfileContactSync $sync The sync.
	 *
	 * @return array<int, string> The distinct, non-empty candidates.
	 */
	private function emailCandidates(ProfileContactSync $sync): array {
		return array_filter(
			array_unique(
				[
					$sync->event->getUser()->getEMailAddress(),
					$sync->userId,
				]
			)
		);
	}//end emailCandidates()

	/**
	 * Search the contactpersoon whose stored address is an e-mail candidate.
	 *
	 * @param ProfileContactSync $sync The sync.
	 * @param string $emailCandidate The address to search for.
	 *
	 * @return object|null The first contactpersoon whose stored address matches, or null.
	 */
	private function findContactPersonWithEmail(ProfileContactSync $sync, string $emailCandidate): ?object {
		$this->logEmailFallback(sync: $sync, emailCandidate: $emailCandidate);

		// Use _search for case-insensitive matching, then verify the email field in PHP.
		// Scoped to user's organisation via multitenancy to avoid cross-org matches.
		$results = $this->objectService->searchObjects(
			query: ['@self' => $sync->selfQuery(), '_search' => $emailCandidate, '_limit' => 5],
			_rbac: false,
			_multitenancy: true
		);

		if (is_array($results) === false) {
			return null;
		}

		return self::withStoredEmail(results: $results, emailCandidate: $emailCandidate);
	}//end findContactPersonWithEmail()

	/**
	 * Log that the username lookup failed and an e-mail candidate is tried.
	 *
	 * @param ProfileContactSync $sync The sync.
	 * @param string $emailCandidate The address tried.
	 *
	 * @return void
	 */
	private function logEmailFallback(ProfileContactSync $sync, string $emailCandidate): void {
		$sync->logger->debug(
			'[UserProfileUpdatedEventListener] Username lookup failed, trying email fallback',
			[
				'userId' => $sync->userId,
				'emailCandidate' => $emailCandidate,
			]
		);
	}//end logEmailFallback()

	/**
	 * The first result whose stored `e-mailadres` (else `email`) equals the candidate, case-insensitively.
	 *
	 * @param array $results The search results.
	 * @param string $emailCandidate The address searched for.
	 *
	 * @return object|null The matching contactpersoon, or null.
	 */
	private static function withStoredEmail(array $results, string $emailCandidate): ?object {
		foreach ($results as $result) {
			$data = $result->getObject();
			$storedEmail = $data['e-mailadres'] ?? $data['email'] ?? '';
			if (strcasecmp($storedEmail, $emailCandidate) === 0) {
				return $result;
			}
		}

		return null;
	}//end withStoredEmail()
}//end class
