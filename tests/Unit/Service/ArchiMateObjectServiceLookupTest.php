<?php

/**
 * Characterisation test for the OpenRegister object service lookup of
 * ArchiMateService and ArchiMateImportService.
 *
 * Pins, for both services: null without OpenRegister (and no container
 * lookup), null plus a warning when the container cannot resolve the
 * service, and the concrete ObjectService otherwise.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/archimate-import/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Stackiq\Service\ArchiMateImportService;
use OCA\Stackiq\Service\ArchiMateService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;

/**
 * Characterisation of the ArchiMate object service lookup.
 *
 * @spec openspec/specs/archimate-import/spec.md
 */
class ArchiMateObjectServiceLookupTest extends TestCase {

	/**
	 * Everything the doubles saw, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $trace = [];

	/**
	 * The services.
	 *
	 * @return array<string, array{0: class-string}>
	 */
	public static function services(): array {
		return [
			'ArchiMateService'       => [ArchiMateService::class],
			'ArchiMateImportService' => [ArchiMateImportService::class],
		];

	}//end services()

	/**
	 * Without OpenRegister there is no object service and no container lookup.
	 *
	 * @param class-string $class The service.
	 *
	 * @return void
	 *
	 * @dataProvider services
	 */
	public function testWithoutOpenRegisterThereIsNone(string $class): void {
		$this->assertNull(actual: $this->lookup(class: $class, plan: 'notInstalled'));
		$this->assertSame(expected: [['appManager.isInstalled', 'openregister']], actual: $this->trace);

	}//end testWithoutOpenRegisterThereIsNone()

	/**
	 * A failing container lookup gives null and a warning.
	 *
	 * @param class-string $class The service.
	 *
	 * @return void
	 *
	 * @dataProvider services
	 */
	public function testAFailingLookupGivesNoneAndAWarning(string $class): void {
		$this->assertNull(actual: $this->lookup(class: $class, plan: 'throws'));
		$this->assertSame(
			expected: [
				['appManager.isInstalled', 'openregister'],
				['container.get', ObjectService::class],
				['log.warning', 'Failed to get ObjectService', ['error' => 'not registered']],
			],
			actual: $this->trace
		);

	}//end testAFailingLookupGivesNoneAndAWarning()

	/**
	 * Otherwise the concrete object service comes from the container.
	 *
	 * @param class-string $class The service.
	 *
	 * @return void
	 *
	 * @dataProvider services
	 */
	public function testTheObjectServiceComesFromTheContainer(string $class): void {
		$this->assertInstanceOf(expected: ObjectServiceInterface::class, actual: $this->lookup(class: $class, plan: 'ok'));
		$this->assertSame(expected: [['appManager.isInstalled', 'openregister'], ['container.get', ObjectService::class]], actual: $this->trace);

	}//end testTheObjectServiceComesFromTheContainer()

	/**
	 * Run the service's lookup with recording doubles.
	 *
	 * @param class-string $class The service.
	 * @param string       $plan  notInstalled, throws or ok.
	 *
	 * @return ObjectServiceInterface|null
	 */
	private function lookup(string $class, string $plan): ?ObjectServiceInterface {
		$this->trace = [];
		$appManager  = $this->createMock(originalClassName: IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(
			function (string $appId) use ($plan): bool {
				$this->trace[] = ['appManager.isInstalled', $appId];
				return $plan !== 'notInstalled';
			}
		);
		$objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$container     = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($plan, $objectService) {
				$this->trace[] = ['container.get', $id];
				if ($plan === 'throws') {
					throw new \RuntimeException('not registered');
				}

				return $objectService;
			}
		);

		$service = (new \ReflectionClass(objectOrClass: $class))->newInstanceWithoutConstructor();
		foreach (['appManager' => $appManager, 'container' => $container, 'logger' => $this->logger()] as $prop => $value) {
			(new \ReflectionProperty(class: $class, property: $prop))->setValue($service, $value);
		}

		return (new \ReflectionMethod(objectOrMethod: $class, method: 'getObjectService'))->invoke($service);

	}//end lookup()

	/**
	 * A logger that records every line.
	 *
	 * @return AbstractLogger
	 */
	private function logger(): AbstractLogger {
		$trace = &$this->trace;
		return new class($trace) extends AbstractLogger {
			/**
			 * Build the logger.
			 *
			 * @param array $trace The shared trace.
			 */
			public function __construct(private array &$trace) {
			}

			/**
			 * Record one line.
			 *
			 * @param mixed              $level   The level.
			 * @param string|\Stringable $message The message.
			 * @param array              $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->trace[] = ['log.' . $level, (string) $message, $context];
			}
		};

	}//end logger()

}//end class
