<?php

/**
 * Unit tests for StackiqService::getOrganisationMapper().
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/method-decomposition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\Stackiq\Service\StackiqService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * THE INVARIANT UNDER TEST — OpenRegister is an OPTIONAL capability here.
 *
 * Eight call sites in StackiqService used to resolve
 * `OCA\OpenRegister\Db\OrganisationMapper` from the container inline, so on an
 * instance without OpenRegister each one let a raw container exception escape.
 * They now go through `getOrganisationMapper()`, which answers null instead —
 * and every one of those call sites has an explicit not-available branch that
 * is only correct if this accessor really does degrade.
 *
 * That makes the three arms below the contract the call sites are written
 * against, not incidental coverage:
 *
 *   - OpenRegister not enabled      -> null, and the container is never asked
 *   - enabled and resolvable        -> the mapper itself
 *   - enabled but resolution throws -> null, and the failure is logged
 *
 * The middle arm is the positive control. Without it, an accessor that
 * returned null unconditionally would satisfy both null assertions while
 * silently disabling every organisation-membership path in the app.
 */
final class StackiqServiceOrganisationMapperTest extends TestCase {

	/**
	 * Build a StackiqService with only the three properties this
	 * accessor reads, seeded by reflection.
	 *
	 * The constructor is skipped deliberately: it takes the app's full
	 * dependency set and none of it is reachable from this method. Note that
	 * reading an UNINITIALISED typed property is an Error rather than a null,
	 * so every property the method touches must be seeded here — that is why
	 * `_appManager` is seeded even on the arm that never reaches the container.
	 *
	 * @param IAppManager       $appManager The app manager double.
	 * @param ContainerInterface $container The container double.
	 * @param LoggerInterface   $logger     The logger double.
	 *
	 * @return StackiqService The service under test.
	 */
	private function buildService(
		IAppManager $appManager,
		ContainerInterface $container,
		LoggerInterface $logger,
	): StackiqService {
		$service = (new \ReflectionClass(StackiqService::class))
			->newInstanceWithoutConstructor();

		$reflection = new \ReflectionClass($service);

		foreach (
			[
				'_appManager' => $appManager,
				'_container' => $container,
				'_logger' => $logger,
			] as $name => $value
		) {
			$property = $reflection->getProperty($name);
			$property->setAccessible(true);
			$property->setValue($service, $value);
		}

		return $service;

	}//end buildService()

	/**
	 * Invoke the private accessor.
	 *
	 * @param StackiqService $service The service under test.
	 *
	 * @return OrganisationMapper|null The resolved mapper, or null.
	 */
	private function callAccessor(StackiqService $service): ?OrganisationMapper {
		$method = new \ReflectionMethod($service, 'getOrganisationMapper');
		$method->setAccessible(true);
		return $method->invoke($service);

	}//end callAccessor()

	/**
	 * OpenRegister disabled: null, and the container is never consulted —
	 * asking it would be the unguarded lookup this accessor exists to replace.
	 *
	 * @return void
	 */
	public function testReturnsNullAndNeverAsksTheContainerWhenOpenRegisterIsDisabled(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturn(false);

		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');

		$service = $this->buildService(
			appManager: $appManager,
			container: $container,
			logger: $this->createMock(LoggerInterface::class)
		);

		$this->assertNull(
			$this->callAccessor($service),
			'With OpenRegister disabled the accessor must answer null so callers take their not-available branch.'
		);

	}//end testReturnsNullAndNeverAsksTheContainerWhenOpenRegisterIsDisabled()

	/**
	 * THE POSITIVE CONTROL. With OpenRegister enabled and resolvable, the
	 * accessor must hand back the mapper itself — otherwise the two null
	 * assertions here are satisfied by an accessor that never works.
	 *
	 * @return void
	 */
	public function testReturnsTheMapperWhenOpenRegisterIsAvailable(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturn(true);

		$mapper = $this->createMock(OrganisationMapper::class);

		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->once())
			->method('get')
			->with('OCA\\OpenRegister\\Db\\OrganisationMapper')
			->willReturn($mapper);

		$service = $this->buildService(
			appManager: $appManager,
			container: $container,
			logger: $this->createMock(LoggerInterface::class)
		);

		$this->assertSame(
			$mapper,
			$this->callAccessor($service),
			'The accessor must return the resolved mapper, not merely a non-null value.'
		);

	}//end testReturnsTheMapperWhenOpenRegisterIsAvailable()

	/**
	 * A failed resolution DEGRADES: null plus a logged error, never an escaping
	 * container exception. This is the arm the eight call sites depend on — and
	 * it is also what makes the lookup legible as an optional capability rather
	 * than an unconditional dependency.
	 *
	 * @return void
	 */
	public function testDegradesToNullAndLogsWhenResolutionThrows(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturn(true);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('no such service'));

		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message) use (&$logged): void {
				$logged[] = $message;
			}
		);

		$service = $this->buildService(
			appManager: $appManager,
			container: $container,
			logger: $logger
		);

		$this->assertNull(
			$this->callAccessor($service),
			'A container failure must degrade to null rather than escape to the caller.'
		);

		$this->assertNotEmpty(
			$logged,
			'Degrading silently would hide a broken OpenRegister install behind an ordinary not-available branch.'
		);
		$this->assertStringContainsString('no such service', $logged[0]);

	}//end testDegradesToNullAndLogsWhenResolutionThrows()

}//end class
