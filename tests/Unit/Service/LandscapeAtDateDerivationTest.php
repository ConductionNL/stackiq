<?php

/**
 * Tests for the landscape on a date (architecture-future-state-scenarios, task 1).
 *
 * The derivation and PortfolioReportDerivation are the real classes; the
 * usages are plain arrays as OpenRegister returns them.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Stackiq\Service\LandscapeAtDateDerivation;
use OCA\Stackiq\Service\PortfolioReportDerivation;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Stackiq\Service\LandscapeAtDateDerivation
 * @uses   \OCA\Stackiq\Service\PortfolioReportDerivation
 */
class LandscapeAtDateDerivationTest extends TestCase {

	/**
	 * Usages with phase dates are in the landscape when In production or To be phased out on the date.
	 *
	 * @return void
	 */
	public function testTheLandscapeHoldsTheUsagesInProductionOrPhasingOutOnTheDate(): void {
		$usages = [
			['id' => 'u-live', 'module' => 'm-zaak', 'startDateInProduction' => '2024-01-01', 'usedForReferenceComponents' => ['rc-zaak']],
			['id' => 'u-phasing', 'module' => 'm-dms', 'startDateInProduction' => '2020-01-01', 'startDateOutPhasing' => '2026-06-01'],
			['id' => 'u-gone', 'module' => 'm-old', 'startDateInProduction' => '2020-01-01', 'startDateOutPhased' => '2026-01-01'],
			['id' => 'u-later', 'module' => 'm-new', 'startDatePlanned' => '2026-01-01', 'startDateInProduction' => '2027-01-01'],
		];

		$today = $this->derive(usages: $usages, date: '2026-09-27');
		$this->assertSame(['m-zaak', 'm-dms'], array_column($today['entries'], 'module'));
		$this->assertSame(['rc-zaak'], $today['entries'][0]['referenceComponents']);
		$this->assertSame(['today'], $today['entries'][0]['sources']);
		$this->assertSame(0, $today['notDated']);

		$future = $this->derive(usages: $usages, date: '2027-06-30');
		$this->assertSame(['m-zaak', 'm-dms', 'm-new'], array_column($future['entries'], 'module'));
	}

	/**
	 * A planned replacement due on or before the date swaps the usage for its successor, keeping the components.
	 *
	 * @return void
	 */
	public function testAPlannedReplacementBringsTheSuccessor(): void {
		$usages = [[
			'id' => 'u-1', 'module' => ['id' => 'm-a'], 'startDateInProduction' => '2022-01-01',
			'plannedReplacement' => ['uuid' => 'm-b'], 'plannedReplacementDate' => '2027-03-01',
			'usedForReferenceComponents' => [['id' => 'rc-1'], 'rc-2', 'rc-1'],
		]];

		$before = $this->derive(usages: $usages, date: '2027-02-28');
		$this->assertSame('m-a', $before['entries'][0]['module']);
		$this->assertSame([], $before['removed']);

		$after = $this->derive(usages: $usages, date: '2027-03-01');
		$this->assertSame(
			[['usage' => 'u-1', 'module' => 'm-b', 'referenceComponents' => ['rc-1', 'rc-2'], 'sources' => ['plan'], 'replaces' => 'm-a', 'date' => '2027-03-01']],
			$after['entries']
		);
		$this->assertSame([['usage' => 'u-1', 'module' => 'm-a', 'sources' => ['plan'], 'date' => '2027-03-01', 'replacedBy' => 'm-b']], $after['removed']);
	}

	/**
	 * A usage with only a current status is in every landscape; one with nothing is counted as not dated.
	 *
	 * @return void
	 */
	public function testAStatusOnlyUsageStaysAndAnUndatedOneIsCounted(): void {
		$usages = [
			['id' => 'u-status', 'module' => 'm-s', 'status' => 'In production'],
			['id' => 'u-phasing', 'module' => 'm-p', 'status' => 'To be phased out'],
			['id' => 'u-nothing', 'module' => 'm-n', 'status' => 'in-gebruik'],
			['id' => 'u-planned', 'module' => 'm-q', 'status' => 'Planned'],
		];

		foreach (['2026-09-27', '2030-01-01'] as $date) {
			$landscape = $this->derive(usages: $usages, date: $date);
			$this->assertSame(['m-s', 'm-p'], array_column($landscape['entries'], 'module'));
			$this->assertSame(2, $landscape['notDated']);
		}
	}

	/**
	 * A scenario change on a usage the plan also replaces wins, and names both sources.
	 *
	 * @return void
	 */
	public function testAScenarioChangeWinsOverThePlanAndNamesBoth(): void {
		$usages = [
			['id' => 'u-1', 'module' => 'm-a', 'startDateInProduction' => '2022-01-01', 'plannedReplacement' => 'm-b', 'plannedReplacementDate' => '2027-01-01', 'usedForReferenceComponents' => ['rc-1']],
			['id' => 'u-2', 'module' => 'm-c', 'status' => 'In production'],
		];
		$changes = [
			['action' => 'replace', 'usage' => 'u-1', 'module' => 'm-z', 'effectiveDate' => '2027-02-01'],
			['action' => 'add', 'module' => ['id' => 'm-new'], 'referenceComponents' => ['rc-9'], 'effectiveDate' => '2027-03-01'],
			['action' => 'phase out', 'usage' => 'u-2'],
			['action' => 'add', 'module' => 'm-late', 'effectiveDate' => '2028-01-01'],
		];

		$landscape = $this->derive(usages: $usages, date: '2027-06-30', changes: $changes);

		$this->assertSame(['m-z', 'm-new'], array_column($landscape['entries'], 'module'));
		$this->assertSame(['scenario', 'plan'], $landscape['entries'][0]['sources']);
		$this->assertSame(['rc-1'], $landscape['entries'][0]['referenceComponents']);
		$this->assertSame('m-a', $landscape['entries'][0]['replaces']);
		$this->assertSame(['scenario'], $landscape['entries'][1]['sources']);
		$this->assertSame(['rc-9'], $landscape['entries'][1]['referenceComponents']);
		$this->assertSame(
			[
				['usage' => 'u-1', 'module' => 'm-a', 'sources' => ['scenario', 'plan'], 'date' => '2027-02-01', 'replacedBy' => 'm-z'],
				['usage' => 'u-2', 'module' => 'm-c', 'sources' => ['scenario'], 'date' => '2027-06-30', 'replacedBy' => null],
			],
			$landscape['removed']
		);
	}

	/**
	 * A change on a usage outside the landscape, or of an unknown action, changes nothing.
	 *
	 * @return void
	 */
	public function testAChangeOnAnAbsentUsageChangesNothing(): void {
		$usages = [['id' => 'u-1', 'module' => 'm-a', 'status' => 'In production']];

		$landscape = $this->derive(
			usages: $usages,
			date: '2027-01-01',
			changes: [['action' => 'phase out', 'usage' => 'u-9'], ['action' => 'rename', 'usage' => 'u-1']]
		);

		$this->assertSame(['m-a'], array_column($landscape['entries'], 'module'));
		$this->assertSame([], $landscape['removed']);
	}

	/**
	 * Derive on a date.
	 *
	 * @param array<int,array<string,mixed>> $usages  The usages.
	 * @param string                         $date    The date.
	 * @param array<int,array<string,mixed>> $changes Scenario changes.
	 *
	 * @return array<string,mixed>
	 */
	private function derive(array $usages, string $date, array $changes=[]): array {
		return (new LandscapeAtDateDerivation(phases: new PortfolioReportDerivation()))->derive(
			usages: $usages,
			date: new DateTimeImmutable($date),
			changes: $changes
		);
	}
}
