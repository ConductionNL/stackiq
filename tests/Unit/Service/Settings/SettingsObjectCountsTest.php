<?php

/**
 * Unit tests for the schema counting of SettingsObjectCounts.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/settings-service/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Settings;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\Settings\SettingsObjectCounts;
use OCA\Stackiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * The counting path a schema config value of `true` reaches.
 *
 * @covers \OCA\Stackiq\Service\Settings\SettingsObjectCounts
 *
 * @spec openspec/specs/settings-service/spec.md
 */
class SettingsObjectCountsTest extends TestCase {

	/**
	 * Counts run only for schemas set to true, with the register and schema as
	 * integers; a failing count stays zero; a register of false counts nothing.
	 *
	 * @return void
	 */
	public function testCountsOnlyWhatTheConfigSwitchesOn(): void {
		$queries = [];
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('countSearchObjects')->willReturnCallback(
			function (array $query) use (&$queries) {
				$queries[] = $query;
				if ($query['@self']['schema'] === 1 && count($queries) === 2) {
					throw new \RuntimeException('count failed');
				}

				return 7;
			}
		);

		$config = ['register' => '5', 'organisatie_schema' => true, 'contract_schema' => true, 'review_schema' => '3'];
		$counts = $this->counter(objects: $objects, config: $config)->voorzieningenCounts();
		$this->assertSame(expected: 7, actual: $counts['totalOrganisatieObjects']);
		$this->assertSame(expected: 0, actual: $counts['totalContractObjects']);
		$this->assertSame(expected: 0, actual: $counts['totalReviewObjects']);
		$this->assertCount(expectedCount: 19, haystack: $counts);
		$this->assertSame(expected: [['@self' => ['register' => 5, 'schema' => 1]], ['@self' => ['register' => 5, 'schema' => 1]]], actual: $queries);

		$counts = $this->counter(objects: $objects, config: ['register' => false, 'organisatie_schema' => true])->voorzieningenCounts();
		$this->assertSame(expected: 0, actual: $counts['totalOrganisatieObjects']);
		$this->assertCount(expectedCount: 2, haystack: $queries);

	}//end testCountsOnlyWhatTheConfigSwitchesOn()

	/**
	 * Build a counter over a settings double.
	 *
	 * @param ObjectServiceInterface $objects The object service.
	 * @param array                  $config  The voorzieningen config.
	 *
	 * @return SettingsObjectCounts
	 */
	private function counter(ObjectServiceInterface $objects, array $config): SettingsObjectCounts {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getVoorzieningenConfig')->willReturn($config);
		return new SettingsObjectCounts(
			settings: $settings,
			container: $this->createMock(originalClassName: ContainerInterface::class),
			logger: new NullLogger()
		);

	}//end counter()

}//end class
