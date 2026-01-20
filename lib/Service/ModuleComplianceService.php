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

            // Get register ID from voorzieningen config
            $voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
            $registerId = $voorzieningenConfig['register'] ?? null;

            if (!$registerId) {
                $this->logger->warning('ModuleComplianceService: Voorzieningen register not configured', [
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
                    'register' => (int) $registerId,
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
        $tracking = [
            'withStandaardversie' => 0,
            'withoutStandaardversie' => 0,
            'stringType' => 0,
            'arrayType' => 0,
            'objectType' => 0,
            'invalidType' => 0,
        ];

        foreach ($complianceObjects as $complianceObject) {
            $complianceData = $complianceObject->getObject();
            $standaardversie = $complianceData['standaardversie'] ?? null;
            
            if ($standaardversie) {
                $tracking['withStandaardversie']++;
                
                // Handle both string UUID and object with UUID property
                if (is_string($standaardversie)) {
                    $tracking['stringType']++;
                    $standaardversieUuids[] = $standaardversie;
                    $this->logger->debug('ModuleComplianceService: Found string standaardversie', [
                        'complianceId' => $complianceObject->getId(),
                        'standaardversie' => $standaardversie
                    ]);
                } elseif (is_array($standaardversie) && isset($standaardversie['uuid'])) {
                    $tracking['arrayType']++;
                    $standaardversieUuids[] = $standaardversie['uuid'];
                    $this->logger->debug('ModuleComplianceService: Found array standaardversie', [
                        'complianceId' => $complianceObject->getId(),
                        'standaardversie' => $standaardversie['uuid']
                    ]);
                } elseif (is_object($standaardversie) && isset($standaardversie->uuid)) {
                    $tracking['objectType']++;
                    $standaardversieUuids[] = $standaardversie->uuid;
                    $this->logger->debug('ModuleComplianceService: Found object standaardversie', [
                        'complianceId' => $complianceObject->getId(),
                        'standaardversie' => $standaardversie->uuid
                    ]);
                } else {
                    $tracking['invalidType']++;
                    $this->logger->warning('ModuleComplianceService: Invalid standaardversie type', [
                        'complianceId' => $complianceObject->getId(),
                        'type' => gettype($standaardversie),
                        'value' => is_array($standaardversie) ? json_encode($standaardversie) : (string)$standaardversie
                    ]);
                }
            } else {
                $tracking['withoutStandaardversie']++;
                $this->logger->debug('ModuleComplianceService: Compliance object missing standaardversie', [
                    'complianceId' => $complianceObject->getId(),
                    'complianceUuid' => $complianceData['uuid'] ?? 'unknown'
                ]);
            }
        }

        // Remove duplicates and empty values
        $standaardversieUuids = array_unique(array_filter($standaardversieUuids));

        $this->logger->info('ModuleComplianceService: Extracted standaardversie UUIDs', [
            'complianceCount' => count($complianceObjects),
            'tracking' => $tracking,
            'uniqueStandaardversieUuids' => count($standaardversieUuids),
            'standaardversieUuids' => $standaardversieUuids
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
            'complianceMissingModule' => 0,
            'complianceMissingStandaardversie' => 0,
            'modulesFound' => 0,
            'modulesNotFound' => 0,
            'modulesWithNoStandards' => 0,
            'modulesAlreadyUpToDate' => 0,
            'modulesUpdated' => 0,
            'standardsAdded' => 0,
            'errors' => [],
            'samples' => [
                'complianceWithStandaardversie' => [],
                'complianceWithoutStandaardversie' => [],
                'modulesUpdated' => [],
                'modulesSkipped' => [],
            ],
            'modules' => [], // Full list of all processed modules
        ];

        try {
            // Get compliance schema ID from configuration
            $complianceSchemaId = $this->settingsService->getSchemaIdForObjectType('compliancy');

            if (!$complianceSchemaId) {
                throw new \RuntimeException('Compliance schema not configured');
            }

            // Get register ID from voorzieningen config
            $voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
            $registerId = $voorzieningenConfig['register'] ?? null;

            if (!$registerId) {
                throw new \RuntimeException('Voorzieningen register not configured');
            }

            // Get object service
            $objectService = $this->getObjectService();
            if (!$objectService) {
                throw new \RuntimeException('ObjectService not available');
            }

            // Get all compliance objects (both schema AND register are required)
            $query = [
                '@self' => [
                    'schema' => (int) $complianceSchemaId,
                    'register' => (int) $registerId,
                ],
            ];
            $complianceObjects = $objectService->searchObjects($query);
            
            $this->logger->info('ModuleComplianceService: Found compliance objects for bulk sync', [
                'count' => count($complianceObjects)
            ]);

            $results['totalProcessed'] = count($complianceObjects);

            // Group compliance objects by module UUID and track samples
            $complianceByModule = [];
            $sampleCount = 0;
            
            foreach ($complianceObjects as $complianceObject) {
                $complianceData = $complianceObject->getObject();
                $moduleUuid = $complianceData['module'] ?? null;
                $standaardversie = $complianceData['standaardversie'] ?? null;
                
                // Track compliance objects with/without standaardversie (first 5 samples)
                if ($sampleCount < 5) {
                    if ($standaardversie) {
                        $results['samples']['complianceWithStandaardversie'][] = [
                            'id' => $complianceObject->getId(),
                            'uuid' => $complianceData['uuid'] ?? 'unknown',
                            'module' => $moduleUuid,
                            'standaardversie' => $standaardversie,
                        ];
                    } else {
                        $results['samples']['complianceWithoutStandaardversie'][] = [
                            'id' => $complianceObject->getId(),
                            'uuid' => $complianceData['uuid'] ?? 'unknown',
                            'module' => $moduleUuid,
                        ];
                    }
                    $sampleCount++;
                }
                
                if (!$moduleUuid) {
                    $results['complianceMissingModule']++;
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
                        $results['modulesNotFound']++;
                        $results['errors'][] = 'Module not found for UUID: ' . $moduleUuid;
                        
                        // Add to full modules list
                        $results['modules'][] = [
                            'uuid' => $moduleUuid,
                            'name' => 'Not Found',
                            'status' => 'error',
                            'reason' => 'Module not found in database',
                            'complianceCount' => count($moduleComplianceObjects),
                            'currentStandaarden' => [],
                            'newStandaarden' => [],
                            'standardsCount' => 0,
                        ];
                        continue;
                    }

                    // Get module data for tracking
                    $moduleData = $moduleObject->getObject();
                    $moduleName = $moduleData['name'] ?? $moduleData['title'] ?? 'Unknown';

                    // Extract standaardversie UUIDs from compliance objects
                    $standaardversieUuids = $this->extractStandaardversieUuids($moduleComplianceObjects);
                    
                    if (empty($standaardversieUuids)) {
                        $results['modulesWithNoStandards']++;
                        $results['complianceMissingStandaardversie'] += count($moduleComplianceObjects);
                        
                        // Add to full modules list
                        $results['modules'][] = [
                            'uuid' => $moduleUuid,
                            'name' => $moduleName,
                            'status' => 'skipped',
                            'reason' => 'No standaardversie found',
                            'complianceCount' => count($moduleComplianceObjects),
                            'currentStandaarden' => [],
                            'newStandaarden' => [],
                            'standardsCount' => 0,
                        ];
                        
                        // Add to samples (first 5)
                        if (count($results['samples']['modulesSkipped']) < 5) {
                            $results['samples']['modulesSkipped'][] = [
                                'uuid' => $moduleUuid,
                                'name' => $moduleName,
                                'reason' => 'No standaardversie found in ' . count($moduleComplianceObjects) . ' compliance object(s)',
                                'complianceCount' => count($moduleComplianceObjects),
                            ];
                        }
                        continue;
                    }

                    // Get current standaarden from module
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
                        
                        // Add to full modules list
                        $results['modules'][] = [
                            'uuid' => $moduleUuid,
                            'name' => $moduleName,
                            'status' => 'updated',
                            'reason' => 'Standards updated',
                            'complianceCount' => count($moduleComplianceObjects),
                            'currentStandaarden' => $currentStandaarden,
                            'newStandaarden' => $standaardversieUuids,
                            'standardsCount' => count($standaardversieUuids),
                        ];
                        
                        // Add to samples (first 5)
                        if (count($results['samples']['modulesUpdated']) < 5) {
                            $results['samples']['modulesUpdated'][] = [
                                'uuid' => $moduleUuid,
                                'name' => $moduleName,
                                'oldStandaarden' => $currentStandaarden,
                                'newStandaarden' => $standaardversieUuids,
                                'complianceCount' => count($moduleComplianceObjects),
                            ];
                        }
                        
                        $this->logger->info('ModuleComplianceService: Updated module in bulk sync', [
                            'moduleUuid' => $moduleUuid,
                            'moduleName' => $moduleName,
                            'standaarden' => $standaardversieUuids,
                            'count' => count($standaardversieUuids)
                        ]);
                    } else {
                        $results['modulesAlreadyUpToDate']++;
                        
                        // Add to full modules list
                        $results['modules'][] = [
                            'uuid' => $moduleUuid,
                            'name' => $moduleName,
                            'status' => 'up-to-date',
                            'reason' => 'Already up-to-date',
                            'complianceCount' => count($moduleComplianceObjects),
                            'currentStandaarden' => $currentStandaarden,
                            'newStandaarden' => $standaardversieUuids,
                            'standardsCount' => count($currentStandaarden),
                        ];
                        
                        // Add to samples (first 5)
                        if (count($results['samples']['modulesSkipped']) < 5) {
                            $results['samples']['modulesSkipped'][] = [
                                'uuid' => $moduleUuid,
                                'name' => $moduleName,
                                'reason' => 'Already up-to-date',
                                'currentStandaarden' => $currentStandaarden,
                                'extractedStandaarden' => $standaardversieUuids,
                                'complianceCount' => count($moduleComplianceObjects),
                            ];
                        }
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
