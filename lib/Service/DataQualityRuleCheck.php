<?php

/**
 * One declared rule applied to one entry.
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

/**
 * A rule, the entry's value for its field, and what the rule made of it.
 *
 * @category Service
 * @package  OCA\Stackiq\Service
 *
 * @spec openspec/changes/landscape-completeness-score/specs/catalogue-data-quality/spec.md#requirement-req-cdq-003-a-data-quality-page-shows-how-complete-and-current-an-organisation-s-register-is
 */
final class DataQualityRuleCheck {

	/**
	 * The rule type, `required` when the rule names none.
	 *
	 * @var string
	 */
	public readonly string $type;

	/**
	 * The field the rule checks.
	 *
	 * @var string
	 */
	public readonly string $field;

	/**
	 * The entry's value for the field.
	 *
	 * @var mixed
	 */
	public readonly mixed $value;

	/**
	 * The rule's weight.
	 *
	 * @var float
	 */
	public float $weight = 0.0;

	/**
	 * The rule's score for the value, 0 to 1.
	 *
	 * @var float
	 */
	public float $score = 0.0;

	/**
	 * Whether the value meets the rule.
	 *
	 * @var bool
	 */
	public bool $met = false;

	/**
	 * Apply a rule to an entry.
	 *
	 * @param array<string, mixed> $rule   The rule.
	 * @param array<string, mixed> $object The entry.
	 */
	public function __construct(
		public readonly array $rule,
		array $object,
	) {
		$this->type  = (string)($rule['type'] ?? 'required');
		$this->field = (string)$rule['field'];
		$this->value = $object[$this->field] ?? null;
	}//end __construct()
}//end class
