<?php

/**
 * Contract of EntryConfirmationService
 *
 * Confirms that an application or a usage is current, so its freshness resets.
 *
 * @category  Kernel
 * @package   OCA\Stackiq\Kernel\Contracts
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

namespace OCA\Stackiq\Kernel\Contracts;

use OCA\Stackiq\Service\EntryConfirmationRefused;

/**
 * Contract of EntryConfirmationService.
 *
 * @category Kernel
 * @package  OCA\Stackiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface EntryConfirmationServiceInterface {
	/**
	 * Record that the entry is current: set its lastConfirmedAt to now, and nothing else.
	 *
	 * @param string $kind The kind of entry: module or usage.
	 * @param string $id   The entry's id.
	 *
	 * @return array{lastConfirmedAt: string} The moment stored.
	 *
	 * @throws EntryConfirmationRefused With the HTTP status the caller answers.
	 *
	 * @spec openspec/changes/landscape-completeness-score/specs/catalogue-data-quality/spec.md#requirement-req-cdq-002-a-user-confirms-an-entry-is-current-and-its-freshness-resets
	 */
	public function confirm(string $kind, string $id): array;
}//end interface
