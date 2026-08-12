<?php

/**
 * Unit tests for EolMatcherService — conservative version-prefix matching,
 * PUT-semantic stamping, and provenance.
 *
 * @category  Tests
 * @package   OCA\SoftwareCatalog\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-version-matching-is-conservative-and-unambiguous-only
 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-stamping-preserves-every-other-field-and-records-provenance
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Service;

use OCA\SoftwareCatalog\Service\EolMatcherService;
use PHPUnit\Framework\TestCase;

/**
 * Fixture-based coverage of the matcher's decision boundary.
 */
class EolMatcherServiceTest extends TestCase {

	private EolMatcherService $matcher;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->matcher = new EolMatcherService();
	}//end setUp()

	/**
	 * A single most-specific candidate stamps unambiguously.
	 *
	 * @spec openspec/specs/eol-feed-integration/spec.md#scenario-unambiguous-match-stamps-the-version
	 * @return void
	 */
	public function testUnambiguousMatchAtMostSpecificLevel(): void {
		$cycles = [
			['cycle' => '21.3', 'eol' => '2025-11-09'],
			['cycle' => '21', 'eol' => '2026-01-01'],
		];

		$matched = $this->matcher->matchVersion(versie: '21.3.1', cycles: $cycles);

		$this->assertNotNull($matched);
		$this->assertSame('21.3', $matched['cycle']);
		$this->assertSame('2025-11-09', $matched['eol']);
	}//end testUnambiguousMatchAtMostSpecificLevel()

	/**
	 * Two candidates tied at the same (only) matching depth are ambiguous
	 * and must not be stamped.
	 *
	 * @spec openspec/specs/eol-feed-integration/spec.md#scenario-ambiguous-match-is-skipped-not-guessed
	 * @return void
	 */
	public function testAmbiguousTieIsNotMatched(): void {
		$cycles = [
			['cycle' => '2.0', 'eol' => '2024-01-01'],
			['cycle' => '2.1', 'eol' => '2024-06-01'],
		];

		$matched = $this->matcher->matchVersion(versie: '2', cycles: $cycles);

		$this->assertNull($matched);
	}//end testAmbiguousTieIsNotMatched()

	/**
	 * No candidate at all leaves the version unmatched.
	 *
	 * @spec openspec/specs/eol-feed-integration/spec.md#scenario-no-match-leaves-the-version-untouched
	 * @return void
	 */
	public function testNoCandidateIsNotMatched(): void {
		$cycles = [
			['cycle' => '5.2', 'eol' => '2024-01-01'],
			['cycle' => '6.0', 'eol' => '2025-01-01'],
		];

		$matched = $this->matcher->matchVersion(versie: '9.9.9', cycles: $cycles);

		$this->assertNull($matched);
	}//end testNoCandidateIsNotMatched()

	/**
	 * A shorter, more general cycle candidate loses to a longer, more
	 * specific one when both are present — the "prefix overlap across
	 * versions" boundary from design.md's file structure notes.
	 *
	 * @return void
	 */
	public function testMoreSpecificCandidateWinsOverGeneralOne(): void {
		$cycles = [
			['cycle' => '3', 'eol' => '2023-01-01'],
			['cycle' => '3.14', 'eol' => '2029-10-01'],
		];

		$matched = $this->matcher->matchVersion(versie: '3.14.2', cycles: $cycles);

		$this->assertNotNull($matched);
		$this->assertSame('3.14', $matched['cycle']);
	}//end testMoreSpecificCandidateWinsOverGeneralOne()

	/**
	 * An exact-equal cycle/version pair matches at full depth.
	 *
	 * @return void
	 */
	public function testExactEqualCycleMatches(): void {
		$cycles = [
			['cycle' => '16', 'eol' => '2028-11-09'],
		];

		$matched = $this->matcher->matchVersion(versie: '16', cycles: $cycles);

		$this->assertNotNull($matched);
		$this->assertSame('16', $matched['cycle']);
	}//end testExactEqualCycleMatches()

	/**
	 * A cycle from a different product's shape never leaks in — matching is
	 * always scoped to the single cycle set the caller passes in (the
	 * per-module `eolProductSlug` selection happens one layer up, in
	 * EolSyncService).
	 *
	 * @return void
	 */
	public function testDivergingSegmentIsNeverACandidate(): void {
		$cycles = [
			['cycle' => '1.0', 'eol' => '2020-01-01'],
		];

		// '1.1.0' diverges from '1.0' at the second segment ('1' !== '0').
		$matched = $this->matcher->matchVersion(versie: '1.1.0', cycles: $cycles);

		$this->assertNull($matched);
	}//end testDivergingSegmentIsNeverACandidate()

	/**
	 * A cycle with no scheduled EOL date (empty string) is not stamped —
	 * nothing informative to record — but the version is still reported
	 * skipped, not erroring.
	 *
	 * @return void
	 */
	public function testEmptyEolDateIsSkippedNotStamped(): void {
		$result = $this->matcher->matchModuleVersions(
			moduleVersies: [['id' => 'mv-1', 'versie' => '3.14.1']],
			cycles: [['cycle' => '3.14', 'eol' => '']],
			source: 'endoflife.date',
			fetchedAt: '2026-07-23T00:00:00+00:00'
		);

		$this->assertCount(0, $result['stamped']);
		$this->assertCount(1, $result['skipped']);
		$this->assertSame('mv-1', $result['skipped'][0]['id']);
	}//end testEmptyEolDateIsSkippedNotStamped()

	/**
	 * Stamping carries every other field on the moduleVersie forward
	 * unchanged (PUT-semantic base) and adds the three stamped fields.
	 *
	 * @spec openspec/specs/eol-feed-integration/spec.md#scenario-an-unrelated-field-survives-a-stamp
	 * @return void
	 */
	public function testStampPreservesEveryOtherFieldAndAddsProvenance(): void {
		$moduleVersie = [
			'id' => 'mv-uuid-1',
			'module' => 'module-uuid-1',
			'versie' => '21.3.1',
			'beschrijvingKort' => 'A short description that must survive',
			'status' => 'in gebruik',
			'gebruiken' => ['gebruik-1', 'gebruik-2'],
		];
		$matchedCycle = ['cycle' => '21.3', 'eol' => '2025-11-09'];

		$stamped = $this->matcher->buildStamp(
			moduleVersie: $moduleVersie,
			matchedCycle: $matchedCycle,
			source: 'endoflife.date',
			fetchedAt: '2026-07-23T10:00:00+00:00'
		);

		// Stamped fields.
		$this->assertSame('2025-11-09', $stamped['datumEindeOndersteuning']);
		$this->assertSame('endoflife.date', $stamped['eolBron']);
		$this->assertSame('2026-07-23T10:00:00+00:00', $stamped['eolBijgewerktOp']);

		// Every other field survives unchanged (OR saveObject is PUT-semantic).
		$this->assertSame('mv-uuid-1', $stamped['id']);
		$this->assertSame('module-uuid-1', $stamped['module']);
		$this->assertSame('21.3.1', $stamped['versie']);
		$this->assertSame('A short description that must survive', $stamped['beschrijvingKort']);
		$this->assertSame('in gebruik', $stamped['status']);
		$this->assertSame(['gebruik-1', 'gebruik-2'], $stamped['gebruiken']);
	}//end testStampPreservesEveryOtherFieldAndAddsProvenance()

	/**
	 * A hand-entered datumEindeOndersteuning (never passed through the
	 * matcher) never gains eolBron/eolBijgewerktOp — those are only ever
	 * set by buildStamp(), never fabricated for manual entries.
	 *
	 * @return void
	 */
	public function testUnmatchedVersionNeverGainsProvenance(): void {
		$handEntered = [
			'id' => 'mv-uuid-2',
			'versie' => '9.9.9',
			'datumEindeOndersteuning' => '2030-01-01',
		];

		$result = $this->matcher->matchModuleVersions(
			moduleVersies: [$handEntered],
			cycles: [['cycle' => '1.0', 'eol' => '2020-01-01']],
			source: 'endoflife.date',
			fetchedAt: '2026-07-23T00:00:00+00:00'
		);

		$this->assertCount(0, $result['stamped']);
		$this->assertCount(1, $result['skipped']);
		$this->assertArrayNotHasKey('eolBron', $result['skipped'][0]);
		$this->assertArrayNotHasKey('eolBijgewerktOp', $result['skipped'][0]);
		$this->assertSame('2030-01-01', $result['skipped'][0]['datumEindeOndersteuning']);
	}//end testUnmatchedVersionNeverGainsProvenance()

	/**
	 * matchModuleVersions() partitions a mixed batch correctly: matches are
	 * stamped, ambiguous/no-match/empty-versie rows are skipped untouched.
	 *
	 * @return void
	 */
	public function testMatchModuleVersionsPartitionsAMixedBatch(): void {
		$cycles = [
			['cycle' => '21.3', 'eol' => '2025-11-09'],
			['cycle' => '2.0', 'eol' => '2024-01-01'],
			['cycle' => '2.1', 'eol' => '2024-06-01'],
		];

		$moduleVersies = [
			['id' => 'mv-match', 'versie' => '21.3.1'],
			['id' => 'mv-ambiguous', 'versie' => '2'],
			['id' => 'mv-nomatch', 'versie' => '99.0'],
			['id' => 'mv-empty', 'versie' => ''],
		];

		$result = $this->matcher->matchModuleVersions(
			moduleVersies: $moduleVersies,
			cycles: $cycles,
			source: 'endoflife.date',
			fetchedAt: '2026-07-23T00:00:00+00:00'
		);

		$this->assertCount(1, $result['stamped']);
		$this->assertSame('mv-match', $result['stamped'][0]['id']);
		$this->assertSame('2025-11-09', $result['stamped'][0]['datumEindeOndersteuning']);

		$this->assertCount(3, $result['skipped']);
		$skippedIds = array_column($result['skipped'], 'id');
		$this->assertContains('mv-ambiguous', $skippedIds);
		$this->assertContains('mv-nomatch', $skippedIds);
		$this->assertContains('mv-empty', $skippedIds);
	}//end testMatchModuleVersionsPartitionsAMixedBatch()
}//end class
