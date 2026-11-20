<?php

/**
 * Unit tests for the SettingsService orchestration methods:
 * performConsolidatedAutoConfiguration() / autoConfigure() and forceUpdate().
 *
 * The import itself (manualImport) and the version/status reads are stubbed
 * on a partial mock; the configuration steps in between run for real.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/settings-service/spec.md#requirement-the-system-shall-run-auto-configuration-import-and-configuration-maintenance-req-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\Settings\CatalogueLocator;
use OCA\Stackiq\Service\Settings\EmailSettings;
use OCA\Stackiq\Service\Settings\RoleGroupSettings;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Step bookkeeping of the consolidated auto-configuration and the outcome of a force update.
 */
#[CoversClass(SettingsService::class)]
final class SettingsServiceAutoConfigurationFlowTest extends TestCase {

	/**
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig $config;

	/**
	 * Create the shared doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->config = $this->createMock(IAppConfig::class);
	}//end setUp()

	/**
	 * A service whose given public methods are stubbed. OpenRegister is
	 * unavailable, so the voorzieningen and AMEF steps fail fast for real.
	 *
	 * @param array<int, string> $methods The public methods to stub.
	 *
	 * @return SettingsService&MockObject
	 */
	private function service(array $methods): SettingsService {
		$catalogue = $this->createMock(CatalogueLocator::class);
		$catalogue->method('getObjectService')->willReturn(null);

		return $this->getMockBuilder(SettingsService::class)
			->setConstructorArgs(
				[
					$catalogue,
					$this->config,
					$this->createMock(IRequest::class),
					$this->createMock(RoleGroupSettings::class),
					$this->createMock(ContainerInterface::class),
					$this->createMock(IAppManager::class),
					$this->createMock(LoggerInterface::class),
					$this->createMock(IGroupManager::class),
					$this->createMock(IL10N::class),
					$this->createMock(EmailSettings::class),
				]
			)
			->onlyMethods($methods)
			->getMock();
	}//end service()

	/**
	 * Every step result is recorded, and each failing step adds an error
	 * naming the step and its message.
	 *
	 * @return void
	 */
	public function testEachFailingStepIsNamedInTheErrors(): void {
		$service = $this->service(['manualImport', 'createAndConfigureUserGroups']);
		$service->expects($this->once())->method('manualImport')->with(true)->willReturn(
			['success' => true, 'message' => 'Configuration imported successfully']
		);
		$service->method('createAndConfigureUserGroups')->willReturn(
			['success' => true, 'message' => 'User groups configured successfully', 'created' => ['a'], 'existing' => ['b'], 'total' => 2]
		);

		$result = $service->performConsolidatedAutoConfiguration(force: true);

		$this->assertFalse($result['success']);
		$this->assertTrue($result['force']);
		$this->assertSame('Auto-configuration completed with some issues', $result['message']);
		$this->assertSame(
			[
				'Voorzieningen configuration failed: OpenRegister service not available',
				'AMEF configuration failed: OpenRegister service not available',
			],
			$result['errors']
		);
		$this->assertSame(
			[
				'success' => true,
				'message' => 'Configuration imported successfully',
				'details' => ['success' => true, 'message' => 'Configuration imported successfully'],
			],
			$result['steps']['configurationLoad']
		);
		$this->assertSame(
			[
				'success' => true,
				'message' => 'User groups configured successfully',
				'created' => ['a'],
				'existing' => ['b'],
				'total' => 2,
			],
			$result['steps']['groupsConfiguration']
		);
		$this->assertSame(
			['configurationLoad', 'voorzieningenConfiguration', 'amefConfiguration', 'groupsConfiguration'],
			array_keys($result['steps'])
		);
		$this->assertIsInt($result['timestamp']);
	}//end testEachFailingStepIsNamedInTheErrors()

	/**
	 * Exceptions from the import and the group step become failed step results
	 * instead of escaping, and an import result without a message gets a default.
	 *
	 * @return void
	 */
	public function testThrowingStepsBecomeFailedResults(): void {
		$service = $this->service(['manualImport', 'createAndConfigureUserGroups']);
		$service->method('manualImport')->willThrowException(new \RuntimeException('import broke'));
		$service->method('createAndConfigureUserGroups')->willThrowException(new \RuntimeException('groups broke'));

		$result = $service->autoConfigure();

		$this->assertFalse($result['force']);
		$this->assertSame(
			[
				'success' => false,
				'message' => 'Configuration loading failed: import broke',
				'error' => 'import broke',
			],
			$result['steps']['configurationLoad']
		);
		$this->assertSame(
			[
				'success' => false,
				'message' => 'User groups configuration failed: groups broke',
				'error' => 'groups broke',
			],
			$result['steps']['groupsConfiguration']
		);
		$this->assertSame('Configuration loading failed: Configuration loading failed: import broke', $result['errors'][0]);
		$this->assertSame('User groups configuration failed: User groups configuration failed: groups broke', $result['errors'][3]);
		$this->assertCount(4, $result['errors']);
	}//end testThrowingStepsBecomeFailedResults()

	/**
	 * An import result without a message is reported as "Configuration loaded",
	 * and missing group counters default to empty.
	 *
	 * @return void
	 */
	public function testMissingStepFieldsGetDefaults(): void {
		$service = $this->service(['manualImport', 'createAndConfigureUserGroups']);
		$service->expects($this->once())->method('manualImport')->with(false)->willReturn(['success' => false]);
		$service->method('createAndConfigureUserGroups')->willReturn(['success' => false, 'message' => 'no']);

		$result = $service->performConsolidatedAutoConfiguration();

		$this->assertSame('Configuration loaded', $result['steps']['configurationLoad']['message']);
		$this->assertSame('Configuration loading failed: Configuration loaded', $result['errors'][0]);
		$this->assertSame(
			['success' => false, 'message' => 'no', 'created' => [], 'existing' => [], 'total' => 0],
			$result['steps']['groupsConfiguration']
		);
	}//end testMissingStepFieldsGetDefaults()

	/**
	 * A force update resets the completion flag, imports with force and stops
	 * when the import fails.
	 *
	 * @return void
	 */
	public function testForceUpdateStopsOnAFailedImport(): void {
		$service = $this->service(['manualImport', 'getVersionInfo', 'getConfigurationStatus']);
		$this->config->expects($this->once())->method('setValueString')->with('stackiq', 'auto_config_completed', 'false');
		$service->expects($this->once())->method('manualImport')->with(true)->willReturn(
			['success' => false, 'message' => 'Import failed: nope']
		);
		$service->expects($this->never())->method('getVersionInfo');

		$result = $service->forceUpdate();

		$this->assertSame(
			[
				'success' => false,
				'message' => 'Force update failed during import: Import failed: nope',
				'importResult' => ['success' => false, 'message' => 'Import failed: nope'],
			],
			$result
		);
	}//end testForceUpdateStopsOnAFailedImport()

	/**
	 * A successful import with a fully configured catalogue is a success, and
	 * the version info is reduced to its five summary fields.
	 *
	 * @return void
	 */
	public function testForceUpdateSucceedsWhenFullyConfigured(): void {
		$service = $this->service(['manualImport', 'getVersionInfo', 'getConfigurationStatus']);
		$service->method('manualImport')->willReturn(['success' => true, 'message' => 'Configuration imported successfully']);
		$service->method('getVersionInfo')->willReturn(
			[
				'appVersion' => '1.2.0',
				'configuredVersion' => '1.1.0',
				'versionsMatch' => false,
				'needsUpdate' => true,
				'isFullyConfigured' => true,
				'autoConfigCompleted' => true,
			]
		);
		$service->method('getConfigurationStatus')->willReturn(['organization' => ['configured' => true]]);

		$result = $service->forceUpdate();

		$this->assertSame(
			[
				'success' => true,
				'message' => 'Force update completed successfully',
				'importSuccess' => true,
				'importMessage' => 'Configuration imported successfully',
				'finalVersionInfo' => [
					'appVersion' => '1.2.0',
					'configuredVersion' => '1.1.0',
					'versionsMatch' => false,
					'needsUpdate' => true,
					'isFullyConfigured' => true,
				],
				'finalConfigStatus' => ['organization' => ['configured' => true]],
			],
			$result
		);
	}//end testForceUpdateSucceedsWhenFullyConfigured()

	/**
	 * Neither fully configured nor matching versions: the update finishes but
	 * asks for attention; missing version fields get defaults.
	 *
	 * @return void
	 */
	public function testForceUpdateNeedsAttentionWhenNotConfigured(): void {
		$service = $this->service(['manualImport', 'getVersionInfo', 'getConfigurationStatus']);
		$service->method('manualImport')->willReturn(['success' => true]);
		$service->method('getVersionInfo')->willReturn(['isFullyConfigured' => false, 'versionsMatch' => false]);
		$service->method('getConfigurationStatus')->willReturn([]);

		$result = $service->forceUpdate();

		$this->assertFalse($result['success']);
		$this->assertSame('Force update completed but configuration needs attention', $result['message']);
		$this->assertSame('', $result['importMessage']);
		$this->assertSame(
			[
				'appVersion' => null,
				'configuredVersion' => null,
				'versionsMatch' => false,
				'needsUpdate' => false,
				'isFullyConfigured' => false,
			],
			$result['finalVersionInfo']
		);
	}//end testForceUpdateNeedsAttentionWhenNotConfigured()

	/**
	 * Matching versions alone are enough for success.
	 *
	 * @return void
	 */
	public function testForceUpdateSucceedsWhenVersionsMatch(): void {
		$service = $this->service(['manualImport', 'getVersionInfo', 'getConfigurationStatus']);
		$service->method('manualImport')->willReturn(['success' => true, 'message' => 'ok']);
		$service->method('getVersionInfo')->willReturn(['isFullyConfigured' => false, 'versionsMatch' => true]);
		$service->method('getConfigurationStatus')->willReturn([]);

		$this->assertTrue($service->forceUpdate()['success']);
	}//end testForceUpdateSucceedsWhenVersionsMatch()

	/**
	 * An exception during the update is caught and reported.
	 *
	 * @return void
	 */
	public function testForceUpdateReportsAnException(): void {
		$service = $this->service(['manualImport', 'getVersionInfo', 'getConfigurationStatus']);
		$service->method('manualImport')->willReturn(['success' => true]);
		$service->method('getVersionInfo')->willThrowException(new \RuntimeException('version read failed'));

		$result = $service->forceUpdate();

		$this->assertSame(
			[
				'success' => false,
				'message' => 'Force update failed: version read failed',
				'error' => 'version read failed',
			],
			$result
		);
	}//end testForceUpdateReportsAnException()
}//end class
