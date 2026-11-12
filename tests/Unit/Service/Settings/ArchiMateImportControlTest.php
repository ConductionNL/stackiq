<?php

/**
 * Unit tests for relaying the ArchiMate import and export status to
 * ArchiMateService (ArchiMateImportControl for the writes and the cancel,
 * SettingsService for the read), and the config fallbacks used when it
 * cannot be resolved.
 *
 * The status read fallback is pinned by SettingsServiceArchiMateStatusFallbackTest
 * and SettingsServiceObjectCountsGoldenTest.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Service\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/settings-service/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Settings;

use OCA\Stackiq\Service\ArchiMateService;
use OCA\Stackiq\Service\Settings\ArchiMateImportControl;
use OCA\Stackiq\Service\Settings\CatalogueLocator;
use OCA\Stackiq\Service\Settings\EmailSettings;
use OCA\Stackiq\Service\Settings\RoleGroupSettings;
use OCA\Stackiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Pins each relay method on both its delegated and its fallback path.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @spec openspec/specs/settings-service/spec.md
 */
class ArchiMateImportControlTest extends TestCase {

	/**
	 * Every config call, in order.
	 *
	 * @var array<int, array<int, string>>
	 */
	private array $configCalls = [];

	/**
	 * Every error log, in order, as [message, error].
	 *
	 * @var array<int, array<int, string>>
	 */
	private array $errors = [];

	/**
	 * With ArchiMateService resolvable the status read and the cancel are
	 * handed to it.
	 *
	 * @return void
	 */
	public function testTheReadAndTheCancelAreHandedToArchiMateService(): void {
		$archiMate = $this->createMock(ArchiMateService::class);
		$archiMate->expects($this->once())->method('getArchiMateStatus')->willReturn(['import' => ['s' => 1]]);
		$archiMate->expects($this->once())->method('cancelArchiMateImport')->with('op-1')->willReturn(['cancelled' => true]);

		$this->assertSame(['import' => ['s' => 1]], $this->settings($archiMate)->getArchiMateStatus());
		$this->assertSame(['cancelled' => true], $this->service($archiMate)->cancelArchiMateImport('op-1'));
		$this->assertSame([], $this->configCalls);
		$this->assertSame([], $this->errors);
	}//end testTheReadAndTheCancelAreHandedToArchiMateService()

	/**
	 * ArchiMateService has no status setters and no clear methods, so with
	 * it resolvable those calls end in an \Error that the relay's
	 * catch (\Exception) does not catch. Pinned as it is.
	 *
	 * @return void
	 */
	public function testTheMissingArchiMateMethodsEndInAnError(): void {
		$service = $this->service($this->createMock(ArchiMateService::class));
		$calls = [
			'setArchiMateImportStatus' => [['a' => 1]],
			'setArchiMateExportStatus' => [['b' => 2]],
			'clearArchiMateImportStatus' => [],
			'killArchiMateImport' => [],
			'clearArchiMateExportStatus' => [],
		];

		$failed = [];
		foreach ($calls as $method => $args) {
			try {
				$service->$method(...$args);
			} catch (\Error $e) {
				$failed[] = $method;
			}
		}

		$this->assertSame(array_keys($calls), $failed);
		$this->assertSame([], $this->configCalls);
		$this->assertSame([], $this->errors);
	}//end testTheMissingArchiMateMethodsEndInAnError()

	/**
	 * Without ArchiMateService the writes and clears fall back to the app
	 * config, and each failure is logged.
	 *
	 * @return void
	 */
	public function testWithoutArchiMateServiceTheConfigIsUsed(): void {
		$service = $this->service(null);

		$service->setArchiMateImportStatus(['a' => 1]);
		$service->setArchiMateExportStatus(['b' => 2]);
		$cleared = $service->clearArchiMateImportStatus();
		$killed = $service->killArchiMateImport();
		$cancelled = $service->cancelArchiMateImport('op-1');
		$service->clearArchiMateExportStatus();

		$this->assertSame(
			['cleared' => true, 'process_killed' => false, 'process_id' => null, 'was_running' => false, 'messages' => ['Import status cleared via fallback method']],
			$cleared
		);
		$this->assertSame(
			['cleared' => true, 'process_killed' => false, 'process_id' => null, 'was_running' => false, 'messages' => ['Import status cleared via fallback method - could not kill process']],
			$killed
		);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $cancelled['cancellation_time']);
		unset($cancelled['cancellation_time']);
		$this->assertSame(
			[
				'cancelled' => true,
				'was_running' => false,
				'process_id' => null,
				'process_killed' => false,
				'status_cleared' => true,
				'messages' => ['Import status cleared via fallback method - ArchiMateService not available'],
			],
			$cancelled
		);
		$this->assertSame(
			[
				['set', 'archimate_import_status', json_encode(['a' => 1], JSON_PRETTY_PRINT)],
				['set', 'archimate_export_status', json_encode(['b' => 2], JSON_PRETTY_PRINT)],
				['delete', 'archimate_import_status'],
				['delete', 'archimate_import_status'],
				['delete', 'archimate_import_status'],
				['delete', 'archimate_export_status'],
			],
			$this->configCalls
		);
		$this->assertSame(
			[
				['SettingsService: Failed to set ArchiMate import status via ArchiMateService', 'down'],
				['SettingsService: Failed to set ArchiMate export status via ArchiMateService', 'down'],
				['SettingsService: Failed to clear ArchiMate import status via ArchiMateService', 'down'],
				['SettingsService: Failed to kill ArchiMate import process via ArchiMateService', 'down'],
				['SettingsService: Failed to cancel ArchiMate import via ArchiMateService', 'down'],
				['SettingsService: Failed to clear ArchiMate export status via ArchiMateService', 'down'],
			],
			$this->errors
		);
	}//end testWithoutArchiMateServiceTheConfigIsUsed()

	/**
	 * Build the import control over a container that answers ArchiMateService, or throws.
	 *
	 * @param ArchiMateService|null $archiMate The ArchiMate service, or null to throw.
	 *
	 * @return ArchiMateImportControl
	 */
	private function service(?ArchiMateService $archiMate): ArchiMateImportControl {
		return new ArchiMateImportControl(
			config: $this->config(),
			container: $this->container($archiMate),
			logger: $this->logger()
		);
	}//end service()

	/**
	 * Build the settings service, which still reads the status, over the same doubles.
	 *
	 * @param ArchiMateService|null $archiMate The ArchiMate service, or null to throw.
	 *
	 * @return SettingsService
	 */
	private function settings(?ArchiMateService $archiMate): SettingsService {
		$config    = $this->config();
		$container = $this->container($archiMate);
		$logger    = $this->logger();
		return new SettingsService(
			catalogue: new CatalogueLocator(
				config: $config,
				appManager: $this->createMock(IAppManager::class),
				container: $container,
				logger: $logger
			),
			config: $config,
			request: $this->createMock(IRequest::class),
			roleGroupSettings: $this->createMock(RoleGroupSettings::class),
			container: $container,
			appManager: $this->createMock(IAppManager::class),
			logger: $logger,
			groupManager: $this->createMock(IGroupManager::class),
			l10n: $this->createMock(IL10N::class),
			emailSettings: $this->createMock(EmailSettings::class)
		);
	}//end settings()

	/**
	 * An app config double that records writes and deletes.
	 *
	 * @return IAppConfig
	 */
	private function config(): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => $default);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->assertSame('stackiq', $app);
				$this->configCalls[] = ['set', $key, $value];
				return true;
			}
		);
		$config->method('deleteKey')->willReturnCallback(
			function (string $app, string $key): void {
				$this->assertSame('stackiq', $app);
				$this->configCalls[] = ['delete', $key];
			}
		);

		return $config;
	}//end config()

	/**
	 * A container that answers ArchiMateService, or throws.
	 *
	 * @param ArchiMateService|null $archiMate The ArchiMate service, or null to throw.
	 *
	 * @return ContainerInterface
	 */
	private function container(?ArchiMateService $archiMate): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($archiMate) {
				if ($archiMate === null || $id !== ArchiMateService::class) {
					throw new \Exception('down');
				}

				return $archiMate;
			}
		);

		return $container;
	}//end container()

	/**
	 * A logger that records the error logs.
	 *
	 * @return LoggerInterface
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->errors[] = [$message, $context['error'] ?? ''];
			}
		);

		return $logger;
	}//end logger()
}//end class
