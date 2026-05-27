<?php
/**
 * Gebruik Sync Service.
 *
 * Service for synchronizing and processing Gebruik (Usage) objects.
 *
 * This service handles the processing of gebruik schema objects, including:
 * - Processing gebruiktVoorReferentiecomponenten to populate amefElements
 * - Auto-updating status based on date fields
 *
 * @category  Service
 * @package   OCA\SoftwareCatalog\Service
 * @author    Ruben van der Linde <ruben@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl
 * @version   GIT: <git_id>
 * @link      https://github.com/conduction/nextcloud-software-catalog
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-softwarecatalog/tasks.md#task-9
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use OCA\SoftwareCatalog\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntity;
use Exception;
use DateTime;

/**
 * Service for synchronizing and processing Gebruik (Usage) objects.
 *
 * @category Service
 * @package  OCA\SoftwareCatalog\Service
 * @author   Ruben van der Linde <ruben@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl
 * @version  GIT: <git_id>
 * @link     https://github.com/conduction/nextcloud-software-catalog
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.LongVariable)
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.MissingImport)
 * @SuppressWarnings(PHPMD.UnusedLocalVariable)
 * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
 * @SuppressWarnings(PHPMD.Superglobals)
 * @SuppressWarnings(PHPMD.CamelCaseVariableName)
 * @SuppressWarnings(PHPMD.CamelCaseParameterName)
 */
class GebruikSyncService
{

    /**
     * Logger for debugging and error reporting.
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Service for retrieving configuration settings.
     *
     * @var SettingsService
     */
    private SettingsService $settingsService;

    /**
     * Container for lazy service resolution.
     *
     * @var ContainerInterface
     */
    private ContainerInterface $container;

    /**
     * Constructor for GebruikSyncService.
     *
     * @param LoggerInterface    $logger          Logger for debugging and error reporting
     * @param SettingsService    $settingsService Service for retrieving configuration settings
     * @param ContainerInterface $container       DI container for lazy service resolution
     */
    public function __construct(
        LoggerInterface $logger,
        SettingsService $settingsService,
        ContainerInterface $container
    ) {
        $this->logger          = $logger;
        $this->settingsService = $settingsService;
        $this->container       = $container;
    }//end __construct()

    /**
     * Process a specific gebruik object.
     *
     * This method handles both AMEF elements processing and status auto-update.
     *
     * @param ObjectEntity $gebruikObject The gebruik object to process
     *
     * @return array Processing statistics.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-softwarecatalog/tasks.md#task-9
     */
    public function processSpecificGebruik(ObjectEntity $gebruikObject): array
    {
        $startTime = microtime(true);
        $stats     = [
            'startTime'             => date('Y-m-d H:i:s'),
            'gebruikId'             => $gebruikObject->getUuid(),
            'amefElementsProcessed' => 0,
            'statusUpdated'         => false,
            'errors'                => [],
            'duration'              => 0,
        ];

        try {
            $gebruikData = $gebruikObject->getObject();
            $gebruikUuid = $gebruikObject->getUuid();

            $this->logger->debug(
                    'Processing gebruik object',
                    [
                        'app'           => 'softwarecatalog',
                        'gebruikId'     => $gebruikUuid,
                        'currentStatus' => $gebruikData['status'] ?? 'Unknown',
                    ]
                    );

            // Step 1: Process gebruiktVoorReferentiecomponenten for AMEF elements.
            $amefStats = $this->processAmefElements(gebruikObject: $gebruikObject);
            $stats['amefElementsProcessed'] = $amefStats['amefElementsProcessed'];
            $stats['errors'] = array_merge($stats['errors'], $amefStats['errors']);

            // Step 2: Auto-update status based on dates.
            $statusStats            = $this->updateStatusBasedOnDates(gebruikObject: $gebruikObject);
            $stats['statusUpdated'] = $statusStats['statusUpdated'];
            $stats['errors']        = array_merge($stats['errors'], $statusStats['errors']);

            $stats['endTime']  = date('Y-m-d H:i:s');
            $stats['duration'] = round(microtime(true) - $startTime, 3);

            $this->logger->critical(
                    'GEBRUIK PROCESSING COMPLETED',
                    [
                        'app'            => 'softwarecatalog',
                        'gebruikId'      => $gebruikUuid,
                        'stats'          => $stats,
                        'processingTime' => $stats['duration'].'s',
                    ]
                    );

            return $stats;
        } catch (Exception $e) {
            $stats['errors'][] = $e->getMessage();
            $stats['duration'] = round(microtime(true) - $startTime, 3);

            $this->logger->error(
                    'GEBRUIK PROCESSING ERROR',
                    [
                        'app'       => 'softwarecatalog',
                        'gebruikId' => $gebruikObject->getUuid(),
                        'exception' => $e->getMessage(),
                        'file'      => $e->getFile(),
                        'line'      => $e->getLine(),
                        'trace'     => $e->getTraceAsString(),
                    ]
                    );

            return $stats;
        }//end try
    }//end processSpecificGebruik()

    /**
     * Process AMEF elements from gebruiktVoorReferentiecomponenten.
     *
     * Searches for AMEF elements based on IDs in gebruiktVoorReferentiecomponenten
     * and adds their slugs to the amefElements array.
     *
     * @param ObjectEntity $gebruikObject The gebruik object to process
     *
     * @return array Processing statistics.
     */
    private function processAmefElements(ObjectEntity $gebruikObject): array
    {
        $stats = [
            'amefElementsProcessed' => 0,
            'errors'                => [],
        ];

        try {
            $gebruikData = $gebruikObject->getObject();
            $gebruikUuid = $gebruikObject->getUuid();

            // Get the referentiecomponenten IDs.
            $referentieComponenten = $gebruikData['gebruiktVoorReferentiecomponenten'] ?? [];

            if (empty($referentieComponenten) === true) {
                $this->logger->info(
                        'No referentiecomponenten found for gebruik object',
                        [
                            'gebruikId' => $gebruikUuid,
                        ]
                        );
                return $stats;
            }

            $this->logger->debug(
                    'Processing referentiecomponenten',
                    [
                        'app'                        => 'softwarecatalog',
                        'gebruikId'                  => $gebruikUuid,
                        'referentieComponentenCount' => count($referentieComponenten),
                    ]
                    );

            // Extract IDs from referentiecomponenten.
            $referentieIds = [];
            foreach ($referentieComponenten as $component) {
                if (isset($component['id']) === true) {
                    $referentieIds[] = $component['id'];
                }
            }

            if (empty($referentieIds) === true) {
                $this->logger->warning(
                        'No valid IDs found in referentiecomponenten',
                        [
                            'gebruikId' => $gebruikUuid,
                        ]
                        );
                return $stats;
            }

            // Get AMEF register configuration.
            $voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
            $amefRegister        = $voorzieningenConfig['amef_register'] ?? '';
            $elementSchema       = $voorzieningenConfig['element_schema'] ?? '';

            if (empty($amefRegister) === true || empty($elementSchema) === true) {
                $stats['errors'][] = 'AMEF register or element schema not configured';
                $this->logger->error(
                        'AMEF configuration missing',
                        [
                            'app'           => 'softwarecatalog',
                            'amefRegister'  => $amefRegister,
                            'elementSchema' => $elementSchema,
                        ]
                        );
                return $stats;
            }

            // Search for AMEF elements.
            $amefElements = $this->searchAmefElementsByIds(
                ids: $referentieIds,
                register: $amefRegister,
                schema: $elementSchema
            );

            // Extract slugs from found AMEF elements.
            $amefSlugs = [];
            foreach ($amefElements as $amefElement) {
                $amefData = $amefElement->getObject();
                if (isset($amefData['slug']) === true) {
                    $amefSlugs[] = $amefData['slug'];
                    $stats['amefElementsProcessed']++;
                }
            }

            // Update the gebruik object with AMEF slugs.
            if (empty($amefSlugs) === true) {
                $this->logger->info(
                        'No AMEF elements with slugs found',
                        [
                            'gebruikId' => $gebruikUuid,
                        ]
                        );
            }//end if

            if (empty($amefSlugs) === false) {
                $gebruikData['amefElements'] = array_unique($amefSlugs);
                                $this->updateGebruikObject(
                    gebruikObject: $gebruikObject,
                    updatedData: $gebruikData
                );

                $this->logger->critical(
                        'AMEF ELEMENTS UPDATED',
                        [
                            'app'               => 'softwarecatalog',
                            'gebruikId'         => $gebruikUuid,
                            'amefSlugs'         => $amefSlugs,
                            'amefElementsCount' => count($amefSlugs),
                        ]
                        );
            }

            return $stats;
        } catch (Exception $e) {
            $stats['errors'][] = 'AMEF processing error: '.$e->getMessage();
            $this->logger->error(
                    'AMEF PROCESSING ERROR',
                    [
                        'app'       => 'softwarecatalog',
                        'gebruikId' => $gebruikObject->getUuid(),
                        'exception' => $e->getMessage(),
                    ]
                    );

            return $stats;
        }//end try
    }//end processAmefElements()

    /**
     * Search for AMEF elements by IDs.
     *
     * Uses searchObjects to find AMEF elements based on provided IDs.
     * Since searchObjects may not support direct IDs array parameter,
     * this method implements multiple individual searches.
     *
     * @param array  $ids      Array of IDs to search for
     * @param string $register AMEF register ID
     * @param string $schema   Element schema ID
     *
     * @return array Array of found ObjectEntity objects.
     */
    private function searchAmefElementsByIds(array $ids, string $register, string $schema): array
    {
        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        $foundElements = [];

        foreach ($ids as $id) {
            try {
                // Try to search by ID.
                $query = [
                    '@self' => [
                        'register' => (int) $register,
                        'schema'   => (int) $schema,
                    ],
                    'id'    => $id,
                ];

                $elements      = $objectService->searchObjects($query);
                $foundElements = array_merge($foundElements, $elements);
            } catch (Exception $e) {
                $this->logger->warning(
                        'Failed to search for AMEF element',
                        [
                            'app'   => 'softwarecatalog',
                            'id'    => $id,
                            'error' => $e->getMessage(),
                        ]
                        );
            }//end try
        }//end foreach

        $this->logger->info(
                'AMEF elements search completed',
                [
                    'app'                => 'softwarecatalog',
                    'searchedIds'        => $ids,
                    'foundElementsCount' => count($foundElements),
                ]
                );

        return $foundElements;
    }//end searchAmefElementsByIds()

    /**
     * Update status based on date fields.
     *
     * Looks at all status date fields and updates the status to the one
     * with the highest date that is not in the future.
     *
     * @param ObjectEntity $gebruikObject The gebruik object to process
     *
     * @return array Processing statistics.
     */
    private function updateStatusBasedOnDates(ObjectEntity $gebruikObject): array
    {
        $stats = [
            'statusUpdated' => false,
            'errors'        => [],
        ];

        try {
            $gebruikData   = $gebruikObject->getObject();
            $gebruikUuid   = $gebruikObject->getUuid();
            $currentStatus = $gebruikData['status'] ?? '';

            // Define status dates mapping.
            $statusDates = [
                'Verwerving'     => $gebruikData['startDatumVerwerving'] ?? null,
                'Gepland'        => $gebruikData['startDatumGepland'] ?? null,
                'In productie'   => $gebruikData['startDatumInProductie'] ?? null,
                'Uit te faseren' => $gebruikData['startDatumUitTeFaseren'] ?? null,
                'Uitgefaseerd'   => $gebruikData['startDatumUitGefaseerd'] ?? null,
            ];

            $this->logger->info(
                    'CHECKING STATUS DATES',
                    [
                        'app'           => 'softwarecatalog',
                        'gebruikId'     => $gebruikUuid,
                        'currentStatus' => $currentStatus,
                        'statusDates'   => $statusDates,
                    ]
                    );

            // Find the highest date that is not in the future.
            $now          = new DateTime();
            $targetStatus = null;
            $targetDate   = null;

            foreach ($statusDates as $status => $dateString) {
                if (empty($dateString) === false) {
                    try {
                        $date = new DateTime($dateString);

                        // Only consider dates that are not in the future.
                        if ($date <= $now) {
                            if ($targetDate === null || $date > $targetDate) {
                                $targetDate   = $date;
                                $targetStatus = $status;
                            }
                        }
                    } catch (Exception $e) {
                        $this->logger->warning(
                                'Invalid date format',
                                [
                                    'app'        => 'softwarecatalog',
                                    'gebruikId'  => $gebruikUuid,
                                    'status'     => $status,
                                    'dateString' => $dateString,
                                    'error'      => $e->getMessage(),
                                ]
                                );
                    }//end try
                }//end if
            }//end foreach

            // Update status if we found a different one.
            if ($targetStatus !== null && $targetStatus !== $currentStatus) {
                $gebruikData['status'] = $targetStatus;
                                $this->updateGebruikObject(
                    gebruikObject: $gebruikObject,
                    updatedData: $gebruikData
                );
                $stats['statusUpdated'] = true;

                    $basedOnDate = null;
                if ($targetDate === null) {
                    $this->logger->info(
                        'No status update needed',
                        [
                            'app'           => 'softwarecatalog',
                            'gebruikId'     => $gebruikUuid,
                            'currentStatus' => $currentStatus,
                            'targetStatus'  => $targetStatus,
                        ]
                        );
                }

                if ($targetDate !== null) {
                }

                $this->logger->critical(
                        'STATUS AUTO-UPDATED',
                        [
                            'app'         => 'softwarecatalog',
                            'gebruikId'   => $gebruikUuid,
                            'oldStatus'   => $currentStatus,
                            'newStatus'   => $targetStatus,
                            'basedOnDate' => $basedOnDate,
                        ]
                        );
            }//end if

            return $stats;
        } catch (Exception $e) {
            $stats['errors'][] = 'Status update error: '.$e->getMessage();
            $this->logger->error(
                    'STATUS UPDATE ERROR',
                    [
                        'app'       => 'softwarecatalog',
                        'gebruikId' => $gebruikObject->getUuid(),
                        'exception' => $e->getMessage(),
                    ]
                    );

            return $stats;
        }//end try
    }//end updateStatusBasedOnDates()

    /**
     * Update a gebruik object in OpenRegister.
     *
     * @param ObjectEntity $gebruikObject The object to update
     * @param array        $updatedData   The updated data
     *
     * @return void
     *
     * @throws Exception If the update fails.
     */
    private function updateGebruikObject(ObjectEntity $gebruikObject, array $updatedData): void
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            // Get voorzieningenConfig to find the correct register and schema.
            $voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
            $register            = $voorzieningenConfig['register'] ?? '';
            $gebruikSchema       = $voorzieningenConfig['gebruik_schema'] ?? '';

            if (empty($register) === true || empty($gebruikSchema) === true) {
                throw new Exception('Register or gebruik schema not configured');
            }

            // Update the object.
            $objectService->saveObject(
                object: $updatedData,
                register: (int) $register,
                schema: (int) $gebruikSchema,
                id: $gebruikObject->getUuid()
            );

            $this->logger->info(
                    'Gebruik object updated successfully',
                    [
                        'app'       => 'softwarecatalog',
                        'gebruikId' => $gebruikObject->getUuid(),
                    ]
                    );
        } catch (Exception $e) {
            $this->logger->error(
                    'Failed to update gebruik object',
                    [
                        'app'       => 'softwarecatalog',
                        'gebruikId' => $gebruikObject->getUuid(),
                        'error'     => $e->getMessage(),
                    ]
                    );
            throw $e;
        }//end try
    }//end updateGebruikObject()
}//end class
