<?php

/**
 * Test stub for OCA\OpenRegister\Service\ObjectService.
 *
 * The real ObjectService lives in the OpenRegister app which is not available
 * as a Composer dependency in the test environment. This stub declares the
 * methods used by SoftwareCatalog unit tests so PHPUnit can create mocks.
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\SoftwareCatalog\Tests\Stubs\Service
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Stub for ObjectService with the surface used by SoftwareCatalog tests.
 */
abstract class ObjectService
{

    /**
     * Find a single object.
     *
     * @param string|int $id          Object id or uuid.
     * @param string     $register    Register slug or id.
     * @param string     $schema      Schema slug or id.
     * @param bool       $_rbac       Apply RBAC.
     * @param bool       $_multitenancy Apply multitenancy.
     *
     * @return ObjectEntity|null
     */
    abstract public function find(
        string|int $id,
        string $register='',
        string $schema='',
        bool $_rbac=true,
        bool $_multitenancy=true
    ): ?ObjectEntity;

    /**
     * Search objects with pagination.
     *
     * @param array $query          Search query.
     * @param bool  $_rbac          Apply RBAC.
     * @param bool  $_multitenancy  Apply multitenancy.
     *
     * @return array
     */
    abstract public function searchObjectsPaginated(
        array $query=[],
        bool $_rbac=true,
        bool $_multitenancy=true
    ): array;

}//end class
