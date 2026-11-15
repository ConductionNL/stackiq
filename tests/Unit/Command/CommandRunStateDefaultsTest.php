<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction b.v. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Command;

use OCA\Stackiq\Command\GemmaLinkBackfill;
use OCA\Stackiq\Command\ReferenceAuditRun;
use PHPUnit\Framework\TestCase;

/**
 * Pins the default state of the two mutable run-state holders the commands
 * build and tally into. GemmaStandardLinksCommand seeds `counts` with the four
 * outcome keys and then does `$backfill->counts[$outcome]++`, so a drift in the
 * default key set or starting values would silently corrupt the run report;
 * ReferenceAuditRun starts every collection empty and the audit off by default.
 *
 * @spec openspec/specs/gemma-standard-links/spec.md
 */
class CommandRunStateDefaultsTest extends TestCase {
	public function testGemmaLinkBackfillStartsWithAZeroedFourOutcomeTally(): void {
		$backfill = new GemmaLinkBackfill();

		$this->assertFalse($backfill->dryRun);
		$this->assertSame([], $backfill->changes);
		$this->assertSame(
			['checked' => 0, 'needed' => 0, 'written' => 0, 'failed' => 0],
			$backfill->counts,
		);
	}

	public function testGemmaLinkBackfillTallyAcceptsPerOutcomeIncrements(): void {
		$backfill = new GemmaLinkBackfill();

		$backfill->counts['written']++;
		$backfill->counts['failed'] += 2;

		$this->assertSame(
			['checked' => 0, 'needed' => 0, 'written' => 1, 'failed' => 2],
			$backfill->counts,
		);
	}

	public function testReferenceAuditRunStartsEmptyAndReadOnly(): void {
		$run = new ReferenceAuditRun();

		$this->assertFalse($run->write);
		$this->assertNull($run->objectService);
		$this->assertSame([], $run->satellites);
		$this->assertSame([], $run->owners);
		$this->assertSame([], $run->ownerIds);
		$this->assertSame([], $run->counts);
	}
}
