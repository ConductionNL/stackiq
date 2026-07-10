<?php

/**
 * Test stub for OCA\OpenRegister\Db\OrganisationMapper.
 *
 * Declares the narrow surface SoftwareCatalog unit tests mock
 * (findByUuid + save), so PHPUnit can create mocks without the real
 * OpenRegister mapper being autoloadable.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @category Test
 * @package  OCA\SoftwareCatalog\Tests\Stubs\Db
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Stub for the OpenRegister OrganisationMapper.
 */
abstract class OrganisationMapper
{

    /**
     * Find an organisation by uuid.
     *
     * @param string $uuid The organisation uuid.
     *
     * @return Organisation
     */
    abstract public function findByUuid(string $uuid): Organisation;

    /**
     * Persist an organisation entity.
     *
     * @param Organisation $entity The entity to save.
     *
     * @return Organisation
     */
    abstract public function save(Organisation $entity): Organisation;

}//end class
