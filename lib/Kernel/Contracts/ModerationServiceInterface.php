<?php

/**
 * Contract of ModerationService
 *
 * The public surface of ModerationService that other components call, so they depend
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
 * Contract of ModerationService.
 *
 * @category Kernel
 * @package  OCA\Stackiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ModerationServiceInterface {

	/**
	 * The moderated catalog object type (default / legacy — organisatie).
	 */
	public const MODERATED_TYPE = 'organization';

	/**
	 * Approve a pending entry: set it to the type's "approved" value and, for
	 * organisatie only, publish it (`publicatiedatum = now`) so the public
	 * RBAC read gate makes it anonymously visible.
	 *
	 * @param string $uuid The entry uuid.
	 * @param string $type The moderated object type (default: organisatie).
	 *
	 * @return array{ok:bool, reason:string, status:?string} Result.
	 *
	 * @spec openspec/specs/open-data-publishing/spec.md
	 * @spec openspec/specs/catalog-ratings/spec.md#requirement-a-newly-submitted-review-must-require-moderation-approval-before-becoming-public
	 */
	public function approve(string $uuid, string $type = self::MODERATED_TYPE): array;

	/**
	 * List the pending entries (of the given type) awaiting moderation.
	 *
	 * @param string $type The moderated object type (default: organisatie).
	 *
	 * @return array{ok:bool, reason:string, items:array<int,array<string,mixed>>}
	 *
	 * @spec openspec/specs/open-data-publishing/spec.md
	 * @spec openspec/specs/catalog-ratings/spec.md#requirement-review-moderation-must-reuse-the-existing-moderation-queue-mechanism-not-a-second-one
	 */
	public function listPending(string $type = self::MODERATED_TYPE): array;

	/**
	 * Reject a pending entry: set it to the type's "rejected" value; for
	 * organisatie, never give it a `publicatiedatum` (stays invisible).
	 *
	 * @param string $uuid The entry uuid.
	 * @param string $type The moderated object type (default: organisatie).
	 *
	 * @return array{ok:bool, reason:string, status:?string} Result.
	 *
	 * @spec openspec/specs/open-data-publishing/spec.md
	 * @spec openspec/specs/catalog-ratings/spec.md#requirement-a-newly-submitted-review-must-require-moderation-approval-before-becoming-public
	 */
	public function reject(string $uuid, string $type = self::MODERATED_TYPE): array;
}//end interface
