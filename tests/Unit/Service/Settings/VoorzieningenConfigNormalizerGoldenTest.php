<?php

/**
 * Golden test for VoorzieningenConfigNormalizer::normalize().
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

use OCA\Stackiq\Service\Settings\VoorzieningenConfigNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Pins how a raw voorzieningen configuration is normalised.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Service\Settings
 */
final class VoorzieningenConfigNormalizerGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../../fixtures/settings/voorzieningen-normalize.json';

	/**
	 * Every input normalises to the recorded configuration, key order included.
	 *
	 * @return void
	 */
	public function testNormalizeMatchesGolden(): void {
		$inputs = [
			'empty'      => [],
			'register only' => ['register' => 7],
			'null register' => ['register' => null],
			'mixed'      => [
				'register' => '3', 'gebruik_schema' => 20, 'module_schema' => '22', 'contract_schema' => null,
				'suite_schema' => '', 'sector_schema' => false, 'sbomComponent_schema' => 1.5,
				'sources' => ['x'], 'registers' => [1], 'unknown_schema' => '9', 'moduleVersie_schema' => true,
			],
			'every key'  => array_fill_keys(
				['organisatie_schema', 'contactpersoon_schema', 'suite_schema', 'dienst_schema', 'kwetsbaarheid_schema', 'gebruik_schema',
					'contract_schema', 'koppeling_schema', 'beoordeeling_schema', 'module_schema', 'compliancy_schema', 'moduleVersie_schema',
					'sector_schema', 'sbomComponent_schema', 'maintenanceWindow_schema'],
				'5'
			),
		];
		$normalizer = new VoorzieningenConfigNormalizer();
		$actual     = [];
		foreach ($inputs as $name => $input) {
			$actual[$name] = $normalizer->normalize($input);
		}

		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT) . "\n");
		}

		$this->assertSame(json_encode(json_decode((string) file_get_contents(self::FIXTURE), true)), json_encode($actual));

	}//end testNormalizeMatchesGolden()
}//end class
