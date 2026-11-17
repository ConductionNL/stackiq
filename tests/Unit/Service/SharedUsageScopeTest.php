<?php

/**
 * Which usages are shared with an organisation, their marker, the merge into
 * its own page and the connections they hold.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-a-participant-reads-the-usages-shared-with-it-and-their-connections-req-dlg-010
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\RelationRefs;
use OCA\Stackiq\Service\SharedUsageScope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SharedUsageScope.
 */
#[CoversClass(SharedUsageScope::class)]
#[UsesClass(RelationRefs::class)]
class SharedUsageScopeTest extends TestCase {

	/**
	 * The Mozard usage of the spec: Zuiddrecht owns it, Alblasserdam and Papendrecht take part.
	 *
	 * @return array<string, mixed>
	 */
	private static function mozard(): array {
		return [
			'id' => 'u-mozard',
			'consumer' => ['id' => 'org-zd'],
			'participants' => ['org-alb', ['id' => 'org-pap']],
			'koppelingen' => ['c-iburger', ['uuid' => 'c-zaak']],
		];
	}//end mozard()

	/**
	 * A participant gets the usage, marked with the owner and three sharing organisations.
	 *
	 * @return void
	 */
	public function testAParticipantGetsTheUsageMarked(): void {
		$shared = SharedUsageScope::sharedWith(usages: [self::mozard()], organisation: 'org-alb');

		$this->assertCount(1, $shared);
		$this->assertSame('u-mozard', $shared[0]['id']);
		$this->assertSame(['cooperation' => 'org-zd', 'participantCount' => 3], $shared[0]['_shared']);
	}//end testAParticipantGetsTheUsageMarked()

	/**
	 * An outsider, the owner itself, an empty organisation and non-array records get nothing.
	 *
	 * @return void
	 */
	public function testOutsidersOwnersAndJunkGetNothing(): void {
		$usages = [self::mozard(), 'not-a-record', null];

		$this->assertSame([], SharedUsageScope::sharedWith(usages: $usages, organisation: 'org-oostmaas'));
		$this->assertSame([], SharedUsageScope::sharedWith(usages: $usages, organisation: 'org-zd'));
		$this->assertSame([], SharedUsageScope::sharedWith(usages: $usages, organisation: ''));
	}//end testOutsidersOwnersAndJunkGetNothing()

	/**
	 * The marker counts the owner once even when it is also listed as participant, and survives a missing owner.
	 *
	 * @return void
	 */
	public function testTheMarkerCountsEachOrganisationOnce(): void {
		$this->assertSame(
			['cooperation' => 'org-zd', 'participantCount' => 2],
			SharedUsageScope::marker(usage: ['consumer' => 'org-zd', 'participants' => ['org-zd', 'org-alb', 'org-alb']])
		);
		$this->assertSame(
			['cooperation' => null, 'participantCount' => 1],
			SharedUsageScope::marker(usage: ['participants' => ['org-alb']])
		);
	}//end testTheMarkerCountsEachOrganisationOnce()

	/**
	 * Merging appends the shared usages not already on the page and raises the total by that many.
	 *
	 * @return void
	 */
	public function testMergeAddsOnlyNewUsagesAndRaisesTheTotal(): void {
		$own = ['results' => [['id' => 'u-own'], ['id' => 'u-mozard']], 'total' => 7, 'page' => 1];

		$merged = SharedUsageScope::merge(own: $own, shared: [['id' => 'u-mozard', '_shared' => []], ['id' => 'u-new', '_shared' => []]]);

		$this->assertSame(['u-own', 'u-mozard', 'u-new'], array_column($merged['results'], 'id'));
		$this->assertArrayNotHasKey('_shared', $merged['results'][1]);
		$this->assertSame(8, $merged['total']);
		$this->assertSame(1, $merged['page']);
	}//end testMergeAddsOnlyNewUsagesAndRaisesTheTotal()

	/**
	 * Merging into an empty result builds results and total.
	 *
	 * @return void
	 */
	public function testMergeIntoAnEmptyResult(): void {
		$merged = SharedUsageScope::merge(own: [], shared: [['@self' => ['id' => 'u-1']], ['@self' => ['id' => 'u-1']]]);

		$this->assertCount(1, $merged['results']);
		$this->assertSame(1, $merged['total']);
	}//end testMergeIntoAnEmptyResult()

	/**
	 * The owner sees the participants per connection; a participant sees the owner and the other participant.
	 *
	 * @return void
	 */
	public function testConnectionsListWithWhomEachIsShared(): void {
		$this->assertSame(
			[
				'c-iburger' => ['sharedWith' => ['org-alb', 'org-pap'], 'usages' => ['u-mozard']],
				'c-zaak' => ['sharedWith' => ['org-alb', 'org-pap'], 'usages' => ['u-mozard']],
			],
			SharedUsageScope::connections(usages: [self::mozard()], organisation: 'org-zd')
		);
		$this->assertSame(
			['org-zd', 'org-alb'],
			SharedUsageScope::connections(usages: [self::mozard()], organisation: 'org-pap')['c-iburger']['sharedWith']
		);
	}//end testConnectionsListWithWhomEachIsShared()

	/**
	 * A connection held by two usages lists the organisations of both, each usage once.
	 *
	 * @return void
	 */
	public function testConnectionsCombineSeveralUsages(): void {
		$second = ['id' => 'u-2', 'consumer' => 'org-zd', 'participants' => ['org-hen'], 'koppelingen' => ['c-iburger']];

		$result = SharedUsageScope::connections(usages: [self::mozard(), $second, self::mozard()], organisation: 'org-zd');

		$this->assertSame(['org-alb', 'org-pap', 'org-hen'], $result['c-iburger']['sharedWith']);
		$this->assertSame(['u-mozard', 'u-2'], $result['c-iburger']['usages']);
	}//end testConnectionsCombineSeveralUsages()

	/**
	 * Unshared usages, usages of others and an empty organisation give no connections.
	 *
	 * @return void
	 */
	public function testUnsharedAndForeignUsagesGiveNoConnections(): void {
		$unshared = ['id' => 'u-3', 'consumer' => 'org-zd', 'participants' => [], 'koppelingen' => ['c-x']];

		$this->assertSame([], SharedUsageScope::connections(usages: [$unshared], organisation: 'org-zd'));
		$this->assertSame([], SharedUsageScope::connections(usages: [self::mozard()], organisation: 'org-oostmaas'));
		$this->assertSame([], SharedUsageScope::connections(usages: [self::mozard()], organisation: ''));
	}//end testUnsharedAndForeignUsagesGiveNoConnections()
}//end class
