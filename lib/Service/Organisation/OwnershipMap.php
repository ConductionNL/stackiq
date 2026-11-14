<?php

/**
 * Stackiq OwnershipMap
 *
 * Which field of each object type names the organisation that owns it, and
 * which types are owned only through OpenRegister's `@self.organisation`.
 * The organisation merge and the ownership transfer both read this one map,
 * so the two cannot drift apart.
 *
 * @category Service
 * @package  OCA\Stackiq\Service\Organisation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/landscape-move-between-organisations/specs/move-between-organisations/spec.md#requirement-req-mbo-001-an-administrator-moves-chosen-entries-to-another-organisation-and-they-keep-their-identity
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\Organisation;

/**
 * The per-type ownership map.
 *
 * @spec openspec/changes/landscape-move-between-organisations/specs/move-between-organisations/spec.md#requirement-req-mbo-001-an-administrator-moves-chosen-entries-to-another-organisation-and-they-keep-their-identity
 */
final class OwnershipMap {
	/**
	 * Relation types re-pointed via a business-level object field (scalar and/or array).
	 *
	 * The optional `schema` key overrides the OpenRegister schema slug when it
	 * differs from the relation-type key (`aanbod` lives on the `connection`
	 * schema).
	 *
	 * @var array<string, array{field: string, arrayField: string|null, schema?: string}>
	 */
	public const FIELD_RELATION_TYPES = [
		'usage' => ['field' => 'consumer', 'arrayField' => 'participants'],
		'contactPerson' => ['field' => 'organization', 'arrayField' => null],
		'aanbod' => ['field' => 'provider', 'arrayField' => null, 'schema' => 'connection'],
		// The supplier's own applications and services (`provider`, a Vendor
		// $ref to organization): after a takeover they belong to the target.
		'module' => ['field' => 'provider', 'arrayField' => null],
		'catalogService' => ['field' => 'provider', 'arrayField' => null],
	];

	/**
	 * Relation types re-pointed via the OpenRegister system-level `@self.organisation` field,
	 * as count key => schema slug. Modules and services carry a `provider`
	 * field too, so their ownership counts under its own key.
	 *
	 * @var array<string, string>
	 */
	public const SELF_ORGANISATION_RELATION_TYPES = [
		'catalogContract' => 'catalogContract',
		'compliancy' => 'compliancy',
		'moduleOwnership' => 'module',
		'catalogServiceOwnership' => 'catalogService',
	];

	/**
	 * The schemas whose entries can move between organisations.
	 *
	 * @return list<string> Schema slugs.
	 *
	 * @spec openspec/changes/landscape-move-between-organisations/specs/move-between-organisations/spec.md#requirement-req-mbo-001-an-administrator-moves-chosen-entries-to-another-organisation-and-they-keep-their-identity
	 */
	public static function movableSchemas(): array {
		$schemas = [];
		foreach (self::FIELD_RELATION_TYPES as $type => $mapping) {
			$schemas[] = ($mapping['schema'] ?? $type);
		}

		return array_values(array_unique(array_merge($schemas, array_values(self::SELF_ORGANISATION_RELATION_TYPES))));
	}//end movableSchemas()

	/**
	 * The field of a schema's objects that names the owning organisation, or
	 * null for a type owned only through `@self.organisation` (or not movable).
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return string|null The field.
	 *
	 * @spec openspec/changes/landscape-move-between-organisations/specs/move-between-organisations/spec.md#requirement-req-mbo-001-an-administrator-moves-chosen-entries-to-another-organisation-and-they-keep-their-identity
	 */
	public static function owningFieldOf(string $schema): ?string {
		foreach (self::FIELD_RELATION_TYPES as $type => $mapping) {
			if (($mapping['schema'] ?? $type) === $schema) {
				return $mapping['field'];
			}
		}

		return null;
	}//end owningFieldOf()

	/**
	 * Whether an entity exposes its owning organisation.
	 *
	 * @param object $entity The object entity.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/landscape-move-between-organisations/specs/move-between-organisations/spec.md#requirement-req-mbo-001-an-administrator-moves-chosen-entries-to-another-organisation-and-they-keep-their-identity
	 */
	public static function hasOrganisationAccessor(object $entity): bool {
		return property_exists($entity, 'organisation') === true
			|| method_exists($entity, 'getOrganisation') === true;
	}//end hasOrganisationAccessor()
}//end class
