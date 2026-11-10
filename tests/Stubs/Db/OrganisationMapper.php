<?php

/**
 * Test stub for OCA\OpenRegister\Db\OrganisationMapper.
 *
 * Declares the narrow surface Stackiq unit tests mock
 * (findByUuid, findBySlug, findList, save, update), so PHPUnit can create mocks without the real
 * OpenRegister mapper being autoloadable.
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Stubs\Db
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Stub for the OpenRegister OrganisationMapper.
 */
abstract class OrganisationMapper {

	/**
	 * Find an organisation by uuid.
	 *
	 * @param string $uuid The organisation uuid.
	 *
	 * @return Organisation
	 */
	abstract public function findByUuid(string $uuid): Organisation;

	/**
	 * Find an organisation by slug.
	 *
	 * @param string $slug The organisation slug.
	 *
	 * @return Organisation
	 */
	abstract public function findBySlug(string $slug): Organisation;

	/**
	 * Find the organisations one list query asks for, ordered by name.
	 *
	 * @param ListQuery $query The limit, offset and column filters.
	 *
	 * @return Organisation[]
	 */
	abstract public function findList(ListQuery $query): array;

	/**
	 * Persist an organisation entity.
	 *
	 * @param Organisation $organisation The entity to save.
	 *
	 * @return Organisation
	 */
	abstract public function save(Organisation $organisation): Organisation;

	/**
	 * Update an organisation entity.
	 *
	 * @param Organisation $entity The entity to update.
	 *
	 * @return Organisation
	 */
	abstract public function update(Organisation $entity): Organisation;

}//end class
