<?php

/**
 * Golden test for FederationMerger::plan over a mixed peer pull.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\Federation\FederationMerger;
use PHPUnit\Framework\TestCase;

/**
 * Pins the full create/update/withdraw plan, including order, for a pull that
 * mixes new, changed, unchanged, removed, foreign and id-less entries.
 *
 * @spec openspec/specs/federated-catalog-sync/spec.md
 */
class FederationMergerPlanGoldenTest extends TestCase {
	/**
	 * A mixed pull.
	 *
	 * @return void
	 */
	public function testMixedPull(): void {
		$peerUrl = 'https://peer.example.org';
		$source  = fn (string $peerId, array $extra = []): array => $extra + [
			'instance' => $peerUrl,
			'organisation' => 'Peer',
			'peerEntryId' => $peerId,
			'syncedAt' => '2026-01-01T00:00:00Z',
			'stale' => false,
			'withdrawn' => false,
		];
		$peerEntries  = [
			['id' => 'new-1', 'name' => 'New'],
			['uuid' => 'chg-1', 'name' => 'Changed now', '_source' => ['instance' => 'spoof']],
			['id' => 'same-1', 'name' => 'Same'],
			['id' => 42, 'name' => 'Int id'],
			['id' => '  ', 'uuid' => 'uuid-fallback', 'name' => 'Uuid fallback'],
			['id' => '', 'name' => 'No id'],
			['name' => 'No id at all'],
			['id' => 'noid-local', 'name' => 'Local has no OR id'],
			['id' => 'foreign-1', 'name' => 'Foreign'],
		];
		$localMirrors = [
			['id' => 'or-1', 'name' => 'Changed before', '_source' => $source('chg-1')],
			['id' => 'or-2', 'name' => 'Same', '_source' => $source('same-1', ['syncedAt' => '2025-12-01T00:00:00Z'])],
			['id' => 'or-3', 'name' => 'Gone', '_source' => $source('gone-1')],
			['name' => 'Local has no OR id', 'extra' => 1, '_source' => $source('noid-local')],
			['id' => 'or-5', 'name' => 'Foreign', '_source' => ['instance' => 'https://other.example.org', 'peerEntryId' => 'foreign-1']],
			['id' => 'or-6', 'name' => 'No provenance'],
			['id' => 'or-7', 'name' => 'Blank peer id', '_source' => $source('  ')],
			['id' => 'or-8', 'name' => 'Gone 2', '_source' => 'not-an-array'],
			['id' => 'or-9', 'name' => 'Int', '_source' => $source('42')],
		];

		$plan = (new FederationMerger())->plan(
			['url' => $peerUrl, 'organisation' => 'Peer', 'syncedAt' => '2026-02-02T00:00:00Z'],
			$peerEntries,
			$localMirrors
		);

		$path = dirname(__DIR__, 2) . '/fixtures/federation/mixed-plan.json';
		$this->assertSame(json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR), $plan);
	}
}
