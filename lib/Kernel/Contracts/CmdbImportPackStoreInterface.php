<?php

/**
 * Contract of CmdbImportPackStore
 *
 * The public surface of CmdbImportPackStore that other components call, so they depend
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
 * Contract of CmdbImportPackStore.
 *
 * @category Kernel
 * @package  OCA\Stackiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CmdbImportPackStoreInterface {

	/**
	 * The store created the pack from the shipped file.
	 */
	public const SEEDED = 'seeded';

	/**
	 * The store replaced an unedited pack with a newer shipped one.
	 */
	public const UPDATED = 'updated';

	/**
	 * Whether OpenRegister's migration-pack store can be used.
	 *
	 * Only an OpenRegister with the hardened pack store counts: it has
	 * HARDENED_CLASS, and no write method of its pack controller carries an
	 * attribute or annotation that skips the CSRF check or admits non-admins.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	public function isAvailable(): bool;

	/**
	 * The stored pack for a shipped pack: found, seeded when missing, or updated when unedited and outdated.
	 *
	 * The answer holds the stored definition, the pack the engine runs (the
	 * definition without the marker), whether it was edited (null when it
	 * carries no marker) and whether an edit kept a newer shipped pack out.
	 *
	 * @param array<string, mixed> $shipped The shipped pack, already validated.
	 *
	 * @return array<string, mixed> id (int), slug, version, definition (stored, with the marker),
	 *                              pack (without it), edited (bool|null), newerShipped (bool) and outcome.
	 *
	 * @throws CmdbImportException MAPPING_UNAVAILABLE when the store is missing or fails,
	 *                             or when the pack was renamed in OpenRegister. A create
	 *                             that fails because another request seeded the pack
	 *                             first is no failure: that pack is used.
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	public function resolve(array $shipped): array;
}//end interface
