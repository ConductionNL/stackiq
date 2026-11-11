<?php

/**
 * Unit tests for the cronjob configuration: reading, updating and the
 * per-job switch and context, round-tripped through an in-memory IAppConfig.
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
 * Pins every branch of the cronjob configuration methods.
 *
 * PHPUnit assertions take positional arguments; the named-parameter sniff does
 * not apply to them.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class CronjobSettingsTest extends TestCase {

	private const JOB = 'organization_contact_sync';

	/**
	 * The backing key/value store of the IAppConfig double.
	 *
	 * @var array<string, string>
	 */
	private array $store = [];

	/**
	 * Whether the IAppConfig double throws on every read.
	 *
	 * @var boolean
	 */
	private bool $failing = false;

	/**
	 * The logger double, so log calls can be asserted.
	 *
	 * @var LoggerInterface&\PHPUnit\Framework\MockObject\MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Build the service under test over the in-memory store.
	 *
	 * @return SettingsService
	 */
	private function service(): SettingsService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = ''): string {
				if ($this->failing === true) {
					throw new \RuntimeException('config down');
				}

				$this->assertSame('stackiq', $app);
				return $this->store[$key] ?? $default;
			}
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->assertSame('stackiq', $app);
				$this->store[$key] = $value;
				return true;
			}
		);

		$this->logger = $this->createMock(LoggerInterface::class);

		return new SettingsService(
			config: $config,
			request: $this->createMock(IRequest::class),
			container: $this->createMock(ContainerInterface::class),
			appManager: $this->createMock(IAppManager::class),
			logger: $this->logger,
			groupManager: $this->createMock(IGroupManager::class),
			l10n: $this->createMock(IL10N::class)
		);
	}//end service()

	/**
	 * Nothing stored: the one known job is listed with its defaults.
	 *
	 * @return void
	 */
	public function testConfigListsTheKnownJobWithDefaults(): void {
		$this->store['cronjob_config'] = 'not json';

		$result = $this->service()->getCronjobConfig();

		$this->assertTrue($result['success']);
		$this->assertIsInt($result['timestamp']);
		$this->assertSame(
			[
				self::JOB => [
					'id' => self::JOB,
					'name' => 'Organization Contact Sync',
					'description' => 'Syncs organizations and contacts between Stackiq and OpenRegister.',
					'interval' => 300,
					'userId' => null,
					'organisationUuid' => null,
					'enabled' => true,
				],
			],
			$result['cronjobs']
		);
	}//end testConfigListsTheKnownJobWithDefaults()

	/**
	 * A failing read answers success false and logs the error.
	 *
	 * @return void
	 */
	public function testConfigReadFailureIsReported(): void {
		$service = $this->service();
		$this->failing = true;
		$this->logger->expects($this->once())->method('error')->with('Failed to get cronjob config', ['exception' => 'config down']);

		$this->assertSame(
			['success' => false, 'message' => 'Failed to get cronjob config: config down', 'cronjobs' => []],
			$service->getCronjobConfig()
		);
	}//end testConfigReadFailureIsReported()

	/**
	 * An update without a job id or for an unknown job is refused and stores nothing.
	 *
	 * @return void
	 */
	public function testUpdateRefusesAMissingOrUnknownJob(): void {
		$service = $this->service();

		$this->assertSame(['success' => false, 'message' => 'Job ID is required'], $service->updateCronjobConfig([]));
		$this->assertSame(
			['success' => false, 'message' => 'Unknown cronjob: nope'],
			$service->updateCronjobConfig(['jobId' => 'nope'])
		);
		$this->assertSame([], $this->store);
	}//end testUpdateRefusesAMissingOrUnknownJob()

	/**
	 * A valid update is stored pretty-printed, logged, and read back.
	 *
	 * @return void
	 */
	public function testUpdateStoresAndReadsBack(): void {
		$service = $this->service();
		$this->logger->expects($this->once())->method('info')->with(
			'Cronjob configuration updated',
			['jobId' => self::JOB, 'userId' => 'admin', 'organisationUuid' => 'org-1']
		);

		$result = $service->updateCronjobConfig(
			['jobId' => self::JOB, 'userId' => 'admin', 'organisationUuid' => 'org-1', 'enabled' => false]
		);

		$stored = ['userId' => 'admin', 'organisationUuid' => 'org-1', 'enabled' => false];
		$this->assertSame(
			['success' => true, 'message' => 'Cronjob configuration updated successfully', 'config' => $stored],
			$result
		);
		$this->assertSame(json_encode([self::JOB => $stored], JSON_PRETTY_PRINT), $this->store['cronjob_config']);
		$this->assertFalse($service->getCronjobConfig()['cronjobs'][self::JOB]['enabled']);
		$this->assertSame(
			['userId' => 'admin', 'organisationUuid' => 'org-1', 'enabled' => false],
			$service->getCronjobContext(self::JOB)
		);
	}//end testUpdateStoresAndReadsBack()

	/**
	 * A failing update answers success false and logs the error.
	 *
	 * @return void
	 */
	public function testUpdateFailureIsReported(): void {
		$service = $this->service();
		$this->failing = true;
		$this->logger->expects($this->once())->method('error')->with('Failed to update cronjob config', ['exception' => 'config down']);

		$this->assertSame(
			['success' => false, 'message' => 'Failed to update cronjob config: config down'],
			$service->updateCronjobConfig(['jobId' => self::JOB])
		);
	}//end testUpdateFailureIsReported()

	/**
	 * The context is null unless both user and organisation are set.
	 *
	 * @return void
	 */
	public function testContextNeedsBothUserAndOrganisation(): void {
		$service = $this->service();
		$this->assertNull($service->getCronjobContext(self::JOB));

		$this->store['cronjob_config'] = json_encode([self::JOB => ['userId' => 'admin', 'organisationUuid' => '']]);
		$this->assertNull($service->getCronjobContext(self::JOB));

		$this->store['cronjob_config'] = json_encode([self::JOB => ['userId' => 'admin', 'organisationUuid' => 'org-1']]);
		$this->assertSame(
			['userId' => 'admin', 'organisationUuid' => 'org-1', 'enabled' => true],
			$service->getCronjobContext(self::JOB)
		);
	}//end testContextNeedsBothUserAndOrganisation()

	/**
	 * A failing context read is logged and answers null.
	 *
	 * @return void
	 */
	public function testContextReadFailureIsNull(): void {
		$service = $this->service();
		$this->failing = true;
		$this->logger->expects($this->once())->method('error')->with(
			'Failed to get cronjob context',
			['jobId' => self::JOB, 'exception' => 'config down']
		);

		$this->assertNull($service->getCronjobContext(self::JOB));
	}//end testContextReadFailureIsNull()

	/**
	 * Only a stored false-like flag switches a job off.
	 *
	 * @return void
	 */
	public function testOnlyAFalseLikeFlagSwitchesAJobOff(): void {
		$service = $this->service();
		$this->assertTrue($service->isCronjobEnabled(self::JOB));

		$cases = [[false, false], [0, false], ['0', false], ['false', false], [true, true], ['no', true], [null, true]];
		foreach ($cases as [$flag, $expected]) {
			$this->store['cronjob_config'] = json_encode([self::JOB => ['enabled' => $flag]]);
			$this->assertSame($expected, $service->isCronjobEnabled(self::JOB), var_export($flag, true));
		}

		$this->store['cronjob_config'] = json_encode([self::JOB => 'flat']);
		$this->assertTrue($service->isCronjobEnabled(self::JOB));
	}//end testOnlyAFalseLikeFlagSwitchesAJobOff()
}//end class
