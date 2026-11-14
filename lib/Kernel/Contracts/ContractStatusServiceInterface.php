<?php

/**
 * Contract of ContractStatusService
 *
 * The public surface of ContractStatusService that other components call, so they depend
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

use DateTimeImmutable;

/**
 * Contract of ContractStatusService.
 *
 * @category Kernel
 * @package  OCA\Stackiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ContractStatusServiceInterface {

	/**
	 * Scan all contracts and expire the eligible ones.
	 *
	 * Returns the number of contracts transitioned. Degrades to 0 (no error)
	 * when OpenRegister is unavailable or the contract schema is not configured.
	 *
	 * @param DateTimeImmutable|null $now Logical "now" (defaults to current time).
	 *
	 * @return int The number of contracts transitioned to Verlopen.
	 *
	 * @spec openspec/specs/contract-administration/spec.md
	 */
	public function expirePastContracts(?DateTimeImmutable $now = null): int;
}//end interface
