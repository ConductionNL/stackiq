<?php

/**
 * Incoming references to a catalogue entry
 *
 * Which objects in the catalogue register point at an entry through a
 * single-object `$ref` to its schema. Those references keep the uuid when the
 * entry moves to another schema, but would then point at an object of the
 * wrong type, so a type change is refused while any exist.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service\EntryType
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/landscape-change-entry-type/specs/entry-type-change/spec.md#requirement-req-etc-001-an-entry-changes-type-and-keeps-its-identity
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\EntryType;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Lists the references that block a type change, read from the register's own schemas at runtime.
 *
 * @spec openspec/changes/landscape-change-entry-type/specs/entry-type-change/spec.md#requirement-req-etc-001-an-entry-changes-type-and-keeps-its-identity
 */
class EntryReferences {

	/**
	 * OpenRegister's schema mapper.
	 *
	 * @var string
	 */
	public const SCHEMA_MAPPER = 'OCA\OpenRegister\Db\SchemaMapper';

	/**
	 * OpenRegister's register mapper.
	 *
	 * @var string
	 */
	public const REGISTER_MAPPER = 'OCA\OpenRegister\Db\RegisterMapper';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's mappers.
	 * @param LoggerInterface $logger Records a scan that could not run.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The references that point at the entry, by schema and field, with their count.
	 *
	 * Counted as the system: a reference the caller cannot read still breaks.
	 * When the scan cannot run, the answer is a blocker saying so, never an empty list.
	 *
	 * @param object $objects OpenRegister's ObjectService.
	 * @param int $registerId The catalogue register.
	 * @param int $schemaId The entry's current schema.
	 * @param string $uuid The entry.
	 *
	 * @return array<int, array{schema: string, field: string, count: int}>
	 *
	 * @spec openspec/changes/landscape-change-entry-type/specs/entry-type-change/spec.md#requirement-req-etc-001-an-entry-changes-type-and-keeps-its-identity
	 */
	public function blockers(object $objects, int $registerId, int $schemaId, string $uuid): array {
		try {
			$fields = $this->referencingFields(registerId: $registerId, schemaId: $schemaId);
		} catch (Throwable $e) {
			$this->logger->warning('EntryReferences: the register schemas could not be read', ['exception' => $e->getMessage()]);
			return [['schema' => '', 'field' => '', 'count' => -1]];
		}

		$blockers = [];
		foreach ($fields as $field) {
			$count = $this->count(objects: $objects, registerId: $registerId, field: $field, uuid: $uuid);
			if ($count !== 0) {
				$blockers[] = ['schema' => $field['slug'], 'field' => $field['property'], 'count' => $count];
			}
		}

		return $blockers;

	}//end blockers()

	/**
	 * Every single-object property in the register whose `$ref` names the schema.
	 *
	 * @param int $registerId The register.
	 * @param int $schemaId The referenced schema.
	 *
	 * @return array<int, array{schemaId: int, slug: string, property: string}>
	 *
	 * @spec openspec/changes/landscape-change-entry-type/specs/entry-type-change/spec.md#requirement-req-etc-001-an-entry-changes-type-and-keeps-its-identity
	 */
	public function referencingFields(int $registerId, int $schemaId): array {
		$schemas = $this->container->get(self::SCHEMA_MAPPER);
		$register = $this->container->get(self::REGISTER_MAPPER)->find($registerId);
		$target = $schemas->find($schemaId);
		$names = self::namesOf(schema: $target);

		$fields = [];
		foreach ((array)$register->getSchemas() as $candidateId) {
			$candidate = $schemas->find($candidateId);
			foreach ((array)$candidate->getProperties() as $property => $definition) {
				if (self::refersTo(definition: $definition, names: $names) === true) {
					$fields[] = ['schemaId' => (int)$candidate->getId(), 'slug' => (string)$candidate->getSlug(), 'property' => (string)$property];
				}
			}
		}

		return $fields;

	}//end referencingFields()

	/**
	 * The ways a `$ref` can name a schema: its slug, its id, its uuid.
	 *
	 * @param object $schema The schema.
	 *
	 * @return array<int, string>
	 */
	private static function namesOf(object $schema): array {
		return array_values(
			array_filter(
				[(string)$schema->getSlug(), (string)$schema->getId(), (string)$schema->getUuid()],
				static fn (string $name): bool => $name !== ''
			)
		);

	}//end namesOf()

	/**
	 * Whether a property is a single-object reference to one of the names.
	 *
	 * Arrays of references are not counted: OpenRegister's filter matches a scalar value.
	 *
	 * @param mixed $definition The property definition.
	 * @param array<int, string> $names The schema's names.
	 *
	 * @return bool
	 */
	private static function refersTo(mixed $definition, array $names): bool {
		if (is_array($definition) === false || ($definition['type'] ?? 'object') === 'array') {
			return false;
		}

		$ref = (string)($definition['$ref'] ?? '');
		if ($ref === '') {
			return false;
		}

		$last = substr($ref, (int)strrpos('/' . $ref, '/'));
		return in_array($ref, $names, true) === true || in_array($last, $names, true) === true;

	}//end refersTo()

	/**
	 * How many objects of a schema hold the uuid in the field; -1 when that cannot be told.
	 *
	 * @param object $objects OpenRegister's ObjectService.
	 * @param int $registerId The register.
	 * @param array{schemaId: int, slug: string, property: string} $field The field.
	 * @param string $uuid The entry.
	 *
	 * @return int
	 */
	private function count(object $objects, int $registerId, array $field, string $uuid): int {
		try {
			$result = $objects->searchObjectsPaginated(
				query: ['@self' => ['register' => $registerId, 'schema' => $field['schemaId']], $field['property'] => $uuid, '_limit' => 1],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('EntryReferences: references could not be counted', ['schema' => $field['slug'], 'exception' => $e->getMessage()]);
			return -1;
		}

		return (int)($result['total'] ?? count((array)($result['results'] ?? [])));

	}//end count()
}//end class
