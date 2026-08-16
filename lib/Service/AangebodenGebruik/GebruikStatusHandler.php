<?php

/**
 * Gebruik Status Handler for SoftwareCatalog
 *
 * Handles status transition operations for AangebodenGebruik objects, extracted
 * from AangebodenGebruikService to reduce ExcessiveClassLength and
 * CyclomaticComplexity on that service.
 *
 * @category  Service
 * @package   OCA\SoftwareCatalog\Service\AangebodenGebruik
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-7
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Service\AangebodenGebruik;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Log\LoggerInterface;

/**
 * Handles validate → transition → persist lifecycle for AangebodenGebruik status changes.
 *
 * AangebodenGebruikService delegates all updateStatus() logic here so that its
 * own methods stay below ExcessiveMethodLength (≤100 lines) and CyclomaticComplexity (≤10).
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-7
 */
class GebruikStatusHandler {
	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService The OpenRegister object service.
	 * @param StatusTransitionValidator $validator Status transition validator.
	 * @param LoggerInterface $logger Logger instance.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-7
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly StatusTransitionValidator $validator,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Update the status of a gebruik object after transition validation.
	 *
	 * @param object $gebruikObject The current gebruik object (from OpenRegister).
	 * @param string $newStatus The requested new status.
	 *
	 * @return array<string,mixed> Result with keys 'success' (bool), 'message' (string),
	 *                             and 'object' (object|null) when successful.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-7
	 */
	public function updateStatus(object $gebruikObject, string $newStatus): array {
		$objectData = $gebruikObject->getObject();
		$currentStatus = (string)($objectData['status'] ?? '');

		// Validate the transition before any persistence.
		if ($this->validateTransition(current: $currentStatus, next: $newStatus) === false) {
			return [
				'success' => false,
				'message' => $this->validator->buildErrorMessage(
					currentStatus: $currentStatus,
					newStatus: $newStatus
				),
				'object' => null,
			];
		}

		return $this->persistStatusChange(gebruikObject: $gebruikObject, newStatus: $newStatus);
	}//end updateStatus()

	/**
	 * Validate a status transition using the transition map.
	 *
	 * @param string $current Current status value.
	 * @param string $next Requested next status value.
	 *
	 * @return bool True when the transition is permitted.
	 */
	private function validateTransition(string $current, string $next): bool {
		if ($current === '') {
			// New objects with no status may transition to any status.
			return true;
		}

		return $this->validator->isAllowed(currentStatus: $current, newStatus: $next);
	}//end validateTransition()

	/**
	 * Persist the status change to OpenRegister.
	 *
	 * @param object $gebruikObject The gebruik object to update.
	 * @param string $newStatus The validated new status.
	 *
	 * @return array<string,mixed> Result array with 'success', 'message', and 'object'.
	 */
	private function persistStatusChange(object $gebruikObject, string $newStatus): array {
		try {
			$objectData = $gebruikObject->getObject();
			$objectData['status'] = $newStatus;

			$updated = $this->objectService->saveObject(
				register: $gebruikObject->getRegister(),
				schema: $gebruikObject->getSchema(),
				object: $objectData
			);

			$this->logger->info(
				'GebruikStatusHandler: Status updated successfully',
				[
					'uuid' => $gebruikObject->getUuid(),
					'newStatus' => $newStatus,
				]
			);

			return [
				'success' => true,
				'message' => sprintf('Status updated to "%s".', $newStatus),
				'object' => $updated,
			];
		} catch (\Exception $e) {
			$this->logger->error(
				'GebruikStatusHandler: Failed to persist status change',
				[
					'uuid' => $gebruikObject->getUuid(),
					'newStatus' => $newStatus,
					'exception' => $e->getMessage(),
				]
			);

			return [
				'success' => false,
				'message' => 'Failed to persist status change: ' . $e->getMessage(),
				'object' => null,
			];
		}//end try

	}//end persistStatusChange()
}//end class
