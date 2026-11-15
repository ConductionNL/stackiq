<?php

/**
 * Contract of EolSyncService
 *
 * The public surface of EolSyncService that other components call, so they depend
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
 * Contract of EolSyncService.
 *
 * @category Kernel
 * @package  OCA\Stackiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface EolSyncServiceInterface {

	/**
	 * Get the current EOL sync configuration.
	 *
	 * @return array The configuration (see `EolSyncSettings::getEolSyncConfig()`).
	 *
	 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-products-are-mapped-to-endoflife-date-via-per-module-config
	 */
	public function getConfig(): array;

	/**
	 * Get the last-recorded sync status.
	 *
	 * @return array The status (see `EolSyncSettings::getEolSyncStatus()`).
	 *
	 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-the-feature-degrades-gracefully-when-the-feed-is-unavailable
	 */
	public function getStatus(): array;

	/**
	 * Run the EOL sync: resolve config and the EOL register/schema, find
	 * every mapped module, match and stamp its versions, and record the
	 * outcome. Invoked identically by the scheduled background job and the
	 * manual "sync now" admin endpoint.
	 *
	 * Never throws to the caller — every failure mode (feature disabled,
	 * OpenRegister absent, register/schema not resolvable) degrades to a
	 * recorded "unavailable" status instead, per the graceful-degradation
	 * requirement.
	 *
	 * @return array{available: bool, reason: string|null, matched: int, skipped: int, lastRunAt: string|null}
	 *
	 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-eol-sync-runs-on-a-schedule-with-a-manual-trigger
	 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-the-feature-degrades-gracefully-when-the-feed-is-unavailable
	 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-softwarecatalog-performs-no-direct-http-to-the-eol-feed
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Complexity 13 (threshold 10). The spec
	 * requires this method to never throw: feature disabled, OpenRegister absent, register or
	 * schema unresolvable, and per-module match failures each have to degrade to a recorded
	 * "unavailable"/skipped outcome rather than propagate. Each of those is one flat guard.
	 *
	 * @SuppressWarnings(PHPMD.NPathComplexity) NPath 896 (threshold 200) — the combinatorial
	 * product of those independent graceful-degradation guards, not nested logic.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) 100 lines, exactly at the threshold. The
	 * resolve-config → resolve-register/schema → match → stamp → record-status sequence is one
	 * transaction whose steps all feed the single status array returned at the end; extracting
	 * the middle steps would mean threading that accumulator through helpers for no real gain.
	 */
	public function run(): array;

	/**
	 * Update the EOL sync configuration.
	 *
	 * @param array $data The submitted configuration fields.
	 *
	 * The save asks integriq to resolve the end-of-life feed connection again
	 * (adopt-connection-registry).
	 *
	 * @return array The persisted configuration result.
	 *
	 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-products-are-mapped-to-endoflife-date-via-per-module-config
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-stackiq-conn-002-a-save-asks-integriq-to-look-again-and-a-run-reports-what-it-met
	 */
	public function updateConfig(array $data): array;
}//end interface
