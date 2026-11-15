<?php

/**
 * Unit tests for the CronjobSettings organisation listing failure paths.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Settings;

use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\Stackiq\Service\Settings\CronjobSettings;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Listing organisations for cronjobs without OpenRegister, or when it fails.
 *
 * @covers \OCA\Stackiq\Service\Settings\CronjobSettings
 */
class CronjobSettingsOrganisationsFailureTest extends TestCase {

	/**
	 * Without OpenRegister nothing is asked of the container.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNothingIsListed(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');

		$this->assertSame(
			['success' => false, 'message' => 'OpenRegister is not installed', 'organisations' => []],
			$this->settings(installed: ['files'], container: $container)->getAvailableOrganisationsForCronjobs()
		);
	}//end testWithoutOpenRegisterNothingIsListed()

	/**
	 * A failing mapper is logged and reported with its reason.
	 *
	 * @return void
	 */
	public function testAFailingMapperIsReported(): void {
		$mapper = $this->createMock(OrganisationMapper::class);
		$mapper->method('findList')->willThrowException(new RuntimeException('db down'));
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(OrganisationMapper::class)->willReturn($mapper);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')
			->with('Failed to get available organisations for cronjobs', ['exception' => 'db down']);

		$this->assertSame(
			['success' => false, 'message' => 'Failed to get available organisations: db down', 'organisations' => []],
			$this->settings(installed: ['openregister'], container: $container, logger: $logger)->getAvailableOrganisationsForCronjobs()
		);
	}//end testAFailingMapperIsReported()

	/**
	 * The settings under test.
	 *
	 * @param array<int, string>   $installed The installed apps.
	 * @param ContainerInterface   $container The container.
	 * @param LoggerInterface|null $logger    The logger.
	 *
	 * @return CronjobSettings
	 */
	private function settings(array $installed, ContainerInterface $container, ?LoggerInterface $logger = null): CronjobSettings {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn($installed);

		return new CronjobSettings(
			$this->createMock(IAppConfig::class),
			$appManager,
			$container,
			($logger ?? $this->createMock(LoggerInterface::class)),
		);
	}//end settings()
}//end class
