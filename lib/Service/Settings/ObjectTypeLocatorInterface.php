<?php

/**
 * Where the objects of one object type are stored.
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
 * Resolves the configured OpenRegister register and schema of an object type
 * (organisatie, module, beoordeeling, element, ...). Services that only need
 * these two lookups depend on this interface instead of the whole settings
 * service.
 *
 * @category Service
 * @package  OCA\Stackiq\Service\Settings
 *
 * @spec openspec/specs/settings-service/spec.md
 */
interface ObjectTypeLocatorInterface {

	/**
	 * The configured schema id of an object type.
	 *
	 * @param string $objectType The object type
	 *
	 * @return int|null The schema id, or null when none is configured
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getSchemaIdForObjectType(string $objectType): ?int;

	/**
	 * The configured register id of an object type.
	 *
	 * @param string $objectType The object type
	 *
	 * @return int|null The register id, or null when none is configured
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getRegisterIdForObjectType(string $objectType): ?int;
}//end interface
