<?php

/**
 * Test stub for OCA\OpenRegister\Db\MagicMapper.
 *
 * The real MagicMapper lives in the OpenRegister app, which is not a composer
 * dependency of this one — the same reason every other stub in this directory
 * exists. ADR-083 injected it into ContactpersonenController, so the
 * controller's tests cannot construct their subject without a type they can
 * load.
 *
 * ⚠️ Declares only the surface this app calls: `update()`. That is deliberate
 * and it is also the weakness — a hand-rolled double drifts from a class nobody
 * here owns, which is exactly what ADR-084 removed for ObjectService by
 * publishing a contract. MagicMapper has no published contract yet, so this
 * stub is the interim. Keep it minimal so the debt stays visible.
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\SoftwareCatalog\Tests\Stubs\Db
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Stub for MagicMapper with the surface SoftwareCatalog uses.
 */
abstract class MagicMapper {

	/**
	 * Persist changes to an existing object.
	 *
	 * @param mixed ...$args The real signature's arguments.
	 *
	 * @return mixed
	 */
	abstract public function update(mixed ...$args): mixed;
}//end class
