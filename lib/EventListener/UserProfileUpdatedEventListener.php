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

    public function __construct()
    {
    }

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
            $logger = \OC::$server->get(LoggerInterface::class);
            $changes = $event->getChanges();

            // Check if any of the mapped fields were changed.
            $relevantChanges = array_intersect($changes, array_keys(self::FIELD_MAP));
            if (empty($relevantChanges) === true) {
                $logger->debug('[UserProfileUpdatedEventListener] No relevant field changes for contactpersoon sync', [
                    'userId'  => $event->getUserId(),
                    'changes' => $changes,
                ]);
                return;
            }

            $logger->info('[UserProfileUpdatedEventListener] Syncing user profile changes to contactpersoon', [
                'userId'          => $event->getUserId(),
                'relevantChanges' => $relevantChanges,
            ]);

            $this->syncToContactpersoon($event, $logger);
        } catch (\Exception $e) {
            try {
                $logger = \OC::$server->get(LoggerInterface::class);
                $logger->error('[UserProfileUpdatedEventListener] Error syncing profile to contactpersoon', [
                    'userId'    => $event->getUserId(),
                    'exception' => $e->getMessage(),
                    'file'      => $e->getFile(),
                    'line'      => $e->getLine(),
                ]);
            } catch (\Exception $logException) {
                // Silently fail if logging fails.
            }
        }
    }

    /**
     * Find the contactpersoon object by username and update its fields.
     *
     * @param UserProfileUpdatedEvent $event  The profile updated event.
     * @param LoggerInterface         $logger The logger.
     *
     * @return void
     */
    private function syncToContactpersoon(UserProfileUpdatedEvent $event, LoggerInterface $logger): void
    {
        $objectService = \OC::$server->get('OCA\OpenRegister\Service\ObjectService');
        $settingsService = \OC::$server->get(SettingsService::class);

        // Get the voorzieningen config for register and schema.
        $voorzieningenConfig = $settingsService->getVoorzieningenConfig();
        $register = $voorzieningenConfig['register'] ?? '';
        $contactpersoonSchema = $voorzieningenConfig['contactpersoon_schema'] ?? '';

        if (empty($register) === true || empty($contactpersoonSchema) === true) {
            $logger->warning('[UserProfileUpdatedEventListener] Voorzieningen config missing register or contactpersoon_schema');
            return;
        }

        $userId = $event->getUserId();

        // Find contactpersoon by username field using searchObjects.
        $query = [
            '@self' => [
                'register' => (int) $register,
                'schema'   => (int) $contactpersoonSchema,
            ],
            'username' => $userId,
        ];

        $results = $objectService->searchObjects(
            query: $query,
            _rbac: false,
            _multitenancy: false
        );

        if (empty($results) === true || (is_array($results) === true && count($results) === 0)) {
            $logger->info('[UserProfileUpdatedEventListener] No contactpersoon found for user', [
                'userId'   => $userId,
                'register' => $register,
                'schema'   => $contactpersoonSchema,
            ]);
            return;
        }

        // Get the first matching contactpersoon.
        $contactpersoon = is_array($results) === true ? reset($results) : $results;
        $contactData = $contactpersoon->getObject();
        $newData = $event->getNewData();
        $changes = $event->getChanges();

        // Build the patch with only changed fields.
        $patch = [];
        foreach (self::FIELD_MAP as $userField => $contactField) {
            if (in_array($userField, $changes, true) === false) {
                continue;
            }

            $newValue = $newData[$userField] ?? null;
            $patch[$contactField] = $newValue ?? '';
        }

        if (empty($patch) === true) {
            $logger->debug('[UserProfileUpdatedEventListener] No fields to patch on contactpersoon', [
                'userId' => $userId,
            ]);
            return;
        }

        $logger->info('[UserProfileUpdatedEventListener] Patching contactpersoon object', [
            'userId'           => $userId,
            'contactpersoonId' => $contactpersoon->getUuid(),
            'patch'            => $patch,
        ]);

        // Only save the changed fields to avoid property authorization issues
        // with protected fields like 'rollen'.
        $objectService->saveObject(
            register: $contactpersoon->getRegister(),
            schema: $contactpersoon->getSchema(),
            object: $patch,
            _rbac: false,
            _multitenancy: false,
            uuid: $contactpersoon->getUuid()
        );

        $logger->info('[UserProfileUpdatedEventListener] Successfully synced user profile to contactpersoon', [
            'userId'           => $userId,
            'contactpersoonId' => $contactpersoon->getUuid(),
            'patchedFields'    => array_keys($patch),
        ]);
    }
}
