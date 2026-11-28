<?php

/**
 * Contract of MaintenanceRecipientService
 *
 * The public surface of MaintenanceRecipientService that other components call, so they depend
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

use OCA\OpenRegister\Contract\ObjectEntityInterface;

/**
 * Contract of MaintenanceRecipientService.
 *
 * @category Kernel
 * @package  OCA\Stackiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MaintenanceRecipientServiceInterface {

	/**
	 * Whether an object belongs to the maintenanceWindow schema.
	 *
	 * @param ObjectEntityInterface $object The object from the event.
	 *
	 * @return boolean True for a maintenance window.
	 *
	 * @spec openspec/specs/maintenance-and-supplier-roadmap/spec.md#requirement-req-msr-003-the-owners-of-every-usage-are-notified
	 */
	public function isMaintenanceWindow(ObjectEntityInterface $object): bool;

	/**
	 * Load a maintenance window and record the owners to notify on it.
	 *
	 * @param string          $uuid     The window's id.
	 * @param string|int|null $register The register it lives in.
	 * @param string|int|null $schema   Its schema.
	 *
	 * @return array<int, string>|null The user ids recorded, or null when no owners were recorded.
	 *
	 * @throws \Throwable When the window or the product cannot be read (DoesNotExistException
	 *                    for a window that is gone), or the window cannot be written; the job
	 *                    decides whether to try again.
	 *
	 * @spec openspec/specs/maintenance-and-supplier-roadmap/spec.md#requirement-req-msr-003-the-owners-of-every-usage-are-notified
	 */
	public function recordRecipientsFor(string $uuid, string|int|null $register, string|int|null $schema): ?array;
}//end interface
