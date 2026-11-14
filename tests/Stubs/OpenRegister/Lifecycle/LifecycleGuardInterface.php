<?php

/**
 * Test stub of OpenRegister's LifecycleGuardInterface.
 *
 * A copy of `lib/Lifecycle/LifecycleGuardInterface.php` in
 * ConductionNL/openregister: the same one method, so a guard that does not
 * implement it fails here as it would in production.
 *
 * @category  Tests
 * @package   OCA\OpenRegister\Lifecycle
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Lifecycle;

/**
 * Apps implement this interface to authorise a lifecycle transition.
 */
interface LifecycleGuardInterface {
	/**
	 * Authorise (or deny) a transition.
	 *
	 * The array carries no key type on purpose: psalm reads a stub's docblock
	 * types as signature types, so `array<string, mixed>` here would be held
	 * against the native `array` an implementation writes.
	 *
	 * @param array $object The object payload.
	 * @param string $action The transition action being applied.
	 * @param string $userId The uid of the caller.
	 *
	 * @return GuardResult
	 */
	public function check(array $object, string $action, string $userId): GuardResult;
}//end interface
