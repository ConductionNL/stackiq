<?php

/**
 * Contract of IntakeService
 *
 * The public surface of IntakeService that other components call, so they depend
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
 * Contract of IntakeService.
 *
 * @category Kernel
 * @package  OCA\Stackiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface IntakeServiceInterface {

	/**
	 * Submit an anonymous organisation registration into the moderation queue.
	 *
	 * Validates + sanitises the payload, refuses a duplicate pending submission
	 * for the same organisation name, then persists it with
	 * `registratiestatus = pending` and NO `publicatiedatum` (so the public RBAC
	 * gate keeps it invisible until approval).
	 *
	 * @param array<string,mixed> $payload The anonymous registration payload.
	 *
	 * @return array{ok:bool, reason:string, uuid:?string, status:?string} Result.
	 *
	 * @spec openspec/specs/open-data-publishing/spec.md
	 */
	public function submit(array $payload): array;
}//end interface
