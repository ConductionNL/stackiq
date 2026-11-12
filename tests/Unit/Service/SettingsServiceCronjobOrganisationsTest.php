<?php

/**
 * Unit tests for SettingsService::getAvailableOrganisationsForCronjobs().
 *
 * OpenRegister's OrganisationMapper::findAll() is paged with typed int limit and
 * offset; the old nine-argument call with null limit/offset raised a TypeError.
 * The service now asks findList() for an unbounded ListQuery.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\Stackiq\Service\Settings\EmailSettings;
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
 * Tests for the cronjob organisation listing.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://github.com/ConductionNL/stackiq
 */
class SettingsServiceCronjobOrganisationsTest extends TestCase {

	/**
	 * Every organisation is listed through one unbounded ListQuery.
	 *
	 * @return void
	 */
	public function testListsEveryOrganisationThroughAnUnboundedListQuery(): void {
		$organisation = new class {
			/**
			 * @return string
			 */
			public function getUuid(): string {
				return 'org-1';
			}

			/**
			 * @return string
			 */
			public function getName(): string {
				return 'Gemeente Voorbeeldstad';
			}

			/**
			 * @return string
			 */
			public function getDescription(): string {
				return 'Test';
			}
		};

		$mapper = $this->createMock(OrganisationMapper::class);
		$mapper->expects($this->once())->method('findList')->willReturnCallback(
			function (ListQuery $query) use ($organisation): array {
				$this->assertTrue($query->isUnbounded());
				$this->assertSame([], $query->filters);
				return [$organisation];
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(OrganisationMapper::class)->willReturn($mapper);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);

		$service = new SettingsService(
			$this->createMock(IAppConfig::class),
			$this->createMock(IRequest::class),
			$container,
			$appManager,
			$this->createMock(LoggerInterface::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IL10N::class),
			$this->createMock(EmailSettings::class),
		);

		$this->assertSame(
			[
				'success' => true,
				'organisations' => [
					['uuid' => 'org-1', 'name' => 'Gemeente Voorbeeldstad', 'description' => 'Test'],
				],
			],
			$service->getAvailableOrganisationsForCronjobs()
		);

	}//end testListsEveryOrganisationThroughAnUnboundedListQuery()

}//end class
