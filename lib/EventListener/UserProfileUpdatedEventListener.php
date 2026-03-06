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
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\EventListener;

use OCA\OpenRegister\Event\UserProfileUpdatedEvent;
use OCA\SoftwareCatalog\Service\SettingsService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Syncs user profile changes to the corresponding contactpersoon object.
 *
 * When a user updates their profile via /api/user/me, this listener finds
 * the matching contactpersoon (by username field) and updates the relevant
 * fields: voornaam, tussenvoegsel, achternaam, functie, e-mailadres.
 *
 * @implements IEventListener<UserProfileUpdatedEvent>
 */
class UserProfileUpdatedEventListener implements IEventListener
{
    /**
     * Field mapping from user profile keys to contactpersoon keys.
     */
    private const FIELD_MAP = [
        'firstName'  => 'voornaam',
        'middleName' => 'tussenvoegsel',
        'lastName'   => 'achternaam',
        'functie'    => 'functie',
        'email'      => 'e-mailadres',
    ];

    /**
     * Constructor for UserProfileUpdatedEventListener.
     */
    public function __construct()
    {
    }//end __construct()

    /**
     * Handle the UserProfileUpdatedEvent.
     *
     * @param Event $event The dispatched event.
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        if ($event instanceof UserProfileUpdatedEvent === false) {
            return;
        }

        try {
            $logger  = \OC::$server->get(LoggerInterface::class);
            $changes = $event->getChanges();

            // Check if any of the mapped fields were changed.
            $relevantChanges = array_intersect($changes, array_keys(self::FIELD_MAP));
            if (empty($relevantChanges) === true) {
                $logger->debug(
                        '[UserProfileUpdatedEventListener] No relevant field changes for contactpersoon sync',
                        [
                            'userId'  => $event->getUserId(),
                            'changes' => $changes,
                        ]
                        );
                return;
            }

            $logger->info(
                    '[UserProfileUpdatedEventListener] Syncing user profile changes to contactpersoon',
                    [
                        'userId'          => $event->getUserId(),
                        'relevantChanges' => $relevantChanges,
                    ]
                    );

            $this->syncToContactpersoon(event: $event, logger: $logger);
        } catch (\Exception $e) {
            try {
                $logger = \OC::$server->get(LoggerInterface::class);
                $logger->error(
                        '[UserProfileUpdatedEventListener] Error syncing profile to contactpersoon',
                        [
                            'userId'    => $event->getUserId(),
                            'exception' => $e->getMessage(),
                            'file'      => $e->getFile(),
                            'line'      => $e->getLine(),
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
     * @param UserProfileUpdatedEvent $event  The profile updated event.
     * @param LoggerInterface         $logger The logger.
     *
     * @return void
     */
    private function syncToContactpersoon(UserProfileUpdatedEvent $event, LoggerInterface $logger): void
    {
        $objectService   = \OC::$server->get('OCA\OpenRegister\Service\ObjectService');
        $settingsService = \OC::$server->get(SettingsService::class);

        // Get the voorzieningen config for register and schema.
        $voorzieningenConfig = $settingsService->getVoorzieningenConfig();
        $register            = $voorzieningenConfig['register'] ?? '';
        $contactpersoonSchema = $voorzieningenConfig['contactpersoon_schema'] ?? '';

        if (empty($register) === true || empty($contactpersoonSchema) === true) {
            $logger->warning(
                '[UserProfileUpdatedEventListener] Voorzieningen config missing register or contactpersoon_schema'
            );
            return;
        }

        $userId    = $event->getUserId();
        $selfQuery = [
            'register' => (int) $register,
            'schema'   => (int) $contactpersoonSchema,
        ];

        $contactpersoon = $this->findContactpersoon(
            objectService: $objectService,
            selfQuery: $selfQuery,
            userId: $userId,
            event: $event,
            logger: $logger
        );

        if ($contactpersoon === null) {
            $logger->info(
                    '[UserProfileUpdatedEventListener] No contactpersoon found for user',
                    [
                        'userId'   => $userId,
                        'register' => $register,
                        'schema'   => $contactpersoonSchema,
                    ]
                    );
            return;
        }

        $contactData = $contactpersoon->getObject();
        $newData     = $event->getNewData();
        $changes     = $event->getChanges();

        // Build the patch with only changed fields.
        $patch = [];
        foreach (self::FIELD_MAP as $userField => $contactField) {
            if (in_array($userField, $changes, true) === false) {
                continue;
            }

            $newValue = $newData[$userField] ?? null;
            $patch[$contactField] = $newValue ?? '';
        }

        // Backfill the username field if it was missing (found via email fallback).
        if (empty($contactData['username']) === true) {
            $patch['username'] = $userId;
            $logger->info(
                    '[UserProfileUpdatedEventListener] Backfilling username on contactpersoon',
                    [
                        'userId'           => $userId,
                        'contactpersoonId' => $contactpersoon->getUuid(),
                    ]
                    );
        }

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
                    'userId'           => $userId,
                    'contactpersoonId' => $contactpersoon->getUuid(),
                    'patch'            => $patch,
                ]
                );

        // Merge the patch into existing data and save directly via mapper to skip schema validation.
        // Schema validation can reject existing data with legacy values (e.g. notificaties enum).
        $mergedObject = array_merge($contactData, $patch);
        $contactpersoon->setObject($mergedObject);

        // Regenerate _name metadata from the schema's objectNameField template.
        // (e.g. "{{ voornaam }} {{ tussenvoegsel }} {{ achternaam }}").
        // Without this, _name stays stale after field updates because we bypass the full saveObject flow.
        $schemaMapper         = \OC::$server->get('OCA\OpenRegister\Db\SchemaMapper');
        $registerMapper       = \OC::$server->get('OCA\OpenRegister\Db\RegisterMapper');
        $metaHydrationHandler = \OC::$server->get('OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler');

        $schemaEntity   = null;
        $registerEntity = null;
        try {
            $schemaEntity   = $schemaMapper->find(
                id: (int) $contactpersoonSchema,
                _rbac: false,
                _multitenancy: false
            );
            $registerEntity = $registerMapper->find(
                id: (int) $register,
                _rbac: false,
                _multitenancy: false
            );
        } catch (\Exception $e) {
            $logger->warning(
                    '[UserProfileUpdatedEventListener] Could not load schema/register entities for _name hydration',
                    [
                        'error' => $e->getMessage(),
                    ]
                    );
        }

        if ($schemaEntity !== null) {
            $metaHydrationHandler->hydrateObjectMetadata(entity: $contactpersoon, schema: $schemaEntity);
            $logger->debug(
                    '[UserProfileUpdatedEventListener] Regenerated _name metadata',
                    [
                        'newName' => $contactpersoon->getName(),
                    ]
                    );
        }

        // Pass register and schema so the magic mapper route is triggered and the.
        // Per-schema magic table is updated (not just the blob table).
        $objectMapper = \OC::$server->get('OCA\OpenRegister\Db\ObjectEntityMapper');
        $objectMapper->update(entity: $contactpersoon, register: $registerEntity, schema: $schemaEntity);

        $logger->info(
                '[UserProfileUpdatedEventListener] Successfully synced user profile to contactpersoon',
                [
                    'userId'           => $userId,
                    'contactpersoonId' => $contactpersoon->getUuid(),
                    'patchedFields'    => array_keys($patch),
                ]
                );
    }//end syncToContactpersoon()

    /**
     * Find a contactpersoon by username, falling back to a case-insensitive email search.
     *
     * @param object                  $objectService The OpenRegister ObjectService.
     * @param array                   $selfQuery     The @self register/schema filter.
     * @param string                  $userId        The Nextcloud user ID.
     * @param UserProfileUpdatedEvent $event         The profile updated event.
     * @param LoggerInterface         $logger        The logger.
     *
     * @return object|null The contactpersoon entity or null if not found.
     */
    private function findContactpersoon(
        object $objectService,
        array $selfQuery,
        string $userId,
        UserProfileUpdatedEvent $event,
        LoggerInterface $logger
    ): ?object {
        // 1. Search by username = userId, scoped to the user's organisation (multitenancy).
        // This prevents updating a contactpersoon from a different organisation when.
        // Multiple records share the same username across orgs.
        $results = $objectService->searchObjects(
            query: ['@self' => $selfQuery, 'username' => $userId],
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
                        'userId'         => $userId,
                        'emailCandidate' => $emailCandidate,
                    ]
                    );

            // Use _search for case-insensitive matching, then verify the email field in PHP.
            // Scoped to user's organisation via multitenancy to avoid cross-org matches.
            $results = $objectService->searchObjects(
                query: ['@self' => $selfQuery, '_search' => $emailCandidate],
                _rbac: false,
                _multitenancy: true
            );

            if (is_array($results) === true) {
                foreach ($results as $result) {
                    $data        = $result->getObject();
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
