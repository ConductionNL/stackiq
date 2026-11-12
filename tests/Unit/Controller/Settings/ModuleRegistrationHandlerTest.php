<?php

/**
 * Characterisation tests for ModuleRegistrationHandler::handle().
 *
 * Pins the validation answer, the registration of each module, the result
 * and every log call before the persistence step is split into named steps.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Controller\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller\Settings;

use OCA\Stackiq\Controller\Settings\ModuleRegistrationHandler;
use OCA\Stackiq\Service\ModuleRegistrationService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ModuleRegistrationHandler.
 */
class ModuleRegistrationHandlerTest extends TestCase {

	/**
	 * Every log call as [level, message, context].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array}>
	 */
	private array $logs = [];

	/**
	 * The registration service double.
	 *
	 * @var ModuleRegistrationService|MockObject
	 */
	private ModuleRegistrationService|MockObject $registration;

	/**
	 * Build the handler with a recording logger.
	 *
	 * @return ModuleRegistrationHandler
	 */
	private function handler(): ModuleRegistrationHandler {
		$this->registration = $this->createMock(ModuleRegistrationService::class);
		$logger             = $this->createMock(LoggerInterface::class);
		foreach (['info', 'error', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logs[] = [$level, $message, $context];
				}
			);
		}

		return new ModuleRegistrationHandler($this->registration, $logger);

	}//end handler()

	/**
	 * Missing modules is a validation failure; nothing is registered or logged.
	 *
	 * @return void
	 */
	public function testValidationFailure(): void {
		$handler = $this->handler();
		$this->registration->expects($this->never())->method('handleModuleRegistration');

		$this->assertSame(
			[
				'success' => false,
				'message' => 'Validation failed: modules field is required and must be an array.',
				'errors' => ['modules field is required and must be an array.'],
			],
			$handler->handle(['modules' => 'x'])
		);
		$this->assertSame([], $this->logs);

	}//end testValidationFailure()

	/**
	 * Each module is registered as an object, then counted and logged.
	 *
	 * @return void
	 */
	public function testRegistersEachModule(): void {
		$handler = $this->handler();
		$seen    = [];
		$this->registration->method('handleModuleRegistration')->willReturnCallback(
			function (object $module) use (&$seen): void {
				$seen[] = $module;
			}
		);

		$result = $handler->handle(['modules' => [['name' => 'a'], ['name' => 'b']]]);

		$this->assertSame(['success' => true, 'message' => '2 module(s) registered successfully.', 'registered' => 2], $result);
		$this->assertEquals([(object)['name' => 'a'], (object)['name' => 'b']], $seen);
		$this->assertSame(
			[
				['debug', 'ModuleRegistrationHandler: Resolving dependencies for modules', ['count' => 2]],
				['info', 'ModuleRegistrationHandler: Modules registered', ['count' => 2]],
			],
			$this->logs
		);

	}//end testRegistersEachModule()

	/**
	 * A failing registration stops the run with the failure answer and an error log.
	 *
	 * @return void
	 */
	public function testFailureStopsTheRun(): void {
		$handler = $this->handler();
		$calls = 0;
		$this->registration->expects($this->exactly(2))->method('handleModuleRegistration')->willReturnCallback(
			function () use (&$calls): void {
				$calls++;
				if ($calls === 2) {
					throw new \RuntimeException('bad module');
				}
			}
		);

		$result = $handler->handle(['modules' => [['name' => 'a'], ['name' => 'b'], ['name' => 'c']]]);

		$this->assertSame(['success' => false, 'message' => 'Failed to register modules: bad module'], $result);
		$this->assertSame(
			[
				['debug', 'ModuleRegistrationHandler: Resolving dependencies for modules', ['count' => 3]],
				['error', 'ModuleRegistrationHandler: Failed to register modules', ['exception' => 'bad module']],
			],
			$this->logs
		);

	}//end testFailureStopsTheRun()
}//end class
