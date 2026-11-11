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
	 * The AMEF register id as a positive int.
	 *
	 * @param array<string, mixed> $amefConfig The AMEF configuration.
	 * @param IAppConfig           $config     The app configuration holding the legacy keys.
	 *
	 * @return int|null The register id, or null when none is configured or it is not a positive number.
	 *
	 * @spec openspec/specs/archimate-import/spec.md
	 */
	public static function from(array $amefConfig, IAppConfig $config): ?int {
		$rawRegisterId = self::raw(amefConfig: $amefConfig, config: $config);

		// Validate and normalize to positive int.
		if ($rawRegisterId !== '' && is_numeric((string)$rawRegisterId) === true) {
			$registerId = (int)$rawRegisterId;
			if ($registerId > 0) {
				return $registerId;
			}
		}

		return null;
	}//end from()

	/**
	 * The configured register id, before validation.
	 *
	 * @param array<string, mixed> $amefConfig The AMEF configuration.
	 * @param IAppConfig           $config     The app configuration holding the legacy keys.
	 *
	 * @return mixed The raw id.
	 */
	private static function raw(array $amefConfig, IAppConfig $config): mixed {
		// Try JSON config keys first: support both 'register_id' and 'register'.
		$rawRegisterId = $amefConfig['register_id'] ?? $amefConfig['register'] ?? null;

		// Fallback to legacy individual app config keys if not present in JSON.
		if ($rawRegisterId === null || $rawRegisterId === '') {
			$rawRegisterId = $config->getValueString('stackiq', 'amef_register_id', '');
			if ($config->getValueString('stackiq', 'amef_register', '') !== '') {
				// If only the plain register key is configured, use it as the ID.
				$rawRegisterId = $config->getValueString('stackiq', 'amef_register', '');
			}
		}

		return $rawRegisterId;
	}//end raw()
}//end class
