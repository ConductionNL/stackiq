<?php

/**
 * Contract of ConnectionSuggestionService
 *
 * The public surface of ConnectionSuggestionService that other components call, so they depend
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
 * Contract of ConnectionSuggestionService.
 *
 * @category Kernel
 * @package  OCA\Stackiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ConnectionSuggestionServiceInterface {

	/**
	 * A usage, or null when it does not exist or the caller may not read it.
	 *
	 * @param string $usageUuid The usage.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/connections-derived-dependencies/specs/derived-connections/spec.md#requirement-req-dcn-003-only-the-organisation-that-owns-the-usage-accepts-or-dismisses-its-suggestions
	 */
	public function findUsage(string $usageUuid): ?array;

	/**
	 * Whether an organisation may change a usage: it is the usage's consumer or one of its participants.
	 *
	 * @param array<string, mixed> $usage        The usage.
	 * @param string|null          $organisation The caller's active organisation.
	 *
	 * @return boolean
	 *
	 * @spec openspec/changes/connections-derived-dependencies/specs/derived-connections/spec.md#requirement-req-dcn-003-only-the-organisation-that-owns-the-usage-accepts-or-dismisses-its-suggestions
	 */
	public function mayChange(array $usage, ?string $organisation): bool;

	/**
	 * The suggestions for a usage, each `{id, connection, name, otherModule,
	 * nationalProvision, type, direction, reason, draft, from}`.
	 *
	 * @param array<string, mixed> $usage The usage, as findUsage() returns it.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/connections-derived-dependencies/specs/derived-connections/spec.md#requirement-req-dcn-001-stackiq-suggests-the-known-connections-of-an-application-inside-the-organisations-landscape
	 */
	public function suggestFor(array $usage): array;

	/**
	 * Accept suggestions: existing connections join the usage's connections; a
	 * draft is created first, with status `in development`.
	 *
	 * @param array<string, mixed> $usage The usage.
	 * @param list<string>         $ids   The suggestion ids to accept; ids that are not current suggestions are ignored.
	 *
	 * @return list<string> The connection uuids added to the usage.
	 *
	 * @spec openspec/changes/connections-derived-dependencies/specs/derived-connections/spec.md#requirement-req-dcn-002-a-replacing-usage-is-offered-the-connections-of-the-usage-it-replaces
	 */
	public function accept(array $usage, array $ids): array;

	/**
	 * Dismiss suggestions: their ids are kept on the usage so they are not suggested again.
	 *
	 * @param array<string, mixed> $usage The usage.
	 * @param list<string>         $ids   The suggestion ids to dismiss; ids that are not current suggestions are ignored.
	 *
	 * @return list<string> The ids dismissed.
	 *
	 * @spec openspec/changes/connections-derived-dependencies/specs/derived-connections/spec.md#requirement-req-dcn-001-stackiq-suggests-the-known-connections-of-an-application-inside-the-organisations-landscape
	 */
	public function dismiss(array $usage, array $ids): array;
}//end interface
