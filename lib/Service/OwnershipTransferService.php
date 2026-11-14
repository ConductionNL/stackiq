<?php

/**
 * Stackiq OwnershipTransferService
 *
 * Moves chosen entries from one organisation to another without recreating
 * them: `@self.organisation` and the type's owning field (from the shared
 * OwnershipMap) are re-pointed on a full save, so each entry keeps its uuid,
 * history, files and relations. `plan()` is the dry run: each chosen entry is
 * listed as moving or skipped with the reason, and linked entries of the
 * source organisation that stay behind are named. `execute()` moves exactly
 * what the plan moves.
 *
 * The controller checks that the caller may administer both organisations
 * before calling either method; this service never tombstones anything.
 *
 * @category Service
 * @package  OCA\Stackiq\Service
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

namespace OCA\Stackiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\Organisation\OwnershipMap;
use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use Psr\Log\LoggerInterface;

/**
 * Plan and carry out a move of entries between organisations.
 *
 * @spec openspec/changes/landscape-move-between-organisations/specs/move-between-organisations/spec.md#requirement-req-mbo-001-an-administrator-moves-chosen-entries-to-another-organisation-and-they-keep-their-identity
 */
class OwnershipTransferService {
	/**
	 * Linked entries that do not move with an entry: for a schema, the field
	 * that lists the linked ids and the schema they live in.
	 *
	 * @var array<string, array{field: string, schema: string}>
	 */
	private const LINKED = [
		'usage' => ['field' => 'koppelingen', 'schema' => 'connection'],
	];

	/**
	 * Constructor.
	 *
	 * @param RegisterConfigReaderInterface $registerConfig The register and the object service
	 * @param ObjectTypeLocatorInterface    $objectTypes    Schema ids by slug
	 * @param LoggerInterface               $logger         The logger
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RegisterConfigReaderInterface $registerConfig,
		private readonly ObjectTypeLocatorInterface $objectTypes,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The dry run: what moves, what is skipped and why, and what stays behind.
	 *
	 * @param array<int, mixed> $objects The chosen entries, each `{schema, id}`
	 * @param string            $source  The organisation they are moved from
	 * @param string            $target  The organisation they are moved to
	 *
	 * @return array{source: string, target: string, items: list<array<string, mixed>>, staysBehind: list<array<string, mixed>>}
	 *
	 * @spec openspec/changes/landscape-move-between-organisations/specs/move-between-organisations/spec.md#requirement-req-mbo-003-the-dry-run-shows-what-moves-and-what-stays-behind
	 */
	public function plan(array $objects, string $source, string $target): array {
		$targetKnown = $source !== $target && $this->find(schema: 'organization', id: $target) !== null;

		$items = [];
		$moving = [];
		foreach ($objects as $object) {
			$item = $this->planItem(object: $object, source: $source, targetKnown: $targetKnown);
			$items[] = $item;
			if ($item['action'] === 'move') {
				$moving[$item['schema'] . ':' . $item['id']] = $item;
			}
		}

		return [
			'source' => $source,
			'target' => $target,
			'items' => array_map(static fn (array $item): array => array_diff_key($item, ['entity' => true]), $items),
			'staysBehind' => $this->staysBehind(moving: $moving, source: $source),
		];
	}//end plan()

	/**
	 * Move what the plan moves, and answer the plan with the number moved.
	 *
	 * @param array<int, mixed> $objects The chosen entries, each `{schema, id}`
	 * @param string            $source  The organisation they are moved from
	 * @param string            $target  The organisation they are moved to
	 *
	 * @return array<string, mixed> The plan plus `moved`.
	 *
	 * @spec openspec/changes/landscape-move-between-organisations/specs/move-between-organisations/spec.md#requirement-req-mbo-001-an-administrator-moves-chosen-entries-to-another-organisation-and-they-keep-their-identity
	 */
	public function execute(array $objects, string $source, string $target): array {
		$plan = $this->plan(objects: $objects, source: $source, target: $target);
		$moved = 0;
		foreach ($plan['items'] as $item) {
			if ($item['action'] !== 'move') {
				continue;
			}

			$entity = $this->find(schema: $item['schema'], id: $item['id']);
			if ($entity === null) {
				continue;
			}

			$data = self::movedTo(data: $entity->getObject(), schema: $item['schema'], source: $source, target: $target);
			$this->save(schema: $item['schema'], id: $item['id'], data: $data);
			$moved++;
		}

		$this->logger->info('Stackiq: entries moved between organisations', ['source' => $source, 'target' => $target, 'moved' => $moved]);

		return $plan + ['moved' => $moved];
	}//end execute()

	/**
	 * The data of an entry owned by the target: `@self.organisation` and the
	 * owning field re-pointed, everything else as it was.
	 *
	 * @param array<string, mixed> $data   The entry
	 * @param string               $schema Its schema
	 * @param string               $source The organisation it leaves
	 * @param string               $target The organisation it joins
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/landscape-move-between-organisations/specs/move-between-organisations/spec.md#requirement-req-mbo-001-an-administrator-moves-chosen-entries-to-another-organisation-and-they-keep-their-identity
	 */
	public static function movedTo(array $data, string $schema, string $source, string $target): array {
		$self = $data['@self'] ?? [];
		if (is_array($self) === false) {
			$self = [];
		}

		$self['organisation'] = $target;
		$data['@self'] = $self;

		$field = OwnershipMap::owningFieldOf(schema: $schema);
		if ($field !== null && self::isOwnedField(value: ($data[$field] ?? null), source: $source) === true) {
			$data[$field] = $target;
		}

		return $data;
	}//end movedTo()

	/**
	 * One chosen entry, planned.
	 *
	 * @param mixed  $object      The chosen entry
	 * @param string $source      The source organisation
	 * @param bool   $targetKnown Whether the target organisation exists and differs from the source
	 *
	 * @return array<string, mixed>
	 */
	private function planItem(mixed $object, string $source, bool $targetKnown): array {
		if (is_array($object) === false) {
			$object = [];
		}

		$schema = trim((string) ($object['schema'] ?? ''));
		$id = trim((string) ($object['id'] ?? ''));
		$item = ['schema' => $schema, 'id' => $id, 'name' => '', 'action' => 'skip', 'reason' => null];

		if (in_array($schema, OwnershipMap::movableSchemas(), true) === false || $id === '') {
			$item['reason'] = 'This kind of entry cannot move between organisations';
			return $item;
		}

		if ($targetKnown === false) {
			$item['reason'] = 'The target organisation is unknown or the same as the source';
			return $item;
		}

		$entity = $this->find(schema: $schema, id: $id);
		if ($entity === null) {
			$item['reason'] = 'Not found';
			return $item;
		}

		$item['name'] = self::nameOf(data: $entity->getObject());
		if ($this->ownerOf(entity: $entity) !== $source) {
			$item['reason'] = 'Not owned by the source organisation';
			return $item;
		}

		$item['action'] = 'move';
		return $item;
	}//end planItem()

	/**
	 * Linked entries of the source organisation that do not move.
	 *
	 * @param array<string, array<string, mixed>> $moving The moving items by `schema:id`
	 * @param string                              $source The source organisation
	 *
	 * @return list<array<string, mixed>>
	 */
	private function staysBehind(array $moving, string $source): array {
		$stays = [];
		foreach ($moving as $item) {
			$link = self::LINKED[$item['schema']] ?? null;
			if ($link === null) {
				continue;
			}

			$entity = $this->find(schema: $item['schema'], id: $item['id']);
			if ($entity === null) {
				continue;
			}

			foreach ((array) ($entity->getObject()[$link['field']] ?? []) as $linkedId) {
				$linkedId = self::idOf(value: $linkedId);
				$key = $link['schema'] . ':' . $linkedId;
				if ($linkedId === '' || isset($moving[$key]) === true || isset($stays[$key]) === true) {
					continue;
				}

				$linked = $this->find(schema: $link['schema'], id: $linkedId);
				if ($linked !== null && $this->ownerOf(entity: $linked) === $source) {
					$stays[$key] = [
						'schema' => $link['schema'],
						'id' => $linkedId,
						'name' => self::nameOf(data: $linked->getObject()),
						'organisation' => $source,
						'linkedTo' => $item['id'],
					];
				}
			}
		}//end foreach

		return array_values($stays);
	}//end staysBehind()

	/**
	 * The organisation that owns an entity, or null when it cannot be read.
	 *
	 * @param object $entity The object entity
	 *
	 * @return string|null
	 */
	private function ownerOf(object $entity): ?string {
		if (OwnershipMap::hasOrganisationAccessor(entity: $entity) === false) {
			return null;
		}

		try {
			$owner = $entity->getOrganisation();
		} catch (\Throwable $e) {
			return null;
		}

		if (is_string($owner) === false) {
			return null;
		}

		return $owner;
	}//end ownerOf()

	/**
	 * One object, or null when it does not exist or cannot be read.
	 *
	 * @param string $schema The schema slug
	 * @param string $id     The uuid
	 *
	 * @return object|null
	 */
	private function find(string $schema, string $id): ?object {
		$objectService = $this->objectService();
		$registerId = $this->registerConfig->getVoorzieningenRegisterId();
		$schemaId = $this->objectTypes->getSchemaIdForObjectType(objectType: $schema);
		if ($objectService === null || $registerId === null || $schemaId === null) {
			return null;
		}

		try {
			return $objectService->find(id: $id, register: $registerId, schema: $schemaId);
		} catch (\Throwable $e) {
			return null;
		}
	}//end find()

	/**
	 * Save one object whole, under its own uuid.
	 *
	 * @param string               $schema The schema slug
	 * @param string               $id     The uuid
	 * @param array<string, mixed> $data   The full object
	 *
	 * @return void
	 */
	private function save(string $schema, string $id, array $data): void {
		$objectService = $this->objectService();
		$registerId = $this->registerConfig->getVoorzieningenRegisterId();
		$schemaId = $this->objectTypes->getSchemaIdForObjectType(objectType: $schema);
		if ($objectService === null || $registerId === null || $schemaId === null) {
			return;
		}

		$objectService->saveObject(object: $data, extend: [], register: $registerId, schema: $schemaId, uuid: $id);
	}//end save()

	/**
	 * The object service, or null without OpenRegister.
	 *
	 * @return ObjectServiceInterface|null
	 */
	private function objectService(): ?ObjectServiceInterface {
		return $this->registerConfig->getObjectService();
	}//end objectService()

	/**
	 * Whether an owning field belongs to the source: it names the source, or names no one.
	 * A field naming a third organisation is left as it is.
	 *
	 * @param mixed  $value  The field value
	 * @param string $source The source organisation
	 *
	 * @return bool
	 */
	private static function isOwnedField(mixed $value, string $source): bool {
		return $value === null || self::idOf(value: $value) === '' || self::refersTo(value: $value, organisation: $source) === true;
	}//end isOwnedField()

	/**
	 * Whether a field value names this organisation (a uuid, or an object with its id).
	 *
	 * @param mixed  $value        The field value
	 * @param string $organisation The organisation uuid
	 *
	 * @return bool
	 */
	private static function refersTo(mixed $value, string $organisation): bool {
		return self::idOf(value: $value) === $organisation;
	}//end refersTo()

	/**
	 * The id in a reference: a string, or an object's id.
	 *
	 * @param mixed $value The reference
	 *
	 * @return string
	 */
	private static function idOf(mixed $value): string {
		if (is_string($value) === true) {
			return trim($value);
		}

		if (is_array($value) === true) {
			return trim((string) ($value['id'] ?? ($value['uuid'] ?? ($value['@self']['id'] ?? ''))));
		}

		return '';
	}//end idOf()

	/**
	 * An entry's display name.
	 *
	 * @param array<string, mixed> $data The entry
	 *
	 * @return string
	 */
	private static function nameOf(array $data): string {
		return (string) ($data['name'] ?? ($data['title'] ?? ($data['@self']['name'] ?? '')));
	}//end nameOf()
}//end class
