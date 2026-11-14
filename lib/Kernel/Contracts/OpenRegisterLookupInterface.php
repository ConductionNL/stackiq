<?php

/**
 * Contract of OpenRegisterLookup
 *
 * The public surface of OpenRegisterLookup that other components call, so they depend
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
 * Contract of OpenRegisterLookup.
 *
 * @category Kernel
 * @package  OCA\Stackiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface OpenRegisterLookupInterface {

	/**
	 * The uuid of the caller's active organisation.
	 *
	 * @return string|null The uuid, or null without a session, without an active organisation or when the lookup fails.
	 *
	 * @spec openspec/specs/aangeboden-gebruik-api/spec.md#requirement-the-system-shall-list-offered-usage-records-scoped-to-the-callers-role-req-001
	 */
	public function activeOrganisationUuid(): ?string;
}//end interface
