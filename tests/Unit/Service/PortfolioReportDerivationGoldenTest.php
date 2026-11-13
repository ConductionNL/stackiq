<?php

/**
 * Golden tests for PortfolioReportDerivation::deriveEolState() and suggestTimeClassification().
 *
 * Pins the end-of-support state for every date shape and the TIME class for
 * every score pair, so splitting the two methods keeps every outcome.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Stackiq\Service\PortfolioReportDerivation;
use PHPUnit\Framework\TestCase;

/**
 * Golden tests for the end-of-support state and the suggested TIME class.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */
class PortfolioReportDerivationGoldenTest extends TestCase {

	/**
	 * The end-of-support state for every end and withdrawn date shape.
	 *
	 * @return void
	 */
	public function testEndOfSupportStateForEveryDateShape(): void {
		$now = new DateTimeImmutable('2026-06-01T12:00:00+00:00');
		$derivation = new PortfolioReportDerivation();
		$cases = [
			[null, ['passed' => false, 'withdrawn' => false, 'endDate' => null, 'withdrawnDate' => null]],
			[[], ['passed' => false, 'withdrawn' => false, 'endDate' => null, 'withdrawnDate' => null]],
			[['dateEndSupport' => '2026-06-01'], ['passed' => true, 'withdrawn' => false, 'endDate' => '2026-06-01', 'withdrawnDate' => null]],
			[['dateEndSupport' => '2026-06-02'], ['passed' => false, 'withdrawn' => false, 'endDate' => '2026-06-02', 'withdrawnDate' => null]],
			[['dateEndSupport' => 'not a date'], ['passed' => false, 'withdrawn' => false, 'endDate' => 'not a date', 'withdrawnDate' => null]],
			[['dateEndSupport' => 20250101], ['passed' => false, 'withdrawn' => false, 'endDate' => null, 'withdrawnDate' => null]],
			[['dateWithdrawn' => '2026-01-01'], ['passed' => false, 'withdrawn' => true, 'endDate' => null, 'withdrawnDate' => '2026-01-01']],
			[['dateWithdrawn' => '   '], ['passed' => false, 'withdrawn' => false, 'endDate' => null, 'withdrawnDate' => null]],
			[['dateWithdrawn' => ' x '], ['passed' => false, 'withdrawn' => true, 'endDate' => null, 'withdrawnDate' => ' x ']],
			[['dateWithdrawn' => 1], ['passed' => false, 'withdrawn' => false, 'endDate' => null, 'withdrawnDate' => null]],
		];

		foreach ($cases as [$moduleVersion, $expected]) {
			$this->assertSame($expected, $derivation->deriveEolState($moduleVersion, $now));
		}
	}//end testEndOfSupportStateForEveryDateShape()

	/**
	 * The TIME class for every score pair, null while a score is missing.
	 *
	 * @return void
	 */
	public function testTimeClassificationForEveryScorePair(): void {
		$derivation = new PortfolioReportDerivation();
		$grid = [];
		foreach ([null, 1, 2, 3, 4, 5] as $value) {
			foreach ([null, 1, 2, 3, 4, 5] as $fit) {
				$grid[] = ($value ?? '-') . '/' . ($fit ?? '-') . '=' . ($derivation->suggestTimeClassification($value, $fit) ?? 'null');
			}
		}

		$this->assertSame(
			[
				'-/-=null', '-/1=null', '-/2=null', '-/3=null', '-/4=null', '-/5=null',
				'1/-=null', '1/1=Eliminate', '1/2=Eliminate', '1/3=Tolerate', '1/4=Tolerate', '1/5=Tolerate',
				'2/-=null', '2/1=Eliminate', '2/2=Eliminate', '2/3=Tolerate', '2/4=Tolerate', '2/5=Tolerate',
				'3/-=null', '3/1=Migrate', '3/2=Migrate', '3/3=Invest', '3/4=Invest', '3/5=Invest',
				'4/-=null', '4/1=Migrate', '4/2=Migrate', '4/3=Invest', '4/4=Invest', '4/5=Invest',
				'5/-=null', '5/1=Migrate', '5/2=Migrate', '5/3=Invest', '5/4=Invest', '5/5=Invest',
			],
			$grid
		);
	}//end testTimeClassificationForEveryScorePair()

}//end class
