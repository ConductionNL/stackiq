<?php

/**
 * Contract of LandscapeComparisonService
 *
 * The public surface of LandscapeComparisonService that other components call, so they depend
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
 * @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-002-an-information-manager-shall-compare-todays-landscape-with-the-plan-on-a-date
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Kernel\Contracts;

use DomainException;
use Exception;
use InvalidArgumentException;
use OutOfBoundsException;

/**
 * Contract of LandscapeComparisonService.
 *
 * @category Kernel
 * @package  OCA\Stackiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface LandscapeComparisonServiceInterface {

	/**
	 * Compare an organisation's landscape today with its landscape on a date, or with a scenario's.
	 *
	 * @param string $organisationUuid The organisation, already checked to be readable by the caller.
	 * @param string $date             The date (Y-m-d); ignored when a scenario is named.
	 * @param string $scenarioId       The scenario, or '' for the plan alone.
	 *
	 * @return array<string,mixed> The comparison in the shape of design D3.
	 *
	 * @throws InvalidArgumentException When neither a valid date nor a scenario with a target date is given.
	 * @throws OutOfBoundsException     When the scenario does not exist or the caller may not read it.
	 * @throws DomainException          When the scenario belongs to another organisation.
	 * @throws Exception                When OpenRegister or the register configuration is unavailable.
	 *
	 * @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-002-an-information-manager-shall-compare-todays-landscape-with-the-plan-on-a-date
	 */
	public function compare(string $organisationUuid, string $date='', string $scenarioId=''): array;
}//end interface
