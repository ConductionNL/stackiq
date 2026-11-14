<?php

/**
 * Entry type service
 *
 * Changes a catalogue entry between application, service and system
 * software without recreating it. Application and service are separate
 * schemas, so that change is OpenRegister's move, which keeps the uuid and
 * everything keyed on it (history, files, notes, relations). Application and
 * system software share the module schema, so that change is its `type` field.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
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

namespace OCA\Stackiq\Service;

use OCA\Stackiq\Service\EntryType\EntryReferences;
use OCA\Stackiq\Service\EntryType\EntryShape;
use OCA\Stackiq\Service\EntryType\EntryTypeRefusal;
use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;
use OCP\App\IAppManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Previews and performs the type change of one entry.
 *
 * @spec openspec/changes/landscape-change-entry-type/specs/entry-type-change/spec.md#requirement-req-etc-001-an-entry-changes-type-and-keeps-its-identity
 */
class EntryTypeService {

	public const APPLICATION = 'application';

	public const SERVICE = 'service';

	public const SYSTEM_SOFTWARE = 'systemSoftware';

	/**
	 * The changes offered, from each type.
	 *
	 * @var array<string, array<int, string>>
	 */
	public const TRANSITIONS = [
		self::APPLICATION => [self::SERVICE, self::SYSTEM_SOFTWARE],
		self::SERVICE => [self::APPLICATION],
		self::SYSTEM_SOFTWARE => [self::APPLICATION],
	];

	/**
	 * The schema each type lives in.
	 *
	 * @var array<string, string>
	 */
	public const SCHEMA_OF = [
		self::APPLICATION => 'module',
		self::SYSTEM_SOFTWARE => 'module',
		self::SERVICE => 'catalogService',
	];

	/**
	 * The `module.type` value of the two module types.
	 *
	 * @var array<string, string>
	 */
	public const MODULE_TYPE = [
		self::APPLICATION => 'Application',
		self::SYSTEM_SOFTWARE => 'System software',
	];

	/**
	 * Fields both schemas declare with a different meaning; never carried.
	 *
	 * `module.type` is Application or System software, `catalogService.type`
	 * is the list of service types.
	 *
	 * @var array<int, string>
	 */
	private const NOT_CARRIED = ['type'];

	/**
	 * OpenRegister's move.
	 *
	 * @var string
	 */
	public const MOVE = 'OCA\OpenRegister\Service\Object\MoveObjectInterface';

	/**
	 * OpenRegister's object service.
	 *
	 * @var string
	 */
	public const OBJECTS = 'OCA\OpenRegister\Service\ObjectService';

	/**
	 * OpenRegister's permission check.
	 *
	 * @var string
	 */
	public const PERMISSIONS = 'OCA\OpenRegister\Service\Object\PermissionHandlerInterface';

	/**
	 * Pure readings of an entry's type and fields.
	 *
	 * @var EntryShape
	 */
	private readonly EntryShape $shape;

	/**
	 * Constructor.
	 *
	 * @param ObjectTypeLocatorInterface $catalogue The catalogue register and schema ids.
	 * @param IAppManager $appManager Whether OpenRegister is installed.
	 * @param EntryReferences $references The references that block a change.
	 * @param ContainerInterface $container Resolves OpenRegister's mappers, move and permission check.
	 * @param IUserSession $userSession The caller, who must be allowed on both sides.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ObjectTypeLocatorInterface $catalogue,
		private readonly IAppManager $appManager,
		private readonly EntryReferences $references,
		private readonly ContainerInterface $container,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		$this->shape = new EntryShape();

	}//end __construct()

	/**
	 * What a change to the target type keeps, drops and is blocked by. Writes nothing.
	 *
	 * @param string $uuid The entry.
	 * @param string $targetType One of the type constants.
	 *
	 * @return array<string, mixed> uuid, currentType, targetType, carried (field names), dropped (field => value),
	 *                              blockers ({schema, field, count}) and serviceTypes (the values a service may take).
	 *
	 * @throws EntryTypeRefusal 404 when the entry is not found, 422 for a change that is not offered.
	 *
	 * @spec openspec/changes/landscape-change-entry-type/specs/entry-type-change/spec.md#requirement-req-etc-002-the-user-sees-what-carries-over-before-confirming
	 */
	public function preview(string $uuid, string $targetType): array {
		return $this->context(uuid: $uuid, targetType: $targetType)['preview'];

	}//end preview()

	/**
	 * Change the entry's type, keeping its uuid and everything keyed on it.
	 *
	 * @param string $uuid The entry.
	 * @param string $targetType One of the type constants.
	 * @param array<int, string> $serviceTypes The service types, required when the target is a service.
	 *
	 * @return array{uuid: string, targetType: string, moved: bool, errors: array<int, string>}
	 *
	 * @throws EntryTypeRefusal 403, 404, 409 or 422.
	 *
	 * @spec openspec/changes/landscape-change-entry-type/specs/entry-type-change/spec.md#requirement-req-etc-001-an-entry-changes-type-and-keeps-its-identity
	 */
	public function change(string $uuid, string $targetType, array $serviceTypes=[]): array {
		$context = $this->context(uuid: $uuid, targetType: $targetType);
		$preview = $context['preview'];
		if ($preview['blockers'] !== []) {
			throw new EntryTypeRefusal(
				message: 'Other entries still point at this one, so its type cannot change.',
				status: 409,
				blockers: $preview['blockers']
			);
		}

		$this->authorise(context: $context);

		if ($context['sourceSchemaId'] === $context['targetSchemaId']) {
			return $this->retype(context: $context);
		}

		$overrides = $this->targetOverrides(targetType: $targetType, serviceTypes: $serviceTypes, allowed: $preview['serviceTypes']);
		return $this->move(context: $context, overrides: $overrides);

	}//end change()

	/**
	 * The entry, its types and schemas, and the preview.
	 *
	 * @param string $uuid The entry.
	 * @param string $targetType The target type.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws EntryTypeRefusal 404 or 422.
	 */
	private function context(string $uuid, string $targetType): array {
		$objects = $this->objects();
		$registerId = $this->catalogue->getRegisterIdForObjectType(objectType: 'module');
		$found = $this->locate(objects: $objects, registerId: $registerId, uuid: $uuid);

		$currentType = $found['type'];
		if (in_array($targetType, (self::TRANSITIONS[$currentType] ?? []), true) === false) {
			throw new EntryTypeRefusal(message: 'This entry cannot change into that type.', status: 422);
		}

		$targetSchemaId = $this->schemaId(type: $targetType);
		$data = (array)$found['entity']->getObject();
		$fields = $this->fields(sourceSchemaId: $found['schemaId'], targetSchemaId: $targetSchemaId, data: $data);

		return [
			'objects' => $objects,
			'entity' => $found['entity'],
			'registerId' => (int)$registerId,
			'sourceSchemaId' => $found['schemaId'],
			'targetSchemaId' => $targetSchemaId,
			'targetType' => $targetType,
			'data' => $data,
			'preview' => [
				'uuid' => $uuid,
				'currentType' => $currentType,
				'targetType' => $targetType,
				'carried' => $fields['carried'],
				'dropped' => $fields['dropped'],
				'blockers' => $this->blockersFor(
					objects: $objects,
					registerId: (int)$registerId,
					schemaId: $found['schemaId'],
					uuid: $uuid,
					sameSchema: $found['schemaId'] === $targetSchemaId
				),
				'serviceTypes' => $fields['serviceTypes'],
			],
		];

	}//end context()

	/**
	 * The references that block the change; none when the entry stays in its schema.
	 *
	 * @param object $objects OpenRegister's ObjectService.
	 * @param int $registerId The register.
	 * @param int $schemaId The entry's schema.
	 * @param string $uuid The entry.
	 * @param bool $sameSchema Whether the change keeps the schema.
	 *
	 * @return array<int, array{schema: string, field: string, count: int}>
	 */
	private function blockersFor(object $objects, int $registerId, int $schemaId, string $uuid, bool $sameSchema): array {
		if ($sameSchema === true) {
			return [];
		}

		return $this->references->blockers(objects: $objects, registerId: $registerId, schemaId: $schemaId, uuid: $uuid);

	}//end blockersFor()

	/**
	 * The entry, read with the caller's own rights, its schema and its type.
	 *
	 * @param object $objects OpenRegister's ObjectService.
	 * @param int|null $registerId The register.
	 * @param string $uuid The entry.
	 *
	 * @return array{entity: object, schemaId: int, type: string}
	 *
	 * @throws EntryTypeRefusal 404 when no application or service has the uuid.
	 */
	private function locate(object $objects, ?int $registerId, string $uuid): array {
		foreach (['module', 'catalogService'] as $objectType) {
			$schemaId = $this->catalogue->getSchemaIdForObjectType(objectType: $objectType);
			if ($registerId === null || $schemaId === null) {
				continue;
			}

			try {
				$entity = $objects->find(id: $uuid, register: $registerId, schema: $schemaId);
			} catch (Throwable $e) {
				$entity = null;
			}

			if ($entity !== null) {
				$type = $this->shape->typeOf(objectType: $objectType, data: (array)$entity->getObject());
				return ['entity' => $entity, 'schemaId' => (int)$schemaId, 'type' => $type];
			}
		}

		throw new EntryTypeRefusal(message: 'Entry not found.', status: 404);

	}//end locate()

	/**
	 * The schema id of a type.
	 *
	 * @param string $type The type.
	 *
	 * @return int
	 *
	 * @throws EntryTypeRefusal 422 when the catalogue does not configure it.
	 */
	private function schemaId(string $type): int {
		$schemaId = $this->catalogue->getSchemaIdForObjectType(objectType: self::SCHEMA_OF[$type]);
		if ($schemaId === null) {
			throw new EntryTypeRefusal(message: 'The catalogue has no schema for that type.', status: 422);
		}

		return (int)$schemaId;

	}//end schemaId()

	/**
	 * Which fields carry over, which filled ones are dropped with their values, and the service types on offer.
	 *
	 * @param int $sourceSchemaId The entry's schema.
	 * @param int $targetSchemaId The target schema.
	 * @param array<string, mixed> $data The entry's data.
	 *
	 * @return array{carried: array<int, string>, dropped: array<string, mixed>, serviceTypes: array<int, string>}
	 *
	 * @spec openspec/changes/landscape-change-entry-type/specs/entry-type-change/spec.md#requirement-req-etc-002-the-user-sees-what-carries-over-before-confirming
	 */
	public function fields(int $sourceSchemaId, int $targetSchemaId, array $data): array {
		$mapper = $this->container->get(EntryReferences::SCHEMA_MAPPER);
		$source = (array)$mapper->find($sourceSchemaId)->getProperties();
		$target = (array)$mapper->find($targetSchemaId)->getProperties();

		if ($sourceSchemaId === $targetSchemaId) {
			return ['carried' => array_values(array_diff(array_keys($source), self::NOT_CARRIED)), 'dropped' => [], 'serviceTypes' => []];
		}

		$carried = array_values(array_diff(array_keys(array_intersect_key($source, $target)), self::NOT_CARRIED));
		$dropped = [];
		foreach ($data as $field => $value) {
			$isOwnField = in_array($field, $carried, true) === false && str_starts_with((string)$field, '@') === false;
			if ($isOwnField === true && $this->shape->isFilled(value: $value) === true) {
				$dropped[$field] = $value;
			}
		}

		return ['carried' => $carried, 'dropped' => $dropped, 'serviceTypes' => $this->shape->serviceTypesOf(properties: $target)];

	}//end fields()

	/**
	 * Refuse unless the caller may update the entry and create in the target schema,
	 * the rights OpenRegister's own move endpoint asks. Fails closed.
	 *
	 * @param array<string, mixed> $context The change.
	 *
	 * @return void
	 *
	 * @throws EntryTypeRefusal 403.
	 */
	private function authorise(array $context): void {
		$user = $this->userSession->getUser();
		try {
			$permissions = $this->container->get(self::PERMISSIONS);
			$schemas = $this->container->get(EntryReferences::SCHEMA_MAPPER);
			$entity = $context['entity'];
			$allowed = $user !== null
				&& $permissions->hasPermission(
					schema: $schemas->find($context['sourceSchemaId']),
					action: 'update',
					objectOwner: $entity->getOwner(),
					object: $entity
				) === true
				&& $permissions->hasPermission(schema: $schemas->find($context['targetSchemaId']), action: 'create') === true;
		} catch (Throwable $e) {
			$this->logger->warning('EntryTypeService: the permission check failed', ['exception' => $e->getMessage()]);
			$allowed = false;
		}

		if ($allowed === false) {
			throw new EntryTypeRefusal(message: 'You may not change the type of this entry.', status: 403);
		}

	}//end authorise()

	/**
	 * Switch an application and system software by the module's type field.
	 *
	 * @param array<string, mixed> $context The change.
	 *
	 * @return array{uuid: string, targetType: string, moved: bool, errors: array<int, string>}
	 */
	private function retype(array $context): array {
		$data = $context['data'];
		$data['type'] = self::MODULE_TYPE[$context['targetType']];
		$context['objects']->saveObject(
			object: $data,
			register: $context['registerId'],
			schema: $context['sourceSchemaId'],
			uuid: $context['preview']['uuid']
		);

		return ['uuid' => $context['preview']['uuid'], 'targetType' => $context['targetType'], 'moved' => false, 'errors' => []];

	}//end retype()

	/**
	 * The fields the target sets that the source cannot carry.
	 *
	 * @param string $targetType The target type.
	 * @param array<int, mixed> $serviceTypes The service types asked for.
	 * @param array<int, string> $allowed The service types the schema allows.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws EntryTypeRefusal 422 when a service is asked without valid service types.
	 */
	private function targetOverrides(string $targetType, array $serviceTypes, array $allowed): array {
		if ($targetType !== self::SERVICE) {
			return ['type' => self::MODULE_TYPE[self::APPLICATION]];
		}

		$types = array_values(array_unique(array_filter(array_map('strval', $serviceTypes), static fn (string $type): bool => $type !== '')));
		if ($types === [] || array_diff($types, $allowed) !== []) {
			throw new EntryTypeRefusal(message: 'Name the service type of the new service.', status: 422);
		}

		return ['type' => $types];

	}//end targetOverrides()

	/**
	 * Clear what the target cannot hold, then move the object with OpenRegister.
	 *
	 * The entry is saved with only the carried fields first, without validation
	 * against its current schema, because it is about to have the target's
	 * shape. When the move fails the original data is written back.
	 *
	 * @param array<string, mixed> $context The change.
	 * @param array<string, mixed> $overrides The target's own fields.
	 *
	 * @return array{uuid: string, targetType: string, moved: bool, errors: array<int, string>}
	 *
	 * @throws EntryTypeRefusal 422 when OpenRegister refuses the move.
	 */
	private function move(array $context, array $overrides): array {
		$objects = $context['objects'];
		$uuid = $context['preview']['uuid'];
		$prepared = array_merge(array_intersect_key($context['data'], array_flip($context['preview']['carried'])), $overrides);

		$this->write(objects: $objects, context: $context, data: $prepared);
		try {
			$stored = $objects->find(id: $uuid, register: $context['registerId'], schema: $context['sourceSchemaId']);
			$outcome = $this->moveWithOpenRegister(context: $context, object: $stored);
		} catch (Throwable $e) {
			$this->logger->error('EntryTypeService: the move failed', ['uuid' => $uuid, 'exception' => $e->getMessage()]);
			$outcome = ['moved' => false, 'errors' => ['The move could not be performed.']];
		}

		if (($outcome['moved'] ?? false) !== true) {
			$this->write(objects: $objects, context: $context, data: $context['data']);
			throw new EntryTypeRefusal(message: implode(' ', (array)($outcome['errors'] ?? ['The entry was not moved.'])), status: 422);
		}

		return ['uuid' => $uuid, 'targetType' => $context['targetType'], 'moved' => true, 'errors' => array_values((array)($outcome['errors'] ?? []))];

	}//end move()

	/**
	 * Write the entry's data at its current address, unvalidated.
	 *
	 * @param object $objects OpenRegister's ObjectService.
	 * @param array<string, mixed> $context The change.
	 * @param array<string, mixed> $data The data.
	 *
	 * @return void
	 */
	private function write(object $objects, array $context, array $data): void {
		$objects->saveObject(
			object: $data,
			register: $context['registerId'],
			schema: $context['sourceSchemaId'],
			uuid: $context['preview']['uuid'],
			_validation: false
		);

	}//end write()

	/**
	 * Call OpenRegister's move for the entry.
	 *
	 * @param array<string, mixed> $context The change.
	 * @param object|null $object The entry as stored now.
	 *
	 * @return array<string, mixed> OpenRegister's answer.
	 */
	private function moveWithOpenRegister(array $context, ?object $object): array {
		$registers = $this->container->get(EntryReferences::REGISTER_MAPPER);
		$schemas = $this->container->get(EntryReferences::SCHEMA_MAPPER);
		$register = $registers->find($context['registerId']);

		return $this->container->get(self::MOVE)->move(
			object: $object,
			sourceRegister: $register,
			sourceSchema: $schemas->find($context['sourceSchemaId']),
			targetRegister: $register,
			targetSchema: $schemas->find($context['targetSchemaId']),
			actor: (string)$this->userSession->getUser()?->getUID(),
		);

	}//end moveWithOpenRegister()

	/**
	 * OpenRegister's ObjectService.
	 *
	 * @return object
	 *
	 * @throws EntryTypeRefusal 503 when OpenRegister is not available.
	 */
	private function objects(): object {
		try {
			$objects = null;
			if (in_array('openregister', $this->appManager->getInstalledApps(), true) === true) {
				$objects = $this->container->get(self::OBJECTS);
			}
		} catch (Throwable $e) {
			$objects = null;
		}

		if (is_object($objects) === false) {
			throw new EntryTypeRefusal(message: 'OpenRegister is not available.', status: 503);
		}

		return $objects;

	}//end objects()
}//end class
