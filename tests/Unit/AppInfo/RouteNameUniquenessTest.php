<?php

/**
 * Every declared route must survive registration.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec exclude Mechanical invariant of appinfo/routes.php, not a product requirement.
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\AppInfo;

use PHPUnit\Framework\TestCase;

/**
 * Nextcloud names a route after its controller, its action and its `postfix`,
 * and after nothing else. `OC\AppFramework\Routing\RouteParser::processRoute()`
 * builds `strtolower($appName . '.' . $controller . '.' . $action . $postfix)`,
 * and `RouteCollection::add()` OVERWRITES an entry that already carries that
 * name.
 *
 * Neither the URL nor the verb is part of the name. So two entries that point
 * at the same controller action and declare no `postfix` are one route, and the
 * last one declared is the one that survives. Nothing warns, and `routes.php`
 * still reads as though both are there.
 *
 * 🔴 THIS APP LOST ONE OF 132 ROUTES THAT WAY. `settings#getArchiMateConfig`
 * was declared twice, at `/api/archimate/config` and at
 * `/api/archimate/status`, with no `postfix` on either. The later declaration
 * wins, so `GET /api/archimate/status` answers and `GET /api/archimate/config`
 * is a 404 that no part of this repo mentions.
 *
 * Which of the two survives is decided by line order alone, and that is the
 * part worth a test. `src/store/modules/settings.js` polls
 * `/api/archimate/status` from `loadArchiMateStatus()` and from
 * `refreshArchiMateStatus()`, and both wrap the fetch in a `catch` with an
 * empty body. Swap those two lines in `routes.php` and the ArchiMate import and
 * export progress stops updating, silently, with no error anywhere.
 *
 * 🔑 Unit tests cannot see this. They call the controller action directly, and
 * an action with a full test suite and no reachable route answers exactly like
 * one that works. So the assertion below is on the registration KEY of each
 * entry, not on the file parsing or on the array being non-empty.
 */
class RouteNameUniquenessTest extends TestCase {

	/**
	 * Read the declared route entries.
	 *
	 * @return array<string, array<int, array<string, mixed>>> The route file.
	 */
	private function routeFile(): array {
		$routes = include dirname(__DIR__, 3) . '/appinfo/routes.php';
		$this->assertIsArray($routes, 'appinfo/routes.php must return an array');

		return $routes;
	}

	/**
	 * The key Nextcloud registers a route under, minus the app name.
	 *
	 * @param array<string, mixed> $route One entry from the route file.
	 *
	 * @return string The registration key.
	 */
	private function registrationKey(array $route): string {
		return strtolower($route['name'] . ($route['postfix'] ?? ''));
	}

	/**
	 * No two entries may register under the same key.
	 *
	 * @return void
	 */
	public function testEveryDeclaredRouteRegistersUnderItsOwnName(): void {
		$file = $this->routeFile();

		foreach (['routes', 'ocs'] as $section) {
			$seen = [];

			foreach (($file[$section] ?? []) as $entry) {
				$key = $this->registrationKey($entry);

				$this->assertArrayNotHasKey(
					$key,
					$seen,
					sprintf(
						"Two '%s' entries register as '%s', so Nextcloud keeps only the last one.\n"
						. "  kept:        %s %s\n"
						. "  OVERWRITTEN: %s %s\n"
						. "Give each entry its own 'postfix'.",
						$section,
						$key,
						$entry['verb'] ?? 'GET',
						$entry['url'] ?? '?',
						$seen[$key]['verb'] ?? 'GET',
						$seen[$key]['url'] ?? '?'
					)
				);

				$seen[$key] = $entry;
			}
		}

	}//end testEveryDeclaredRouteRegistersUnderItsOwnName()

	/**
	 * Name both URLs, so a lost one is named and not just counted.
	 *
	 * @return void
	 */
	public function testBothArchimateReadRoutesAreRoutedAgain(): void {
		$entries = $this->routeFile()['routes'];

		$byKey = [];
		foreach ($entries as $entry) {
			$byKey[$this->registrationKey($entry)] = ($entry['verb'] ?? 'GET') . ' ' . $entry['url'];
		}

		$this->assertSame(
			'GET /api/archimate/status',
			($byKey['settings#getarchimateconfigstatus'] ?? null),
			'the ArchiMate status endpoint the settings store polls must keep its own route name'
		);
		$this->assertSame(
			'GET /api/archimate/config',
			($byKey['settings#getarchimateconfig'] ?? null),
			'the ArchiMate config endpoint was the one that lost, and must be reachable'
		);

	}//end testBothArchimateReadRoutesAreRoutedAgain()

}//end class
