<?php

/**
 * Platform properties that must stay true (platform-properties).
 *
 * stackiq is EUPL-1.2 licensed in both its app manifest and its LICENSE file
 * (REQ-PLAT-001), and every route it serves declares who may call it, so its own
 * `/api/*` keeps the access the schemas give (REQ-PLAT-002).
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-the-catalogue-runs-on-the-customers-own-nextcloud-req-plat-001
 * @spec openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-catalogue-data-is-read-and-written-through-a-rest-api-req-plat-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\AppInfo;

use PHPUnit\Framework\TestCase;

/**
 * Reads the shipped files, so it needs no Nextcloud.
 *
 * @coversNothing
 */
class PlatformPropertiesTest extends TestCase {

	private const ROOT = __DIR__ . '/../../..';

	/**
	 * The attributes that state who may call a controller method.
	 *
	 * @var list<string>
	 */
	private const ACCESS_ATTRIBUTES = ['NoAdminRequired', 'PublicPage', 'AuthorizedAdminSetting', 'SubAdminRequired'];

	public function testTheAppAndItsLicenceFileAreEupl(): void {
		$info = simplexml_load_file(self::ROOT . '/appinfo/info.xml');

		$this->assertNotFalse($info);
		$this->assertSame('EUPL-1.2', (string) $info->licence);
		$this->assertStringStartsWith('EUROPEAN UNION PUBLIC LICENCE v. 1.2', (string) file_get_contents(self::ROOT . '/LICENSE'));
	}//end testTheAppAndItsLicenceFileAreEupl()

	public function testEveryRouteDeclaresWhoMayCallIt(): void {
		$routes = self::routes();
		$this->assertGreaterThan(50, count($routes), 'the routes are read at all');

		$undeclared = [];
		foreach ($routes as [$controller, $method]) {
			$posture = self::posture(controller: $controller, method: $method);
			if ($posture === null) {
				$undeclared[] = $controller . '#' . $method;
			}
		}

		$this->assertSame([], $undeclared, 'Routed methods without an access attribute or a documented admin-only posture');
	}//end testEveryRouteDeclaresWhoMayCallIt()

	public function testAMethodWithoutAnyAccessDeclarationIsFound(): void {
		$source = <<<'PHP'
		<?php
		class ExampleController {
			/**
			 * @return void
			 */
			public function open(): void {}

			#[NoAdminRequired]
			public function shared(): void {}

			/**
			 * @auth admin-only it writes across tenants.
			 */
			public function admin(): void {}
		}
		PHP;

		$this->assertNull(self::postureIn(source: $source, method: 'open'));
		$this->assertSame('NoAdminRequired', self::postureIn(source: $source, method: 'shared'));
		$this->assertSame('admin-only', self::postureIn(source: $source, method: 'admin'));
		$this->assertNull(self::postureIn(source: $source, method: 'missing'));
	}//end testAMethodWithoutAnyAccessDeclarationIsFound()

	/**
	 * Every `controller#method` routes.php names.
	 *
	 * @return list<array{0: string, 1: string}>
	 */
	private static function routes(): array {
		$source = (string) file_get_contents(self::ROOT . '/appinfo/routes.php');
		preg_match_all("/'name'\\s*=>\\s*'(\\w+)#(\\w+)'/", $source, $matches, PREG_SET_ORDER);

		return array_values(array_unique(array_map(static fn (array $match): array => [$match[1], $match[2]], $matches), SORT_REGULAR));
	}//end routes()

	/**
	 * How a routed method declares who may call it.
	 *
	 * @param string $controller The route's controller part, e.g. `cmdbImport`.
	 * @param string $method     The method.
	 *
	 * @return string|null The attribute, `admin-only`, or null when it declares nothing or does not exist.
	 */
	private static function posture(string $controller, string $method): ?string {
		$path = self::ROOT . '/lib/Controller/' . ucfirst($controller) . 'Controller.php';
		if (is_file($path) === false) {
			return null;
		}

		return self::postureIn(source: (string) file_get_contents($path), method: $method);
	}//end posture()

	/**
	 * How a method in a source file declares who may call it: one of the access
	 * attributes (or their docblock annotation), or `@auth admin-only` for a method
	 * that keeps Nextcloud's default of admins only on purpose.
	 *
	 * @param string $source The controller source.
	 * @param string $method The method.
	 *
	 * @return string|null
	 */
	private static function postureIn(string $source, string $method): ?string {
		// A docblock may not run past its own end, or it would carry an earlier method's attributes.
		$pattern = '/((?:\\s*#\\[[^\\]]*\\]|\\s*\\/\\*\\*(?:(?!\\*\\/).)*\\*\\/)*)\\s*public function ' . preg_quote($method, '/') . '\\s*\\(/s';
		if (preg_match($pattern, $source, $match) !== 1) {
			return null;
		}

		foreach (self::ACCESS_ATTRIBUTES as $attribute) {
			if (preg_match('/#\\[\\s*(?:\\\\?[\\w\\\\]*\\\\)?' . $attribute . '\\b|@' . $attribute . '\\b/', $match[1]) === 1) {
				return $attribute;
			}
		}

		if (preg_match('/@auth\\s+admin-only\\b/', $match[1]) === 1) {
			return 'admin-only';
		}

		return null;
	}//end postureIn()
}//end class
