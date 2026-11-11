<?php

/**
 * One load of the CMDB import profile and its packs.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service\Cmdb
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\Cmdb;

use Closure;

/**
 * The validator, the profile and the store reader of one load, and the packs it took so far.
 *
 * Built and read by CmdbImportProfile only.
 *
 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
 */
final class CmdbPackLoadRun {
	/**
	 * Reads the stored pack for a shipped one; null when the store is not available.
	 *
	 * @var Closure|null
	 */
	public ?Closure $readStored = null;

	/**
	 * The pack taken per target.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $packs = [];

	/**
	 * Where each pack came from, as the mapping overview shows it, per target.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $sources = [];

	/**
	 * Where each pack came from, as a refusal names it, per target.
	 *
	 * @var array<string, CmdbPackOrigin>
	 */
	public array $origins = [];

	/**
	 * Constructor.
	 *
	 * @param object $validator OpenRegister's pack validator.
	 * @param array<string, mixed> $profile The decoded profile.
	 */
	public function __construct(
		public readonly object $validator,
		public readonly array $profile,
	) {
	}//end __construct()
}//end class
