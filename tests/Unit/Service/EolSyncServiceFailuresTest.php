<?php

/**
 * Unit tests for EolSyncService's degraded and partial runs.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\ConnectionReportService;
use OCA\Stackiq\Service\EolMatcherService;
use OCA\Stackiq\Service\EolSyncService;
use OCA\Stackiq\Service\Settings\EolSyncSettings;
use OCA\Stackiq\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A run that cannot reach OpenRegister degrades; a run that fails on one
 * module or one save carries on with the rest.
 */
#[CoversClass(EolSyncService::class)]
#[UsesClass(EolMatcherService::class)]
final class EolSyncServiceFailuresTest extends TestCase {

	private const CONFIG = [
		'enabled' => true,
		'register' => 'integriq',
		'productSchema' => 'eol_product',
		'cycleSchema' => 'eol_cycle',
		'intervalSeconds' => 86400,
	];

	/**
	 * The settings service.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settings;

	/**
	 * The EOL sync settings.
	 *
	 * @var EolSyncSettings&MockObject
	 */
	private EolSyncSettings&MockObject $eolSettings;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The connection report service.
	 *
	 * @var ConnectionReportService&MockObject
	 */
	private ConnectionReportService&MockObject $reports;

	/**
	 * Wire the collaborators for a configured, installed OpenRegister.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settings = $this->createMock(SettingsService::class);
		$this->eolSettings = $this->createMock(EolSyncSettings::class);
		$this->eolSettings->method('getEolSyncConfig')->willReturn(self::CONFIG);
		$this->settings->method('isOpenRegisterInstalled')->willReturn(true);
		$this->settings->method('getRegisterIdForObjectType')->willReturn(10);
		$this->settings->method('getSchemaIdForObjectType')->willReturnMap([['module', 20], ['moduleVersion', 21]]);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->reports = $this->createMock(ConnectionReportService::class);
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return EolSyncService
	 */
	private function service(): EolSyncService {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-23T12:00:00+00:00'));

		return new EolSyncService(
			settingsService: $this->settings,
			eolSyncSettings: $this->eolSettings,
			matcher: new EolMatcherService(),
			timeFactory: $time,
			logger: $this->logger,
			connectionReports: $this->reports,
		);
	}//end service()

	/**
	 * An OpenRegister row exposing its data through getObject() and getUuid().
	 *
	 * @param array<string,mixed> $data The data.
	 * @param string              $uuid The uuid.
	 *
	 * @return object
	 */
	private function entity(array $data, string $uuid): object {
		return new class($data, $uuid) {
			/**
			 * Constructor.
			 *
			 * @param array<string,mixed> $data The data.
			 * @param string              $uuid The uuid.
			 */
			public function __construct(private readonly array $data, private readonly string $uuid) {
			}

			/**
			 * The data.
			 *
			 * @return array<string,mixed>
			 */
			public function getObject(): array {
				return $this->data;
			}

			/**
			 * The uuid.
			 *
			 * @return string
			 */
			public function getUuid(): string {
				return $this->uuid;
			}
		};
	}//end entity()

	/**
	 * An unavailable ObjectService degrades the run and reports it, logged when it threw.
	 *
	 * @return void
	 */
	public function testAnUnavailableObjectServiceDegrades(): void {
		$this->settings->method('getObjectService')->willReturnOnConsecutiveCalls(
			self::throwException(new RuntimeException('container broken')),
			null
		);
		$this->logger->expects(self::once())->method('info')->with(
			'[EolSyncService] OpenRegister ObjectService unavailable — degrading to manual-only',
			['error' => 'container broken']
		);
		$expected = [
			'available' => false,
			'reason' => 'object-service-unavailable',
			'matched' => 0,
			'skipped' => 0,
			'lastRunAt' => '2026-07-23T12:00:00+00:00',
		];
		$this->reports->expects(self::exactly(2))->method('eolSyncRan')->with(runStatus: $expected);

		$service = $this->service();
		self::assertSame($expected, $service->run());
		self::assertSame($expected, $service->run());
	}//end testAnUnavailableObjectServiceDegrades()

	/**
	 * Saving the config tells the connection report.
	 *
	 * @return void
	 */
	public function testSavingTheConfigIsReported(): void {
		$this->eolSettings->method('updateEolSyncConfig')->willReturn(['success' => true]);
		$this->reports->expects(self::once())->method('eolSyncConfigSaved');

		self::assertSame(['success' => true], $this->service()->updateConfig(['enabled' => true]));
	}//end testSavingTheConfigIsReported()

	/**
	 * A module whose cycles or versions cannot be read is skipped with a warning;
	 * a version without a uuid or a failing save is left untouched; entity rows are read.
	 *
	 * @return void
	 */
	public function testFailuresOnOneModuleDoNotStopTheRun(): void {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('searchObjects')->willReturnCallback(
			function (array $query): mixed {
				if ($query['@self']['schema'] === 20) {
					return [
						$this->entity(['eolProductSlug' => 'mysql'], 'mod-cycles-fail'),
						['id' => 'mod-versions-fail', 'eolProductSlug' => 'redis'],
						['id' => 'mod-ok', 'eolProductSlug' => 'postgresql'],
						['eolProductSlug' => 'php'],
					];
				}

				return match ($query['module']) {
					'mod-versions-fail' => throw new RuntimeException('versions offline'),
					'mod-ok' => [
						['version' => '16.2'],
						$this->entity(['version' => '15.1', 'id' => 'mv-saved-fails'], 'ignored'),
						$this->entity(['version' => '14.3'], 'mv-from-uuid'),
					],
					default => 0,
				};
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			static function (array $config): array {
				if ($config['filters']['product'] === 'mysql') {
					throw new RuntimeException('cycles offline');
				}

				return [
					['product' => 'postgresql', 'cycle' => '16', 'eol' => '2028-11-09'],
					['product' => 'postgresql', 'cycle' => '15', 'eol' => '2027-11-11'],
					['product' => 'postgresql', 'cycle' => '14', 'eol' => '2026-11-12'],
					['product' => 'other', 'cycle' => '13', 'eol' => '2025-11-13'],
				];
			}
		);
		$saved = [];
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ...$rest) use (&$saved): \OCA\OpenRegister\Db\ObjectEntity {
				if ($object['id'] === 'mv-saved-fails') {
					throw new RuntimeException('locked');
				}

				$saved[] = $object['id'];
				return $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
			}
		);
		$this->settings->method('getObjectService')->willReturn($objectService);

		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(
			static function (string $message, array $context = []) use (&$warnings): void {
				$warnings[] = [$message, $context];
			}
		);

		$status = $this->service()->run();

		self::assertSame(['mv-from-uuid'], $saved);
		self::assertSame(3, $status['matched']);
		self::assertTrue($status['available']);
		self::assertSame(
			[
				['[EolSyncService] Failed to read eol_cycle rows for product — module skipped this run', ['product' => 'mysql', 'error' => 'cycles offline']],
				['[EolSyncService] Failed to read moduleVersie rows for module — module skipped this run', ['module' => 'mod-versions-fail', 'error' => 'versions offline']],
				['[EolSyncService] Stamped moduleVersie has no uuid — skipping save', []],
				['[EolSyncService] Failed to save a stamped moduleVersie — left untouched', ['uuid' => 'mv-saved-fails', 'error' => 'locked']],
			],
			$warnings
		);
	}//end testFailuresOnOneModuleDoNotStopTheRun()

	/**
	 * A module search that answers no list finds no modules.
	 *
	 * @return void
	 */
	public function testAModuleSearchWithoutAListFindsNothing(): void {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('searchObjects')->willReturn(0);
		$objectService->expects(self::never())->method('findAll');
		$this->settings->method('getObjectService')->willReturn($objectService);

		$status = $this->service()->run();

		self::assertSame(['available' => true, 'reason' => null, 'matched' => 0, 'skipped' => 0, 'lastRunAt' => '2026-07-23T12:00:00+00:00'], $status);
	}//end testAModuleSearchWithoutAListFindsNothing()
}//end class
