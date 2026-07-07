<?php

/**
 * Gebruik Bulk Handler for SoftwareCatalog
 *
 * Handles bulk-create operations for AangebodenGebruik objects, extracted from
 * AangebodenGebruikService to reduce ExcessiveClassLength on that service.
 *
 * @category  Service
 * @package   OCA\SoftwareCatalog\Service\AangebodenGebruik
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-7
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Service\AangebodenGebruik;

use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Handles bulk-create lifecycle for AangebodenGebruik objects.
 *
 * Decomposes bulkCreate() into validateBulkInput → processBulkItem → aggregateBulkResults
 * so each step stays below PHPMD ExcessiveMethodLength and CyclomaticComplexity thresholds.
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-7
 */
class GebruikBulkHandler
{
    /**
     * Constructor.
     *
     * @param ObjectService   $objectService The OpenRegister object service.
     * @param LoggerInterface $logger        Logger instance.
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-7
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Process a bulk-create request for AangebodenGebruik objects.
     *
     * @param array<int,array<string,mixed>> $items    Array of item data arrays to create.
     * @param string                         $register The target OpenRegister register slug.
     * @param string                         $schema   The target schema slug.
     *
     * @return array<string,mixed> Aggregate result with 'created', 'failed', and 'errors'.
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-7
     */
    public function bulkCreate(array $items, string $register, string $schema): array
    {
        $errors = $this->validateBulkInput(items: $items);
        if (empty($errors) === false) {
            return [
                'success' => false,
                'created' => 0,
                'failed'  => count($items),
                'errors'  => $errors,
            ];
        }

        $results = [];
        foreach ($items as $index => $item) {
            $results[] = $this->processBulkItem(item: $item, index: $index, register: $register, schema: $schema);
        }

        return $this->aggregateBulkResults(results: $results);

    }//end bulkCreate()

    /**
     * Validate the bulk-create input array.
     *
     * @param array<int,mixed> $items Items to validate (runtime check enforces each is array).
     *
     * @return string[] Validation error messages (empty on success).
     */
    private function validateBulkInput(array $items): array
    {
        if (empty($items) === true) {
            return ['Bulk input may not be empty.'];
        }

        $errors = [];
        foreach ($items as $index => $item) {
            if (is_array($item) === false) {
                $errors[] = sprintf('Item at index %d is not an array.', $index);
                continue;
            }

            if (isset($item['afnemer']) === false || isset($item['aanbieder']) === false) {
                $errors[] = sprintf('Item at index %d is missing required fields: afnemer, aanbieder.', $index);
            }
        }

        return $errors;

    }//end validateBulkInput()

    /**
     * Create a single gebruik item via ObjectService.
     *
     * @param array<string,mixed> $item     The item data array.
     * @param int                 $index    The zero-based index in the batch.
     * @param string              $register The target register slug.
     * @param string              $schema   The target schema slug.
     *
     * @return array<string,mixed> Per-item result with 'success', 'index', and optionally 'error'.
     */
    private function processBulkItem(array $item, int $index, string $register, string $schema): array
    {
        try {
            $created = $this->objectService->saveObject(
                register: $register,
                schema: $schema,
                object: $item
            );

            return [
                'success' => true,
                'index'   => $index,
                'uuid'    => $created->getUuid(),
            ];
        } catch (\Exception $e) {
            $this->logger->warning(
                'GebruikBulkHandler: Failed to create bulk item',
                ['index' => $index, 'exception' => $e->getMessage()]
            );

            return [
                'success' => false,
                'index'   => $index,
                'error'   => $e->getMessage(),
            ];
        }//end try

    }//end processBulkItem()

    /**
     * Aggregate per-item results into a single summary.
     *
     * @param array<int,array<string,mixed>> $results Per-item result arrays.
     *
     * @return array<string,mixed> Summary with 'success', 'created', 'failed', and 'errors'.
     */
    private function aggregateBulkResults(array $results): array
    {
        $created = 0;
        $failed  = 0;
        $errors  = [];

        foreach ($results as $result) {
            if ($result['success'] !== true) {
                $failed++;
                $errors[] = sprintf('[%d] %s', $result['index'], $result['error'] ?? 'Unknown error');
                continue;
            }

            $created++;
        }

        return [
            'success' => $failed === 0,
            'created' => $created,
            'failed'  => $failed,
            'errors'  => $errors,
        ];

    }//end aggregateBulkResults()
}//end class
