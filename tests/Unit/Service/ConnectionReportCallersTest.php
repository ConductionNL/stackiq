<?php

/**
 * The places that hand a save, a pull or a run to ConnectionReportService.
 *
 * ConnectionReportServiceTest proves what a report says. These tests prove
 * each report is asked for: a peer change and a pull in FederationService, a
 * settings save and a run in EolSyncService, and the hand-built factories in
 * Application that would otherwise pass nothing and switch every report off
 * without a sound. They also prove that a service built without a reporter
 * answers exactly as before.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-stackiq-conn-002-a-save-asks-integriq-to-look-again-and-a-run-reports-what-it-met
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use DateTime;
use OCA\Stackiq\Service\ConnectionReportService;
use OCA\Stackiq\Service\EolMatcherService;
use OCA\Stackiq\Service\EolSyncService;
use OCA\Stackiq\Service\Federation\FederationConfig;
use OCA\Stackiq\Service\Federation\FederationMerger;
use OCA\Stackiq\Service\Federation\FederationService;
use OCA\Stackiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the report call sites.
 *
 * @covers \OCA\Stackiq\Service\Federation\FederationService
 * @covers \OCA\Stackiq\Service\EolSyncService
 */
class ConnectionReportCallersTest extends TestCase {

	/**
	 * The peers the federation config holds.
	 *
	 * @var array<int, string>
	 */
	private array $peers = [];

	/**
	 * Mocked federation config.
	 *
	 * @var FederationConfig&MockObject
	 */
	private FederationConfig&MockObject $federationConfig;

	/**
	 * A FederationService on an instance without OpenCatalogi.
	 *
	 * @param ConnectionReportService|null $reports The reporter, or none.
	 * @param bool                         $enabled Whether federation_enabled is on.
	 *
	 * @return FederationService
	 */
	private function federation(?ConnectionReportService $reports, bool $enabled = true): FederationService {
		$this->federationConfig = $this->createMock(originalClassName: FederationConfig::class);
		$this->federationConfig->method('isEnabled')->willReturn($enabled);
		$this->federationConfig->method('getPeers')->willReturnCallback(fn (): array => $this->peers);
		$this->federationConfig->method('getLocalFederationHosts')->willReturn([]);
		$this->federationConfig->method('setPeers')->willReturnCallback(
			function (array $peers): void {
				$this->peers = $peers;
			}
		);

		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['stackiq']);

		return new FederationService(
			container: $this->createMock(originalClassName: ContainerInterface::class),
			appManager: $appManager,
			config: $this->federationConfig,
			merger: new FederationMerger(),
			settingsService: $this->createMock(originalClassName: SettingsService::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			connectionReports: $reports,
		);
	}//end federation()

	/**
	 * A reporter double that records nothing by itself.
	 *
	 * @return ConnectionReportService&MockObject
	 */
	private function reporter(): ConnectionReportService&MockObject {
		return $this->createMock(originalClassName: ConnectionReportService::class);
	}//end reporter()

	/**
	 * A new peer hands the fresh status to the reporter, and a known peer hands nothing.
	 *
	 * @return void
	 */
	public function testAddingAPeerAsksForARefresh(): void {
		$this->peers = [];
		$reports     = $this->reporter();
		$reports->expects($this->once())->method('federationPeersChanged')->with(
			$this->callback(
				callback: static fn (array $status): bool => $status['available'] === false
					&& $status['peers'][0]['url'] === 'https://peer.example'
			)
		);

		$service = $this->federation(reports: $reports);

		$this->assertSame(expected: ['ok' => true, 'reason' => 'peer added'], actual: $service->addPeer('https://peer.example'));
		$this->assertSame(expected: ['ok' => true, 'reason' => 'peer already present'], actual: $service->addPeer('https://peer.example'));
	}//end testAddingAPeerAsksForARefresh()

	/**
	 * A refused or missing peer changes no settings, so it asks for nothing.
	 *
	 * @return void
	 */
	public function testARefusedPeerChangeAsksForNothing(): void {
		$this->peers = [];
		$reports     = $this->reporter();
		$reports->expects($this->never())->method('federationPeersChanged');

		$service = $this->federation(reports: $reports);

		$this->assertFalse(condition: $service->addPeer('http://localhost/catalog')['ok']);
		$this->assertFalse(condition: $service->removePeer('https://never.example')['ok']);
	}//end testARefusedPeerChangeAsksForNothing()

	/**
	 * Removing a peer asks for a refresh.
	 *
	 * @return void
	 */
	public function testRemovingAPeerAsksForARefresh(): void {
		$this->peers = ['https://peer.example'];
		$reports     = $this->reporter();
		$reports->expects($this->once())->method('federationPeersChanged');

		$this->assertSame(
			expected: ['ok' => true, 'reason' => 'peer removed'],
			actual: $this->federation(reports: $reports)->removePeer('https://peer.example')
		);
	}//end testRemovingAPeerAsksForARefresh()

	/**
	 * A pull hands its own result to the reporter, and returns it unchanged.
	 *
	 * @return void
	 */
	public function testAPullReportsItsResult(): void {
		$expected = ['ok' => false, 'reason' => 'federation disabled', 'peers' => []];
		$reports  = $this->reporter();
		$reports->expects($this->once())->method('federationPulled')->with($expected);

		$this->assertSame(expected: $expected, actual: $this->federation(reports: $reports, enabled: false)->pullAllPeers());
	}//end testAPullReportsItsResult()

	/**
	 * Without a reporter the federation service answers exactly as before.
	 *
	 * @return void
	 */
	public function testFederationWithoutAReporterAnswersAsBefore(): void {
		$this->peers = [];
		$service     = $this->federation(reports: null, enabled: false);

		$this->assertSame(expected: ['ok' => true, 'reason' => 'peer added'], actual: $service->addPeer('https://peer.example'));
		$this->assertSame(expected: ['ok' => true, 'reason' => 'peer removed'], actual: $service->removePeer('https://peer.example'));
		$this->assertSame(
			expected: ['ok' => false, 'reason' => 'federation disabled', 'peers' => []],
			actual: $service->pullAllPeers()
		);
	}//end testFederationWithoutAReporterAnswersAsBefore()

	/**
	 * An EolSyncService around a settings service that holds the given config.
	 *
	 * @param ConnectionReportService|null $reports The reporter, or none.
	 * @param bool                         $enabled Whether the sync is switched on.
	 *
	 * @return EolSyncService
	 */
	private function eol(?ConnectionReportService $reports, bool $enabled): EolSyncService {
		$config = [
			'enabled' => $enabled,
			'register' => 'integriq',
			'productSchema' => 'eol_product',
			'cycleSchema' => 'eol_cycle',
			'intervalSeconds' => 86400,
		];

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getEolSyncConfig')->willReturn($config);
		$settings->method('updateEolSyncConfig')->willReturn(['success' => true, 'config' => $config]);
		$settings->method('isOpenRegisterInstalled')->willReturn(false);

		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-09-14T12:00:00+00:00'));

		return new EolSyncService(
			settingsService: $settings,
			matcher: new EolMatcherService(),
			timeFactory: $time,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			connectionReports: $reports,
		);
	}//end eol()

	/**
	 * An EOL settings save asks the reporter for a refresh.
	 *
	 * @return void
	 */
	public function testAnEolSaveAsksForARefresh(): void {
		$reports = $this->reporter();
		$reports->expects($this->once())->method('eolSyncConfigSaved')->with();

		$result = $this->eol(reports: $reports, enabled: false)->updateConfig(['enabled' => false]);

		$this->assertTrue(condition: $result['success']);
	}//end testAnEolSaveAsksForARefresh()

	/**
	 * A run that degrades hands its recorded status to the reporter, once.
	 *
	 * @return void
	 */
	public function testAnEolRunReportsTheStatusItRecorded(): void {
		$reports = $this->reporter();
		$reports->expects($this->once())->method('eolSyncRan')->with(
			$this->callback(
				callback: static fn (array $status): bool => $status['available'] === false
					&& $status['reason'] === 'openregister-not-installed'
			)
		);

		$status = $this->eol(reports: $reports, enabled: true)->run();

		$this->assertSame(expected: 'openregister-not-installed', actual: $status['reason']);
	}//end testAnEolRunReportsTheStatusItRecorded()

	/**
	 * Without a reporter the EOL service answers exactly as before.
	 *
	 * @return void
	 */
	public function testEolWithoutAReporterAnswersAsBefore(): void {
		$service = $this->eol(reports: null, enabled: false);

		$this->assertSame(expected: 'disabled', actual: $service->run()['reason']);
		$this->assertTrue(condition: $service->updateConfig(['enabled' => false])['success']);
	}//end testEolWithoutAReporterAnswersAsBefore()

	/**
	 * The hand-built factories pass the reporter by name.
	 *
	 * FederationService and EolSyncService are registered with explicit
	 * factories in Application::register(). The constructor default is null,
	 * so a factory that leaves the argument out builds a service that never
	 * reports, and nothing anywhere says so.
	 *
	 * @return void
	 */
	public function testTheHandBuiltFactoriesPassTheReporter(): void {
		$source = (string) file_get_contents(dirname(__DIR__, 3) . '/lib/AppInfo/Application.php');

		foreach (['FederationService', 'EolSyncService'] as $service) {
			$matched = preg_match(
				'/return new ' . $service . '\((.*?)\);/s',
				$source,
				$factory
			);

			$this->assertSame(expected: 1, actual: $matched, message: 'no hand-built factory for ' . $service);
			$this->assertStringContainsString(
				needle: 'connectionReports: $container->get(ConnectionReportService::class)',
				haystack: $factory[1],
				message: $service . ' is built without the connection reporter'
			);
		}
	}//end testTheHandBuiltFactoriesPassTheReporter()
}//end class
