<?php

/**
 * Resolves the AMEF (ArchiMate) register id.
 *
 * @category Service
 * @package  OCA\Stackiq\Service\ArchiMate
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\ArchiMate;

use OCP\IAppConfig;

/**
 * The AMEF register id: from the AMEF configuration, or the legacy app config keys.
 *
 * @spec openspec/specs/archimate-import/spec.md
 */
final class AmefRegisterId {
	/**
	 * Constructor.
	 *
	 * @param IAppConfig $config The app configuration holding the legacy keys.
	 */
	public function __construct(
		private readonly IAppConfig $config,
	) {
	}//end __construct()

	/**
	 * The AMEF register id as a positive int.
	 *
	 * @param array<string, mixed> $amefConfig The AMEF configuration.
	 *
	 * @return int|null The register id, or null when none is configured or it is not a positive number.
	 *
	 * @spec openspec/specs/archimate-import/spec.md
	 */
	public function resolve(array $amefConfig): ?int {
		$rawRegisterId = $this->rawId(amefConfig: $amefConfig);

		// Validate and normalize to positive int.
		if ($rawRegisterId !== '' && is_numeric((string)$rawRegisterId) === true) {
			$registerId = (int)$rawRegisterId;
			if ($registerId > 0) {
				return $registerId;
			}
		}

		return null;
	}//end resolve()

	/**
	 * The configured register id, before validation.
	 *
	 * @param array<string, mixed> $amefConfig The AMEF configuration.
	 *
	 * @return mixed The raw id.
	 */
	private function rawId(array $amefConfig): mixed {
		// Try JSON config keys first: support both 'register_id' and 'register'.
		$rawRegisterId = $amefConfig['register_id'] ?? $amefConfig['register'] ?? null;

		// Fallback to legacy individual app config keys if not present in JSON.
		if ($rawRegisterId === null || $rawRegisterId === '') {
			$rawRegisterId = $this->config->getValueString('stackiq', 'amef_register_id', '');
			if ($this->config->getValueString('stackiq', 'amef_register', '') !== '') {
				// If only the plain register key is configured, use it as the ID.
				$rawRegisterId = $this->config->getValueString('stackiq', 'amef_register', '');
			}
		}

		return $rawRegisterId;
	}//end rawId()
}//end class
