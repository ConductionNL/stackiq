<?php

/**
 * Stackiq's health and metrics are the OpenRegister AppHost generics (adopt-apphost).
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\AppInfo
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/adopt-apphost/tasks.md#2-wiring-deletions-real-healthmetrics-routes
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\AppInfo;

use OCA\Stackiq\AppInfo\Application;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionMethod;

/**
 * Covers Application::registerObservabilityControllers(), the routes and the manifest block.
 *
 * @coversNothing
 */
class ObservabilityAdoptionTest extends TestCase {

	/**
	 * The services the method registers, by name.
	 *
	 * @return array<string, \Closure>
	 */
	private function registrations(): array {
		$registered = [];
		$context    = $this->createMock(IRegistrationContext::class);
		$context->method('registerService')->willReturnCallback(
			function (string $name, \Closure $factory) use (&$registered): void {
				$registered[$name] = $factory;
			}
		);

		$method = new ReflectionMethod(Application::class, 'registerObservabilityControllers');
		$method->invoke((new \ReflectionClass(Application::class))->newInstanceWithoutConstructor(), $context);

		return $registered;
	}//end registrations()

	/**
	 * The two routes resolve to the generics, built from what the container holds.
	 *
	 * @return void
	 */
	public function testHealthAndMetricsAreTheAppHostGenerics(): void {
		$registered = $this->registrations();
		$this->assertSame(['OCA\\Stackiq\\Controller\\HealthController', 'OCA\\Stackiq\\Controller\\MetricsController'], array_keys($registered));

		$asked     = [];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use (&$asked) {
				$asked[] = $id;
				if ($id === 'OCP\\IRequest') {
					return $this->createMock(IRequest::class);
				}

				return $this->createMock($id);
			}
		);

		$generics = [
			'OCA\\Stackiq\\Controller\\HealthController'  => 'OCA\\OpenRegister\\AppHost\\Controller\\GenericHealthController',
			'OCA\\Stackiq\\Controller\\MetricsController' => 'OCA\\OpenRegister\\AppHost\\Controller\\GenericMetricsController',
		];
		foreach ($generics as $name => $generic) {
			try {
				$controller = $registered[$name]($container);
			} catch (\Error $missing) {
				// OpenRegister is not on this test autoloader: the factory still named the generic.
				$this->assertSame('Class "' . $generic . '" not found', $missing->getMessage());
				continue;
			}

			$this->assertInstanceOf($generic, $controller);
			$this->assertContains(
				$name === 'OCA\\Stackiq\\Controller\\HealthController' ? 'OCA\\OpenRegister\\AppHost\\Observability\\HealthCheckExecutor' : 'OCA\\OpenRegister\\AppHost\\Observability\\MetricsEngine',
				$asked
			);
		}
	}//end testHealthAndMetricsAreTheAppHostGenerics()

	/**
	 * Registering autoloads nothing from OpenRegister: the factories only name it.
	 *
	 * @return void
	 */
	public function testRegisteringTouchesNoOpenRegisterClass(): void {
		$registered = $this->registrations();

		$this->assertCount(2, $registered);
		foreach ($registered as $factory) {
			$this->assertTrue((new \ReflectionFunction($factory))->isStatic(), 'a static closure keeps no reference to the app');
		}
	}//end testRegisteringTouchesNoOpenRegisterClass()

	/**
	 * `/api/health` and `/api/metrics` are routed; the old note that settings#status served health is gone.
	 *
	 * @return void
	 */
	public function testTheRoutesExistAndSettingsStatusIsNoLongerCalledHealth(): void {
		$source = (string)file_get_contents(__DIR__ . '/../../../appinfo/routes.php');
		$routes = (require __DIR__ . '/../../../appinfo/routes.php')['routes'];
		$byName = [];
		foreach ($routes as $route) {
			$byName[$route['name']][] = $route['url'];
		}

		$this->assertContains('/api/health', $byName['health#index']);
		$this->assertContains('/api/metrics', $byName['metrics#index']);
		$this->assertContains('/api/settings/status', $byName['settings#status'], 'the settings status endpoint is unchanged');
		$this->assertStringNotContainsString('/api/health is served by settings#status', $source);
	}//end testTheRoutesExistAndSettingsStatusIsNoLongerCalledHealth()

	/**
	 * The manifest declares the database and OpenRegister checks and the usage gauge.
	 *
	 * @return void
	 */
	public function testTheManifestDeclaresTheObservabilityBlock(): void {
		$manifest = json_decode((string)file_get_contents(__DIR__ . '/../../../src/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
		$block    = $manifest['observability'];

		$this->assertSame('adr006', $block['health']['statusCodePolicy']);
		$this->assertSame([['database', 'database'], ['openregister', 'orAvailable']], array_map(fn (array $check): array => [$check['id'], $check['type']], $block['health']['checks']));
		$this->assertSame(
			[['name' => 'gebruik_total', 'type' => 'gauge', 'help' => 'Gebruik (usage) records', 'source' => ['kind' => 'objectCount', 'register' => 'stackiq', 'schema' => 'usage']]],
			$block['metrics']
		);
	}//end testTheManifestDeclaresTheObservabilityBlock()
}//end class
