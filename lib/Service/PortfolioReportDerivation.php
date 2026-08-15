<?php

/**
 * Portfolio Rationalization Report — pure derivation helpers.
 *
 * Extracted from `PortfolioReportService` to keep that class's cyclomatic/
 * class complexity under the project's PHPMD threshold (mirrors
 * `ViewQueryBuilder`, extracted from `ViewService` for the same reason).
 * Every method here is a pure function over its arguments — no I/O, no
 * OpenRegister calls — mirroring `src/utils/lifecyclePhase.js` and
 * `src/utils/contractCost.js` so the frontend roadmap/cost views and this
 * backend aggregate agree on the same rules for the same inputs
 * (design.md Decision 3).
 *
 * @category  Service
 * @package   OCA\SoftwareCatalog\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Service;

use DateTimeImmutable;

/**
 * Pure derivation helpers for the portfolio rationalization report.
 *
 * @spec openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md
 */
class PortfolioReportDerivation {
	/**
	 * End-of-support "approaching" look-ahead window in days, matching
	 * `application-lifecycle-tracking`'s default `eol_warning_window_days`.
	 */
	public const EOL_WINDOW_DAYS = 180;

	/**
	 * Derive the lifecycle phase of a gebruik — the most advanced phase
	 * whose start date is in the past. Mirrors `src/utils/lifecyclePhase.js`
	 * `derivePhase()`.
	 *
	 * @param array<string,mixed> $usage The gebruik data bag.
	 * @param DateTimeImmutable $now Reference moment.
	 *
	 * @return string The derived phase.
	 *
	 * @spec openspec/specs/application-lifecycle-tracking/spec.md
	 */
	public function deriveLifecyclePhase(array $usage, DateTimeImmutable $now): string {
		$steps = [
			'Uitgefaseerd' => 'startDateOutPhased',
			'Uit te faseren' => 'startDateOutPhasing',
			'In productie' => 'startDateInProduction',
			'Gepland' => 'startDatePlanned',
			'Verwerving' => 'startDateAcquisition',
		];

		foreach ($steps as $phase => $field) {
			$date = $this->parseDate(value: $usage[$field] ?? null);
			if ($date !== null && $date <= $now) {
				return $phase;
			}
		}

		return 'Onbekend';
	}//end deriveLifecyclePhase()

	/**
	 * Derive end-of-support state from a moduleVersie. Mirrors
	 * `src/utils/lifecyclePhase.js` `endOfSupportState()`.
	 *
	 * @param array<string,mixed>|null $moduleVersion The linked moduleVersie data bag.
	 * @param DateTimeImmutable $now Reference moment.
	 *
	 * @return array{passed: bool, withdrawn: bool, endDate: string|null, withdrawnDate: string|null}
	 *
	 * @spec openspec/specs/application-lifecycle-tracking/spec.md
	 */
	public function deriveEolState(?array $moduleVersion, DateTimeImmutable $now): array {
		$endRaw = $moduleVersion['dateEndSupport'] ?? null;
		$withdrawnRaw = $moduleVersion['dateWithdrawn'] ?? null;
		if (is_string($withdrawnRaw) === false || trim($withdrawnRaw) === '') {
			$withdrawnRaw = null;
		}

		$endDate = null;
		if (is_string($endRaw) === true) {
			$endDate = $endRaw;
		}

		$end = $this->parseDate(value: $endRaw);

		return [
			'passed' => $end !== null && $end <= $now,
			'withdrawn' => $withdrawnRaw !== null,
			'endDate' => $endDate,
			'withdrawnDate' => $withdrawnRaw,
		];
	}//end deriveEolState()

	/**
	 * Whether a moduleVersie's end-of-support falls within the approaching
	 * window. Mirrors `src/utils/lifecyclePhase.js` `isEolApproaching()`.
	 *
	 * @param array<string,mixed>|null $moduleVersion The linked moduleVersie data bag.
	 * @param DateTimeImmutable $now Reference moment.
	 *
	 * @return bool True when end-of-support is within `self::EOL_WINDOW_DAYS`.
	 *
	 * @spec openspec/specs/application-lifecycle-tracking/spec.md
	 */
	public function isEolApproaching(?array $moduleVersion, DateTimeImmutable $now): bool {
		$end = $this->parseDate(value: $moduleVersion['dateEndSupport'] ?? null);
		if ($end === null) {
			return false;
		}

		$horizon = $now->modify('+' . self::EOL_WINDOW_DAYS . ' days');

		return $end > $now && $end <= $horizon;
	}//end isEolApproaching()

	/**
	 * Render a report row's EOL status as a short CSV label.
	 *
	 * @param array<string,mixed> $row A report row (carries `eol.passed` and `eolApproaching`).
	 *
	 * @return string One of `passed`, `approaching`, `ok`.
	 *
	 * @spec openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md#requirement-csv-export-of-the-portfolio-report
	 */
	public function eolStatusLabel(array $row): string {
		if ($row['eol']['passed'] === true) {
			return 'passed';
		}

		if ($row['eolApproaching'] === true) {
			return 'approaching';
		}

		return 'ok';
	}//end eolStatusLabel()

	/**
	 * Annualised cost of a single contract. Mirrors
	 * `src/utils/contractCost.js` `annualisedCost()`.
	 *
	 * @param array<string,mixed> $contract The contract data bag.
	 *
	 * @return array{annual: float, oneOff: float}
	 *
	 * @spec openspec/specs/contract-administration/spec.md
	 */
	public function annualisedCost(array $contract): array {
		$amount = $contract['cost'] ?? null;
		if (is_numeric($amount) === false) {
			return ['annual' => 0.0, 'oneOff' => 0.0];
		}

		$amount = (float)$amount;

		return match ($contract['costPeriod'] ?? null) {
			'Maandelijks' => ['annual' => $amount * 12, 'oneOff' => 0.0],
			'Jaarlijks' => ['annual' => $amount, 'oneOff' => 0.0],
			'Eenmalig' => ['annual' => 0.0, 'oneOff' => $amount],
			default => ['annual' => 0.0, 'oneOff' => 0.0],
		};
	}//end annualisedCost()

	/**
	 * Resolve the uuid of a relation value that may be a plain string, a
	 * nested object (`{id: ...}` / `{uuid: ...}`), or null. Mirrors
	 * `src/utils/lifecyclePhase.js` `resolveUuid()`.
	 *
	 * @param mixed $value A relation value.
	 *
	 * @return string The resolved uuid, or '' when unresolved.
	 *
	 * @spec openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md#requirement-portfolio-rationalization-report-aggregates-per-organisation
	 */
	public function resolveRelationId(mixed $value): string {
		if (is_string($value) === true) {
			return trim($value);
		}

		if (is_array($value) === true) {
			$id = $value['uuid'] ?? $value['id'] ?? ($value['@self']['id'] ?? null);
			if (is_string($id) === true) {
				return trim($id);
			}

			return (string)($id ?? '');
		}

		return '';
	}//end resolveRelationId()

	/**
	 * Parse a date value, or null when blank/unparseable. Fails closed —
	 * never throws.
	 *
	 * @param mixed $value A raw date string.
	 *
	 * @return DateTimeImmutable|null The parsed date, or null.
	 *
	 * @spec openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md#requirement-portfolio-rationalization-report-aggregates-per-organisation
	 */
	public function parseDate(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (\Exception $e) {
			return null;
		}
	}//end parseDate()

	/**
	 * Normalize OpenRegister search results (ObjectEntity or plain array)
	 * into plain data-bag arrays.
	 *
	 * @param array<int,mixed> $results Raw search results.
	 *
	 * @return array<int,array<string,mixed>> Normalized data bags.
	 *
	 * @spec openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md#requirement-report-aggregation-queries-are-bounded
	 */
	public function normalizeResults(array $results): array {
		return array_map(
			static function ($object) {
				if (is_array($object) === true) {
					return $object;
				}

				if (is_object($object) === true && method_exists($object, 'getObject') === true) {
					return $object->getObject();
				}

				return [];
			},
			$results
		);
	}//end normalizeResults()
}//end class
