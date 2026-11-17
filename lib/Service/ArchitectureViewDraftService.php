<?php

/**
 * Stackiq ArchitectureViewDraftService
 *
 * Writes an architecture view an assistant drafted, as a draft a person
 * reviews (architecture-assistant-drafted-views). Finds the elements a draft
 * can reuse, validates the drafted structure before anything is written,
 * reuses an element or relation that already exists, and writes every
 * object it creates with `origin: assistant` in the same save (ADR-088).
 * Every read and write runs with the caller's rights.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/architecture-assistant-drafted-views/specs/architecture-assistant-views/spec.md#requirement-req-aav-002-stackiq-shall-offer-a-create-tool-that-writes-a-draft-view-from-elements-and-relations
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\Stackiq\Exception\DraftRefusedException;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use OCP\IUserSession;
use RuntimeException;
use Throwable;

/**
 * Drafts architecture views for review.
 *
 * @spec openspec/changes/architecture-assistant-drafted-views/specs/architecture-assistant-views/spec.md#requirement-req-aav-002-stackiq-shall-offer-a-create-tool-that-writes-a-draft-view-from-elements-and-relations
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) One draft is one composite write: validation, resolution and three kinds of save belong together.
 */
class ArchitectureViewDraftService {

	/**
	 * The register every AMEF object lives in.
	 */
	public const REGISTER = 'vng-gemma';

	/**
	 * The most elements one draft may hold.
	 */
	public const MAX_ELEMENTS = 60;

	/**
	 * The most relations one draft may hold.
	 */
	public const MAX_RELATIONS = 120;

	/**
	 * The most rows a search returns.
	 */
	public const MAX_SEARCH = 50;

	/**
	 * The mark every object the draft creates carries.
	 */
	public const ORIGIN = 'assistant';

	/**
	 * The ArchiMate 3.2 element types a draft may use.
	 *
	 * @var list<string>
	 */
	public const ELEMENT_TYPES = [
		'BusinessActor', 'BusinessRole', 'BusinessCollaboration', 'BusinessInterface', 'BusinessProcess',
		'BusinessFunction', 'BusinessInteraction', 'BusinessEvent', 'BusinessService', 'BusinessObject',
		'Contract', 'Representation', 'Product',
		'ApplicationComponent', 'ApplicationCollaboration', 'ApplicationInterface', 'ApplicationFunction',
		'ApplicationInteraction', 'ApplicationProcess', 'ApplicationEvent', 'ApplicationService', 'DataObject',
		'Node', 'Device', 'SystemSoftware', 'TechnologyCollaboration', 'TechnologyInterface', 'Path',
		'CommunicationNetwork', 'TechnologyFunction', 'TechnologyProcess', 'TechnologyInteraction',
		'TechnologyEvent', 'TechnologyService', 'Artifact',
		'Equipment', 'Facility', 'DistributionNetwork', 'Material',
		'Stakeholder', 'Driver', 'Assessment', 'Goal', 'Outcome', 'Principle', 'Requirement', 'Constraint',
		'Meaning', 'Value',
		'Resource', 'Capability', 'ValueStream', 'CourseOfAction',
		'WorkPackage', 'Deliverable', 'ImplementationEvent', 'Plateau', 'Gap',
		'Grouping', 'Location',
	];

	/**
	 * The eleven ArchiMate relation types.
	 *
	 * @var list<string>
	 */
	public const RELATION_TYPES = [
		'Association', 'Flow', 'Serving', 'Realization', 'Composition', 'Aggregation',
		'Assignment', 'Access', 'Triggering', 'Specialization', 'Influence',
	];

	/**
	 * The size a drafted node is drawn at, as the editor's palette places one.
	 */
	private const NODE_SIZE = ['width' => 120, 'height' => 55];

	/**
	 * Constructor.
	 *
	 * @param RegisterConfigReaderInterface $registers   Hands out OpenRegister's object service.
	 * @param IUserSession                  $userSession The session the tool runs in.
	 */
	public function __construct(
		private readonly RegisterConfigReaderInterface $registers,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * The elements a draft can reuse, matched on text, as a short projection.
	 *
	 * @param string       $query The search text.
	 * @param list<string> $types ArchiMate element types to keep; empty keeps every type.
	 * @param int          $limit The most rows, capped at MAX_SEARCH.
	 *
	 * @return list<array{uuid: string, identifier: string, name: string, type: string, gemmaType: string}>
	 *
	 * @throws InvalidArgumentException When a type is not an ArchiMate element type.
	 *
	 * @spec openspec/changes/architecture-assistant-drafted-views/specs/architecture-assistant-views/spec.md#requirement-req-aav-001-stackiq-shall-offer-a-read-tool-that-finds-architecture-elements-for-a-draft
	 */
	public function searchElements(string $query, array $types = [], int $limit = 20): array {
		foreach ($types as $type) {
			if (in_array($type, self::ELEMENT_TYPES, true) === false) {
				throw new InvalidArgumentException("'{$type}' is not an ArchiMate element type.");
			}
		}

		$limit = max(1, min($limit, self::MAX_SEARCH));
		$filters = ['_search' => $query, '_limit' => $limit];
		if (count($types) === 1) {
			$filters['type'] = $types[0];
		}

		$rows = [];
		foreach ($this->search(schema: 'element', filters: $filters) as $element) {
			if ($types !== [] && in_array((string)($element['type'] ?? ''), $types, true) === false) {
				continue;
			}

			$rows[] = [
				'uuid' => (string)($element['uuid'] ?? ''),
				'identifier' => (string)($element['identifier'] ?? ''),
				'name' => (string)($element['name'] ?? ''),
				'type' => (string)($element['type'] ?? ''),
				'gemmaType' => (string)($element['gemmaType'] ?? ''),
			];
		}

		return array_slice($rows, 0, $limit);
	}//end searchElements()

	/**
	 * Write a view in status draft from elements and relations.
	 *
	 * Everything is validated before the first save. Elements are written
	 * first, then relations, then the view, each new object with the
	 * assistant mark in its own save; a refused save stops the draft there.
	 *
	 * @param string                           $name        The view name.
	 * @param string                           $description What the view shows.
	 * @param list<array<string, mixed>>       $elements    Each `{key, uuid}` or `{key, type, name}`.
	 * @param list<array<string, mixed>>       $relations   Each `{source, target, type}`, the ends as element keys.
	 *
	 * @return array{uuid: string, url: string, created: array{elements: int, relations: int}}
	 *
	 * @throws InvalidArgumentException When the draft is malformed or names an element the caller cannot read.
	 * @throws DraftRefusedException    When OpenRegister refuses a save under the caller's rights.
	 * @throws RuntimeException         When OpenRegister is not available.
	 *
	 * @spec openspec/changes/architecture-assistant-drafted-views/specs/architecture-assistant-views/spec.md#requirement-req-aav-002-stackiq-shall-offer-a-create-tool-that-writes-a-draft-view-from-elements-and-relations
	 * @spec openspec/changes/architecture-assistant-drafted-views/specs/architecture-assistant-views/spec.md#requirement-req-aav-003-every-object-the-draft-tool-writes-shall-carry-the-assistant-mark-in-the-same-write
	 */
	public function draftView(string $name, string $description, array $elements, array $relations): array {
		$this->validate(name: $name, elements: $elements, relations: $relations);

		$created = ['elements' => 0, 'relations' => 0];
		$refs = [];
		foreach ($elements as $element) {
			[$refs[(string)$element['key']], $isNew] = $this->resolveElement(element: $element);
			$created['elements'] += (int)$isNew;
		}

		$nodeIds = [];
		$nodes = [];
		foreach ($elements as $element) {
			$key = (string)$element['key'];
			$nodeIds[$key] = self::newIdentifier();
			$nodes[] = [
				'viewNodeId' => $nodeIds[$key],
				'parent' => null,
				'elementRef' => $refs[$key]['identifier'],
				'width' => self::NODE_SIZE['width'],
				'height' => self::NODE_SIZE['height'],
				'name' => $refs[$key]['name'],
				'type' => $refs[$key]['type'],
			];
		}

		$connections = [];
		foreach ($relations as $relation) {
			$source = $refs[(string)$relation['source']]['identifier'];
			$target = $refs[(string)$relation['target']]['identifier'];
			[$relationId, $isNew] = $this->ensureRelation(type: (string)$relation['type'], source: $source, target: $target);
			$created['relations'] += (int)$isNew;
			$connections[] = [
				'viewRelationshipId' => self::newIdentifier(),
				'modelRelationshipId' => $relationId,
				'sourceId' => $nodeIds[(string)$relation['source']],
				'targetId' => $nodeIds[(string)$relation['target']],
				'type' => (string)$relation['type'],
				'bendpoints' => [],
			];
		}

		$view = $this->save(schema: 'view', object: [
			'identifier' => self::newIdentifier(),
			'name' => $name,
			'documentation' => $description,
			'status' => 'draft',
			'origin' => self::ORIGIN,
			'draftedFor' => (string)($this->userSession->getUser()?->getUID() ?? ''),
			'draftedAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
			'tags' => [],
			'nodes' => $nodes,
			'connections' => $connections,
			'xml' => ['viewNodes' => $nodes, 'viewRelationships' => $connections],
		]);

		return ['uuid' => $view['uuid'], 'url' => '/apps/stackiq/views/' . $view['uuid'], 'created' => $created];
	}//end draftView()

	/**
	 * Refuse a malformed draft before anything is written.
	 *
	 * @param string                     $name      The view name.
	 * @param list<array<string, mixed>> $elements  The drafted elements.
	 * @param list<array<string, mixed>> $relations The drafted relations.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException Naming what is wrong.
	 */
	private function validate(string $name, array $elements, array $relations): void {
		if (trim($name) === '') {
			throw new InvalidArgumentException('A drafted view needs a name.');
		}

		if ($elements === [] || count($elements) > self::MAX_ELEMENTS) {
			throw new InvalidArgumentException('A drafted view holds 1 to ' . self::MAX_ELEMENTS . ' elements.');
		}

		if (count($relations) > self::MAX_RELATIONS) {
			throw new InvalidArgumentException('A drafted view holds at most ' . self::MAX_RELATIONS . ' relations.');
		}

		$keys = [];
		foreach ($elements as $index => $element) {
			$keys[] = $this->validElementKey(element: $element, index: (int)$index, seen: $keys);
		}

		foreach ($relations as $index => $relation) {
			$this->validateRelation(relation: $relation, index: (int)$index, keys: $keys);
		}
	}//end validate()

	/**
	 * The key of one drafted element, after checking its shape.
	 *
	 * @param mixed        $element The drafted element.
	 * @param int          $index   Its position.
	 * @param list<string> $seen    The keys before it.
	 *
	 * @return string The key.
	 *
	 * @throws InvalidArgumentException Naming what is wrong.
	 */
	private function validElementKey(mixed $element, int $index, array $seen): string {
		$key = null;
		if (is_array($element) === true) {
			$key = ($element['key'] ?? null);
		}

		if (is_string($key) === false || $key === '' || in_array($key, $seen, true) === true) {
			throw new InvalidArgumentException("Element {$index} needs a key of its own.");
		}

		$uuid = ($element['uuid'] ?? null);
		if (is_string($uuid) === true && $uuid !== '') {
			return $key;
		}

		$type = ($element['type'] ?? null);
		if (is_string($type) === false || in_array($type, self::ELEMENT_TYPES, true) === false) {
			throw new InvalidArgumentException("Element '{$key}' needs an existing uuid, or an ArchiMate element type and a name.");
		}

		if (is_string($element['name'] ?? null) === false || trim((string)$element['name']) === '') {
			throw new InvalidArgumentException("Element '{$key}' needs a name.");
		}

		return $key;
	}//end validElementKey()

	/**
	 * Check one drafted relation.
	 *
	 * @param mixed        $relation The drafted relation.
	 * @param int          $index    Its position.
	 * @param list<string> $keys     The element keys.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException Naming what is wrong.
	 */
	private function validateRelation(mixed $relation, int $index, array $keys): void {
		if (is_array($relation) === false || in_array(($relation['type'] ?? null), self::RELATION_TYPES, true) === false) {
			throw new InvalidArgumentException("Relation {$index} needs an ArchiMate relation type.");
		}

		foreach (['source', 'target'] as $end) {
			if (in_array(($relation[$end] ?? null), $keys, true) === false) {
				throw new InvalidArgumentException("Relation {$index} names a {$end} that is not one of the element keys.");
			}
		}
	}//end validateRelation()

	/**
	 * The element a drafted entry stands for: the named one, an existing one
	 * of the same type and exact name, or a new one with the assistant mark.
	 *
	 * @param array<string, mixed> $element The drafted element.
	 *
	 * @return array{0: array{identifier: string, name: string, type: string}, 1: bool} The element, and whether it was created.
	 *
	 * @throws InvalidArgumentException When a named element cannot be read by the caller.
	 */
	private function resolveElement(array $element): array {
		$uuid = (string)($element['uuid'] ?? '');
		if ($uuid !== '') {
			$found = $this->find(schema: 'element', uuid: $uuid);
			if ($found === null) {
				throw new InvalidArgumentException("Element '{$element['key']}' names an element that does not exist or that you cannot read.");
			}

			return [self::elementRef(element: $found), false];
		}

		$type = (string)$element['type'];
		$name = trim((string)$element['name']);
		foreach ($this->search(schema: 'element', filters: ['type' => $type, 'name' => $name, '_limit' => 5]) as $existing) {
			if (($existing['type'] ?? null) === $type && ($existing['name'] ?? null) === $name) {
				return [self::elementRef(element: $existing), false];
			}
		}

		$saved = $this->save(
			schema: 'element',
			object: ['identifier' => self::newIdentifier(), 'type' => $type, 'name' => $name, 'origin' => self::ORIGIN]
		);

		return [self::elementRef(element: $saved), true];
	}//end resolveElement()

	/**
	 * The relation of this type between these two elements: the existing one, or a new one with the assistant mark.
	 *
	 * @param string $type   The ArchiMate relation type.
	 * @param string $source The source element's identifier.
	 * @param string $target The target element's identifier.
	 *
	 * @return array{0: string, 1: bool} The relation identifier a connection stores, and whether it was created.
	 */
	private function ensureRelation(string $type, string $source, string $target): array {
		foreach ($this->search(schema: 'relation', filters: ['type' => $type, 'source' => $source, 'target' => $target, '_limit' => 5]) as $existing) {
			if (($existing['type'] ?? null) === $type && ($existing['source'] ?? null) === $source && ($existing['target'] ?? null) === $target) {
				return [self::identifierOf(object: $existing), false];
			}
		}

		$saved = $this->save(
			schema: 'relation',
			object: ['identifier' => self::newIdentifier(), 'type' => $type, 'source' => $source, 'target' => $target, 'origin' => self::ORIGIN]
		);

		return [self::identifierOf(object: $saved), true];
	}//end ensureRelation()

	/**
	 * The fields of an element a node and a relation refer to it by.
	 *
	 * @param array<string, mixed> $element The element.
	 *
	 * @return array{identifier: string, name: string, type: string}
	 */
	private static function elementRef(array $element): array {
		return [
			'identifier' => self::identifierOf(object: $element),
			'name' => (string)($element['name'] ?? ''),
			'type' => (string)($element['type'] ?? ''),
		];
	}//end elementRef()

	/**
	 * The ArchiMate identifier of an object, or its uuid when it has none, as the editor reads it.
	 *
	 * @param array<string, mixed> $object The object.
	 *
	 * @return string
	 */
	private static function identifierOf(array $object): string {
		$identifier = (string)($object['identifier'] ?? '');
		if ($identifier !== '') {
			return $identifier;
		}

		return (string)($object['uuid'] ?? '');
	}//end identifierOf()

	/**
	 * A fresh ArchiMate identifier, `id-<uuid>`, the form the editor and the import write.
	 *
	 * @return string
	 */
	private static function newIdentifier(): string {
		$bytes = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
		$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

		return 'id-' . vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
	}//end newIdentifier()

	/**
	 * Save one object with the caller's rights.
	 *
	 * @param string               $schema The schema slug.
	 * @param array<string, mixed> $object The object.
	 *
	 * @return array<string, mixed> The saved object, with its uuid.
	 *
	 * @throws DraftRefusedException When OpenRegister refuses the save.
	 */
	private function save(string $schema, array $object): array {
		try {
			$entity = $this->objectService()->saveObject(
				object: $object,
				options: new ObjectSaveOptions(target: new ObjectFindTarget(register: self::REGISTER, schema: $schema))
			);
		} catch (DraftRefusedException $e) {
			throw $e;
		} catch (Throwable $e) {
			if (self::isRefusal(error: $e) === true) {
				throw new DraftRefusedException(message: "You may not create a {$schema} here: " . $e->getMessage(), code: 0, previous: $e);
			}

			throw new RuntimeException("The {$schema} could not be saved: " . $e->getMessage(), 0, $e);
		}

		return self::rowOf(row: $entity);
	}//end save()

	/**
	 * Whether a save failed on the caller's rights.
	 *
	 * @param Throwable $error The failure.
	 *
	 * @return bool
	 */
	private static function isRefusal(Throwable $error): bool {
		$class = get_class($error);

		return str_ends_with($class, 'NotAuthorizedException') === true
			|| str_ends_with($class, 'AuthorizationBlockException') === true
			|| stripos($error->getMessage(), 'permission') !== false;
	}//end isRefusal()

	/**
	 * One object by uuid with the caller's rights, or null.
	 *
	 * @param string $schema The schema slug.
	 * @param string $uuid   The uuid.
	 *
	 * @return array<string, mixed>|null
	 */
	private function find(string $schema, string $uuid): ?array {
		try {
			$entity = $this->objectService()->find(id: $uuid, options: new ObjectFindOptions(register: self::REGISTER, schema: $schema));
		} catch (Throwable $e) {
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return self::rowOf(row: $entity);
	}//end find()

	/**
	 * Objects of one schema matching filters, with the caller's rights.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function search(string $schema, array $filters): array {
		$found = $this->objectService()->searchObjectsBySlug(registerSlug: self::REGISTER, schemaSlug: $schema, filters: $filters);
		if (is_array($found) === false) {
			return [];
		}

		return array_values(array_map(static fn (mixed $row): array => self::rowOf(row: $row), ($found['results'] ?? $found)));
	}//end search()

	/**
	 * An OpenRegister row as a plain array with its uuid.
	 *
	 * @param mixed $row An entity or an array.
	 *
	 * @return array<string, mixed>
	 */
	private static function rowOf(mixed $row): array {
		if (is_object($row) === true && method_exists($row, 'getObject') === true) {
			$data = (array)$row->getObject();
			$uuid = '';
			if (method_exists($row, 'getUuid') === true) {
				$uuid = (string)$row->getUuid();
			}

			return $data + ['uuid' => $uuid];
		}

		if (is_object($row) === true && $row instanceof \JsonSerializable) {
			$row = $row->jsonSerialize();
		}

		$data = (array)$row;
		$uuid = (string)($data['uuid'] ?? $data['id'] ?? ($data['@self']['id'] ?? ''));

		return array_merge($data, ['uuid' => $uuid]);
	}//end rowOf()

	/**
	 * OpenRegister's object service.
	 *
	 * @return ObjectServiceInterface
	 *
	 * @throws RuntimeException When OpenRegister is not installed.
	 */
	private function objectService(): ObjectServiceInterface {
		$objectService = $this->registers->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available.');
		}

		return $objectService;
	}//end objectService()
}//end class
