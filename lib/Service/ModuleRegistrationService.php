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
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version   GIT: <git_id>
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-softwarecatalog/tasks.md#task-9
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
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
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
     * @spec openspec/changes/retrofit-2026-05-24-annotate-softwarecatalog/tasks.md#task-9
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
            $objectService = $this->getObjectService();
            if ($objectService === null) {
                $this->logger->error('ModuleRegistrationService: ObjectService not available');
                return;
            }

            // Look up the organisatie object whose UUID matches the module's _organisation.
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
                return;
            }

            // Search for the organisatie by its organisation UUID.
            // Organisation objects share their UUID with the OpenRegister Organisation entity.
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
                return;
            }

            if ($organisatieObject === null) {
                $this->logger->debug(
                        'ModuleRegistrationService: Organisatie not found for organisation UUID',
                        [
                            'moduleId'         => $moduleId,
                            'organisationUuid' => $organisationUuid,
                        ]
                        );
                return;
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
                return;
            }

            // Map the org type to geregistreerdDoor value.
            $geregistreerdDoor = self::TYPE_MAP[$orgType] ?? null;

            if ($geregistreerdDoor === null) {
                $this->logger->warning(
                        'ModuleRegistrationService: Unknown org type, cannot map geregistreerdDoor',
                        [
                            'moduleId' => $moduleId,
                            'orgType'  => $orgType,
                        ]
                        );
                return;
            }

            // Check if already set correctly.
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

            // Update the module with the correct geregistreerdDoor.
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
