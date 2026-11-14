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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
	 * The minimum version of the OpenRegister application required
	 *
	 * @var string The minimum required version of OpenRegister
	 */
	public const MIN_OPENREGISTER_VERSION = '0.1.7';

	/**
	 * Object type => the app-config key its schema id is actually stored under.
	 *
	 * The key was always derived from the schema SLUG. Eight slugs are now
	 * English while the stored keys are deliberately still Dutch — renaming that
	 * family is a migration across ~40 sites in three shapes, and doing a SUBSET
	 * resolves some types while leaving others silently "not configured", which
	 * is how the ratings feature died once already.
	 *
	 * So slug and key diverge, and every place that DERIVES the key from the
	 * object type (`$objectType . '_schema'`) has to come through here. Missing
	 * one is not an error: the lookup finds nothing and the caller reads it as
	 * "not configured". `getRegisterIdForObjectType()` did exactly that.
	 *
	 * @var array<string, string>
	 */
	public const LEGACY_SCHEMA_KEY = [
		'software-review' => 'beoordeeling_schema',
		// Not a Dutch-to-English legacy key like the rest, but the same
		// mechanism: `contract` collided with shillinq's and pipelinq's, all
		// three carrying `contractNumber`, so shillinq owns the contract and
		// this became the catalogue facet. The config KEY stays
		// `contract_schema` because it is live persisted state, and without
		// this entry the default `<type>_schema` rule would look for
		// `catalogContract_schema` and resolve nothing.
		'catalogContract' => 'contract_schema',
		'bioMeasure' => 'bioMaatregel_schema',
		'connection' => 'koppeling_schema',
		'contactPerson' => 'contactpersoon_schema',
		'moduleVersion' => 'moduleVersie_schema',
		'organization' => 'organisatie_schema',
		'catalogService' => 'dienst_schema',
		'usage' => 'gebruik_schema',
		'vulnerability' => 'kwetsbaarheid_schema',
	];

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
