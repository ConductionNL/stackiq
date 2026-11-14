<?php

/**
 * BaselineClassificationSeedTest
 *
 * The demo data classifies Voorbeeld Name 1 by three organisations, so its
 * summary shows BBN2 twice and BBN3 once (security-baseline-classification task 5).
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Settings;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use OCA\Stackiq\Service\UsageClassificationService;
use PHPUnit\Framework\TestCase;

/**
 * The seeded classifications and the summary they give.
 */
class BaselineClassificationSeedTest extends TestCase {

	/**
	 * The seeded usages of the stackiq register for module-voorbeeld-name-1-1, with the references as the import resolves them.
	 *
	 * @return list<array>
	 */
	private static function seededUsages(): array {
		$mock = json_decode((string) file_get_contents(__DIR__ . '/../../../lib/Settings/stackiq_mock_register.json'), true);

		$usages = [];
		foreach ($mock['components']['objects'] as $object) {
			if (($object['@self']['register'] ?? '') === 'stackiq' && ($object['@self']['schema'] ?? '') === 'usage'
				&& ($object['module'] ?? null) === '@ref:module-voorbeeld-name-1-1'
			) {
				$object['module'] = 'module-voorbeeld-name-1-1';
				$usages[] = $object;
			}
		}

		return $usages;
	}//end seededUsages()

	/**
	 * Three organisations classified the application: BBN2 twice and BBN3 once overall, and the stored overall is the highest aspect.
	 *
	 * @return void
	 */
	public function testTheDemoSummaryShowsThreeOrganisations(): void {
		$usages = self::seededUsages();
		$this->assertCount(3, $usages);
		$this->assertCount(3, array_unique(array_column($usages, 'consumer')));
		foreach ($usages as $usage) {
			$levels = [$usage['availabilityLevel'], $usage['integrityLevel'], $usage['confidentialityLevel']];
			sort($levels);
			$this->assertSame(end($levels), $usage['bbnLevel'], (string) $usage['@self']['slug']);
		}

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('searchObjects')->willReturn($usages);
		$config = $this->createMock(RegisterConfigReaderInterface::class);
		$config->method('getObjectService')->willReturn($objects);

		$summary = (new UsageClassificationService(config: $config))->summary(moduleId: 'module-voorbeeld-name-1-1');

		$this->assertSame(3, $summary['organisations']);
		$this->assertSame(['BBN1' => 0, 'BBN2' => 2, 'BBN3' => 1], $summary['levels']['overall']);
	}//end testTheDemoSummaryShowsThreeOrganisations()
}//end class
