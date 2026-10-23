<?php

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Stackiq\Service\DataQualityDerivation;
use PHPUnit\Framework\TestCase;

/**
 * REQ-CDQ-003: the Data quality page's figures from the declared rules.
 */
final class DataQualityDerivationTest extends TestCase {
	private const RULES = [
		['type' => 'required', 'field' => 'businessOwner', 'weight' => 2],
		['type' => 'required', 'field' => 'usedForReferenceComponents', 'weight' => 1],
		['type' => 'format', 'field' => 'website', 'format' => 'url', 'weight' => 1],
		['type' => 'freshness', 'field' => 'lastConfirmedAt', 'halfLifeDays' => 365, 'weight' => 2],
	];

	public function testRulesCountTheEntriesThatMeetThem(): void {
		$result = (new DataQualityDerivation())->evaluate(rules: self::RULES, objects: $this->usages(), now: $this->now());

		$met = array_combine(array_column($result['rules'], 'field'), array_column($result['rules'], 'met'));
		self::assertSame(['businessOwner' => 2, 'usedForReferenceComponents' => 1, 'website' => 1, 'lastConfirmedAt' => 1], $met);
		self::assertSame([3, 3, 3, 3], array_column($result['rules'], 'total'));
	}//end testRulesCountTheEntriesThatMeetThem()

	public function testTheLeastCompleteEntryComesFirstWithWhatIsMissing(): void {
		$result = (new DataQualityDerivation())->evaluate(rules: self::RULES, objects: $this->usages(), now: $this->now());

		self::assertSame(['djuma', 'mozard', 'openzaak'], array_column($result['entries'], 'id'));
		self::assertSame(['businessOwner', 'usedForReferenceComponents', 'website'], $result['entries'][0]['missing']);
		self::assertSame(0.0, $result['entries'][0]['completeness']);
		self::assertSame(['usedForReferenceComponents'], $result['entries'][1]['missing']);
		self::assertSame(1.0, $result['entries'][2]['completeness']);
		self::assertSame('2026-10-01T00:00:00+00:00', $result['entries'][2]['confirmedAt']);
	}//end testTheLeastCompleteEntryComesFirstWithWhatIsMissing()

	public function testSummaryScoresTheRegisterOutOfAHundred(): void {
		$summary = (new DataQualityDerivation())->evaluate(rules: self::RULES, objects: $this->usages(), now: $this->now())['summary'];

		self::assertSame(3, $summary['entries']);
		self::assertSame(4, $summary['ruleCount']);
		// Completeness: djuma 0, mozard 2/3, openzaak 1 -> 56.
		self::assertSame(56, $summary['completeness']);
		// Two entries never confirmed or confirmed more than a half-life ago.
		self::assertSame(2, $summary['notConfirmed']);
		self::assertGreaterThan(0, $summary['score']);
		self::assertLessThan(100, $summary['score']);
	}//end testSummaryScoresTheRegisterOutOfAHundred()

	public function testNoEntriesGivesNoScore(): void {
		$summary = (new DataQualityDerivation())->evaluate(rules: self::RULES, objects: [], now: $this->now())['summary'];

		self::assertNull($summary['score']);
		self::assertNull($summary['completeness']);
	}//end testNoEntriesGivesNoScore()

	private function now(): DateTimeImmutable {
		return new DateTimeImmutable('2026-10-23T00:00:00+00:00');
	}//end now()

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function usages(): array {
		return [
			['id' => 'mozard', 'businessOwner' => 'marloes', 'usedForReferenceComponents' => [], 'website' => 'https://mozard.nl', 'lastConfirmedAt' => '2025-01-02T00:00:00+00:00'],
			['id' => 'openzaak', 'businessOwner' => 'marloes', 'usedForReferenceComponents' => ['zrc'], 'website' => 'not a url', 'lastConfirmedAt' => '2026-10-01T00:00:00+00:00'],
			['id' => 'djuma', 'businessOwner' => '', 'website' => null],
		];
	}//end usages()
}//end class
