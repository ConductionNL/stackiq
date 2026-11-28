<?php

/**
 * Contract of AttestationAnswerService
 *
 * The public surface of AttestationAnswerService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Stackiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/archive/2026-11-25-landscape-owner-attestation/specs/owner-attestation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Kernel\Contracts;

use DomainException;
use OutOfBoundsException;
use UnexpectedValueException;

/**
 * Contract of AttestationAnswerService.
 *
 * @category Kernel
 * @package  OCA\Stackiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AttestationAnswerServiceInterface {

	/**
	 * Confirm a request: the entry's lastConfirmedAt is now and the request reads confirmed.
	 *
	 * @param string $requestId The request id.
	 * @param string $uid       The signed-in user.
	 *
	 * @return array<string, mixed> The confirmed request.
	 *
	 * @throws OutOfBoundsException     When the request or its entry does not exist.
	 * @throws DomainException          When the user is not the request's assignee.
	 * @throws UnexpectedValueException When the request was already answered.
	 *
	 * @spec openspec/changes/archive/2026-11-25-landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-003-an-owner-confirms-or-corrects-each-entry
	 */
	public function confirm(string $requestId, string $uid): array;

	/**
	 * The assignee saved the entry themselves: their open requests for it read corrected.
	 *
	 * @param string $entrySchema The entry type (usage or module).
	 * @param string $entryId     The entry id.
	 * @param string $uid         The user who saved.
	 *
	 * @return int How many requests were marked corrected.
	 *
	 * @spec openspec/changes/archive/2026-11-25-landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-003-an-owner-confirms-or-corrects-each-entry
	 */
	public function markCorrected(string $entrySchema, string $entryId, string $uid): int;

	/**
	 * Every pending request past its deadline reads overdue. Idempotent: only pending moves.
	 *
	 * @return int How many requests became overdue.
	 *
	 * @spec openspec/changes/archive/2026-11-25-landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-003-an-owner-confirms-or-corrects-each-entry
	 */
	public function markOverdue(): int;

	/**
	 * Whether an update changed anything a person typed: a field outside the derived fields.
	 *
	 * @param array<string, mixed> $before The entry before the save.
	 * @param array<string, mixed> $after  The entry after the save.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-11-25-landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-003-an-owner-confirms-or-corrects-each-entry
	 */
	public function isChangedByHand(array $before, array $after): bool;
}//end interface
