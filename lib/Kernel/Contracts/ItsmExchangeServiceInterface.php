<?php

/**
 * Contract of ItsmExchangeService
 *
 * The public surface of ItsmExchangeService that other components call, so they depend
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
 * Contract of ItsmExchangeService.
 *
 * @category Kernel
 * @package  OCA\Stackiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ItsmExchangeServiceInterface {

	/**
	 * Set up the exchange: check every flow, then create or update them all.
	 *
	 * Nothing is saved unless every flow passes preflight, so the instance never
	 * holds half an exchange. Running it again updates the flows it created.
	 *
	 * @param string $desk         The desk key.
	 * @param string $organisation The uuid of the organisation whose applications are exchanged.
	 * @param string $runAs        The user the scheduled imports run as.
	 * @param string $templateId   The desk's asset template for new records, when the desk needs one.
	 *
	 * @return array<string, mixed> `created`, and either `flows` or `blocking` per flow key.
	 *
	 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-001-an-administrator-sets-up-the-exchange-without-stackiq-holding-a-credential
	 */
	public function setUp(string $desk, string $organisation, string $runAs, string $templateId = ''): array;

	/**
	 * What is set up today.
	 *
	 * @return array<string, mixed> The desk, the organisation, the flows and the desks on offer.
	 *
	 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-007-the-cmdb-page-says-what-stackiq-is
	 */
	public function status(): array;
}//end interface
