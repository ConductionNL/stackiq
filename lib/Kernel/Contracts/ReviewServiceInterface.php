<?php

/**
 * Contract of ReviewService
 *
 * The public surface of ReviewService that other components call, so they depend
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
 * @spec openspec/specs/method-decomposition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Kernel\Contracts;

/**
 * Contract of ReviewService.
 *
 * @category Kernel
 * @package  OCA\Stackiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ReviewServiceInterface {

	/**
	 * Submit an authenticated review into the moderation queue.
	 *
	 * Requires an active session (never anonymous). Strips every
	 * client-controlled privileged key, re-derives `auteur` from the
	 * session, binds the subject (`modules`/`diensten`), and forces
	 * `status = pending`.
	 *
	 * @param array<string,mixed> $payload The review payload
	 *                                     (naam, waardering,
	 *                                     beschrijvingKort/Lang).
	 * @param string $subjectType 'module' or 'catalogService'.
	 * @param string $subjectId The uuid of the module/dienst being reviewed.
	 *
	 * @return array{ok:bool, reason:string, uuid:?string, status:?string} Result.
	 *
	 * @spec openspec/specs/catalog-ratings/spec.md#requirement-the-submitting-users-identity-must-be-bound-server-side-and-must-not-be-accepted-from-client-input
	 */
	public function submit(array $payload, string $subjectType, string $subjectId): array;
}//end interface
