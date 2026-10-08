<?php

/**
 * Tests that cancelling an ArchiMate import reaches the running import.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/archimate-import-progress/spec.md#requirement-req-aip-002-an-admin-shall-be-able-to-cancel-a-running-import
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\ArchiMateService;
use OCA\Stackiq\Service\ProgressTracker;
use OCA\Stackiq\Service\SettingsService;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The real ArchiMateService and SettingsService path the cancel endpoint takes.
 */
class ArchiMateServiceCancelTest extends TestCase {

	/**
	 * The distributed cache every request shares.
	 *
	 * @var array<string, mixed>
	 */
	private array $sharedCache = [];

	/**
	 * A tracker on the shared cache.
	 *
	 * @return ProgressTracker The tracker.
	 */
	private function tracker(): ProgressTracker {
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn ($key) => $this->sharedCache[$key] ?? null);
		$cache->method('set')->willReturnCallback(
			function ($key, $value, $ttl = 0): bool {
				$this->sharedCache[$key] = $value;
				return true;
			}
		);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);

		$factory->method('isAvailable')->willReturn(true);

		return new ProgressTracker(
			cacheFactory: $factory,
			userSession: $this->createMock(IUserSession::class),
			logger: $this->createMock(LoggerInterface::class),
			config: $this->createConfiguredMock(IConfig::class, ['getSystemValueString' => '\\OC\\Memcache\\Redis']),
			appConfig: $this->createMock(IAppConfig::class)
		);
	}//end tracker()

	/**
	 * Build the real ArchiMateService on the shared cache.
	 *
	 * @param IAppConfig|null $config The app config, or a plain mock.
	 *
	 * @return ArchiMateService The service.
	 */
	private function archiMateService(?IAppConfig $config = null): ArchiMateService {
		// IRootFolder cannot be doubled without the server tree, and cancel does not use it.
		$service = (new \ReflectionClass(ArchiMateService::class))->newInstanceWithoutConstructor();
		$values  = [
			'config'          => $config ?? $this->createMock(IAppConfig::class),
			'logger'          => $this->createMock(LoggerInterface::class),
			'progressTracker' => $this->tracker(),
		];
		foreach ($values as $prop => $value) {
			(new \ReflectionProperty(ArchiMateService::class, $prop))->setValue($service, $value);
		}

		return $service;
	}//end archiMateService()

	/**
	 * Cancelling an operation sets the flag the running import reads, and clears the stored status.
	 *
	 * @return void
	 */
	public function testCancellingAnOperationSetsTheFlagTheImportReads(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->once())->method('deleteKey')->with('stackiq', 'archimate_import_status');

		$result = $this->archiMateService($config)->cancelArchiMateImport('archimate_import_abc12345');

		$this->assertTrue($result['cancelled']);
		$this->assertSame('archimate_import_abc12345', $result['operation_id']);
		$this->assertTrue($this->tracker()->isCancelRequested('archimate_import_abc12345'));
	}//end testCancellingAnOperationSetsTheFlagTheImportReads()

	/**
	 * An id outside the import pattern is refused and sets nothing.
	 *
	 * @return void
	 */
	public function testAnIdOutsideThePatternIsRefused(): void {
		$result = $this->archiMateService()->cancelArchiMateImport('progress_someone_elses');

		$this->assertFalse($result['cancelled']);
		$this->assertSame([], $this->sharedCache);
	}//end testAnIdOutsideThePatternIsRefused()

	/**
	 * SettingsService, which the endpoint calls, reaches the method and passes the id on.
	 *
	 * @return void
	 */
	public function testTheSettingsServicePathReachesTheCancel(): void {
		$service   = $this->archiMateService();
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(ArchiMateService::class)->willReturn($service);

		$settings = (new \ReflectionClass(SettingsService::class))->newInstanceWithoutConstructor();
		foreach (['container' => $container, 'logger' => $this->createMock(LoggerInterface::class)] as $prop => $value) {
			(new \ReflectionProperty(SettingsService::class, $prop))->setValue($settings, $value);
		}

		$result = $settings->cancelArchiMateImport('archimate_import_abc12345');

		$this->assertTrue($result['cancelled']);
		$this->assertTrue($this->tracker()->isCancelRequested('archimate_import_abc12345'));
	}//end testTheSettingsServicePathReachesTheCancel()
}//end class
