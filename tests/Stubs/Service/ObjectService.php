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
     * @param string|int $id            Object id or uuid.
     * @param array|null $_extend       Optional extend directives.
     * @param bool       $files         Include files.
     * @param string|int|null $register Register slug or id.
     * @param string|int|null $schema   Schema slug or id.
     * @param bool       $_rbac         Apply RBAC.
     * @param bool       $_multitenancy Apply multitenancy.
     *
     * @return ObjectEntity|null
     */
    abstract public function find(
        string|int $id,
        ?array $_extend=[],
        bool $files=false,
        string|int|null $register=null,
        string|int|null $schema=null,
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

    /**
     * Search objects (non-paginated).
     *
     * @param array       $query         Search query.
     * @param bool        $_rbac         Apply RBAC.
     * @param bool        $_multitenancy Apply multitenancy.
     * @param array|null  $ids           Optional id filter.
     * @param string|null $uses          Optional uses filter.
     * @param array|null  $views         Optional views filter.
     *
     * @return array|int
     */
    abstract public function searchObjects(
        array $query=[],
        bool $_rbac=true,
        bool $_multitenancy=true,
        ?array $ids=null,
        ?string $uses=null,
        ?array $views=null
    ): array|int;

    /**
     * Count objects matching a query (true SQL COUNT, no hydration).
     *
     * @param array       $query         Search query.
     * @param bool        $_rbac         Apply RBAC.
     * @param bool        $_multitenancy Apply multitenancy.
     * @param array|null  $ids           Optional id filter.
     * @param string|null $uses          Optional uses filter.
     *
     * @return int
     */
    abstract public function countSearchObjects(
        array $query=[],
        bool $_rbac=true,
        bool $_multitenancy=true,
        ?array $ids=null,
        ?string $uses=null
    ): int;

    /**
     * Persist an object.
     *
     * @param array      $object        The object data bag.
     * @param int|string $register      Register slug or id.
     * @param int|string $schema        Schema slug or id.
     * @param string     $uuid          Object uuid.
     * @param bool       $_rbac         Apply RBAC.
     * @param bool       $_multitenancy Apply multitenancy.
     *
     * @return ObjectEntity
     */
    abstract public function saveObject(
        array $object=[],
        int|string $register='',
        int|string $schema='',
        string $uuid='',
        bool $_rbac=true,
        bool $_multitenancy=true
    ): ObjectEntity;

}//end class
