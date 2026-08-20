<?php

/**
 * Regression test for SettingsService::getArchiMateStatus()'s fallback path.
 *
 * @category  Test
 * @package   OCA\SoftwareCatalog\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/specs/settings-service/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Service;

use OCA\SoftwareCatalog\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * THE DEFECT UNDER TEST.
 *
 * getArchiMateStatus() falls back to reading the persisted import/export
 * status blobs directly whenever ArchiMateService cannot be resolved from
 * the container. That fallback read the config, json_decode()d it, and
 * then shipped:
 *
 *     $importValue = [];
 *     if (is_array($importDecoded) === true) {
 *     }
 *
 * — an empty if body left behind by commit 651a055f, which hoisted the
 * else-branch out of `if (is_array($d)) { $v = $d; } else { $v = []; }`
 * and deleted the if-branch with the `else` keyword.
 *
 * The decoded status was therefore discarded and the admin panel was
 * handed `import => []` and `export => []` unconditionally. A finished
 * import and an import that never ran rendered identically, which is the
 * failure mode the status blob exists to prevent.
 *
 * The test asserts on the ITEM inside the envelope (`import.processed`),
 * not on the envelope: `getArchiMateStatus()` always returned a
 * well-formed array with `import` and `export` keys, and that is precisely
 * why the defect survived.
 */
final class SettingsServiceArchiMateStatusFallbackTest extends TestCase {

	/**
	 * Build a SettingsService whose container ALWAYS throws, so
	 * getArchiMateStatus() is forced down its config-reading fallback —
	 * the branch that carried the defect.
	 *
	 * @param array $store Reference to the backing key/value store.
	 *
	 * @return SettingsService The service under test.
	 */
	private function makeService(array &$store): SettingsService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use (&$store): string {
				return $store[$key] ?? $default;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(
			new \Exception('ArchiMateService not resolvable in a unit context')
		);

		return new SettingsService(
			config: $config,
			request: $this->createMock(IRequest::class),
			container: $container,
			appManager: $this->createMock(IAppManager::class),
			logger: $this->createMock(LoggerInterface::class),
			groupManager: $this->createMock(IGroupManager::class),
			l10n: $this->createMock(IL10N::class)
		);

	}//end makeService()

	/**
	 * A persisted import status MUST reach the caller. Before the fix this
	 * returned [] no matter what had been stored.
	 *
	 * @return void
	 */
	public function testPersistedImportStatusIsReturnedNotDiscarded(): void {
		$store = [
			'archimate_import_status' => json_encode(
				[
					'status' => 'completed',
					'processed' => 42,
				]
			),
		];

		$status = $this->makeService($store)->getArchiMateStatus();

		$this->assertSame(
			'completed',
			$status['import']['status'] ?? null,
			'The persisted ArchiMate import status must be returned. An empty array here means '
			. 'a finished import and an import that never ran are indistinguishable in the admin panel.'
		);
		$this->assertSame(42, $status['import']['processed'] ?? null);

	}//end testPersistedImportStatusIsReturnedNotDiscarded()

	/**
	 * Same for the export half — two separate sites carried the same
	 * defect, so both need their own arm.
	 *
	 * @return void
	 */
	public function testPersistedExportStatusIsReturnedNotDiscarded(): void {
		$store = [
			'archimate_export_status' => json_encode(
				[
					'status' => 'running',
					'exported' => 7,
				]
			),
		];

		$status = $this->makeService($store)->getArchiMateStatus();

		$this->assertSame('running', $status['export']['status'] ?? null);
		$this->assertSame(7, $status['export']['exported'] ?? null);

	}//end testPersistedExportStatusIsReturnedNotDiscarded()

	/**
	 * The positive control for the negative case: when nothing is stored
	 * the default '{}' decodes to an empty array and an empty array is the
	 * correct answer. Without this arm the assertions above could be
	 * satisfied by code that never consults the config at all.
	 *
	 * @return void
	 */
	public function testUnsetStatusStillYieldsAnEmptyArray(): void {
		$store = [];
		$status = $this->makeService($store)->getArchiMateStatus();

		$this->assertSame([], $status['import']);
		$this->assertSame([], $status['export']);

	}//end testUnsetStatusStillYieldsAnEmptyArray()

}//end class
