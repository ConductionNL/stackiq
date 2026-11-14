<?php

/**
 * Unit tests for ObjectServiceLookup.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\ArchiMate
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\ArchiMate;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Stackiq\Service\ArchiMate\ObjectServiceLookup;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Covers the OpenRegister object service lookup and its two null paths.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ObjectServiceLookupTest extends TestCase {

	/**
	 * Without OpenRegister installed the container is never asked.
	 *
	 * @return void
	 */
	public function testReturnsNullWhenOpenRegisterIsNotInstalled(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->expects($this->once())->method('isInstalled')->with('openregister')->willReturn(false);
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');

		$lookup = new ObjectServiceLookup($appManager, $container, $this->createMock(LoggerInterface::class));

		$this->assertNull($lookup->find());
	}//end testReturnsNullWhenOpenRegisterIsNotInstalled()

	/**
	 * With OpenRegister installed the object service comes from the container.
	 *
	 * @return void
	 */
	public function testReturnsObjectServiceFromContainer(): void {
		$service    = $this->createMock(ObjectServiceInterface::class);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->once())->method('get')->with(ObjectService::class)->willReturn($service);

		$lookup = new ObjectServiceLookup($appManager, $container, $this->createMock(LoggerInterface::class));

		$this->assertSame($service, $lookup->find());
	}//end testReturnsObjectServiceFromContainer()

	/**
	 * A failing container lookup is logged and yields null.
	 *
	 * @return void
	 */
	public function testContainerFailureIsLoggedAndYieldsNull(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('not registered'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with('Failed to get ObjectService', ['error' => 'not registered']);

		$lookup = new ObjectServiceLookup($appManager, $container, $logger);

		$this->assertNull($lookup->find());
	}//end testContainerFailureIsLoggedAndYieldsNull()
}//end class
