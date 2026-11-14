<?php

/**
 * Contract of MergeOrganisatieService
 *
 * The public surface of MergeOrganisatieService that other components call, so they depend
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
 * Contract of MergeOrganisatieService.
 *
 * @category Kernel
 * @package  OCA\Stackiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MergeOrganisatieServiceInterface {

	/**
	 * Preview a merge: per-relation-type counts, no writes.
	 *
	 * @param string $sourceUuid The source organisation UUID.
	 * @param string $targetUuid The target organisation UUID.
	 *
	 * @return array{sourceUuid: string, targetUuid: string, counts: array<string, int>, blockers: array<int, array{type: string, message: string}>}
	 *
	 * @spec openspec/specs/organisation-merge/spec.md#requirement-the-system-shall-preview-a-merge-with-per-relation-type-counts-before-any-write
	 */
	public function dryRun(string $sourceUuid, string $targetUuid): array;

	/**
	 * Execute a merge: re-point every relation type, migrate group
	 * membership, tombstone the source. Idempotent — a re-run against a
	 * partially or fully completed merge only processes what remains (see
	 * class docblock).
	 *
	 * @param string $sourceUuid The source organisation UUID.
	 * @param string $targetUuid The target organisation UUID.
	 * @param string|null $actorUid The acting admin's UID (for progress/audit attribution).
	 *
	 * @return array{ok: bool, operationId?: string, sourceUuid: string, targetUuid: string,
	 *   status?: string, counts?: array<string, int>,
	 *   blockers?: array<int, array{type: string, message: string}>}
	 *
	 * @spec openspec/specs/organisation-merge/spec.md#requirement-execute-must-re-point-every-relation-type-while-preserving-every-unrelated-field-on-each-object
	 */
	public function execute(string $sourceUuid, string $targetUuid, ?string $actorUid = null): array;
}//end interface
