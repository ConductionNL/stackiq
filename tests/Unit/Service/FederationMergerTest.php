<?php

/**
 * Unit tests for FederationMerger.
 *
 * Covers the pull/merge invariants (create new peer entries, update changed
 * ones, withdraw entries the peer no longer publishes, never touch entries that
 * are not this peer's mirrors), idempotency on a stable peer-entry id, the
 * anti-spoof provenance stamping, and the staleness threshold logic.
 *
 * @category  Tests
 * @package   OCA\SoftwareCatalog\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/changes/federated-catalog-sync/specs/federated-catalog-sync/spec.md
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Service;

use OCA\SoftwareCatalog\Service\Federation\FederationMerger;
use PHPUnit\Framework\TestCase;

/**
 * Test class for the federation merge + staleness reconciler.
 */
class FederationMergerTest extends TestCase {
	private const PEER = 'https://peer.example';

	private const ORG = 'Gemeente Peer';

	private const NOW = '2026-06-15T12:00:00+00:00';

	/**
	 * @var FederationMerger
	 */
	private FederationMerger $merger;

	/**
	 * Set up a fresh merger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->merger = new FederationMerger();
	}//end setUp()

	/**
	 * A peer entry with no local mirror is planned as a CREATE with provenance.
	 *
	 * @return void
	 */
	public function testNewPeerEntryIsCreatedWithProvenance(): void {
		$plan = $this->merger->plan(
			self::PEER,
			self::ORG,
			[['id' => 'p-1', 'name' => 'Service A']],
			[],
			self::NOW
		);

		$this->assertCount(1, $plan['create']);
		$this->assertCount(0, $plan['update']);
		$this->assertCount(0, $plan['withdraw']);

		$mirror = $plan['create'][0];
		$this->assertSame('Service A', $mirror['name']);
		$this->assertSame(self::PEER, $mirror['_source']['instance']);
		$this->assertSame(self::ORG, $mirror['_source']['organisation']);
		$this->assertSame('p-1', $mirror['_source']['peerEntryId']);
		$this->assertSame(self::NOW, $mirror['_source']['syncedAt']);
		$this->assertFalse($mirror['_source']['stale']);
		$this->assertFalse($mirror['_source']['withdrawn']);
		// The peer's own id/uuid is stripped (OR assigns its own).
		$this->assertArrayNotHasKey('id', $mirror);
	}//end testNewPeerEntryIsCreatedWithProvenance()

	/**
	 * A peer cannot spoof its provenance: any inbound `_source` is overwritten.
	 *
	 * @return void
	 */
	public function testInboundSourceCannotBeSpoofed(): void {
		$plan = $this->merger->plan(
			self::PEER,
			self::ORG,
			[['id' => 'p-1', 'name' => 'X', '_source' => ['instance' => 'https://evil.example']]],
			[],
			self::NOW
		);

		$this->assertSame(self::PEER, $plan['create'][0]['_source']['instance']);
	}//end testInboundSourceCannotBeSpoofed()

	/**
	 * A changed peer entry is planned as an UPDATE carrying the local OR id.
	 *
	 * @return void
	 */
	public function testChangedPeerEntryIsUpdated(): void {
		$local = [
			$this->mirror('or-uuid-1', 'p-1', ['name' => 'Old name']),
		];

		$plan = $this->merger->plan(
			self::PEER,
			self::ORG,
			[['id' => 'p-1', 'name' => 'New name']],
			$local,
			self::NOW
		);

		$this->assertCount(0, $plan['create']);
		$this->assertCount(1, $plan['update']);
		$this->assertCount(0, $plan['withdraw']);
		$this->assertSame('or-uuid-1', $plan['update'][0]['id']);
		$this->assertSame('New name', $plan['update'][0]['name']);
	}//end testChangedPeerEntryIsUpdated()

	/**
	 * Re-pulling an unchanged catalog is a no-op (idempotent on the peer id).
	 *
	 * @return void
	 */
	public function testUnchangedRepullIsIdempotent(): void {
		$local = [
			$this->mirror('or-uuid-1', 'p-1', ['name' => 'Same']),
		];

		$plan = $this->merger->plan(
			self::PEER,
			self::ORG,
			[['id' => 'p-1', 'name' => 'Same']],
			$local,
			'2026-06-15T13:00:00+00:00'
		);

		$this->assertSame([], $plan['create']);
		$this->assertSame([], $plan['update']);
		$this->assertSame([], $plan['withdraw']);
	}//end testUnchangedRepullIsIdempotent()

	/**
	 * An entry the peer no longer publishes is WITHDRAWN (marked, not deleted).
	 *
	 * @return void
	 */
	public function testRemovedPeerEntryIsWithdrawn(): void {
		$local = [
			$this->mirror('or-uuid-1', 'p-1', ['name' => 'Gone']),
			$this->mirror('or-uuid-2', 'p-2', ['name' => 'Stays']),
		];

		$plan = $this->merger->plan(
			self::PEER,
			self::ORG,
			[['id' => 'p-2', 'name' => 'Stays']],
			$local,
			self::NOW
		);

		$this->assertCount(0, $plan['create']);
		$this->assertCount(1, $plan['withdraw']);
		$withdrawn = $plan['withdraw'][0];
		$this->assertSame('or-uuid-1', $withdrawn['id']);
		$this->assertTrue($withdrawn['_source']['withdrawn']);
		$this->assertTrue($withdrawn['_source']['stale']);
		$this->assertSame(self::NOW, $withdrawn['_source']['withdrawnAt']);
	}//end testRemovedPeerEntryIsWithdrawn()

	/**
	 * Mirrors belonging to a DIFFERENT peer are never reconciled (no withdraw).
	 *
	 * @return void
	 */
	public function testOtherPeersMirrorsAreUntouched(): void {
		$local = [
			// A mirror of a different peer.
			[
				'id' => 'or-uuid-9',
				'_source' => ['instance' => 'https://other.example', 'peerEntryId' => 'x-1'],
				'name' => 'Other peer entry',
			],
		];

		$plan = $this->merger->plan(self::PEER, self::ORG, [], $local, self::NOW);

		// The other peer's mirror is NOT withdrawn by THIS peer's empty pull.
		$this->assertSame([], $plan['withdraw']);
		$this->assertSame([], $plan['create']);
		$this->assertSame([], $plan['update']);
	}//end testOtherPeersMirrorsAreUntouched()

	/**
	 * Peer entries without a stable id are skipped (cannot merge idempotently).
	 *
	 * @return void
	 */
	public function testEntryWithoutStableIdIsSkipped(): void {
		$plan = $this->merger->plan(
			self::PEER,
			self::ORG,
			[['name' => 'No id']],
			[],
			self::NOW
		);

		$this->assertSame([], $plan['create']);
	}//end testEntryWithoutStableIdIsSkipped()

	/**
	 * Staleness threshold: stale only at/after the configured failure count.
	 *
	 * @return void
	 */
	public function testStalenessThreshold(): void {
		$this->assertFalse($this->merger->isStale(0));
		$this->assertFalse($this->merger->isStale(2));
		$this->assertTrue($this->merger->isStale(3));
		$this->assertTrue($this->merger->isStale(5));

		// Custom threshold.
		$this->assertFalse($this->merger->isStale(1, 2));
		$this->assertTrue($this->merger->isStale(2, 2));
	}//end testStalenessThreshold()

	/**
	 * applyStale toggles the flag idempotently.
	 *
	 * @return void
	 */
	public function testApplyStale(): void {
		$mirror = $this->mirror('or-uuid-1', 'p-1', ['name' => 'X']);
		$staled = $this->merger->applyStale($mirror, true);
		$this->assertTrue($staled['_source']['stale']);

		$fresh = $this->merger->applyStale($staled, false);
		$this->assertFalse($fresh['_source']['stale']);
	}//end testApplyStale()

	/**
	 * Build a stored-mirror data bag for THIS peer.
	 *
	 * @param string $orUuid The OR-assigned id.
	 * @param string $peerId The stable peer-entry id.
	 * @param array<string,mixed> $payload Extra payload fields.
	 *
	 * @return array<string,mixed> The mirror data bag.
	 */
	private function mirror(string $orUuid, string $peerId, array $payload): array {
		return array_merge(
			['id' => $orUuid],
			$payload,
			[
				'_source' => [
					'instance' => self::PEER,
					'organisation' => self::ORG,
					'peerEntryId' => $peerId,
					'syncedAt' => '2026-06-01T00:00:00+00:00',
					'stale' => false,
					'withdrawn' => false,
				],
			]
		);
	}//end mirror()
}//end class
