<?php

/**
 * Unit tests for the middleware registrar.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Middleware
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Middleware;

use OCA\Stackiq\Middleware\ApiContractMiddleware;
use OCA\Stackiq\Middleware\MiddlewareRegistrar;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * Pins which middleware the app registers.
 *
 * @covers \OCA\Stackiq\Middleware\MiddlewareRegistrar
 */
final class MiddlewareRegistrarTest extends TestCase {

	/**
	 * The API contract middleware is registered, exactly once and nothing else.
	 *
	 * @return void
	 */
	public function testRegistersOnlyTheApiContractMiddleware(): void {
		$context = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->expects($this->once())
			->method('registerMiddleware')
			->with(ApiContractMiddleware::class);
		$context->expects($this->never())->method('registerService');
		$context->expects($this->never())->method('registerServiceAlias');
		$context->expects($this->never())->method('registerEventListener');

		(new MiddlewareRegistrar())->register(context: $context);
	}//end testRegistersOnlyTheApiContractMiddleware()
}//end class
