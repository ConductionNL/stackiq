<?php

/**
 * Structural guard: stackiq must not ship its own GuzzleHttp classes.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Nextcloud bundles its own Guzzle for OCP\Http\Client\IClientService.
 * Composer autoloaders are global, so a GuzzleHttp\ class in this app's
 * vendor/ can be loaded instead of the server's and break every outgoing
 * HTTP request on the instance (Guzzle 8 rejects the CURLOPT_HTTP_VERSION
 * option Nextcloud passes). Use IClientService in lib/ and Symfony
 * HttpClient in tests; never add guzzlehttp/* to composer.json.
 */
final class NoBundledGuzzleTest extends TestCase {
	/**
	 * Absolute path to the app root.
	 *
	 * @var string
	 */
	private string $appRoot;

	/**
	 * Resolve the app root.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->appRoot = dirname(__DIR__, 2);
	}

	/**
	 * The lock file must not resolve any guzzlehttp/* package, dev or not.
	 *
	 * @return void
	 */
	public function testLockFileContainsNoGuzzlePackage(): void {
		$lock = json_decode((string) file_get_contents($this->appRoot . '/composer.lock'), true);
		$this->assertIsArray(actual: $lock);

		$found = [];
		foreach (['packages', 'packages-dev'] as $section) {
			foreach (($lock[$section] ?? []) as $package) {
				if (str_starts_with((string) ($package['name'] ?? ''), 'guzzlehttp/') === true) {
					$found[] = $section . ': ' . $package['name'];
				}
			}
		}

		$this->assertSame(expected: [], actual: $found, message: 'composer.lock must not contain guzzlehttp/* packages; they shadow Nextcloud\'s Guzzle.');
	}

	/**
	 * The generated autoloader must not map the global GuzzleHttp\ namespace.
	 *
	 * @return void
	 */
	public function testAutoloaderDoesNotMapGuzzleNamespace(): void {
		$composerDir = $this->appRoot . '/vendor/composer';
		if (is_dir($composerDir) === false) {
			$this->markTestSkipped(message: 'vendor/ is not installed.');
		}

		$found = [];
		foreach (['autoload_psr4.php', 'autoload_classmap.php'] as $file) {
			$path = $composerDir . '/' . $file;
			if (is_file($path) === false) {
				continue;
			}

			$map = include $path;
			foreach (array_keys((array) $map) as $key) {
				if (str_starts_with((string) $key, 'GuzzleHttp\\') === true) {
					$found[] = $file . ': ' . $key;
				}
			}
		}

		$this->assertSame(expected: [], actual: $found, message: 'vendor/ must not autoload GuzzleHttp\\ classes; they shadow Nextcloud\'s Guzzle.');
	}
}
