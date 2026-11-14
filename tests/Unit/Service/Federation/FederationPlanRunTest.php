<?php

/**
 * Unit tests for FederationPlanRun.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Service\Federation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Federation;

use OCA\Stackiq\Service\Federation\FederationPlanRun;
use PHPUnit\Framework\TestCase;

/**
 * The provenance a federated entry carries and the plan a run returns.
 *
 * @covers \OCA\Stackiq\Service\Federation\FederationPlanRun
 */
class FederationPlanRunTest extends TestCase {
	/**
	 * Provenance names the peer, its organisation, the entry and the sync time, never stale or withdrawn.
	 *
	 * @return void
	 */
	public function testProvenanceDescribesThePeerEntry(): void {
		$run = new FederationPlanRun(
			['url' => 'https://peer.example', 'organisation' => 'org-p', 'syncedAt' => '2026-11-12T10:00:00Z', 'token' => 'x'],
			[]
		);

		self::assertSame(
			[
				'instance' => 'https://peer.example',
				'organisation' => 'org-p',
				'peerEntryId' => 'e-1',
				'syncedAt' => '2026-11-12T10:00:00Z',
				'stale' => false,
				'withdrawn' => false,
			],
			$run->provenance('e-1')
		);
	}

	/**
	 * The plan holds what the run collected, in its three buckets.
	 *
	 * @return void
	 */
	public function testThePlanReturnsTheCollectedBuckets(): void {
		$run = new FederationPlanRun(['url' => 'u', 'organisation' => 'o', 'syncedAt' => 't'], ['p-1' => ['uuid' => 'l-1']]);

		self::assertSame(['create' => [], 'update' => [], 'withdraw' => []], $run->plan());

		$run->create[]   = ['id' => 'p-2'];
		$run->update[]   = ['id' => 'p-1'];
		$run->withdraw[] = 'l-9';

		self::assertSame(['create' => [['id' => 'p-2']], 'update' => [['id' => 'p-1']], 'withdraw' => ['l-9']], $run->plan());
		self::assertSame(['p-1' => ['uuid' => 'l-1']], $run->localByPeerId);
	}
}
