<?php

/**
 * Unit tests for the outer failure path of RoleGroupSettings::updateUserGroupsConfig.
 *
 * @category  Test
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

use OCA\Stackiq\Service\Settings\RoleGroupSettings;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A failure that escapes a role update is caught by the whole-config update and reported.
 *
 * @covers \OCA\Stackiq\Service\Settings\RoleGroupSettings
 *
 * @spec openspec/specs/settings-service/spec.md
 */
class RoleGroupSettingsUpdateFailureTest extends TestCase {

	/**
	 * When logging a failed role write itself fails, the update answers a failure naming it
	 * and logs the config it was given.
	 *
	 * @return void
	 */
	public function testAnEscapingFailureIsReportedWithTheConfig(): void {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('setValueString')->willThrowException(new RuntimeException('db down'));
		$logged = [];
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			static function (string $message, array $context = []) use (&$logged): void {
				$logged[] = [$message, $context];
				if (count($logged) === 1) {
					throw new RuntimeException('log sink full');
				}
			}
		);
		$settings = new RoleGroupSettings(
			config: $config,
			appManager: $this->createMock(originalClassName: IAppManager::class),
			groupManager: $this->createMock(originalClassName: IGroupManager::class),
			logger: $logger
		);

		$answer = $settings->updateUserGroupsConfig(config: ['generic' => ['users'], 'superUser' => ['admin']]);

		$this->assertSame(
			['success' => false, 'message' => 'Failed to update user groups config: log sink full'],
			$answer
		);
		$this->assertSame(
			[
				['SettingsService: Failed to update generic user groups', ['exception' => 'db down']],
				[
					'Failed to update user groups config',
					['exception' => 'log sink full', 'config' => ['generic' => ['users'], 'superUser' => ['admin']]],
				],
			],
			$logged
		);
	}//end testAnEscapingFailureIsReportedWithTheConfig()
}//end class
