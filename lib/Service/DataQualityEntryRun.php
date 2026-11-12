<?php

/**
 * The running totals of one entry's data quality evaluation.
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
 * One entry being evaluated: the entry, the moment, and what its rules added up to so far.
 *
 * @category Service
 * @package  OCA\Stackiq\Service
 *
 * @spec openspec/changes/landscape-completeness-score/specs/catalogue-data-quality/spec.md#requirement-req-cdq-003-a-data-quality-page-shows-how-complete-and-current-an-organisation-s-register-is
 */
final class DataQualityEntryRun {

	/**
	 * The sum of every rule's score times its weight.
	 *
	 * @var float
	 */
	public float $weighted = 0.0;

	/**
	 * The sum of every rule's weight.
	 *
	 * @var float
	 */
	public float $weights = 0.0;

	/**
	 * The sum of the required rules' weights.
	 *
	 * @var float
	 */
	public float $required = 0.0;

	/**
	 * The sum of the required rules' weights times their scores.
	 *
	 * @var float
	 */
	public float $requiredMet = 0.0;

	/**
	 * The fields whose non-freshness rule is not met.
	 *
	 * @var array<int, string>
	 */
	public array $missing = [];

	/**
	 * Whether each rule is met, in rule order.
	 *
	 * @var array<int, bool>
	 */
	public array $met = [];

	/**
	 * Whether a freshness rule is not met.
	 *
	 * @var bool
	 */
	public bool $stale = false;

	/**
	 * The value of the last freshness rule's field, when filled.
	 *
	 * @var string|null
	 */
	public ?string $confirmedAt = null;

	/**
	 * Start evaluating one entry.
	 *
	 * @param array<string, mixed> $object The entry.
	 * @param DateTimeImmutable    $now    Reference moment.
	 */
	public function __construct(
		public readonly array $object,
		public readonly DateTimeImmutable $now,
	) {
	}//end __construct()
}//end class
