<?php

/**
 * The overall BBN level of a usage and its classification stamp.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/security-baseline-classification/specs/baseline-classification/spec.md#requirement-req-bcl-002-the-overall-bbn-level-shall-be-the-highest-of-the-three-aspects-on-every-save
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\UsageClassification;
use PHPUnit\Framework\TestCase;

/**
 * Covers UsageClassification.
 *
 * @covers \OCA\Stackiq\Service\UsageClassification
 */
final class UsageClassificationTest extends TestCase {

	private const NOW = '2026-11-14T09:00:00Z';

	public function testTheOverallLevelIsTheHighestAspect(): void {
		$this->assertSame('BBN3', UsageClassification::derivedLevel(usage: ['availabilityLevel' => 'BBN1', 'integrityLevel' => 'BBN2', 'confidentialityLevel' => 'BBN3']));
		$this->assertSame('BBN2', UsageClassification::derivedLevel(usage: ['availabilityLevel' => 'BBN2']));
		$this->assertNull(UsageClassification::derivedLevel(usage: []));
		$this->assertNull(UsageClassification::derivedLevel(usage: ['availabilityLevel' => 'BBN9', 'integrityLevel' => '']));
	}

	public function testAFirstClassificationSetsTheLevelAndStamps(): void {
		$this->assertSame(
			['bbnLevel' => 'BBN3', 'classifiedAt' => self::NOW, 'classifiedBy' => 'ciso'],
			UsageClassification::patch(
				usage: ['availabilityLevel' => 'BBN1', 'integrityLevel' => 'BBN2', 'confidentialityLevel' => 'BBN3'],
				previous: [],
				userId: 'ciso',
				now: self::NOW
			)
		);
	}

	public function testAnUnclassifiedUsageNeedsNoWrite(): void {
		$this->assertSame([], UsageClassification::patch(usage: ['name' => 'Zaaksysteem'], previous: [], userId: 'u', now: self::NOW));
		$this->assertSame([], UsageClassification::patch(usage: ['bbnLevel' => null], previous: [], userId: 'u', now: self::NOW));
	}

	public function testAnUnchangedSaveWritesNothing(): void {
		$saved = ['availabilityLevel' => 'BBN2', 'integrityLevel' => 'BBN2', 'confidentialityLevel' => 'BBN3', 'bbnLevel' => 'BBN3', 'name' => 'renamed'];

		$this->assertSame([], UsageClassification::patch(usage: $saved, previous: $saved, userId: 'u', now: self::NOW), 'the subscriber\'s own write-back changes nothing');
	}

	public function testClearingEveryAspectEmptiesTheLevelAndStamps(): void {
		$previous = ['availabilityLevel' => 'BBN2', 'bbnLevel' => 'BBN2'];

		$this->assertSame(
			['bbnLevel' => null, 'classifiedAt' => self::NOW, 'classifiedBy' => 'u'],
			UsageClassification::patch(usage: ['availabilityLevel' => '', 'bbnLevel' => 'BBN2'], previous: $previous, userId: 'u', now: self::NOW)
		);
	}

	public function testAHandSetLevelIsPutBackWithoutAStamp(): void {
		$previous = ['integrityLevel' => 'BBN1', 'bbnLevel' => 'BBN1'];

		$this->assertSame(['bbnLevel' => 'BBN1'], UsageClassification::patch(usage: ['integrityLevel' => 'BBN1', 'bbnLevel' => 'BBN3'], previous: $previous, userId: 'u', now: self::NOW));
	}

	public function testAnEmptyStringAndAMissingValueAreTheSame(): void {
		$this->assertSame([], UsageClassification::patch(usage: ['availabilityLevel' => ''], previous: [], userId: 'u', now: self::NOW));
	}
}
