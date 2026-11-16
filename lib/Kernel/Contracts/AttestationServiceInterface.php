<?php

/**
 * Contract of AttestationService
 *
 * The public surface of AttestationService that other components call, so they depend
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
 * @spec openspec/changes/landscape-owner-attestation/specs/owner-attestation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Kernel\Contracts;

use InvalidArgumentException;
use RuntimeException;

/**
 * Contract of AttestationService.
 *
 * @category Kernel
 * @package  OCA\Stackiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AttestationServiceInterface {

	/**
	 * Per entry type: the field naming the organisation, and the owner fields.
	 *
	 * @var array<string, array{organisation: string, owners: array<int, string>}>
	 */
	public const SCOPES = [
		'usage'  => ['organisation' => 'consumer', 'owners' => ['businessOwner', 'technicalOwner']],
		'module' => ['organisation' => 'provider', 'owners' => ['contactPerson']],
	];

	/**
	 * Start a round and create its requests.
	 *
	 * @param string $scopeSchema    `usage` or `module`.
	 * @param string $organisationId The organisation whose entries the round covers.
	 * @param string $deadline       The deadline, as YYYY-MM-DD.
	 * @param string $name           The round's name.
	 * @param string $startedBy      The user who starts it.
	 *
	 * @return array{round: array<string, mixed>, requestCount: int, unassigned: array<int, array{entryId: string, entryName: string}>}
	 *
	 * @throws InvalidArgumentException When the scope, organisation or deadline is not valid.
	 * @throws RuntimeException         When OpenRegister or the schemas are not available.
	 *
	 * @spec openspec/changes/landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-001-an-information-manager-starts-a-confirmation-round-for-a-scope
	 */
	public function start(string $scopeSchema, string $organisationId, string $deadline, string $name, string $startedBy): array;
}//end interface
