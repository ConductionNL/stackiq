<?php

/**
 * Module Event Processor for SoftwareCatalog
 *
 * Extracts shared module-event processing logic from SoftwareCatalogEventListener,
 * reducing ExcessiveClassComplexity and CouplingBetweenObjects on the listener.
 *
 * @category  Service
 * @package   OCA\SoftwareCatalog\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-6
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Service;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Handles shared module-event processing logic extracted from SoftwareCatalogEventListener.
 *
 * This processor centralises the schema-ID lookup, early-guard, and delegation
 * steps that were duplicated across handleObjectCreated / handleObjectUpdated /
 * handleObjectDeleted in the event listener.
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-6
 */
class ModuleEventProcessor
{
    /**
     * Constructor.
     *
     * @param ContainerInterface $container DI container for lazy service resolution.
     * @param SettingsService    $settings  Settings service for schema ID lookup.
     * @param LoggerInterface    $logger    Logger instance.
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-6
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly SettingsService $settings,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Resolve schema IDs for all handled object types in one call.
     *
     * Reduces repeated calls to SettingsService inside each handle* method.
     *
     * @return array<string,int|null> Map of object-type → schema ID (int) or null.
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-6
     */
    public function resolveSchemaIds(): array
    {
        return [
            'organisatie'     => $this->settings->getSchemaIdForObjectType(objectType: 'organisatie'),
            'contactpersoon'  => $this->settings->getSchemaIdForObjectType(objectType: 'contactpersoon'),
            'contactgegevens' => $this->settings->getSchemaIdForObjectType(objectType: 'contactgegevens'),
            'gebruik'         => $this->settings->getSchemaIdForObjectType(objectType: 'gebruik'),
        ];
    }//end resolveSchemaIds()

    /**
     * Process an organization create event via OrganizationSyncService.
     *
     * @param object              $object    The created object.
     * @param array<string,mixed> $schemaIds Resolved schema ID map from resolveSchemaIds().
     *
     * @return void
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-6
     */
    public function processOrganisatieCreated(object $object, array $schemaIds): void
    {
        $objectSchemaIdInt   = (int) $object->getSchema();
        $organisatieSchemaId = $schemaIds['organisatie'];

        if ($organisatieSchemaId === null || $objectSchemaIdInt !== (int) $organisatieSchemaId) {
            return;
        }

        $objectData = $object->getObject();
        $status     = strtolower($objectData['status'] ?? '');

        if (in_array(needle: $status, haystack: ['actief', 'active']) !== true) {
            $this->logger->debug(
                'SoftwareCatalog: Skipping non-active organization creation',
                ['objectId' => $object->getUuid(), 'status' => $status]
            );
            return;
        }

        try {
            $orgSyncService = $this->container->get('OCA\SoftwareCatalog\Service\OrganizationSyncService');
            $orgSyncService->processSpecificOrganization($object);
        } catch (\Exception $e) {
            $this->logger->error(
                'SoftwareCatalog: Failed to process organization creation',
                ['objectId' => $object->getUuid(), 'exception' => $e->getMessage()]
            );
        }

    }//end processOrganisatieCreated()

    /**
     * Process an organization update event via OrganizationSyncService.
     *
     * @param object              $object    The new object state.
     * @param object|null         $oldObject The previous object state (may be null).
     * @param array<string,mixed> $schemaIds Resolved schema ID map from resolveSchemaIds().
     *
     * @return bool True if this object type was handled, false if it was not an organisation.
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-6
     */
    public function processOrganisatieUpdated(object $object, ?object $oldObject, array $schemaIds): bool
    {
        $objectSchemaIdInt   = (int) $object->getSchema();
        $organisatieSchemaId = $schemaIds['organisatie'];

        if ($organisatieSchemaId === null || $objectSchemaIdInt !== (int) $organisatieSchemaId) {
            return false;
        }

        $objectData = $object->getObject();
        $status     = strtolower($objectData['status'] ?? '');
        $oldStatus  = '';
        if ($oldObject !== null) {
            $oldData   = $oldObject->getObject();
            $oldStatus = strtolower($oldData['status'] ?? '');
        }

        if (in_array(needle: $status, haystack: ['actief', 'active']) === true && $status !== $oldStatus) {
            $this->processActiveOrganisatieUpdate(object: $object, status: $status);
        }

        return true;

    }//end processOrganisatieUpdated()

    /**
     * Process an organization deletion event via OrganizationSyncService.
     *
     * @param object              $object    The deleted object.
     * @param array<string,mixed> $schemaIds Resolved schema ID map from resolveSchemaIds().
     *
     * @return bool True if this object was an organisation, false otherwise.
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-6
     */
    public function processOrganisatieDeleted(object $object, array $schemaIds): bool
    {
        $objectSchemaIdInt   = (int) $object->getSchema();
        $organisatieSchemaId = $schemaIds['organisatie'];

        if ($organisatieSchemaId === null || $objectSchemaIdInt !== (int) $organisatieSchemaId) {
            return false;
        }

        $this->logger->info(
            'SoftwareCatalog: Processing organization deletion',
            ['objectId' => $object->getUuid()]
        );

        try {
            $orgSyncService = $this->container->get('OCA\SoftwareCatalog\Service\OrganizationSyncService');
            $orgSyncService->processSpecificOrganization($object);
        } catch (\Exception $e) {
            $this->logger->error(
                'SoftwareCatalog: Failed to process organization deletion',
                ['objectId' => $object->getUuid(), 'exception' => $e->getMessage()]
            );
        }

        return true;

    }//end processOrganisatieDeleted()

    /**
     * Delegate active-organisation processing after status-change confirmation.
     *
     * @param object $object The organisation object.
     * @param string $status The (normalised) new status.
     *
     * @return void
     */
    private function processActiveOrganisatieUpdate(object $object, string $status): void
    {
        $objectId = $object->getUuid();

        $this->logger->info(
            'SoftwareCatalog: Processing active organization update',
            ['objectId' => $objectId, 'status' => $status]
        );

        try {
            $voorzieningenConfig = $this->settings->getVoorzieningenConfig();
            $register            = $voorzieningenConfig['register'] ?? '';
            $organizationSchema  = $voorzieningenConfig['organisatie_schema'] ?? '';

            $objectService   = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $orgWithContacts = $objectService->find(
                id: $objectId,
                register: $register,
                schema: $organizationSchema,
                _extend: ['contactpersonen'],
                _rbac: false,
                _multitenancy: false
            );

            $orgSyncService = $this->container->get('OCA\SoftwareCatalog\Service\OrganizationSyncService');
            $orgSyncService->processSpecificOrganization($orgWithContacts);
        } catch (\Exception $e) {
            $this->logger->error(
                'SoftwareCatalog: Failed to process organization update',
                ['objectId' => $objectId, 'exception' => $e->getMessage()]
            );
        }//end try

    }//end processActiveOrganisatieUpdate()
}//end class
