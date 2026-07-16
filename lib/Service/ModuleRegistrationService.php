<?php
/**
 * Module Registration Service.
 *
 * Service for auto-setting geregistreerdDoor on module objects
 * based on the owning organisation's type.
 *
 * @category  Service
 * @package   OCA\SoftwareCatalog\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/specs/method-decomposition/spec.md
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Service;

use OCA\OpenRegister\Service\ObjectService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for auto-setting geregistreerdDoor on module objects
 * based on the owning organisation's type.
 *
 * @category Service
 * @package  OCA\SoftwareCatalog\Service
 */
class ModuleRegistrationService
{
    /**
     * Mapping from organisatie.type to module.geregistreerdDoor.
     */
    private const TYPE_MAP = [
        'Gemeente'     => 'Gemeente',
        'Leverancier'  => 'Leverancier',
        'Samenwerking' => 'Samenwerking',
        'Community'    => 'Community',
    ];

    /**
     * Constructor for ModuleRegistrationService.
     *
     * @param ContainerInterface $container       The DI container
     * @param SettingsService    $settingsService The settings service
     * @param LoggerInterface    $logger          The logger instance
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Handle a module create/update event: look up the owning organisatie's type
     * and set geregistreerdDoor accordingly.
     *
     * @param object $moduleObject The module object to process
     *
     * @return void
     *
     * @spec openspec/specs/method-decomposition/spec.md
     */
    public function handleModuleRegistration(object $moduleObject): void
    {
        $moduleId         = $moduleObject->getId();
        $organisationUuid = $moduleObject->getOrganisation();

        if (empty($organisationUuid) === true) {
            $this->logger->debug(
                    'ModuleRegistrationService: Module has no organisation, skipping',
                    [
                        'moduleId' => $moduleId,
                    ]
                    );
            return;
        }

        $this->logger->info(
                'ModuleRegistrationService: Processing module for geregistreerdDoor',
                [
                    'moduleId'         => $moduleId,
                    'organisationUuid' => $organisationUuid,
                ]
                );

        try {
            $orgType = $this->resolveOrganisationType(moduleId: $moduleId, organisationUuid: $organisationUuid);
            if ($orgType === null) {
                return;
            }

            $geregistreerdDoor = $this->mapOrgTypeToRegisteredBy(moduleId: $moduleId, orgType: $orgType);
            if ($geregistreerdDoor === null) {
                return;
            }

            $this->updateModuleRegisteredBy(moduleObject: $moduleObject, geregistreerdDoor: $geregistreerdDoor, orgType: $orgType);
        } catch (\Exception $e) {
            $this->logger->error(
                    'ModuleRegistrationService: Failed to set geregistreerdDoor',
                    [
                        'moduleId'  => $moduleId,
                        'exception' => $e->getMessage(),
                        'file'      => $e->getFile(),
                        'line'      => $e->getLine(),
                    ]
                    );
        }//end try
    }//end handleModuleRegistration()

    /**
     * Resolve the organisation type for the given organisation UUID.
     *
     * Returns null when any prerequisite is missing (object service unavailable,
     * register/schema unconfigured, organisatie not found, or organisatie has
     * no type) — all of which represent legitimate skip-paths, logged at the
     * appropriate level.
     *
     * @param mixed  $moduleId         The module identifier (for logging)
     * @param string $organisationUuid The organisation UUID to look up
     *
     * @return string|null The organisation type, or null when not resolvable
     */
    private function resolveOrganisationType($moduleId, string $organisationUuid): ?string
    {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            $this->logger->error('ModuleRegistrationService: ObjectService not available');
            return null;
        }

        $organisatieSchemaId = $this->settingsService->getSchemaIdForObjectType('organisatie');
        $voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
        $registerId          = $voorzieningenConfig['register'] ?? null;

        if ($organisatieSchemaId === null || $registerId === null) {
            $this->logger->warning(
                    'ModuleRegistrationService: Organisatie schema or register not configured',
                    [
                        'organisatieSchemaId' => $organisatieSchemaId,
                        'registerId'          => $registerId,
                    ]
                    );
            return null;
        }

        try {
            $organisatieObject = $objectService->find(
                id: $organisationUuid,
                register: (int) $registerId,
                schema: (int) $organisatieSchemaId
            );
        } catch (\Exception $e) {
            $this->logger->debug(
                    'ModuleRegistrationService: Organisatie not found for organisation UUID',
                    [
                        'moduleId'         => $moduleId,
                        'organisationUuid' => $organisationUuid,
                    ]
                    );
            return null;
        }

        if ($organisatieObject === null) {
            $this->logger->debug(
                    'ModuleRegistrationService: Organisatie not found for organisation UUID',
                    [
                        'moduleId'         => $moduleId,
                        'organisationUuid' => $organisationUuid,
                    ]
                    );
            return null;
        }

        $organisatieData = $organisatieObject->getObject();
        $orgType         = $organisatieData['type'] ?? null;

        if (empty($orgType) === true) {
            $this->logger->debug(
                    'ModuleRegistrationService: Organisatie has no type, skipping',
                    [
                        'moduleId'         => $moduleId,
                        'organisationUuid' => $organisationUuid,
                    ]
                    );
            return null;
        }

        return (string) $orgType;
    }//end resolveOrganisationType()

    /**
     * Map an organisation type to the geregistreerdDoor enum value.
     *
     * @param mixed  $moduleId The module identifier (for logging)
     * @param string $orgType  The organisation type
     *
     * @return string|null The geregistreerdDoor value, or null when unknown
     */
    private function mapOrgTypeToRegisteredBy($moduleId, string $orgType): ?string
    {
        $geregistreerdDoor = self::TYPE_MAP[$orgType] ?? null;

        if ($geregistreerdDoor === null) {
            $this->logger->warning(
                    'ModuleRegistrationService: Unknown org type, cannot map geregistreerdDoor',
                    [
                        'moduleId' => $moduleId,
                        'orgType'  => $orgType,
                    ]
                    );
            return null;
        }

        return $geregistreerdDoor;
    }//end mapOrgTypeToRegisteredBy()

    /**
     * Persist geregistreerdDoor on the module object if not already correct.
     *
     * @param object $moduleObject      The module entity to update
     * @param string $geregistreerdDoor The resolved geregistreerdDoor value
     * @param string $orgType           The originating organisation type
     *
     * @return void
     */
    private function updateModuleRegisteredBy(object $moduleObject, string $geregistreerdDoor, string $orgType): void
    {
        $moduleId     = $moduleObject->getId();
        $moduleData   = $moduleObject->getObject();
        $currentValue = $moduleData['geregistreerdDoor'] ?? null;

        if ($currentValue === $geregistreerdDoor) {
            $this->logger->debug(
                    'ModuleRegistrationService: geregistreerdDoor already correct',
                    [
                        'moduleId'          => $moduleId,
                        'geregistreerdDoor' => $geregistreerdDoor,
                    ]
                    );
            return;
        }

        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return;
        }

        $moduleData['geregistreerdDoor'] = $geregistreerdDoor;

        $objectService->saveObject(
            object: $moduleData,
            register: $moduleObject->getRegister(),
            schema: $moduleObject->getSchema(),
            uuid: $moduleObject->getUuid(),
            _rbac: false,
            _multitenancy: false
        );

        $this->logger->info(
                'ModuleRegistrationService: Set geregistreerdDoor on module',
                [
                    'moduleId'          => $moduleId,
                    'orgType'           => $orgType,
                    'geregistreerdDoor' => $geregistreerdDoor,
                ]
                );
    }//end updateModuleRegisteredBy()

    /**
     * Get the object service from the DI container.
     *
     * @return ObjectService|null The object service or null if not available
     */
    private function getObjectService(): ?ObjectService
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Exception $e) {
            $this->logger->error(
                    'ModuleRegistrationService: Failed to get ObjectService',
                    [
                        'exception' => $e->getMessage(),
                    ]
                    );
            return null;
        }
    }//end getObjectService()
}//end class
