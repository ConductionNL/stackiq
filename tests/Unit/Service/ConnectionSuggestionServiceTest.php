<?php

/**
 * Unit tests for ConnectionSuggestionService.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/connections-derived-dependencies/tasks.md#task-1-suggestion-service
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

require_once __DIR__ . '/ConnectionSuggestionStore.php';

use OCA\Stackiq\Service\ConnectionSuggestionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Suggestions from the landscape and carried over from a replaced usage, accept and dismiss.
 */
#[CoversClass(ConnectionSuggestionService::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\OCA\Stackiq\Service\RelationRefs::class)]
class ConnectionSuggestionServiceTest extends TestCase {
	/**
	 * The store.
	 *
	 * @var ConnectionSuggestionStore
	 */
	private ConnectionSuggestionStore $store;

	/**
	 * The service under test.
	 *
	 * @var ConnectionSuggestionService
	 */
	private ConnectionSuggestionService $service;

	/**
	 * A municipality with usages of X (the one under test) and Y; connections X-Y, X-Z, X-national.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new ConnectionSuggestionStore();
		$this->store->put('u-x', 10, ['consumer' => 'org-a', 'module' => 'mod-x', 'moduleVersion' => 'x-2', 'koppelingen' => []]);
		$this->store->put('u-y', 10, ['consumer' => 'org-a', 'module' => 'mod-y']);
		$this->store->put('u-other-org', 10, ['consumer' => 'org-b', 'module' => 'mod-z']);
		$this->store->put('c-xy', 20, ['name' => 'X to Y', 'moduleA' => 'mod-x', 'moduleB' => 'mod-y', 'type' => 'digikoppeling', 'dataExchangeDirection' => 'AtoB']);
		$this->store->put('c-zx', 20, ['name' => 'Z to X', 'moduleA' => 'mod-z', 'moduleB' => 'mod-x']);
		$this->store->put('c-xn', 20, ['name' => 'X to BRP', 'moduleA' => 'mod-x', 'nonMunicipalProvision' => ['id' => 'el-brp']]);
		$this->service = new ConnectionSuggestionService($this->store->config(test: $this));
	}

	/**
	 * A connection whose other end the organisation uses, and one to a national provision, are suggested; one to an application it does not use is not.
	 *
	 * @return void
	 */
	public function testTheLandscapeAndNationalProvisionsAreSuggested(): void {
		$suggestions = $this->suggestionsFor(uuid: 'u-x');

		$this->assertSame(['c-xy', 'c-xn'], array_column($suggestions, 'id'));
		$this->assertSame(
			[
				'id' => 'c-xy',
				'connection' => 'c-xy',
				'name' => 'X to Y',
				'otherModule' => 'mod-y',
				'nationalProvision' => null,
				'type' => 'digikoppeling',
				'direction' => 'AtoB',
				'reason' => ConnectionSuggestionService::REASON_SHARED_LANDSCAPE,
				'draft' => false,
				'from' => null,
			],
			$suggestions[0]
		);
		$this->assertSame('el-brp', $suggestions[1]['nationalProvision']);
	}

	/**
	 * Connections already on the usage, or dismissed for it, are not suggested.
	 *
	 * @return void
	 */
	public function testRecordedAndDismissedConnectionsAreLeftOut(): void {
		$this->store->put('u-x', 10, ['consumer' => 'org-a', 'module' => 'mod-x', 'koppelingen' => [['id' => 'c-xy']], 'dismissedConnectionSuggestions' => ['c-xn']]);

		$this->assertSame([], $this->suggestionsFor(uuid: 'u-x'));
	}

	/**
	 * A usage of the same application at another version offers its connections as carry-over.
	 *
	 * @return void
	 */
	public function testANewVersionCarriesOverTheOldConnections(): void {
		$this->store->put('u-x1', 10, ['consumer' => 'org-a', 'module' => 'mod-x', 'moduleVersion' => 'x-1', 'koppelingen' => ['c-zx']]);

		$carried = array_values(array_filter($this->suggestionsFor(uuid: 'u-x'), static fn (array $s): bool => $s['reason'] === ConnectionSuggestionService::REASON_CARRY_OVER));

		$this->assertSame(['c-zx'], array_column($carried, 'id'));
		$this->assertSame('u-x1', $carried[0]['from']);
		$this->assertSame('mod-z', $carried[0]['otherModule']);
		$this->assertFalse($carried[0]['draft']);
	}

	/**
	 * A usage whose planned replacement is this application offers drafts from the successor; accepting one creates it in development and records it.
	 *
	 * @return void
	 */
	public function testASuccessorGetsDraftConnections(): void {
		$this->store->put('u-old', 10, ['consumer' => 'org-a', 'module' => 'mod-old', 'plannedReplacement' => ['id' => 'mod-x'], 'koppelingen' => ['c-old']]);
		$this->store->put('c-old', 20, ['name' => 'Old to Q', 'moduleA' => 'mod-q', 'moduleB' => 'mod-old', 'type' => 'file transfer', 'status' => 'in use']);

		$draft = array_values(array_filter($this->suggestionsFor(uuid: 'u-x'), static fn (array $s): bool => $s['draft'] === true));
		$this->assertSame(['draft:c-old'], array_column($draft, 'id'));
		$this->assertSame('mod-q', $draft[0]['otherModule']);

		$added = $this->service->accept(usage: $this->service->findUsage(usageUuid: 'u-x'), ids: ['draft:c-old']);

		$created = $this->store->saves[0];
		$this->assertSame(20, $created['schema']);
		$this->assertNull($created['uuid']);
		$this->assertSame('mod-q', $created['data']['moduleA']);
		$this->assertSame('mod-x', $created['data']['moduleB']);
		$this->assertSame('in development', $created['data']['status']);
		$this->assertSame('Carried over from connection Old to Q (c-old).', $created['data']['longDescription']);
		$this->assertSame(['created-1'], $added);
		$this->assertSame(['created-1'], $this->store->objects['u-x']['data']['koppelingen']);
	}

	/**
	 * Accept adds the chosen connections to the usage and ignores ids that are not suggestions.
	 *
	 * @return void
	 */
	public function testAcceptAddsOnlyCurrentSuggestions(): void {
		$added = $this->service->accept(usage: $this->service->findUsage(usageUuid: 'u-x'), ids: ['c-xy', 'c-zx', 'nonsense']);

		$this->assertSame(['c-xy'], $added);
		$this->assertCount(1, $this->store->saves);
		$this->assertSame('u-x', $this->store->saves[0]['uuid']);
		$this->assertSame(['c-xy'], $this->store->objects['u-x']['data']['koppelingen']);
		$this->assertArrayNotHasKey('id', $this->store->saves[0]['data']);
		$this->assertSame(['c-xn'], array_column($this->suggestionsFor(uuid: 'u-x'), 'id'));
	}

	/**
	 * Dismiss records the id on the usage, so it is not suggested again; nothing to dismiss saves nothing.
	 *
	 * @return void
	 */
	public function testDismissKeepsASuggestionAway(): void {
		$this->assertSame([], $this->service->dismiss(usage: $this->service->findUsage(usageUuid: 'u-x'), ids: ['c-zx']));
		$this->assertSame([], $this->store->saves);

		$this->assertSame(['c-xy'], $this->service->dismiss(usage: $this->service->findUsage(usageUuid: 'u-x'), ids: ['c-xy']));

		$this->assertSame(['c-xy'], $this->store->objects['u-x']['data']['dismissedConnectionSuggestions']);
		$this->assertSame(['c-xn'], array_column($this->suggestionsFor(uuid: 'u-x'), 'id'));
	}

	/**
	 * Only the consumer or a participant may change the usage.
	 *
	 * @return void
	 */
	public function testOnlyTheConsumerOrAParticipantMayChange(): void {
		$usage = ['consumer' => ['id' => 'org-a'], 'participants' => ['org-c', ['id' => 'org-d']]];

		$this->assertTrue($this->service->mayChange(usage: $usage, organisation: 'org-a'));
		$this->assertTrue($this->service->mayChange(usage: $usage, organisation: 'org-c'));
		$this->assertTrue($this->service->mayChange(usage: $usage, organisation: 'org-d'));
		$this->assertFalse($this->service->mayChange(usage: $usage, organisation: 'org-b'));
		$this->assertFalse($this->service->mayChange(usage: $usage, organisation: null));
		$this->assertFalse($this->service->mayChange(usage: ['consumer' => null], organisation: ''));
	}

	/**
	 * An unknown usage is null; a usage without application or consumer has no suggestions.
	 *
	 * @return void
	 */
	public function testUnknownOrIncompleteUsages(): void {
		$this->assertNull($this->service->findUsage(usageUuid: 'nope'));
		$this->assertNull($this->service->findUsage(usageUuid: 'c-xy'));
		$this->assertSame([], $this->service->suggestFor(usage: ['id' => 'u', 'consumer' => 'org-a']));
	}

	/**
	 * The suggestions for a stored usage.
	 *
	 * @param string $uuid The usage.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function suggestionsFor(string $uuid): array {
		return $this->service->suggestFor(usage: $this->service->findUsage(usageUuid: $uuid));
	}
}
