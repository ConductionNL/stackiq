<?php

/**
 * API Client for SoftwareCatalogueService
 *
 * Extracted from SoftwareCatalogueService to reduce ExcessiveClassLength and
 * CouplingBetweenObjects on that service. Handles all HTTP API communication.
 *
 * @category  Service
 * @package   OCA\SoftwareCatalog\Service\SoftwareCatalogue
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Service\SoftwareCatalogue;

use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Handles all API communication for the SoftwareCatalogue domain.
 *
 * SoftwareCatalogueService delegates API fetch operations to this class,
 * keeping its own constructor coupling below the PHPMD CouplingBetweenObjects
 * threshold and keeping its methods below ExcessiveMethodLength.
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-2
 */
class ApiClient
{
    /**
     * Constructor.
     *
     * @param ObjectService   $objectService The OpenRegister object service.
     * @param LoggerInterface $logger        Logger instance.
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-2
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Fetch objects for a given register and schema with optional filters.
     *
     * @param string              $register The register slug to query.
     * @param string              $schema   The schema slug to query.
     * @param array<string,mixed> $filters  Optional filter parameters.
     * @param int                 $limit    Maximum number of results (default: 100).
     * @param int                 $offset   Pagination offset (default: 0).
     *
     * @return array<string,mixed> Paginated results with 'results' and 'total' keys.
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-2
     */
    public function fetchObjects(
        string $register,
        string $schema,
        array $filters=[],
        int $limit=100,
        int $offset=0
    ): array {
        $query = array_merge(
            $filters,
            [
                '_register' => $register,
                '_schema'   => $schema,
                '_limit'    => $limit,
                '_offset'   => $offset,
            ]
        );

        $this->logger->debug(
            'ApiClient: Fetching objects',
            ['register' => $register, 'schema' => $schema, 'limit' => $limit]
        );

        try {
            return $this->objectService->searchObjectsPaginated($query);
        } catch (\Exception $e) {
            $this->logger->error(
                'ApiClient: Failed to fetch objects',
                ['register' => $register, 'schema' => $schema, 'exception' => $e->getMessage()]
            );
            return ['results' => [], 'total' => 0];
        }

    }//end fetchObjects()

    /**
     * Fetch a single object by ID from the given register and schema.
     *
     * @param string $id       The object UUID or ID.
     * @param string $register The register slug.
     * @param string $schema   The schema slug.
     *
     * @return object|null The object entity, or null if not found.
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-2
     */
    public function fetchObject(string $id, string $register, string $schema): ?object
    {
        try {
            return $this->objectService->find(
                id: $id,
                register: $register,
                schema: $schema
            );
        } catch (\Exception $e) {
            $this->logger->warning(
                'ApiClient: Object not found',
                ['id' => $id, 'register' => $register, 'schema' => $schema, 'exception' => $e->getMessage()]
            );
            return null;
        }

    }//end fetchObject()

    /**
     * Save (create or update) an object in the given register and schema.
     *
     * @param string              $register The register slug.
     * @param string              $schema   The schema slug.
     * @param array<string,mixed> $data     The object data to persist.
     *
     * @return object|null The saved object entity, or null on failure.
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-2
     */
    public function saveObject(string $register, string $schema, array $data): ?object
    {
        try {
            return $this->objectService->saveObject(
                register: $register,
                schema: $schema,
                object: $data
            );
        } catch (\Exception $e) {
            $this->logger->error(
                'ApiClient: Failed to save object',
                ['register' => $register, 'schema' => $schema, 'exception' => $e->getMessage()]
            );
            return null;
        }

    }//end saveObject()
}//end class
