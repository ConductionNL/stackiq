<?php

/**
 * Unit tests for SettingsService::resetAutoConfiguration() and cleanupOldConfiguration().
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
 * Resetting the auto-configuration flag (optionally with the schema and
 * register keys) and removing the retired configuration keys.
 */
final class SettingsServiceResetCleanupTest extends TestCase {

	/**
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig $config;

	/**
	 * Build the service around the given IAppConfig mock.
	 *
	 * @return SettingsService
	 */
	private function service(): SettingsService {
		return new SettingsService(
			catalogue: $this->createMock(CatalogueLocator::class),
			config: $this->config,
			request: $this->createMock(IRequest::class),
			roleGroupSettings: $this->createMock(RoleGroupSettings::class),
			container: $this->createMock(ContainerInterface::class),
			appManager: $this->createMock(IAppManager::class),
			logger: $this->createMock(LoggerInterface::class),
			groupManager: $this->createMock(IGroupManager::class),
			l10n: $this->createMock(IL10N::class),
			emailSettings: $this->createMock(EmailSettings::class)
		);

	}//end service()

	/**
	 * Create the IAppConfig mock.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->config = $this->createMock(IAppConfig::class);

	}//end setUp()

	/**
	 * A plain reset only clears the completion flag.
	 *
	 * @return void
	 */
	public function testAPlainResetOnlyClearsTheFlag(): void {
		$written = [];
		$this->config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value) use (&$written): bool {
				$written[$key] = [$app, $value];
				return true;
			}
		);

		$result = $this->service()->resetAutoConfiguration();

		$this->assertSame(['auto_config_completed' => ['stackiq', 'false']], $written);
		$this->assertSame(['auto_config_completed_flag'], $result['reset_items']);
		$this->assertTrue($result['success']);

	}//end testAPlainResetOnlyClearsTheFlag()

	/**
	 * A full reset also empties the twelve schema and register keys.
	 *
	 * @return void
	 */
	public function testAFullResetEmptiesTheSchemaAndRegisterKeys(): void {
		$written = [];
		$this->config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value) use (&$written): bool {
				$written[$key] = $value;
				return true;
			}
		);

		$result = $this->service()->resetAutoConfiguration(resetConfiguration: true);

		$this->assertCount(13, $written);
		$this->assertSame('false', $written['auto_config_completed']);
		$this->assertSame('', $written['organization_schema']);
		$this->assertSame('', $written['voorzieningen_contactpersoon_register']);
		$this->assertSame(['auto_config_completed_flag', 'schema_register_configurations'], $result['reset_items']);

	}//end testAFullResetEmptiesTheSchemaAndRegisterKeys()

	/**
	 * A failing write is reported, not thrown.
	 *
	 * @return void
	 */
	public function testAFailingResetIsReported(): void {
		$this->config->method('setValueString')->willThrowException(new \Exception('read only'));

		$result = $this->service()->resetAutoConfiguration();

		$this->assertFalse($result['success']);
		$this->assertSame('Failed to reset auto-configuration: read only', $result['message']);

	}//end testAFailingResetIsReported()

	/**
	 * Cleanup deletes every retired key in the app's namespace, including
	 * the stored secrets of the old flat email settings.
	 *
	 * @return void
	 */
	public function testCleanupDeletesTheRetiredKeys(): void {
		$deleted = [];
		$this->config->method('deleteKey')->willReturnCallback(
			function (string $app, string $key) use (&$deleted): void {
				$this->assertSame('stackiq', $app);
				$deleted[] = $key;
			}
		);

		$result = $this->service()->cleanupOldConfiguration();

		$this->assertTrue($result['success']);
		$this->assertSame([], $result['errors']);
		$this->assertSame($deleted, $result['cleaned']);
		$this->assertCount(60, $deleted);
		foreach (['email_smtp_password', 'email_ses_secret_key', 'amef_register_id', 'voorzieningen_register', 'test_receiver_override'] as $key) {
			$this->assertContains($key, $deleted);
		}

		$this->assertNotContains('email_config', $deleted, 'The current email blob must survive the cleanup.');
		$this->assertNotContains('amef_config', $deleted);

	}//end testCleanupDeletesTheRetiredKeys()

	/**
	 * A key that cannot be deleted is listed as an error while the others
	 * are still removed.
	 *
	 * @return void
	 */
	public function testCleanupCarriesOnPastAFailingKey(): void {
		$this->config->method('deleteKey')->willReturnCallback(
			function (string $app, string $key): void {
				if ($key === 'sender_name') {
					throw new \Exception('locked');
				}
			}
		);

		$result = $this->service()->cleanupOldConfiguration();

		$this->assertSame(["Failed to delete key 'sender_name': locked"], $result['errors']);
		$this->assertCount(59, $result['cleaned']);
		$this->assertNotContains('sender_name', $result['cleaned']);

	}//end testCleanupCarriesOnPastAFailingKey()
}//end class
