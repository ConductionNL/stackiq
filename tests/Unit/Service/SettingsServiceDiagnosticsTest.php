<?php

/**
 * Unit tests for SettingsService::getDebugInfo() and getVersionInfo().
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
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

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\RegisterService;
use OCA\Stackiq\Service\Settings\CatalogueLocator;
use OCA\Stackiq\Service\Settings\EmailSettings;
use OCA\Stackiq\Service\Settings\RoleGroupSettings;
use OCA\Stackiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The debug and version panels of the admin page: what they expose, what
 * they withhold, and how the app and configured versions are compared.
 */
final class SettingsServiceDiagnosticsTest extends TestCase {

	/**
	 * Backing key/value store of the IAppConfig fake.
	 *
	 * @var array<string, string>
	 */
	private array $store = [];

	/**
	 * @var CatalogueLocator&MockObject
	 */
	private CatalogueLocator $catalogue;

	/**
	 * @var EmailSettings&MockObject
	 */
	private EmailSettings $emailSettings;

	/**
	 * @var RoleGroupSettings&MockObject
	 */
	private RoleGroupSettings $roleGroups;

	/**
	 * @var IAppManager&MockObject
	 */
	private IAppManager $appManager;

	/**
	 * Create the shared doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = [];
		$this->catalogue = $this->createMock(CatalogueLocator::class);
		$this->emailSettings = $this->createMock(EmailSettings::class);
		$this->roleGroups = $this->createMock(RoleGroupSettings::class);
		$this->appManager = $this->createMock(IAppManager::class);

	}//end setUp()

	/**
	 * Build the service over an in-memory IAppConfig.
	 *
	 * @return SettingsService
	 */
	private function service(): SettingsService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default=''): string {
				return $this->store[$key] ?? $default;
			}
		);

		return new SettingsService(
			catalogue: $this->catalogue,
			config: $config,
			request: $this->createMock(IRequest::class),
			roleGroupSettings: $this->roleGroups,
			container: $this->createMock(ContainerInterface::class),
			appManager: $this->appManager,
			logger: $this->createMock(LoggerInterface::class),
			groupManager: $this->createMock(IGroupManager::class),
			l10n: $this->createMock(IL10N::class),
			emailSettings: $this->emailSettings
		);

	}//end service()

	/**
	 * Make OpenRegister report the given configured app version.
	 *
	 * @param string|null|\Exception $stored The stored version, or an exception to throw.
	 *
	 * @return void
	 */
	private function configuredVersion(string|null|\Exception $stored): void {
		$configService = $this->createMock(ConfigurationService::class);
		if ($stored instanceof \Exception) {
			$configService->method('getConfiguredAppVersion')->willThrowException($stored);
		} else {
			$configService->method('getConfiguredAppVersion')->willReturn($stored);
		}

		$this->catalogue->method('getConfigurationService')->willReturn($configService);

	}//end configuredVersion()

	/**
	 * The debug info lists the configuration keys, the role groups and the
	 * email settings with every secret removed.
	 *
	 * @return void
	 */
	public function testDebugInfoStripsEverySecret(): void {
		$this->store['organization_schema'] = '12';
		$this->roleGroups->method('getGenericUserGroups')->willReturn(['users']);
		$this->roleGroups->method('getOrganizationAdminGroups')->willReturn([]);
		$this->roleGroups->method('getSuperUserGroups')->willReturn(['admin']);
		$this->emailSettings->method('getEmailSettings')->willReturn(
			[
				'smtpHost' => 'mail.example.org',
				'smtpPassword' => 's1',
				'sendgridApiKey' => 's2',
				'mailgunApiKey' => 's3',
				'postmarkApiKey' => 's4',
				'sesSecretKey' => 's5',
				'mailjetSecretKey' => 's6',
			]
		);
		$this->catalogue->method('isOpenRegisterInstalled')->willReturn(false);

		$info = $this->service()->getDebugInfo();

		$this->assertSame(['smtpHost' => 'mail.example.org'], $info['emailSettings']);
		$this->assertSame('12', $info['configuration']['organization_schema']);
		$this->assertSame('', $info['configuration']['contact_schema']);
		$this->assertCount(16, $info['configuration']);
		$this->assertSame(['generic' => ['users'], 'organizationAdmin' => [], 'superUser' => ['admin']], $info['userGroups']);
		$this->assertSame([], $info['openRegister']['availableRegisters']);

	}//end testDebugInfoStripsEverySecret()

	/**
	 * Registers are only listed when OpenRegister is installed AND enabled;
	 * a failing listing is reported inside the openRegister block.
	 *
	 * @return void
	 */
	public function testDebugInfoListsRegistersOnlyForAnEnabledOpenRegister(): void {
		$this->emailSettings->method('getEmailSettings')->willReturn([]);
		$this->catalogue->method('isOpenRegisterInstalled')->willReturn(true);
		$this->catalogue->method('isOpenRegisterEnabled')->willReturnOnConsecutiveCalls(false, true, true);
		$registerService = $this->createMock(RegisterService::class);
		$registerService->method('findAll')->willReturnOnConsecutiveCalls([['id' => 1]], $this->throwException(new \Exception('db')));
		$this->catalogue->method('getRegisterService')->willReturn($registerService);

		$service = $this->service();
		$disabled = $service->getDebugInfo();
		$enabled = $service->getDebugInfo();
		$failing = $service->getDebugInfo();

		$this->assertSame([], $disabled['openRegister']['availableRegisters']);
		$this->assertSame([['id' => 1]], $enabled['openRegister']['availableRegisters']);
		$this->assertSame('db', $failing['openRegister']['error']);

	}//end testDebugInfoListsRegistersOnlyForAnEnabledOpenRegister()

	/**
	 * A failure outside the OpenRegister block ends up as a top-level error.
	 *
	 * @return void
	 */
	public function testDebugInfoReportsAnEarlyFailure(): void {
		$this->roleGroups->method('getGenericUserGroups')->willThrowException(new \Exception('groups'));

		$this->assertSame('groups', $this->service()->getDebugInfo()['error']);

	}//end testDebugInfoReportsAnEarlyFailure()

	/**
	 * Equal versions match and need no update; the stored flags come along.
	 *
	 * @return void
	 */
	public function testEqualVersionsMatch(): void {
		$this->appManager->method('getAppVersion')->with('stackiq')->willReturn('2.1.0');
		$this->configuredVersion('2.1.0');
		$this->catalogue->method('isOpenRegisterInstalled')->willReturn(true);
		$this->catalogue->method('isOpenRegisterEnabled')->willReturn(true);
		$this->catalogue->method('getSchemaIdForObjectType')->willReturn(4);
		$this->store['auto_config_completed'] = 'true';

		$info = $this->service()->getVersionInfo();

		$this->assertSame('2.1.0', $info['appVersion']);
		$this->assertSame('2.1.0', $info['configuredVersion']);
		$this->assertTrue($info['versionsMatch']);
		$this->assertFalse($info['needsUpdate']);
		$this->assertSame(0, $info['versionComparison']);
		$this->assertTrue($info['isFullyConfigured']);
		$this->assertTrue($info['autoConfigCompleted']);
		$this->assertTrue($info['openRegisterEnabled']);

	}//end testEqualVersionsMatch()

	/**
	 * A newer app version than the configured one needs an update.
	 *
	 * @return void
	 */
	public function testANewerAppVersionNeedsAnUpdate(): void {
		$this->appManager->method('getAppVersion')->willReturn('2.2.0');
		$this->configuredVersion('2.1.9');
		$this->catalogue->method('isOpenRegisterInstalled')->willReturn(false);
		$this->catalogue->expects($this->never())->method('isOpenRegisterEnabled');
		$this->catalogue->method('getSchemaIdForObjectType')->willReturn(null);

		$info = $this->service()->getVersionInfo();

		$this->assertFalse($info['versionsMatch']);
		$this->assertTrue($info['needsUpdate']);
		$this->assertSame(1, $info['versionComparison']);
		$this->assertFalse($info['isFullyConfigured']);
		$this->assertFalse($info['openRegisterEnabled']);
		$this->assertFalse($info['autoConfigCompleted']);

	}//end testANewerAppVersionNeedsAnUpdate()

	/**
	 * A configured version that cannot be read counts as "never configured":
	 * no match, an update needed, no comparison.
	 *
	 * @return void
	 */
	public function testAnUnreadableConfiguredVersionNeedsAnUpdate(): void {
		$this->appManager->method('getAppVersion')->willReturn('2.2.0');
		$this->configuredVersion(new \Exception('table missing'));

		$info = $this->service()->getVersionInfo();

		$this->assertNull($info['configuredVersion']);
		$this->assertFalse($info['versionsMatch']);
		$this->assertTrue($info['needsUpdate']);
		$this->assertNull($info['versionComparison']);

	}//end testAnUnreadableConfiguredVersionNeedsAnUpdate()

	/**
	 * A failure to read the app version is rethrown as a RuntimeException.
	 *
	 * @return void
	 */
	public function testAnUnreadableAppVersionThrows(): void {
		$this->appManager->method('getAppVersion')->willThrowException(new \Exception('no app'));

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Failed to get version information: no app');
		$this->service()->getVersionInfo();

	}//end testAnUnreadableAppVersionThrows()
}//end class
