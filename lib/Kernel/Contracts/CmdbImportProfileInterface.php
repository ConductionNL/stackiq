<?php

/**
 * Contract of CmdbImportProfile
 *
 * The public surface of CmdbImportProfile that other components call, so they depend
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

use OCA\Stackiq\Exception\CmdbImportException;

/**
 * Contract of CmdbImportProfile.
 *
 * @category Kernel
 * @package  OCA\Stackiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CmdbImportProfileInterface {

	/**
	 * The profile and the packs as the admin settings show them: what the next import runs.
	 *
	 * Loads and validates everything first, so a broken pack throws here as
	 * it does when an import starts. The transform of each field mapping is
	 * passed through as the pack stores it, so no key the engine reads is
	 * hidden from the admin; `required` is normalised to a boolean. Each pack
	 * also carries the write rules the import applies on top of the mapping:
	 * `defaults` (pack values set on every write), `createOnlyDefaults`
	 * (values set on create only, not mapped columns), `createOnlyFields` (mapped
	 * fields written on create or, on update, only when the stored value is
	 * empty; a key of `createOnlyDefaults` only when it is also mapped) and `neverWritten` (fields the
	 * import never writes on an existing object). It also says where it came
	 * from (`source`, `packId`, the stored and the shipped version, whether it
	 * was edited and whether a newer shipped pack was left out because of
	 * that) and whether it is edited in OpenRegister (`editable`).
	 *
	 * @return array{profile: array<string, mixed>, packs: array<int, array<string, mixed>>, openRegisterPacks: bool}
	 *
	 * @throws CmdbImportException MAPPING_UNAVAILABLE when the validator is missing,
	 *                             or the profile or a pack is unreadable or invalid.
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/specs/cmdb-export-import/spec.md#requirement-the-admin-settings-shall-show-the-mapping-the-import-uses-req-cmdb-020
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-3
	 */
	public function mappingOverview(): array;

	/**
	 * The shipped packs an administrator edits in OpenRegister, validated, per target.
	 *
	 * The seed step stores these; it reads them without touching the store.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @throws CmdbImportException MAPPING_UNAVAILABLE when the validator is missing or a file is unreadable or invalid.
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-2
	 */
	public function shippedEditablePacks(): array;
}//end interface
