<?php

/**
 * Unit tests for FederationMerger.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\Federation
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/federated-catalog-sync/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Federation;

use OCA\Stackiq\Service\Federation\FederationMerger;
use PHPUnit\Framework\TestCase;

/**
 * The create/update/withdraw plan and the staleness helpers of a peer pull.
 *
 * @covers \OCA\Stackiq\Service\Federation\FederationMerger
 * @uses   \OCA\Stackiq\Service\Federation\FederationPlanRun
 *
 * @spec openspec/specs/federated-catalog-sync/spec.md
 */
class FederationMergerTest extends TestCase {

	private const PEER_URL = 'https://peer.example.org';

	/**
	 * The merger under test.
	 *
	 * @var FederationMerger
	 */
	private FederationMerger $merger;

	/**
	 * Build the merger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->merger = new FederationMerger();
	}//end setUp()

	/**
	 * The peer descriptor used by every plan.
	 *
	 * @param string $syncedAt The sync moment.
	 *
	 * @return array{url: string, organisation: string, syncedAt: string}
	 */
	private function peer(string $syncedAt = '2026-11-13T10:00:00+00:00'): array {
		return ['url' => self::PEER_URL, 'organisation' => 'Peer Gemeente', 'syncedAt' => $syncedAt];
	}//end peer()

	/**
	 * A stored mirror of this peer.
	 *
	 * @param string              $peerEntryId The peer entry id recorded in provenance.
	 * @param array<string,mixed> $data        The mirror payload.
	 * @param string              $localId     The local OpenRegister id.
	 * @param string              $instance    The provenance instance.
	 *
	 * @return array<string,mixed>
	 */
	private function mirror(string $peerEntryId, array $data, string $localId, string $instance = self::PEER_URL): array {
		return $data + [
			'id' => $localId,
			'_source' => [
				'instance' => $instance,
				'organisation' => 'Peer Gemeente',
				'peerEntryId' => $peerEntryId,
				'syncedAt' => '2026-11-01T00:00:00+00:00',
				'stale' => false,
				'withdrawn' => false,
			],
		];
	}//end mirror()

	/**
	 * A peer entry with no local mirror is planned as a create stamped with provenance.
	 *
	 * @return void
	 */
	public function testNewPeerEntryBecomesACreateWithProvenance(): void {
		$plan = $this->merger->plan(
			peer: $this->peer(),
			peerEntries: [['id' => 'p-1', 'name' => 'Zaaksysteem']],
			localMirrors: []
		);

		$this->assertSame(expected: [], actual: $plan['update']);
		$this->assertSame(expected: [], actual: $plan['withdraw']);
		$this->assertSame(
			expected: [[
				'name' => 'Zaaksysteem',
				'_source' => [
					'instance' => self::PEER_URL,
					'organisation' => 'Peer Gemeente',
					'peerEntryId' => 'p-1',
					'syncedAt' => '2026-11-13T10:00:00+00:00',
					'stale' => false,
					'withdrawn' => false,
				],
			]],
			actual: $plan['create']
		);
	}//end testNewPeerEntryBecomesACreateWithProvenance()

	/**
	 * Inbound provenance and id keys are stripped so a peer cannot spoof its source.
	 *
	 * @return void
	 */
	public function testInboundSourceAndIdsAreStripped(): void {
		$plan = $this->merger->plan(
			peer: $this->peer(),
			peerEntries: [[
				'id' => 'p-1',
				'uuid' => 'u-1',
				'name' => 'X',
				'_source' => ['instance' => 'https://evil.example', 'peerEntryId' => 'forged'],
			]],
			localMirrors: []
		);

		$created = $plan['create'][0];
		$this->assertArrayNotHasKey(key: 'id', array: $created);
		$this->assertArrayNotHasKey(key: 'uuid', array: $created);
		$this->assertSame(expected: self::PEER_URL, actual: $created['_source']['instance']);
		$this->assertSame(expected: 'p-1', actual: $created['_source']['peerEntryId']);
	}//end testInboundSourceAndIdsAreStripped()

	/**
	 * The uuid is the fallback stable id, an integer id is stringified, and
	 * entries without a usable id are skipped.
	 *
	 * @return void
	 */
	public function testStableIdFallsBackToUuidAndSkipsEntriesWithoutOne(): void {
		$plan = $this->merger->plan(
			peer: $this->peer(),
			peerEntries: [
				['id' => '   ', 'uuid' => 'u-9', 'name' => 'blank id'],
				['id' => 42, 'name' => 'int id'],
				['name' => 'no id'],
				['id' => 1.5, 'name' => 'float id'],
				['id' => ['nested'], 'name' => 'array id'],
			],
			localMirrors: []
		);

		$ids = array_map(static fn (array $m): string => $m['_source']['peerEntryId'], $plan['create']);
		$this->assertSame(expected: ['u-9', '42'], actual: $ids);
	}//end testStableIdFallsBackToUuidAndSkipsEntriesWithoutOne()

	/**
	 * Re-pulling an unchanged entry is a no-op even when syncedAt and key order differ.
	 *
	 * @return void
	 */
	public function testUnchangedEntryIsNotPlanned(): void {
		$local = $this->mirror(peerEntryId: 'p-1', data: ['name' => 'Zaaksysteem', 'meta' => ['b' => 2, 'a' => 1]], localId: 'local-1');

		$plan = $this->merger->plan(
			peer: $this->peer(),
			peerEntries: [['meta' => ['a' => 1, 'b' => 2], 'id' => 'p-1', 'name' => 'Zaaksysteem']],
			localMirrors: [$local]
		);

		$this->assertSame(expected: ['create' => [], 'update' => [], 'withdraw' => []], actual: $plan);
	}//end testUnchangedEntryIsNotPlanned()

	/**
	 * A changed entry becomes an update that carries the local id for in-place saving.
	 *
	 * @return void
	 */
	public function testChangedEntryBecomesAnUpdateCarryingTheLocalId(): void {
		$local = $this->mirror(peerEntryId: 'p-1', data: ['name' => 'Old'], localId: 'local-1');

		$plan = $this->merger->plan(
			peer: $this->peer(),
			peerEntries: [['id' => 'p-1', 'name' => 'New']],
			localMirrors: [$local]
		);

		$this->assertSame(expected: [], actual: $plan['create']);
		$this->assertSame(expected: [], actual: $plan['withdraw']);
		$this->assertCount(expectedCount: 1, haystack: $plan['update']);
		$this->assertSame(expected: 'local-1', actual: $plan['update'][0]['id']);
		$this->assertSame(expected: 'New', actual: $plan['update'][0]['name']);
		$this->assertSame(expected: '2026-11-13T10:00:00+00:00', actual: $plan['update'][0]['_source']['syncedAt']);
	}//end testChangedEntryBecomesAnUpdateCarryingTheLocalId()

	/**
	 * A previously stale mirror is updated so the fresh pull clears its stale flag.
	 *
	 * @return void
	 */
	public function testStaleMirrorOfAPublishedEntryIsUpdated(): void {
		$local = $this->mirror(peerEntryId: 'p-1', data: ['name' => 'Same'], localId: 'local-1');
		$local['_source']['stale'] = true;

		$plan = $this->merger->plan(
			peer: $this->peer(),
			peerEntries: [['id' => 'p-1', 'name' => 'Same']],
			localMirrors: [$local]
		);

		$this->assertCount(expectedCount: 1, haystack: $plan['update']);
		$this->assertFalse(condition: $plan['update'][0]['_source']['stale']);
	}//end testStaleMirrorOfAPublishedEntryIsUpdated()

	/**
	 * A mirror the peer no longer publishes is withdrawn and marked stale, never deleted.
	 *
	 * @return void
	 */
	public function testMirrorNoLongerPublishedIsWithdrawn(): void {
		$local = $this->mirror(peerEntryId: 'gone', data: ['name' => 'Gone'], localId: 'local-2');

		$plan = $this->merger->plan(
			peer: $this->peer(syncedAt: '2026-11-13T12:00:00+00:00'),
			peerEntries: [],
			localMirrors: [$local]
		);

		$this->assertSame(expected: [], actual: $plan['create']);
		$this->assertSame(expected: [], actual: $plan['update']);
		$this->assertCount(expectedCount: 1, haystack: $plan['withdraw']);
		$withdrawn = $plan['withdraw'][0];
		$this->assertSame(expected: 'local-2', actual: $withdrawn['id']);
		$this->assertSame(expected: 'Gone', actual: $withdrawn['name']);
		$this->assertTrue(condition: $withdrawn['_source']['withdrawn']);
		$this->assertTrue(condition: $withdrawn['_source']['stale']);
		$this->assertSame(expected: '2026-11-13T12:00:00+00:00', actual: $withdrawn['_source']['withdrawnAt']);
		$this->assertSame(expected: 'gone', actual: $withdrawn['_source']['peerEntryId']);
	}//end testMirrorNoLongerPublishedIsWithdrawn()

	/**
	 * Mirrors of another instance, without provenance or without a peer entry id
	 * are never reconciled: their entries are creates and they are never withdrawn.
	 *
	 * @return void
	 */
	public function testForeignAndUnidentifiedMirrorsAreIgnored(): void {
		$foreign     = $this->mirror(peerEntryId: 'p-1', data: ['name' => 'A'], localId: 'f-1', instance: 'https://other.example.org');
		$noSource    = ['id' => 'local-x', 'name' => 'Local'];
		$blankPeerId = $this->mirror(peerEntryId: ' ', data: ['name' => 'B'], localId: 'b-1');

		$plan = $this->merger->plan(
			peer: $this->peer(),
			peerEntries: [['id' => 'p-1', 'name' => 'A']],
			localMirrors: [$foreign, $noSource, $blankPeerId]
		);

		$this->assertCount(expectedCount: 1, haystack: $plan['create']);
		$this->assertSame(expected: 'p-1', actual: $plan['create'][0]['_source']['peerEntryId']);
		$this->assertSame(expected: [], actual: $plan['update']);
		$this->assertSame(expected: [], actual: $plan['withdraw']);
	}//end testForeignAndUnidentifiedMirrorsAreIgnored()

	/**
	 * Staleness starts at the default threshold of three consecutive failures.
	 *
	 * @return void
	 */
	public function testIsStaleUsesTheDefaultThreshold(): void {
		$this->assertSame(expected: 3, actual: FederationMerger::DEFAULT_STALE_AFTER_FAILURES);
		$this->assertFalse(condition: $this->merger->isStale(consecutiveFailures: 0));
		$this->assertFalse(condition: $this->merger->isStale(consecutiveFailures: 2));
		$this->assertTrue(condition: $this->merger->isStale(consecutiveFailures: 3));
		$this->assertTrue(condition: $this->merger->isStale(consecutiveFailures: 7));
	}//end testIsStaleUsesTheDefaultThreshold()

	/**
	 * A positive override replaces the threshold; zero or negative keeps the default.
	 *
	 * @return void
	 */
	public function testIsStaleHonoursOnlyAPositiveOverride(): void {
		$this->assertTrue(condition: $this->merger->isStale(consecutiveFailures: 1, threshold: 1));
		$this->assertFalse(condition: $this->merger->isStale(consecutiveFailures: 4, threshold: 5));
		$this->assertFalse(condition: $this->merger->isStale(consecutiveFailures: 2, threshold: 0));
		$this->assertFalse(condition: $this->merger->isStale(consecutiveFailures: 2, threshold: -1));
		$this->assertTrue(condition: $this->merger->isStale(consecutiveFailures: 3, threshold: 0));
	}//end testIsStaleHonoursOnlyAPositiveOverride()

	/**
	 * The stale flag is set on existing provenance and creates it when missing.
	 *
	 * @return void
	 */
	public function testApplyStaleSetsTheFlagOnProvenance(): void {
		$withSource = $this->merger->applyStale(
			mirror: ['name' => 'A', '_source' => ['instance' => self::PEER_URL, 'stale' => false]],
			stale: true
		);
		$this->assertSame(
			expected: ['name' => 'A', '_source' => ['instance' => self::PEER_URL, 'stale' => true]],
			actual: $withSource
		);

		$this->assertSame(
			expected: ['name' => 'B', '_source' => ['stale' => false]],
			actual: $this->merger->applyStale(mirror: ['name' => 'B', '_source' => 'not-an-array'], stale: false)
		);
	}//end testApplyStaleSetsTheFlagOnProvenance()
}//end class
