<?php

/**
 * One progress stream: the operation and what was sent so far.
 *
 * @category  Controller
 * @package   OCA\Stackiq\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/settings-admin-controller/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Controller;

/**
 * The operation a stream follows, the last progress it sent and how many polls it made.
 *
 * Built and read by ProgressEventStream only.
 *
 * @spec openspec/specs/settings-admin-controller/spec.md
 */
final class ProgressStreamRun {
	/**
	 * The last progress sent; null before the first.
	 *
	 * @var array|null
	 */
	public ?array $lastProgress = null;

	/**
	 * The polls made so far.
	 *
	 * @var int
	 */
	public int $attempts = 0;

	/**
	 * Constructor.
	 *
	 * @param string $operationId The operation ID.
	 */
	public function __construct(
		public readonly string $operationId,
	) {
	}//end __construct()
}//end class
