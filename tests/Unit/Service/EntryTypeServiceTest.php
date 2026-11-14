<?php

/**
 * Entry type service tests
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
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

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\EntryType\EntryReferences;
use OCA\Stackiq\Service\EntryType\EntryTypeRefusal;
use OCA\Stackiq\Service\EntryTypeService;
use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * A stored object, shaped like OpenRegister's ObjectEntity.
 */
class EntryTypeFakeEntity {

	public function __construct(private array $data, private string $owner = 'supplier') {
	}//end __construct()

	public function getObject(): array {
		return $this->data;
	}//end getObject()

	public function getOwner(): ?string {
		return $this->owner;
	}//end getOwner()
}//end class

/**
 * OpenRegister's ObjectService: find, save, search.
 */
class EntryTypeFakeObjects {

	/** @var array<int, array<string, array<string, mixed>>> Data by schema id and uuid. */
	public array $stored = [];

	/** @var array<string, int> Reference counts by "schemaId.field". */
	public array $referenceCounts = [];

	/** @var list<array<string, mixed>> */
	public array $saves = [];

	/** @var list<array<string, mixed>> */
	public array $searches = [];

	public function find(int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null, bool $_rbac = true, bool $_multitenancy = true): ?object {
		if (isset($this->stored[(int)$schema][(string)$id]) === false) {
			throw new RuntimeException('Object not found');
		}

		return new EntryTypeFakeEntity($this->stored[(int)$schema][(string)$id]);
	}//end find()

	public function saveObject(array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true, bool $silent = false, bool $_validation = true): array {
		$this->saves[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid, 'rbac' => $_rbac, 'validation' => $_validation];
		$this->stored[(int)$schema][(string)$uuid] = $object;
		return $object;
	}//end saveObject()

	public function searchObjectsPaginated(array $query = [], bool $_rbac = true, bool $_multitenancy = true): array {
		$this->searches[] = ['query' => $query, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
		$field = array_values(array_diff(array_keys($query), ['@self', '_limit']))[0];
		return ['total' => ($this->referenceCounts[$query['@self']['schema'] . '.' . $field] ?? 0), 'results' => []];
	}//end searchObjectsPaginated()
}//end class

/**
 * OpenRegister's Schema.
 */
class EntryTypeFakeSchema {

	public function __construct(public int $id, public string $slug, public array $properties) {
	}//end __construct()

	public function getId(): int {
		return $this->id;
	}//end getId()

	public function getSlug(): string {
		return $this->slug;
	}//end getSlug()

	public function getUuid(): string {
		return 'uuid-of-' . $this->slug;
	}//end getUuid()

	public function getProperties(): array {
		return $this->properties;
	}//end getProperties()
}//end class

/**
 * OpenRegister's SchemaMapper and RegisterMapper in one.
 */
class EntryTypeFakeMapper {

	public bool $broken = false;

	/** @param array<int, object> $rows */
	public function __construct(public array $rows) {
	}//end __construct()

	public function find(int|string $id): object {
		if ($this->broken === true || isset($this->rows[(int)$id]) === false) {
			throw new RuntimeException('not found: ' . $id);
		}

		return $this->rows[(int)$id];
	}//end find()
}//end class

/**
 * OpenRegister's Register.
 */
class EntryTypeFakeRegister {

	public function __construct(public array $schemas) {
	}//end __construct()

	public function getSchemas(): array {
		return $this->schemas;
	}//end getSchemas()
}//end class

/**
 * OpenRegister's PermissionHandlerInterface.
 */
class EntryTypeFakePermissions {

	/** @var array<string, bool> Allowed by "slug.action". */
	public array $allowed = ['module.update' => true, 'module.create' => true, 'catalogService.update' => true, 'catalogService.create' => true];

	/** @var list<string> */
	public array $asked = [];

	public function hasPermission(object $schema, string $action, ?string $userId = null, ?string $objectOwner = null, bool $_rbac = true, ?object $object = null, ?string $register = null): bool {
		$this->asked[] = $schema->getSlug() . '.' . $action . ($objectOwner !== null ? '@' . $objectOwner : '');
		return $this->allowed[$schema->getSlug() . '.' . $action] ?? false;
	}//end hasPermission()
}//end class

/**
 * OpenRegister's MoveObjectInterface.
 */
class EntryTypeFakeMove {

	/** @var list<array<string, mixed>> */
	public array $moves = [];

	public array $answer = ['moved' => true, 'errors' => []];

	public function move(object $object, object $sourceRegister, object $sourceSchema, object $targetRegister, object $targetSchema, string $actor): array {
		$this->moves[] = ['data' => $object->getObject(), 'from' => $sourceSchema->getSlug(), 'to' => $targetSchema->getSlug(), 'sameRegister' => $sourceRegister === $targetRegister, 'actor' => $actor];
		return $this->answer;
	}//end move()
}//end class

/**
 * Tests for EntryTypeService.
 *
 * @covers \OCA\Stackiq\Service\EntryTypeService
 * @covers \OCA\Stackiq\Service\EntryType\EntryReferences
 * @covers \OCA\Stackiq\Service\EntryType\EntryTypeRefusal
 * @covers \OCA\Stackiq\Service\EntryType\EntryShape
 */
class EntryTypeServiceTest extends TestCase {

	private const APP = 'aaaaaaaa-0000-0000-0000-000000000001';

	private const SERVICE = 'bbbbbbbb-0000-0000-0000-000000000002';

	private EntryTypeFakeObjects $objects;

	private EntryTypeFakeMapper $schemas;

	private EntryTypeFakePermissions $permissions;

	private EntryTypeFakeMove $mover;

	private bool $permissionsResolvable = true;

	protected function setUp(): void {
		$moduleRef = ['type' => 'object', '$ref' => '#/components/schemas/module'];
		$this->schemas = new EntryTypeFakeMapper(
			[
				1 => new EntryTypeFakeSchema(1, 'module', ['name' => [], 'shortDescription' => [], 'provider' => [], 'licence' => [], 'type' => ['type' => 'string'], 'diensten' => []]),
				2 => new EntryTypeFakeSchema(2, 'catalogService', ['name' => [], 'shortDescription' => [], 'provider' => [], 'modules' => [], 'type' => ['type' => 'array', 'items' => ['enum' => ['Training', 'Licence reseller']]]]),
				3 => new EntryTypeFakeSchema(3, 'usage', ['module' => $moduleRef, 'applications' => ['type' => 'array', 'items' => $moduleRef], 'diensten' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/catalogService']]]),
				4 => new EntryTypeFakeSchema(4, 'connection', ['moduleA' => $moduleRef, 'moduleB' => $moduleRef]),
				5 => new EntryTypeFakeSchema(5, 'catalogContract', ['service' => ['type' => 'object', '$ref' => '#/components/schemas/catalogService']]),
			]
		);
		$this->objects = new EntryTypeFakeObjects();
		$this->objects->stored[1][self::APP] = ['name' => 'Hosting en beheer', 'shortDescription' => 'Beheer', 'provider' => 'org-1', 'licence' => 'EUPL', 'type' => 'Application', 'diensten' => []];
		$this->objects->stored[2][self::SERVICE] = ['name' => 'Training', 'provider' => 'org-1', 'modules' => ['m-1'], 'type' => ['Training']];
		$this->permissions = new EntryTypeFakePermissions();
		$this->mover = new EntryTypeFakeMove();

	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return EntryTypeService
	 */
	private function service(): EntryTypeService {
		$catalogue = $this->createMock(ObjectTypeLocatorInterface::class);
		$catalogue->method('getRegisterIdForObjectType')->willReturn(10);
		$catalogue->method('getSchemaIdForObjectType')->willReturnCallback(
			static fn (string $objectType): ?int => ['module' => 1, 'catalogService' => 2][$objectType] ?? null
		);

		$registers = new EntryTypeFakeMapper([10 => new EntryTypeFakeRegister([1, 2, 3, 4, 5])]);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id): object => match ($id) {
				EntryTypeService::OBJECTS => $this->objects,
				EntryReferences::SCHEMA_MAPPER => $this->schemas,
				EntryReferences::REGISTER_MAPPER => $registers,
				EntryTypeService::MOVE => $this->mover,
				EntryTypeService::PERMISSIONS => $this->permissionsResolvable ? $this->permissions : throw new RuntimeException('no permission handler'),
				default => throw new RuntimeException('not registered: ' . $id),
			}
		);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(['openregister']);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('supplier');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new EntryTypeService($catalogue, $apps, new EntryReferences($container, new NullLogger()), $container, $session, new NullLogger());
	}//end service()

	/**
	 * The refusal a call throws.
	 *
	 * @param callable $call The call.
	 *
	 * @return EntryTypeRefusal
	 */
	private function refusalOf(callable $call): EntryTypeRefusal {
		try {
			$call();
		} catch (EntryTypeRefusal $refusal) {
			return $refusal;
		}

		$this->fail('the change was not refused');
	}//end refusalOf()

	public function testAnApplicationWithoutReferencesBecomesAServiceWithTheSameUuid(): void {
		$result = $this->service()->change(uuid: self::APP, targetType: 'service', serviceTypes: ['Training']);

		$this->assertSame(['uuid' => self::APP, 'targetType' => 'service', 'moved' => true, 'errors' => []], $result);
		$this->assertCount(1, $this->objects->saves, 'one write before the move, none after');
		$this->assertSame(
			['name' => 'Hosting en beheer', 'shortDescription' => 'Beheer', 'provider' => 'org-1', 'type' => ['Training']],
			$this->objects->saves[0]['object']
		);
		$this->assertSame([10, 1, self::APP, true, false], [$this->objects->saves[0]['register'], $this->objects->saves[0]['schema'], $this->objects->saves[0]['uuid'], $this->objects->saves[0]['rbac'], $this->objects->saves[0]['validation']]);
		$this->assertCount(1, $this->mover->moves);
		$this->assertSame(['module', 'catalogService', true, 'supplier'], [$this->mover->moves[0]['from'], $this->mover->moves[0]['to'], $this->mover->moves[0]['sameRegister'], $this->mover->moves[0]['actor']]);
		$this->assertSame(['type' => ['Training']], array_intersect_key($this->mover->moves[0]['data'], ['type' => true]), 'the move gets the object as it is stored after the write');
		$this->assertSame(['module.update@supplier', 'catalogService.create'], $this->permissions->asked);
	}//end testAnApplicationWithoutReferencesBecomesAServiceWithTheSameUuid()

	public function testAnApplicationWithAUsageIsRefusedAndNothingMoves(): void {
		$this->objects->referenceCounts['3.module'] = 1;

		$preview = $this->service()->preview(uuid: self::APP, targetType: 'service');
		$this->assertSame([['schema' => 'usage', 'field' => 'module', 'count' => 1]], $preview['blockers']);

		$refusal = $this->refusalOf(fn () => $this->service()->change(uuid: self::APP, targetType: 'service', serviceTypes: ['Training']));
		$this->assertSame(409, $refusal->status);
		$this->assertSame([['schema' => 'usage', 'field' => 'module', 'count' => 1]], $refusal->blockers);
		$this->assertSame([], $this->objects->saves);
		$this->assertSame([], $this->mover->moves);
	}//end testAnApplicationWithAUsageIsRefusedAndNothingMoves()

	public function testReferencesAreCountedAsTheSystemOnEverySingleReferenceField(): void {
		$this->service()->preview(uuid: self::APP, targetType: 'service');

		$fields = array_map(
			static fn (array $search): string => $search['query']['@self']['schema'] . '.' . array_values(array_diff(array_keys($search['query']), ['@self', '_limit']))[0],
			$this->objects->searches
		);
		$this->assertSame(['3.module', '4.moduleA', '4.moduleB'], $fields, 'array references and references to other schemas are not counted');
		$this->assertFalse($this->objects->searches[0]['rbac']);
		$this->assertFalse($this->objects->searches[0]['multitenancy']);
		$this->assertSame(self::APP, $this->objects->searches[0]['query']['module']);
	}//end testReferencesAreCountedAsTheSystemOnEverySingleReferenceField()

	public function testThePreviewNamesTheDroppedLicenceWithItsValue(): void {
		$preview = $this->service()->preview(uuid: self::APP, targetType: 'service');

		$this->assertSame('application', $preview['currentType']);
		$this->assertSame(['name', 'shortDescription', 'provider'], $preview['carried']);
		$this->assertSame(['licence' => 'EUPL', 'type' => 'Application'], $preview['dropped']);
		$this->assertSame(['Training', 'Licence reseller'], $preview['serviceTypes']);
		$this->assertSame([], $this->objects->saves, 'a preview writes nothing');
	}//end testThePreviewNamesTheDroppedLicenceWithItsValue()

	public function testAServiceNeedsAValidServiceType(): void {
		$this->assertSame(422, $this->refusalOf(fn () => $this->service()->change(uuid: self::APP, targetType: 'service'))->status);
		$this->assertSame(422, $this->refusalOf(fn () => $this->service()->change(uuid: self::APP, targetType: 'service', serviceTypes: ['Hosting']))->status);
		$this->assertSame([], $this->objects->saves);
		$this->assertSame([], $this->mover->moves);
	}//end testAServiceNeedsAValidServiceType()

	public function testACallerWhoMayNotCreateAServiceIsRefused(): void {
		$this->permissions->allowed['catalogService.create'] = false;

		$this->assertSame(403, $this->refusalOf(fn () => $this->service()->change(uuid: self::APP, targetType: 'service', serviceTypes: ['Training']))->status);
		$this->assertSame([], $this->objects->saves);
		$this->assertSame([], $this->mover->moves);
	}//end testACallerWhoMayNotCreateAServiceIsRefused()

	public function testAPermissionCheckThatCannotRunRefuses(): void {
		$this->permissionsResolvable = false;

		$this->assertSame(403, $this->refusalOf(fn () => $this->service()->change(uuid: self::APP, targetType: 'systemSoftware'))->status);
		$this->assertSame([], $this->objects->saves);
	}//end testAPermissionCheckThatCannotRunRefuses()

	public function testAMoveOpenRegisterRefusesRestoresTheOriginalData(): void {
		$original = $this->objects->stored[1][self::APP];
		$this->mover->answer = ['moved' => false, 'errors' => ['The object does not fit the target schema.']];

		$refusal = $this->refusalOf(fn () => $this->service()->change(uuid: self::APP, targetType: 'service', serviceTypes: ['Training']));

		$this->assertSame(422, $refusal->status);
		$this->assertSame('The object does not fit the target schema.', $refusal->getMessage());
		$this->assertCount(2, $this->objects->saves);
		$this->assertSame($original, $this->objects->saves[1]['object']);
		$this->assertSame($original, $this->objects->stored[1][self::APP]);
	}//end testAMoveOpenRegisterRefusesRestoresTheOriginalData()

	public function testAnApplicationBecomesSystemSoftwareByItsTypeField(): void {
		$preview = $this->service()->preview(uuid: self::APP, targetType: 'systemSoftware');
		$this->assertSame([], $preview['dropped']);
		$this->assertSame([], $preview['blockers']);

		$result = $this->service()->change(uuid: self::APP, targetType: 'systemSoftware');

		$this->assertFalse($result['moved']);
		$this->assertSame([], $this->mover->moves);
		$this->assertSame([], $this->objects->searches, 'staying in the module schema breaks no reference');
		$this->assertSame('System software', $this->objects->saves[0]['object']['type']);
		$this->assertSame('EUPL', $this->objects->saves[0]['object']['licence']);
		$this->assertTrue($this->objects->saves[0]['validation']);
		$this->assertSame(['module.update@supplier', 'module.create'], $this->permissions->asked);
	}//end testAnApplicationBecomesSystemSoftwareByItsTypeField()

	public function testSystemSoftwareGoesBackToApplication(): void {
		$this->objects->stored[1][self::APP]['type'] = 'System software';

		$this->assertSame('systemSoftware', $this->service()->preview(uuid: self::APP, targetType: 'application')['currentType']);
		$this->service()->change(uuid: self::APP, targetType: 'application');

		$this->assertSame('Application', $this->objects->saves[0]['object']['type']);
	}//end testSystemSoftwareGoesBackToApplication()

	public function testAServiceBecomesAnApplicationAndDropsItsServiceFields(): void {
		$preview = $this->service()->preview(uuid: self::SERVICE, targetType: 'application');
		$this->assertSame(['modules' => ['m-1'], 'type' => ['Training']], $preview['dropped']);

		$this->service()->change(uuid: self::SERVICE, targetType: 'application');

		$this->assertSame(['name' => 'Training', 'provider' => 'org-1', 'type' => 'Application'], $this->objects->saves[0]['object']);
		$this->assertSame(['catalogService', 'module'], [$this->mover->moves[0]['from'], $this->mover->moves[0]['to']]);
		$this->assertSame([5], array_values(array_unique(array_map(static fn (array $s): int => $s['query']['@self']['schema'], $this->objects->searches))), 'only the contract reference names a service');
		$this->assertSame(self::SERVICE, $this->objects->searches[0]['query']['service']);
	}//end testAServiceBecomesAnApplicationAndDropsItsServiceFields()

	public function testAChangeThatIsNotOfferedIsRefused(): void {
		$this->assertSame(422, $this->refusalOf(fn () => $this->service()->preview(uuid: self::SERVICE, targetType: 'systemSoftware'))->status);
		$this->assertSame(422, $this->refusalOf(fn () => $this->service()->preview(uuid: self::APP, targetType: 'application'))->status);
	}//end testAChangeThatIsNotOfferedIsRefused()

	public function testAnUnknownEntryIsNotFound(): void {
		$this->assertSame(404, $this->refusalOf(fn () => $this->service()->preview(uuid: 'missing', targetType: 'service'))->status);
	}//end testAnUnknownEntryIsNotFound()

	public function testReferencesThatCannotBeScannedBlockTheChange(): void {
		unset($this->schemas->rows[3]);
		$refusal = $this->refusalOf(fn () => $this->service()->change(uuid: self::APP, targetType: 'service', serviceTypes: ['Training']));

		$this->assertSame(409, $refusal->status);
		$this->assertSame([['schema' => '', 'field' => '', 'count' => -1]], $refusal->blockers);
		$this->assertSame([], $this->mover->moves);
	}//end testReferencesThatCannotBeScannedBlockTheChange()
}//end class
