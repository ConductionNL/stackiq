<?php

/**
 * Normalises the stored voorzieningen configuration.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/settings-service/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\Settings;

/**
 * Normalises the voorzieningen configuration to the clean format: only
 * `register` and the known `*_schema` keys, every value a string, every
 * known schema key present.
 *
 * @category Service
 * @package  OCA\Stackiq\Service\Settings
 *
 * @spec openspec/specs/settings-service/spec.md
 */
final class VoorzieningenConfigNormalizer {

	/**
	 * Normalise a raw or legacy voorzieningen configuration.
	 *
	 * @param array $input Raw/legacy configuration.
	 *
	 * @return array Normalized configuration.
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function normalize(array $input): array {
		$normalized = [];

		// Register id.
		$normalized['register'] = '';
		if (isset($input['register']) === true) {
			$normalized['register'] = (string)$input['register'];
		}

		// Known schema keys to support - updated to match actual schemas from register.
		$schemaKeys = [
			'organisatie_schema',
			'contactpersoon_schema',
			'suite_schema',
			'dienst_schema',
			'kwetsbaarheid_schema',
			'gebruik_schema',
			'contract_schema',
			'koppeling_schema',
			'beoordeeling_schema',
			'module_schema',
			'compliancy_schema',
			'moduleVersie_schema',
			'sector_schema',
			'sbomComponent_schema',
			'maintenanceWindow_schema',
		];

		// Copy any present schema keys; ignore sources/registers.
		foreach ($schemaKeys as $key) {
			$normalized[$key] = '';
			if (array_key_exists($key, $input) === true && $input[$key] !== null) {
				$normalized[$key] = (string)$input[$key];
			}
		}

		return $normalized;
	}//end normalize()

}//end class
