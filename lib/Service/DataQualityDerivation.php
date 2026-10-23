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
			$entry = $this->evaluateEntry(rules: $rules, object: $object, now: $now);
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
	 * @param array<int,array<string,mixed>> $rules The rules.
	 * @param array<string,mixed> $object The entry.
	 * @param DateTimeImmutable $now Reference moment.
	 *
	 * @return array<string,mixed> id, score, completeness, missing, stale, confirmedAt and the per-rule `met` list.
	 */
	private function evaluateEntry(array $rules, array $object, DateTimeImmutable $now): array {
		$weighted = 0.0;
		$weights = 0.0;
		$required = 0.0;
		$requiredMet = 0.0;
		$missing = [];
		$met = [];
		$stale = false;
		$confirmedAt = null;

		foreach ($rules as $index => $rule) {
			$type = (string)($rule['type'] ?? 'required');
			$field = (string)$rule['field'];
			$weight = $this->weight(rule: $rule);
			$value = $object[$field] ?? null;
			$score = $this->ruleScore(type: $type, rule: $rule, value: $value, now: $now);

			$weighted += $score * $weight;
			$weights += $weight;
			$met[$index] = $this->isMet(type: $type, rule: $rule, value: $value, now: $now);

			if ($type === 'freshness') {
				$confirmedAt = null;
				if ($this->filled(value: $value) === true) {
					$confirmedAt = (string)$value;
				}

				$stale = $stale || $met[$index] === false;
				continue;
			}

			if ($type === 'required') {
				$required += $weight;
				$requiredMet += $weight * $score;
			}

			if ($met[$index] === false) {
				$missing[] = $field;
			}
		}//end foreach

		return [
			'id' => (string)($object['id'] ?? ($object['@self']['id'] ?? '')),
			'score' => $this->share(part: $weighted, whole: $weights),
			'completeness' => $this->share(part: $requiredMet, whole: $required),
			'missing' => $missing,
			'stale' => $stale,
			'confirmedAt' => $confirmedAt,
			'met' => $met,
		];
	}//end evaluateEntry()

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
	 * @param string $type Rule type.
	 * @param array<string,mixed> $rule The rule.
	 * @param mixed $value The field value.
	 * @param DateTimeImmutable $now Reference moment.
	 *
	 * @return float The score.
	 */
	private function ruleScore(string $type, array $rule, mixed $value, DateTimeImmutable $now): float {
		if ($type === 'freshness') {
			$age = $this->ageInDays(value: $value, now: $now);
			if ($age === null) {
				return 0.0;
			}

			return 0.5 ** ($age / $this->halfLife(rule: $rule));
		}

		return (float)$this->isMet(type: $type, rule: $rule, value: $value, now: $now);
	}//end ruleScore()

	/**
	 * Whether one value meets its rule.
	 *
	 * @param string $type Rule type.
	 * @param array<string,mixed> $rule The rule.
	 * @param mixed $value The field value.
	 * @param DateTimeImmutable $now Reference moment.
	 *
	 * @return bool True when met.
	 */
	private function isMet(string $type, array $rule, mixed $value, DateTimeImmutable $now): bool {
		if ($type === 'freshness') {
			$age = $this->ageInDays(value: $value, now: $now);
			return $age !== null && $age < $this->halfLife(rule: $rule);
		}

		if ($this->filled(value: $value) === false) {
			return false;
		}

		if ($type !== 'format') {
			return true;
		}

		$pattern = self::FORMATS[(string)($rule['format'] ?? '')] ?? null;
		if (isset($rule['pattern']) === true && is_string($rule['pattern']) === true) {
			$pattern = '/' . str_replace('/', '\/', $rule['pattern']) . '/';
		}

		return $pattern === null || (is_scalar($value) === true && preg_match($pattern, (string)$value) === 1);
	}//end isMet()

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
