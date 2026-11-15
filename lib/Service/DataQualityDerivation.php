<?php

/**
 * Data quality per declared rule, per entry and for a whole register.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/landscape-completeness-score/specs/catalogue-data-quality/spec.md#requirement-req-cdq-003-a-data-quality-page-shows-how-complete-and-current-an-organisation-s-register-is
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use DateTimeImmutable;

/**
 * Pure functions over a schema's `x-openregister-quality` rules and its objects.
 *
 * The rule shape and the scoring follow OpenRegister's QualityScorer: `required`
 * scores 1 when the field has a value, `format` when it matches its named
 * pattern, `freshness` decays by half every `halfLifeDays` (default 180). A
 * freshness rule counts as met while the entry is younger than its half-life.
 *
 * @spec openspec/changes/landscape-completeness-score/specs/catalogue-data-quality/spec.md#requirement-req-cdq-003-a-data-quality-page-shows-how-complete-and-current-an-organisation-s-register-is
 */
class DataQualityDerivation {
	/**
	 * The named formats, as OpenRegister's QualityScorer defines them.
	 */
	private const FORMATS = [
		'email' => '/^[^@\s]+@[^@\s]+\.[^@\s]+$/',
		'url' => '#^https?://[^\s]+$#i',
		'date' => '/^\d{4}-\d{2}-\d{2}([T ].*)?$/',
	];

	/**
	 * The half-life a freshness rule gets when it names none.
	 */
	private const DEFAULT_HALF_LIFE_DAYS = 180;

	/**
	 * Evaluate the rules over the objects.
	 *
	 * @param array<int,array<string,mixed>> $rules The declared rules.
	 * @param array<int,array<string,mixed>> $objects The entries, each with an `id`.
	 * @param DateTimeImmutable $now Reference moment for freshness.
	 *
	 * @return array{summary: array<string,mixed>, rules: array<int,array<string,mixed>>, entries: array<int,array<string,mixed>>}
	 *
	 * @spec openspec/changes/landscape-completeness-score/specs/catalogue-data-quality/spec.md#requirement-req-cdq-003-a-data-quality-page-shows-how-complete-and-current-an-organisation-s-register-is
	 */
	public function evaluate(array $rules, array $objects, DateTimeImmutable $now): array {
		$rules = array_values(array_filter($rules, static fn ($rule): bool => is_array($rule) === true && (string)($rule['field'] ?? '') !== ''));

		$ruleRows = [];
		foreach ($rules as $rule) {
			$ruleRows[] = [
				'type' => (string)($rule['type'] ?? 'required'),
				'field' => (string)$rule['field'],
				'weight' => $this->weight(rule: $rule),
				'met' => 0,
				'total' => count($objects),
			];
		}

		$entries = [];
		foreach ($objects as $object) {
			$entry = $this->evaluateEntry(rules: $rules, run: new DataQualityEntryRun(object: $object, now: $now));
			foreach ($entry['met'] as $index => $met) {
				$ruleRows[$index]['met'] += (int)$met;
			}

			unset($entry['met']);
			$entries[] = $entry;
		}

		usort($entries, static fn (array $a, array $b): int => [$a['completeness'], $a['score']] <=> [$b['completeness'], $b['score']]);

		return [
			'summary' => $this->summarise(rules: $ruleRows, entries: $entries),
			'rules' => $ruleRows,
			'entries' => $entries,
		];
	}//end evaluate()

	/**
	 * Score one entry against every rule.
	 *
	 * The rules are a list (evaluate() passes them through array_values()), so
	 * the `met` list keeps the rules' own indexes.
	 *
	 * @param array<int,array<string,mixed>> $rules The rules.
	 * @param DataQualityEntryRun            $run   The entry and the reference moment.
	 *
	 * @return array<string,mixed> id, score, completeness, missing, stale, confirmedAt and the per-rule `met` list.
	 */
	private function evaluateEntry(array $rules, DataQualityEntryRun $run): array {
		foreach ($rules as $rule) {
			$this->applyRule(run: $run, check: new DataQualityRuleCheck(rule: $rule, object: $run->object));
		}

		return [
			'id' => (string)($run->object['id'] ?? ($run->object['@self']['id'] ?? '')),
			'score' => $this->share(part: $run->weighted, whole: $run->weights),
			'completeness' => $this->share(part: $run->requiredMet, whole: $run->required),
			'missing' => $run->missing,
			'stale' => $run->stale,
			'confirmedAt' => $run->confirmedAt,
			'met' => $run->met,
		];
	}//end evaluateEntry()

	/**
	 * Score one rule on the entry and add it to the running totals.
	 *
	 * @param DataQualityEntryRun  $run   The entry being evaluated.
	 * @param DataQualityRuleCheck $check The rule applied to the entry.
	 *
	 * @return void
	 */
	private function applyRule(DataQualityEntryRun $run, DataQualityRuleCheck $check): void {
		$check->weight = $this->weight(rule: $check->rule);
		$check->score  = $this->ruleScore(check: $check, now: $run->now);
		$check->met    = $this->isMet(check: $check, now: $run->now);

		$run->weighted += $check->score * $check->weight;
		$run->weights  += $check->weight;
		$run->met[]     = $check->met;

		if ($check->type === 'freshness') {
			$this->recordFreshness(run: $run, check: $check);
			return;
		}

		$this->recordCompleteness(run: $run, check: $check);
	}//end applyRule()

	/**
	 * Record what a freshness rule says: when the entry was confirmed, and whether it is stale.
	 *
	 * @param DataQualityEntryRun  $run   The entry being evaluated.
	 * @param DataQualityRuleCheck $check The freshness rule applied to the entry.
	 *
	 * @return void
	 */
	private function recordFreshness(DataQualityEntryRun $run, DataQualityRuleCheck $check): void {
		$run->confirmedAt = null;
		if ($this->filled(value: $check->value) === true) {
			$run->confirmedAt = (string)$check->value;
		}

		$run->stale = $run->stale || $check->met === false;
	}//end recordFreshness()

	/**
	 * Record what a non-freshness rule says: its part of the completeness, and a missing field.
	 *
	 * @param DataQualityEntryRun  $run   The entry being evaluated.
	 * @param DataQualityRuleCheck $check The rule applied to the entry.
	 *
	 * @return void
	 */
	private function recordCompleteness(DataQualityEntryRun $run, DataQualityRuleCheck $check): void {
		if ($check->type === 'required') {
			$run->required    += $check->weight;
			$run->requiredMet += $check->weight * $check->score;
		}

		if ($check->met === false) {
			$run->missing[] = $check->field;
		}
	}//end recordCompleteness()

	/**
	 * The register-wide figures.
	 *
	 * @param array<int,array<string,mixed>> $rules The per-rule rows.
	 * @param array<int,array<string,mixed>> $entries The evaluated entries.
	 *
	 * @return array<string,mixed> score and completeness out of 100 (null without entries), the counts.
	 */
	private function summarise(array $rules, array $entries): array {
		$count = count($entries);
		$score = 0.0;
		$completeness = 0.0;
		$stale = 0;
		foreach ($entries as $entry) {
			$score += $entry['score'];
			$completeness += $entry['completeness'];
			$stale += (int)$entry['stale'];
		}

		$summary = [
			'entries' => $count,
			'ruleCount' => count($rules),
			'score' => null,
			'completeness' => null,
			'notConfirmed' => $stale,
		];
		if ($count > 0) {
			$summary['score'] = (int)round($score / $count * 100);
			$summary['completeness'] = (int)round($completeness / $count * 100);
		}

		return $summary;
	}//end summarise()

	/**
	 * A rule's score for one value, between 0 and 1.
	 *
	 * @param DataQualityRuleCheck $check The rule type, the rule and the field value.
	 * @param DateTimeImmutable    $now   Reference moment.
	 *
	 * @return float The score.
	 */
	private function ruleScore(DataQualityRuleCheck $check, DateTimeImmutable $now): float {
		if ($check->type === 'freshness') {
			$age = $this->ageInDays(value: $check->value, now: $now);
			if ($age === null) {
				return 0.0;
			}

			return 0.5 ** ($age / $this->halfLife(rule: $check->rule));
		}

		return (float)$this->isMet(check: $check, now: $now);
	}//end ruleScore()

	/**
	 * Whether one value meets its rule.
	 *
	 * @param DataQualityRuleCheck $check The rule type, the rule and the field value.
	 * @param DateTimeImmutable    $now   Reference moment.
	 *
	 * @return bool True when met.
	 */
	private function isMet(DataQualityRuleCheck $check, DateTimeImmutable $now): bool {
		if ($check->type === 'freshness') {
			return $this->isFresh(check: $check, now: $now);
		}

		if ($this->filled(value: $check->value) === false) {
			return false;
		}

		if ($check->type !== 'format') {
			return true;
		}

		return self::matchesFormat(rule: $check->rule, value: $check->value);
	}//end isMet()

	/**
	 * Whether a value is younger than its rule's half-life.
	 *
	 * @param DataQualityRuleCheck $check The rule and the field value.
	 * @param DateTimeImmutable    $now   Reference moment.
	 *
	 * @return bool
	 */
	private function isFresh(DataQualityRuleCheck $check, DateTimeImmutable $now): bool {
		$age = $this->ageInDays(value: $check->value, now: $now);

		return $age !== null && $age < $this->halfLife(rule: $check->rule);
	}//end isFresh()

	/**
	 * Whether a filled value matches its rule's pattern, or its named format; true when the rule names neither.
	 *
	 * @param array<string,mixed> $rule  The format rule.
	 * @param mixed               $value The field value.
	 *
	 * @return bool
	 */
	private static function matchesFormat(array $rule, mixed $value): bool {
		$pattern = self::FORMATS[(string)($rule['format'] ?? '')] ?? null;
		if (isset($rule['pattern']) === true && is_string($rule['pattern']) === true) {
			$pattern = '/' . str_replace('/', '\/', $rule['pattern']) . '/';
		}

		return $pattern === null || (is_scalar($value) === true && preg_match($pattern, (string)$value) === 1);
	}//end matchesFormat()

	/**
	 * Whether a value counts as filled in.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool False for null, an empty string and an empty list.
	 */
	private function filled(mixed $value): bool {
		if (is_string($value) === true) {
			return trim($value) !== '';
		}

		if (is_array($value) === true) {
			return $value !== [];
		}

		return $value !== null;
	}//end filled()

	/**
	 * Days between a date value and now, or null when it is not a date.
	 *
	 * @param mixed $value The date value.
	 * @param DateTimeImmutable $now Reference moment.
	 *
	 * @return float|null Age in days, never negative.
	 */
	private function ageInDays(mixed $value, DateTimeImmutable $now): ?float {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			$date = new DateTimeImmutable($value);
		} catch (\Exception $e) {
			return null;
		}

		return max(0.0, ($now->getTimestamp() - $date->getTimestamp()) / 86400);
	}//end ageInDays()

	/**
	 * A rule's weight, at least 0.
	 *
	 * @param array<string,mixed> $rule The rule.
	 *
	 * @return float The weight, 1 when absent.
	 */
	private function weight(array $rule): float {
		return max(0.0, (float)($rule['weight'] ?? 1));
	}//end weight()

	/**
	 * A freshness rule's half-life in days.
	 *
	 * @param array<string,mixed> $rule The rule.
	 *
	 * @return float The half-life, the default when absent or not positive.
	 */
	private function halfLife(array $rule): float {
		$days = (float)($rule['halfLifeDays'] ?? self::DEFAULT_HALF_LIFE_DAYS);
		if ($days <= 0) {
			return (float)self::DEFAULT_HALF_LIFE_DAYS;
		}

		return $days;
	}//end halfLife()

	/**
	 * part / whole, or 1 when there is nothing to measure.
	 *
	 * @param float $part The part.
	 * @param float $whole The whole.
	 *
	 * @return float The share.
	 */
	private function share(float $part, float $whole): float {
		if ($whole <= 0) {
			return 1.0;
		}

		return $part / $whole;
	}//end share()
}//end class
