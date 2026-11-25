<?php

/**
 * Contract of OwnershipTransferService
 *
 * The public surface of OwnershipTransferService that other components call, so they depend
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
 * Contract of OwnershipTransferService.
 *
 * @category Kernel
 * @package  OCA\Stackiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface OwnershipTransferServiceInterface {

	/**
	 * The dry run: what moves, what is skipped and why, and what stays behind.
	 *
	 * @param array<int, mixed> $objects The chosen entries, each `{schema, id}`
	 * @param string            $source  The organisation they are moved from
	 * @param string            $target  The organisation they are moved to
	 *
	 * @return array{source: string, target: string, items: list<array<string, mixed>>, staysBehind: list<array<string, mixed>>}
	 *
	 * @spec openspec/changes/archive/2026-11-25-landscape-move-between-organisations/specs/move-between-organisations/spec.md#requirement-req-mbo-003-the-dry-run-shows-what-moves-and-what-stays-behind
	 */
	public function plan(array $objects, string $source, string $target): array;

	/**
	 * Move what the plan moves, and answer the plan with the number moved.
	 *
	 * @param array<int, mixed> $objects The chosen entries, each `{schema, id}`
	 * @param string            $source  The organisation they are moved from
	 * @param string            $target  The organisation they are moved to
	 *
	 * @return array<string, mixed> The plan plus `moved`.
	 *
	 * @spec openspec/changes/archive/2026-11-25-landscape-move-between-organisations/specs/move-between-organisations/spec.md#requirement-req-mbo-001-an-administrator-moves-chosen-entries-to-another-organisation-and-they-keep-their-identity
	 */
	public function execute(array $objects, string $source, string $target): array;
}//end interface
