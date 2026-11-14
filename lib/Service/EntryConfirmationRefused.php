<?php

/**
 * Raised when an entry cannot be confirmed as current.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/landscape-completeness-score/specs/catalogue-data-quality/spec.md#requirement-req-cdq-002-a-user-confirms-an-entry-is-current-and-its-freshness-resets
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use RuntimeException;

/**
 * A refusal with the reason and the HTTP status the caller answers with.
 *
 * @category Service
 * @package  OCA\Stackiq\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/landscape-completeness-score/specs/catalogue-data-quality/spec.md#requirement-req-cdq-002-a-user-confirms-an-entry-is-current-and-its-freshness-resets
 */
class EntryConfirmationRefused extends RuntimeException {
	/**
	 * Constructor.
	 *
	 * @param string $reason  The machine readable reason.
	 * @param int    $status  The HTTP status.
	 * @param string $message The text the user reads.
	 *
	 * @spec exclude pure framework plumbing: an exception carrying its fields.
	 */
	public function __construct(
		public readonly string $reason,
		public readonly int $status,
		string $message='',
	) {
		parent::__construct(message: $message);
	}//end __construct()
}//end class
