<?php

/**
 * Contract of GebruikSyncService
 *
 * The public surface of GebruikSyncService that other components call, so they depend
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

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Contract of GebruikSyncService.
 *
 * @category Kernel
 * @package  OCA\Stackiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface GebruikSyncServiceInterface {

	/**
	 * Process a specific gebruik object.
	 *
	 * This method handles both AMEF elements processing and status auto-update.
	 *
	 * @param ObjectEntity $gebruikObject The gebruik object to process
	 *
	 * @return array Processing statistics.
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function processSpecificGebruik(ObjectEntity $gebruikObject): array;
}//end interface
