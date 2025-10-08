<?php
/**
 * Module Compliance Service
 *
 * This file contains the service class for handling module compliance logic
 * in the SoftwareCatalog application.
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
use OCA\SoftwareCatalog\Service\SettingsService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for handling module compliance logic.
 * 
 * This service handles the automatic synchronization of module 'standaarden'
 * property based on linked compliance objects and their standaardversie references.
 * 
 * @category Service
 * @package  OCA\SoftwareCatalog\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version  1.0.0
 * @link     https://github.com/ConductionNL/SoftwareCatalog
 */
class ModuleComplianceService
{
    /**
     * Constructor for ModuleComplianceService
     *
     * @param ContainerInterface $container The container interface
     * @param SettingsService    $settingsService The settings service
     * @param LoggerInterface   $logger    The logger instance
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Handle module compliance update
     *
     * @param object $moduleObject The module object that was updated
     *
     * @return void
     *
     * @throws \Exception If the update fails
     */
    public function handleModuleComplianceUpdate(object $moduleObject): void
    {
        $startTime = microtime(true);
        $moduleId = $moduleObject->getId();
        
        $this->logger->info('ModuleComplianceService: Starting module compliance update handling', [
            'moduleId' => $moduleId,
            'timestamp' => date('Y-m-d H:i:s')
        ]);

        try {
            // Get the object service
            $objectService = $this->getObjectService();
            if (!$objectService) {
                throw new \RuntimeException('ObjectService not available');
            }

            // Get module data
            $moduleData = $moduleObject->getObject();
            $moduleUuid = $moduleData['uuid'] ?? null;
            
            if (!$moduleUuid) {
                $this->logger->warning('ModuleComplianceService: Module object has no UUID', [
                    'moduleId' => $moduleId
                ]);
                return;
            }

            $this->logger->debug('ModuleComplianceService: Processing module', [
                'moduleId' => $moduleId,
                'moduleUuid' => $moduleUuid
            ]);

            // Get compliance objects linked to this module
            $complianceObjects = $this->getComplianceObjectsForModule($moduleUuid);
            
            $this->logger->debug('ModuleComplianceService: Found compliance objects', [
                'moduleId' => $moduleId,
                'moduleUuid' => $moduleUuid,
                'complianceCount' => count($complianceObjects)
            ]);

            // Extract standaardversie UUIDs from compliance objects
            $standaardversieUuids = $this->extractStandaardversieUuids($complianceObjects);
            
            $this->logger->debug('ModuleComplianceService: Extracted standaardversie UUIDs', [
                'moduleId' => $moduleId,
                'moduleUuid' => $moduleUuid,
                'standaardversieUuids' => $standaardversieUuids,
                'count' => count($standaardversieUuids)
            ]);

            // Get current standaarden from module
            $currentStandaarden = $moduleData['standaarden'] ?? [];
            
            // Ensure currentStandaarden is an array
            if (!is_array($currentStandaarden)) {
                $currentStandaarden = [];
            }

            $this->logger->debug('ModuleComplianceService: Current standaarden', [
                'moduleId' => $moduleId,
                'moduleUuid' => $moduleUuid,
                'currentStandaarden' => $currentStandaarden,
                'count' => count($currentStandaarden)
            ]);

            // Compare and update if different
            if ($this->arraysAreDifferent($currentStandaarden, $standaardversieUuids)) {
                $this->logger->info('ModuleComplianceService: Standaarden differ, updating module', [
                    'moduleId' => $moduleId,
                    'moduleUuid' => $moduleUuid,
                    'oldStandaarden' => $currentStandaarden,
                    'newStandaarden' => $standaardversieUuids
                ]);

                // Update the module with new standaarden
                $this->updateModuleStandaarden($moduleObject, $standaardversieUuids);
                
                $this->logger->info('ModuleComplianceService: Successfully updated module standaarden', [
                    'moduleId' => $moduleId,
                    'moduleUuid' => $moduleUuid,
                    'standaarden' => $standaardversieUuids
                ]);
            } else {
                $this->logger->debug('ModuleComplianceService: Standaarden are already up to date', [
                    'moduleId' => $moduleId,
                    'moduleUuid' => $moduleUuid
                ]);
            }

            $endTime = microtime(true);
            $executionTime = round(($endTime - $startTime) * 1000, 2);
            
            $this->logger->info('ModuleComplianceService: Completed module compliance update handling', [
                'moduleId' => $moduleId,
                'moduleUuid' => $moduleUuid,
                'executionTimeMs' => $executionTime,
                'timestamp' => date('Y-m-d H:i:s')
            ]);

        } catch (\Exception $e) {
            $this->logger->error('ModuleComplianceService: Failed to handle module compliance update', [
                'moduleId' => $moduleId,
                'exception' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Get compliance objects linked to a module
     *
     * @param string $moduleUuid The module UUID
     *
     * @return array Array of compliance objects
     *
     * @throws \Exception If retrieval fails
     */
    private function getComplianceObjectsForModule(string $moduleUuid): array
    {
        try {
            // Get compliance schema ID from configuration
            $complianceSchemaId = $this->settingsService->getSchemaIdForObjectType('compliancy');
            
            if (!$complianceSchemaId) {
                $this->logger->warning('ModuleComplianceService: Compliance schema not configured', [
                    'moduleUuid' => $moduleUuid
                ]);
                return [];
            }

            // Get object service
            $objectService = $this->getObjectService();
            if (!$objectService) {
                throw new \RuntimeException('ObjectService not available');
            }

            // Query compliance objects where module matches the module UUID
            $query = [
                '@self' => [
                    'schema' => (int) $complianceSchemaId,
                ],
                'module' => $moduleUuid,
            ];
            $complianceObjects = $objectService->searchObjects($query);

            $this->logger->debug('ModuleComplianceService: Retrieved compliance objects', [
                'moduleUuid' => $moduleUuid,
                'complianceSchemaId' => $complianceSchemaId,
                'count' => count($complianceObjects)
            ]);

            return $complianceObjects;

        } catch (\Exception $e) {
            $this->logger->error('ModuleComplianceService: Failed to get compliance objects for module', [
                'moduleUuid' => $moduleUuid,
                'exception' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            throw $e;
        }
    }

    /**
     * Extract standaardversie UUIDs from compliance objects
     *
     * @param array $complianceObjects Array of compliance objects
     *
     * @return array Array of standaardversie UUIDs
     */
    private function extractStandaardversieUuids(array $complianceObjects): array
    {
        $standaardversieUuids = [];

        foreach ($complianceObjects as $complianceObject) {
            $complianceData = $complianceObject->getObject();
            $standaardversie = $complianceData['standaardversie'] ?? null;
            
            if ($standaardversie) {
                // Handle both string UUID and object with UUID property
                if (is_string($standaardversie)) {
                    $standaardversieUuids[] = $standaardversie;
                } elseif (is_array($standaardversie) && isset($standaardversie['uuid'])) {
                    $standaardversieUuids[] = $standaardversie['uuid'];
                } elseif (is_object($standaardversie) && isset($standaardversie->uuid)) {
                    $standaardversieUuids[] = $standaardversie->uuid;
                }
            }
        }

        // Remove duplicates and empty values
        $standaardversieUuids = array_unique(array_filter($standaardversieUuids));

        $this->logger->debug('ModuleComplianceService: Extracted standaardversie UUIDs', [
            'complianceCount' => count($complianceObjects),
            'standaardversieUuids' => $standaardversieUuids,
            'uniqueCount' => count($standaardversieUuids)
        ]);

        return $standaardversieUuids;
    }

    /**
     * Check if two arrays are different (ignoring order)
     *
     * @param array $array1 First array
     * @param array $array2 Second array
     *
     * @return bool True if arrays are different
     */
    private function arraysAreDifferent(array $array1, array $array2): bool
    {
        // Sort both arrays to ignore order
        sort($array1);
        sort($array2);
        
        return $array1 !== $array2;
    }

    /**
     * Update module standaarden property
     *
     * @param object $moduleObject The module object to update
     * @param array  $standaardversieUuids Array of standaardversie UUIDs
     *
     * @return void
     *
     * @throws \Exception If update fails
     */
    private function updateModuleStandaarden(object $moduleObject, array $standaardversieUuids): void
    {
        try {
            // Get object service
            $objectService = $this->getObjectService();
            if (!$objectService) {
                throw new \RuntimeException('ObjectService not available');
            }

            // Get current module data
            $moduleData = $moduleObject->getObject();
            
            // Update standaarden property
            $moduleData['standaarden'] = $standaardversieUuids;
            
            // Get register ID from module object
            $registerId = $moduleObject->getRegister();
            
            // Save the updated module
            $objectService->saveObject(
                object: $moduleData,
                extend: [],
                register: $registerId,
                schema: $moduleObject->getSchema(),
                uuid: $moduleObject->getUuid()
            );

            $this->logger->info('ModuleComplianceService: Updated module standaarden', [
                'moduleId' => $moduleObject->getId(),
                'moduleUuid' => $moduleData['uuid'] ?? null,
                'standaarden' => $standaardversieUuids
            ]);

        } catch (\Exception $e) {
            $this->logger->error('ModuleComplianceService: Failed to update module standaarden', [
                'moduleId' => $moduleObject->getId(),
                'standaardversieUuids' => $standaardversieUuids,
                'exception' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            throw $e;
        }
    }

    /**
     * Perform bulk sync of module standards from all compliance objects
     *
     * @return array Results of the bulk sync operation
     *
     * @throws \Exception If the bulk sync fails
     */
    public function bulkSyncModuleStandards(): array
    {
        $startTime = microtime(true);
        
        $this->logger->info('ModuleComplianceService: Starting bulk sync of module standards');
        
        $results = [
            'totalProcessed' => 0,
            'modulesFound' => 0,
            'modulesUpdated' => 0,
            'standardsAdded' => 0,
            'errors' => [],
        ];

        try {
            // Get compliance schema ID from configuration
            $complianceSchemaId = $this->settingsService->getSchemaIdForObjectType('compliancy');
            
            if (!$complianceSchemaId) {
                throw new \RuntimeException('Compliance schema not configured');
            }

            // Get object service
            $objectService = $this->getObjectService();
            if (!$objectService) {
                throw new \RuntimeException('ObjectService not available');
            }

            // Get all compliance objects
            $query = [
                '@self' => [
                    'schema' => (int) $complianceSchemaId,
                ],
            ];
            $complianceObjects = $objectService->searchObjects($query);
            
            $this->logger->info('ModuleComplianceService: Found compliance objects for bulk sync', [
                'count' => count($complianceObjects)
            ]);

            $results['totalProcessed'] = count($complianceObjects);

            // Group compliance objects by module UUID
            $complianceByModule = [];
            foreach ($complianceObjects as $complianceObject) {
                $complianceData = $complianceObject->getObject();
                $moduleUuid = $complianceData['module'] ?? null;
                
                if (!$moduleUuid) {
                    $results['errors'][] = 'Compliance object has no module reference: ' . $complianceObject->getId();
                    continue;
                }

                // Handle both string UUID and object with UUID property
                if (is_string($moduleUuid)) {
                    $moduleUuidValue = $moduleUuid;
                } elseif (is_array($moduleUuid) && isset($moduleUuid['uuid'])) {
                    $moduleUuidValue = $moduleUuid['uuid'];
                } elseif (is_object($moduleUuid) && isset($moduleUuid->uuid)) {
                    $moduleUuidValue = $moduleUuid->uuid;
                } else {
                    $results['errors'][] = 'Invalid module reference in compliance object: ' . $complianceObject->getId();
                    continue;
                }

                if (!isset($complianceByModule[$moduleUuidValue])) {
                    $complianceByModule[$moduleUuidValue] = [];
                }
                $complianceByModule[$moduleUuidValue][] = $complianceObject;
            }

            $results['modulesFound'] = count($complianceByModule);

            // Process each module
            foreach ($complianceByModule as $moduleUuid => $moduleComplianceObjects) {
                try {
                    // Find the module object
                    $moduleObject = $objectService->find($moduleUuid);
                    if (!$moduleObject) {
                        $results['errors'][] = 'Module not found for UUID: ' . $moduleUuid;
                        continue;
                    }

                    // Extract standaardversie UUIDs from compliance objects
                    $standaardversieUuids = $this->extractStandaardversieUuids($moduleComplianceObjects);
                    
                    if (empty($standaardversieUuids)) {
                        continue; // No standards to sync
                    }

                    // Get current standaarden from module
                    $moduleData = $moduleObject->getObject();
                    $currentStandaarden = $moduleData['standaarden'] ?? [];
                    
                    // Ensure currentStandaarden is an array
                    if (!is_array($currentStandaarden)) {
                        $currentStandaarden = [];
                    }

                    // Compare and update if different
                    if ($this->arraysAreDifferent($currentStandaarden, $standaardversieUuids)) {
                        // Update the module with new standaarden
                        $this->updateModuleStandaarden($moduleObject, $standaardversieUuids);
                        
                        $results['modulesUpdated']++;
                        $results['standardsAdded'] += count($standaardversieUuids);
                        
                        $this->logger->info('ModuleComplianceService: Updated module in bulk sync', [
                            'moduleUuid' => $moduleUuid,
                            'standaarden' => $standaardversieUuids,
                            'count' => count($standaardversieUuids)
                        ]);
                    }

                } catch (\Exception $e) {
                    $results['errors'][] = 'Failed to process module ' . $moduleUuid . ': ' . $e->getMessage();
                    $this->logger->error('ModuleComplianceService: Error processing module in bulk sync', [
                        'moduleUuid' => $moduleUuid,
                        'exception' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            }

            $endTime = microtime(true);
            $executionTime = round(($endTime - $startTime) * 1000, 2);
            
            $this->logger->info('ModuleComplianceService: Completed bulk sync of module standards', [
                'results' => $results,
                'executionTimeMs' => $executionTime
            ]);

            return $results;

        } catch (\Exception $e) {
            $this->logger->error('ModuleComplianceService: Bulk sync failed', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Get the object service
     *
     * @return ObjectService|null The object service or null if not available
     */
    private function getObjectService(): ?ObjectService
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Exception $e) {
            $this->logger->error('ModuleComplianceService: Failed to get ObjectService', [
                'exception' => $e->getMessage()
            ]);
            return null;
        }
    }
}
