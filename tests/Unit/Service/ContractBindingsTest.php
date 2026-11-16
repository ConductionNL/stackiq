<?php

/**
 * Unit tests for ContractBindings.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Stackiq\Kernel\Contracts\ViewServiceInterface;
use OCA\Stackiq\Service\ContractBindings;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\Stackiq\ContactPersonHandler;
use OCA\Stackiq\Service\ViewService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * Every kernel contract is aliased once, to a class that implements it.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Service
 *
 * @covers \OCA\Stackiq\Service\ContractBindings
 */
class ContractBindingsTest extends TestCase {

	/**
	 * Run the bindings against a context that records each alias.
	 *
	 * @return array<int, array{0: string, 1: string}> The [alias, target] pairs in registration order.
	 */
	private function aliases(): array {
		$aliases = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->expects($this->atLeastOnce())->method('registerServiceAlias')->willReturnCallback(
			function (string $alias, string $target) use (&$aliases): void {
				$aliases[] = [$alias, $target];
			}
		);
		$context->expects($this->never())->method('registerService');

		(new ContractBindings())->register($context);

		return $aliases;

	}//end aliases()

	/**
	 * Fifty-six contracts are bound, none of them twice.
	 *
	 * @return void
	 */
	public function testEveryContractIsBoundExactlyOnce(): void {
		$aliases = $this->aliases();
		$names   = array_column($aliases, 0);

		$this->assertCount(57, $aliases);
		$this->assertSame($names, array_values(array_unique($names)));

	}//end testEveryContractIsBoundExactlyOnce()

	/**
	 * Each alias is a kernel contract interface and its target implements it.
	 *
	 * @return void
	 */
	public function testEveryTargetImplementsItsContract(): void {
		foreach ($this->aliases() as [$alias, $target]) {
			$this->assertStringStartsWith('OCA\Stackiq\Kernel\Contracts\\', $alias);
			$this->assertTrue(interface_exists($alias), $alias.' is not an interface');
			$this->assertTrue(class_exists($target), $target.' is not a class');
			$this->assertContains($alias, class_implements($target), $target.' does not implement '.$alias);
		}

	}//end testEveryTargetImplementsItsContract()

	/**
	 * Known contracts resolve to their concrete services, in sorted order.
	 *
	 * @return void
	 */
	public function testKnownContractsPointAtTheirServices(): void {
		$map = [];
		foreach ($this->aliases() as [$alias, $target]) {
			$map[$alias] = $target;
		}

		$this->assertSame(SettingsService::class, $map[SettingsServiceInterface::class]);
		$this->assertSame(ViewService::class, $map[ViewServiceInterface::class]);
		$this->assertSame(ContactPersonHandler::class, $map['OCA\Stackiq\Kernel\Contracts\ContactPersonHandlerInterface']);
		$this->assertSame(ViewServiceInterface::class, array_key_last($map));

	}//end testKnownContractsPointAtTheirServices()
}//end class
