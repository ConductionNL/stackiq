<?php

/**
 * Golden test for DataQualityDerivation::evaluate().
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Stackiq\Service\DataQualityDerivation;
use PHPUnit\Framework\TestCase;

/**
 * Pins the scores, completeness, missing fields and freshness the derivation gives.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Service
 */
final class DataQualityDerivationEvaluateGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../fixtures/data-quality/evaluate.json';

	/**
	 * The rule sets under test.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	private static function ruleSets(): array {
		return [
			'mixed' => [
				['type' => 'required', 'field' => 'owner'],
				['type' => 'required', 'field' => 'module', 'weight' => 3],
				['field' => 'name'],
				['type' => 'format', 'field' => 'email', 'format' => 'email'],
				['type' => 'format', 'field' => 'code', 'pattern' => '^[A-Z]{2}/\d+$'],
				['type' => 'format', 'field' => 'any', 'format' => 'unknown-format'],
				['type' => 'freshness', 'field' => 'reviewed', 'halfLifeDays' => 30],
				['type' => 'other', 'field' => 'note', 'weight' => 0.5],
				'not a rule',
				['type' => 'required'],
				['type' => 'required', 'field' => ''],
			],
			'two freshness' => [
				['type' => 'freshness', 'field' => 'reviewed'],
				['type' => 'freshness', 'field' => 'audited', 'halfLifeDays' => 10],
				['type' => 'required', 'field' => 'owner', 'weight' => 0],
			],
			'none' => [],
		];
	}//end ruleSets()

	/**
	 * The objects under test.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function objects(): array {
		return [
			['id' => 'complete', 'owner' => 'anne', 'module' => ['m1'], 'name' => 'A', 'email' => 'a@b.nl', 'code' => 'AB/12', 'any' => 'x', 'reviewed' => '2026-11-01', 'audited' => '2026-11-09', 'note' => 'n'],
			['@self' => ['id' => 'empty'], 'owner' => '  ', 'module' => [], 'name' => null, 'email' => 'nope', 'code' => 'ab/12', 'reviewed' => 'not a date'],
			['id' => 'stale', 'owner' => 0, 'module' => false, 'email' => ['a@b.nl'], 'code' => 12, 'reviewed' => '2020-01-01', 'audited' => '2026-10-01'],
			['owner' => 'x', 'reviewed' => '2026-12-31'],
			[],
		];
	}//end objects()

	/**
	 * Every rule set evaluates to the recorded result.
	 *
	 * @return void
	 */
	public function testEvaluateMatchesGolden(): void {
		$now    = new DateTimeImmutable('2026-11-10T12:00:00Z');
		$actual = [];
		foreach (self::ruleSets() as $name => $rules) {
			$actual[$name]              = (new DataQualityDerivation())->evaluate(rules: $rules, objects: self::objects(), now: $now);
			$actual[$name . ' no objects'] = (new DataQualityDerivation())->evaluate(rules: $rules, objects: [], now: $now);
		}

		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)) . "\n");
		}

		$this->assertSame(
			json_decode((string) file_get_contents(self::FIXTURE), true),
			json_decode((string) json_encode($actual, JSON_PRESERVE_ZERO_FRACTION), true)
		);

	}//end testEvaluateMatchesGolden()
}//end class
