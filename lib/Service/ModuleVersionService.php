<?php
/**
 * Module Version Service
 *
 * Ensures every module has at least one version (moduleVersie).
 * If no versions exist, creates a default 1.0.0 version using
 * the module's name and description.
 *
 * @category  Service
 * @package   OCA\SoftwareCatalog\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version   1.0.0
 * @link      https://github.com/ConductionNL/SoftwareCatalog
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Service;

use OCA\OpenRegister\Service\ObjectService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for ensuring modules always have at least one version.
 *
 * @category Service
 * @package  OCA\SoftwareCatalog\Service
 */
class ModuleVersionService
{
    /**
     * Constructor for ModuleVersionService
     *
     * @param ContainerInterface $container        The DI container
     * @param SettingsService    $settingsService   The settings service
     * @param LoggerInterface    $logger            The logger instance
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Ensures a module has at least one version.
     *
     * Queries moduleVersie objects linked to this module. If none exist,
     * creates a default version with versie "1.0.0" using the module's
     * name and short description.
     *
     * @param object $moduleObject The module object entity
     *
     * @return void
     */
    public function ensureDefaultVersion(object $moduleObject): void
    {
        $moduleUuid = $moduleObject->getUuid();

        $this->logger->info('ModuleVersionService: Checking if module has versions', [
            'moduleUuid' => $moduleUuid,
        ]);

        try {
            $objectService = $this->getObjectService();
            if ($objectService === null) {
                $this->logger->error('ModuleVersionService: ObjectService not available');
                return;
            }

            // Get schema and register IDs.
            $moduleVersieSchemaId = $this->settingsService->getSchemaIdForObjectType('moduleVersie');
            $voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
            $registerId = $voorzieningenConfig['register'] ?? null;

            if ($moduleVersieSchemaId === null || $registerId === null) {
                $this->logger->warning('ModuleVersionService: moduleVersie schema or register not configured', [
                    'moduleVersieSchemaId' => $moduleVersieSchemaId,
                    'registerId' => $registerId,
                ]);
                return;
            }

            // Query moduleVersie objects where module == this module's UUID.
            $query = [
                '@self' => [
                    'schema' => (int) $moduleVersieSchemaId,
                    'register' => (int) $registerId,
                ],
                'module' => $moduleUuid,
            ];
            $existingVersions = $objectService->searchObjects(
                query: $query,
                _rbac: false,
                _multitenancy: false
            );

            $versionCount = is_array($existingVersions) ? count($existingVersions) : 0;

            if ($versionCount > 0) {
                $this->logger->info('ModuleVersionService: Module already has versions, skipping', [
                    'moduleUuid' => $moduleUuid,
                    'versionCount' => $versionCount,
                ]);
                return;
            }

            // No versions exist — create a default 1.0.0 version.
            $moduleData = $moduleObject->getObject();
            $moduleName = $moduleData['voorkeurnaam'] ?? $moduleData['naam'] ?? 'Onbekende applicatie';
            $moduleDescription = $moduleData['beschrijvingKort'] ?? '';

            $versionData = [
                'module' => $moduleUuid,
                'versie' => '1.0.0',
                'beschrijvingKort' => $moduleDescription,
                'beschrijvingLang' => '',
                'status' => 'in gebruik',
            ];

            $savedVersion = $objectService->saveObject(
                object: $versionData,
                register: (int) $registerId,
                schema: (int) $moduleVersieSchemaId,
                _rbac: false,
                _multitenancy: false
            );

            $this->logger->info('ModuleVersionService: Created default version 1.0.0', [
                'moduleUuid' => $moduleUuid,
                'moduleName' => $moduleName,
                'versionUuid' => $savedVersion->getUuid(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('ModuleVersionService: Failed to ensure default version', [
                'moduleUuid' => $moduleUuid,
                'exception' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }

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
            $this->logger->error('ModuleVersionService: Failed to get ObjectService', [
                'exception' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
