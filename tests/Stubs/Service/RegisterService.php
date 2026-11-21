<?php

/**
 * Test stub for OCA\OpenRegister\Service\RegisterService.
 *
 * The real RegisterService lives in the OpenRegister app, which is not
 * available as a Composer dependency in the test environment. This stub
 * declares the surface Stackiq unit tests mock (findAll).
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Stubs\Service
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * Stub for RegisterService with the surface used by Stackiq tests.
 */
abstract class RegisterService {

	/**
	 * Find all registers.
	 *
	 * @return array<int, mixed> The registers, as entities or arrays.
	 */
	abstract public function findAll(): array;

}//end class
