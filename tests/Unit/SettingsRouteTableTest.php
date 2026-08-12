<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction b.v. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit;

use OCA\SoftwareCatalog\Controller\SettingsController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Evaluates `appinfo/routes.php` and pins the canonical `/api/settings` verbs.
 *
 * The route table is EVALUATED, not grepped: a commented-out entry or a line
 * inside a string would satisfy a grep while the router never sees it.
 *
 * @spec openspec/specs/method-decomposition/spec.md#requirement-settingscontroller-settings-crud-endpoints-req-decomp-013
 */
class SettingsRouteTableTest extends TestCase {
	/**
	 * The evaluated route entries.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function routes(): array {
		$table = require __DIR__ . '/../../appinfo/routes.php';

		$this->assertIsArray($table);
		$this->assertArrayHasKey('routes', $table);
		$this->assertIsArray($table['routes']);

		return $table['routes'];
	}

	/**
	 * Find every entry matching a name/url/verb triple.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function match(array $routes, string $name, string $url, string $verb): array {
		return array_values(
			array_filter(
				$routes,
				static fn ($r) => ($r['name'] ?? null) === $name
					&& ($r['url'] ?? null) === $url
					&& ($r['verb'] ?? null) === $verb
			)
		);
	}

	/**
	 * The canonical AppHost triples on `/api/settings`.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function canonicalRouteProvider(): array {
		return [
			'GET  read' => ['settings#index', '/api/settings', 'GET'],
			'POST legacy write' => ['settings#create', '/api/settings', 'POST'],
			'PUT  canonical write' => ['settings#update', '/api/settings', 'PUT'],
		];
	}

	/**
	 * Each canonical route must be registered exactly once.
	 *
	 * @dataProvider canonicalRouteProvider
	 */
	public function testCanonicalRouteIsRegistered(string $name, string $url, string $verb): void {
		$hits = $this->match($this->routes(), $name, $url, $verb);

		$this->assertCount(
			1,
			$hits,
			sprintf('Expected exactly one route %s %s → %s, found %d.', $verb, $url, $name, count($hits))
		);
	}

	/**
	 * Positive control: the route table must be non-trivial.
	 *
	 * If `routes.php` returned an empty list the matcher above would report
	 * "not found" for the right reason, but a matcher bug that scanned
	 * nothing would look identical. This pins that routes were inspected.
	 */
	public function testPositiveControlRouteTableIsPopulated(): void {
		$routes = $this->routes();
		$inspected = count($routes);

		$this->assertGreaterThan(0, $inspected, 'Positive control: the route table evaluated to zero entries.');
		$this->assertGreaterThan(
			50,
			$inspected,
			'Positive control: far fewer routes than expected — the table did not evaluate fully.'
		);

		$settingsRoutes = array_filter(
			$routes,
			static fn ($r) => str_starts_with((string)($r['name'] ?? ''), 'settings#')
		);
		$this->assertGreaterThan(0, count($settingsRoutes), 'Positive control: no settings# routes matched.');
	}

	/**
	 * Every route entry must target a method that actually exists (gate-14).
	 */
	public function testEverySettingsRouteTargetsAnExistingPublicMethod(): void {
		$reflection = new ReflectionClass(SettingsController::class);
		$checked = 0;

		foreach ($this->routes() as $route) {
			$name = (string)($route['name'] ?? '');
			if (str_starts_with($name, 'settings#') === false) {
				continue;
			}

			$method = substr($name, strlen('settings#'));
			$this->assertTrue(
				$reflection->hasMethod($method),
				sprintf('Route %s points at a nonexistent SettingsController::%s().', $name, $method)
			);
			$this->assertTrue(
				$reflection->getMethod($method)->isPublic(),
				sprintf('Route %s targets non-public SettingsController::%s().', $name, $method)
			);
			$checked++;
		}

		$this->assertGreaterThan(0, $checked, 'Positive control: no settings# routes were checked.');
	}

	/**
	 * The PUT entry must sit before the SPA `/{path}` catch-all.
	 */
	public function testCanonicalWriteIsDeclaredBeforeTheSpaCatchAll(): void {
		$routes = $this->routes();

		$updateIndex = null;
		$catchAllIndex = null;

		foreach ($routes as $i => $route) {
			if (($route['name'] ?? null) === 'settings#update' && ($route['verb'] ?? null) === 'PUT') {
				$updateIndex = $i;
			}

			if (($route['url'] ?? null) === '/{path}') {
				$catchAllIndex = $i;
			}
		}

		$this->assertNotNull($updateIndex, 'settings#update PUT is not registered.');
		$this->assertNotNull($catchAllIndex, 'The SPA catch-all is missing — test premise is stale.');
		$this->assertLessThan($catchAllIndex, $updateIndex);
	}
}
