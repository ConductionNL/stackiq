<?php

/**
 * Unit tests for CronjobSettings::getAvailableOrganisationsForCronjobs().
 *
 * OpenRegister's OrganisationMapper::findAll() is paged with typed int limit and
 * offset; the old nine-argument call with null limit/offset raised a TypeError.
 * The service now asks findList() for an unbounded ListQuery.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Settings;

use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\Stackiq\Service\Settings\CronjobSettings;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the cronjob organisation listing.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Service\Settings
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://github.com/ConductionNL/stackiq
 */
class CronjobSettingsOrganisationsTest extends TestCase {

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

		$service = new CronjobSettings(
			$this->createMock(IAppConfig::class),
			$appManager,
			$container,
			$this->createMock(LoggerInterface::class),
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
