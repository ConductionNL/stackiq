<?php

/**
 * Tests for the probe that decides whether OpenRegister's pack store may be used.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\Cmdb
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Cmdb;

use OCA\Stackiq\Service\Cmdb\CmdbImportPackStoreProbe;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use stdClass;

/**
 * CmdbImportPackStoreProbe.
 */
class CmdbImportPackStoreProbeTest extends TestCase {
	private const WRITE_METHODS = ['create', 'update', 'destroy', 'import', 'preview'];

	private const MARKERS = ['NoCSRFRequired', 'NoAdminRequired', 'PublicPage', 'AuthorizedAdminSetting'];

	/**
	 * Counter for unique generated class names.
	 *
	 * @var int
	 */
	private static int $classes = 0;

	/**
	 * Generate a controller class and return its name.
	 *
	 * @param array<string, string> $marked Marker per method, written as `attribute` or `docblock`; keyed `method:marker`.
	 * @param array<int, string> $without Methods the class lacks.
	 *
	 * @return string
	 */
	private static function controller(array $marked = [], array $without = []): string {
		$name = 'ProbeController' . (++self::$classes);
		$body = '';
		foreach (self::WRITE_METHODS as $method) {
			if (in_array($method, $without, true) === true) {
				continue;
			}

			$attribute = '';
			$doc = '';
			foreach ($marked as $key => $style) {
				[$onMethod, $marker] = explode(':', $key);
				if ($onMethod !== $method) {
					continue;
				}

				if ($style === 'attribute') {
					$attribute .= '#[\\OCP\\AppFramework\\Http\\Attribute\\' . $marker . ']';
				} else {
					$doc .= ' * @' . $marker . "\n";
				}
			}

			$body .= "/**\n * Method.\n" . $doc . " */\n" . $attribute . ' public function ' . $method . "() {}\n";
		}

		eval('namespace OCA\\Stackiq\\Tests\\Unit\\Generated; class ' . $name . ' {' . $body . '}');
		return 'OCA\\Stackiq\\Tests\\Unit\\Generated\\' . $name;
	}//end controller()

	/**
	 * A service with the methods the store calls.
	 *
	 * @return object
	 */
	private static function service(): object {
		return new class() {
			/** @return null */
			public function findByPackSlug(): mixed {
				return null;
			}

			/** @return null */
			public function find(): mixed {
				return null;
			}

			/** @return null */
			public function create(): mixed {
				return null;
			}

			/** @return null */
			public function update(): mixed {
				return null;
			}
		};
	}//end service()

	/**
	 * A probe over a container that knows (or not) the service.
	 *
	 * @param string $controller The controller class.
	 * @param object|null $service What the container holds; null for nothing.
	 * @param string $hardened The hardened marker class.
	 *
	 * @return CmdbImportPackStoreProbe
	 */
	private function probe(string $controller, ?object $service, string $hardened = stdClass::class): CmdbImportPackStoreProbe {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn($service !== null);
		$container->method('get')->willReturn($service);

		return new CmdbImportPackStoreProbe(container: $container, serviceClass: 'Some\\Service', hardenedClass: $hardened, controllerClass: $controller);
	}//end probe()

	/**
	 * The guarded controller with a complete service is usable.
	 *
	 * @return void
	 */
	public function testAGuardedControllerAndACompleteServiceAreUsable(): void {
		$service = self::service();
		$this->assertSame($service, $this->probe(controller: self::controller(), service: $service)->service());
	}//end testAGuardedControllerAndACompleteServiceAreUsable()

	/**
	 * Every write method with every opening marker, as attribute and as docblock tag, makes the store unavailable.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function openedWrites(): array {
		$cases = [];
		foreach (self::WRITE_METHODS as $method) {
			foreach (self::MARKERS as $marker) {
				foreach (['attribute', 'docblock'] as $style) {
					$cases[$method . ' ' . $marker . ' ' . $style] = [$method, $marker, $style];
				}
			}
		}

		return $cases;
	}//end openedWrites()

	/**
	 * A write method carrying an opening marker.
	 *
	 * @param string $method The method.
	 * @param string $marker The marker.
	 * @param string $style attribute or docblock.
	 *
	 * @return void
	 *
	 * @dataProvider openedWrites
	 */
	public function testAnOpenedWriteMethodMakesTheStoreUnavailable(string $method, string $marker, string $style): void {
		$controller = self::controller(marked: [$method . ':' . $marker => $style]);
		$this->assertNull($this->probe(controller: $controller, service: self::service())->service());
	}//end testAnOpenedWriteMethodMakesTheStoreUnavailable()

	/**
	 * A controller that lacks any one write method makes the store unavailable.
	 *
	 * @return void
	 */
	public function testAMissingWriteMethodMakesTheStoreUnavailable(): void {
		foreach (self::WRITE_METHODS as $method) {
			$controller = self::controller(without: [$method]);
			$this->assertNull($this->probe(controller: $controller, service: self::service())->service(), $method);
		}
	}//end testAMissingWriteMethodMakesTheStoreUnavailable()

	/**
	 * Without the hardened marker class, the service in the container or one of its methods, the store is unavailable.
	 *
	 * @return void
	 */
	public function testAMissingClassServiceOrServiceMethodMakesTheStoreUnavailable(): void {
		$controller = self::controller();

		$this->assertNull($this->probe(controller: $controller, service: self::service(), hardened: 'No\\Such\\Class')->service(), 'hardened class missing');
		$this->assertNull($this->probe(controller: $controller, service: null)->service(), 'the container has no service');
		$this->assertNull($this->probe(controller: $controller, service: new stdClass())->service(), 'the service lacks its methods');
		$this->assertNull($this->probe(controller: 'No\\Such\\Controller', service: self::service())->service(), 'controller missing');
	}//end testAMissingClassServiceOrServiceMethodMakesTheStoreUnavailable()

	/**
	 * A container that throws makes the store unavailable.
	 *
	 * @return void
	 */
	public function testAContainerThatThrowsMakesTheStoreUnavailable(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willThrowException(new \RuntimeException('boom'));
		$probe = new CmdbImportPackStoreProbe(container: $container, serviceClass: 'Some\\Service', hardenedClass: stdClass::class, controllerClass: self::controller());

		$this->assertNull($probe->service());
	}//end testAContainerThatThrowsMakesTheStoreUnavailable()
}//end class
