<?php

/**
 * Tests for the CMDB export import service.
 *
 * OpenRegister is an in-memory double of `ObjectServiceInterface` that
 * applies the search filters the service sends the way OpenRegister does: a
 * filter on a property the schema (register plus fragments) does not declare
 * matches nothing. Every call must pass `_rbac: false` and
 * `_multitenancy: false`, checked after every test; the mapping runs through
 * OpenRegister's real `MappingEngine` (or its verbatim test copy), progress
 * through the real `ProgressTracker` on an in-memory cache. The fixture
 * tests read the sanitised export through PhpSpreadsheet and are skipped
 * when no OpenRegister vendor directory is available; the other tests feed
 * rows directly.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/cmdb-export-import/tasks.md#task-5
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

require_once __DIR__ . '/../Support/CmdbTestSupport.php';
require_once __DIR__ . '/../Support/FakeMigrationPackService.php';

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Exception\CmdbImportException;
use OCA\Stackiq\Service\Cmdb\CmdbImportPackMarker;
use OCA\Stackiq\Service\Cmdb\CmdbImportPackStore;
use OCA\Stackiq\Service\Cmdb\CmdbImportProfile;
use OCA\Stackiq\Service\Cmdb\CmdbOwnerIdentity;
use OCA\Stackiq\Service\Cmdb\CmdbRowNormaliser;
use OCA\Stackiq\Service\Cmdb\CmdbWorkbookReader;
use OCA\Stackiq\Service\CmdbExportImportService;
use OCA\Stackiq\Service\ProgressTracker;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\StackiqContactSyncService;
use OCA\Stackiq\Tests\Unit\Support\CmdbTestSupport;
use OCA\Stackiq\Tests\Unit\Support\FakeMigrationPackService;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUserSession;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The import, row by row, against an in-memory OpenRegister.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class CmdbExportImportServiceTest extends TestCase {
	private const REGISTER = 20;
	private const MODULE = 43;
	private const ORGANIZATION = 33;
	private const USAGE = 34;
	private const CONTACT_PERSON = 32;

	/**
	 * Objects per schema id, by uuid.
	 *
	 * @var array<int, array<string, array<string, mixed>>>
	 */
	private array $store = [];

	/**
	 * Every saveObject() call: schema, uuid, data, create.
	 *
	 * @var array<int, array{schema: int, uuid: string, data: array<string, mixed>, create: bool}>
	 */
	private array $saves = [];

	/**
	 * Called before every save; may throw.
	 *
	 * @var callable|null
	 */
	private $beforeSave = null;

	/**
	 * The archive and trash markers per schema id and uuid, as OpenRegister keeps them in `@self`.
	 *
	 * @var array<int, array<string, array{archived?: array<string, mixed>|null, deleted?: array<string, mixed>|null}>>
	 */
	private array $states = [];

	/**
	 * Every archive, unarchive, restore and delete: verb, schema id, uuid, reason.
	 *
	 * @var array<int, array{0: string, 1: int, 2: string, 3: string|null}>
	 */
	private array $transitions = [];

	/**
	 * Called before every archive, unarchive, restore and delete with the verb and the uuid; may throw.
	 *
	 * @var callable|null
	 */
	private $beforeTransition = null;

	/**
	 * How often the trash listing was read.
	 *
	 * @var int
	 */
	private int $trashReads = 0;

	/**
	 * The uuid whose delete OpenRegister refuses (deleteObject() answers false), when set.
	 *
	 * @var string|null
	 */
	private ?string $deleteRefusedFor = null;

	/**
	 * How many of the next trash reads throw.
	 *
	 * @var int
	 */
	private int $trashFailures = 0;

	/**
	 * Whether the container knows OpenRegister's archive handler and object mapper.
	 *
	 * @var bool
	 */
	private bool $archiveServicesAvailable = true;

	/**
	 * Contacts in the fake address book: uid => name, email.
	 *
	 * `book` is the address book's URI; a contact without one is in the admin's personal address book.
	 *
	 * @var array<string, array{name: string, email: string, book?: string}>
	 */
	private array $contacts = [];

	/**
	 * The address books new contacts went into: uri => display name.
	 *
	 * @var array<string, string>
	 */
	private array $addressBooks = [];

	/**
	 * Whether Contacts is enabled.
	 *
	 * @var bool
	 */
	private bool $contactsEnabled = true;

	/**
	 * The in-memory distributed cache behind the ProgressTracker.
	 *
	 * @var array<string, mixed>
	 */
	private array $cache = [];

	/**
	 * Thrown by the next write to the cache, once.
	 *
	 * @var \Throwable|null
	 */
	private ?\Throwable $cacheFailure = null;

	/**
	 * Every log line, message plus encoded context.
	 *
	 * @var array<int, string>
	 */
	private array $logLines = [];

	/**
	 * The ProgressTracker of the current service.
	 *
	 * @var ProgressTracker|null
	 */
	private ?ProgressTracker $tracker = null;

	/**
	 * Declared properties per schema id, as the merged register ships them.
	 *
	 * @var array<int, array<int, string>>|null
	 */
	private static ?array $declared = null;

	/**
	 * Property definitions per schema id, as the merged register ships them.
	 *
	 * @var array<int, array<string, array<string, mixed>>>|null
	 */
	private static ?array $definitions = null;

	/**
	 * Property definitions replaced for one test, per schema id and property.
	 *
	 * @var array<int, array<string, array<string, mixed>>>
	 */
	private array $redefined = [];

	/**
	 * Properties taken out of a schema for one test, per schema id.
	 *
	 * @var array<int, array<int, string>>
	 */
	private array $undeclared = [];

	/**
	 * Filter keys the search double ignores, as OpenRegister does with a filter it cannot apply.
	 *
	 * @var array<int, string>
	 */
	private array $ignoredFilters = [];

	/**
	 * Every OpenRegister call that did not pass `_rbac: false` and `_multitenancy: false`.
	 *
	 * @var array<int, string>
	 */
	private array $scopedCalls = [];

	/**
	 * Every searchObjects() query, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $searches = [];

	/**
	 * Thrown by every find(), when set.
	 *
	 * @var \Throwable|null
	 */
	private ?\Throwable $findFailure = null;

	/**
	 * The locking provider every service of a test shares, as the instance does.
	 *
	 * @var ILockingProvider|null
	 */
	private ?ILockingProvider $locks = null;

	/**
	 * Reset the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		CmdbTestSupport::loadMigrationPack();
		$this->store = [self::MODULE => [], self::ORGANIZATION => [], self::USAGE => [], self::CONTACT_PERSON => []];
		$this->saves = [];
		$this->beforeSave = null;
		$this->states = [];
		$this->transitions = [];
		$this->beforeTransition = null;
		$this->trashReads = 0;
		$this->deleteRefusedFor = null;
		$this->trashFailures = 0;
		$this->archiveServicesAvailable = true;
		$this->contacts = [];
		$this->addressBooks = [];
		$this->contactsEnabled = true;
		$this->cache = [];
		$this->cacheFailure = null;
		$this->logLines = [];
		$this->undeclared = [];
		$this->redefined = [];
		$this->ignoredFilters = [];
		$this->scopedCalls = [];
		$this->searches = [];
		$this->findFailure = null;
		$this->locks = $this->lockingProvider();
	}//end setUp()

	/**
	 * An in-memory locking provider: an exclusive lock that is held cannot be taken again.
	 *
	 * @return ILockingProvider
	 */
	private function lockingProvider(): ILockingProvider {
		return new class implements ILockingProvider {
			/**
			 * Held locks, path => type.
			 *
			 * @var array<string, int>
			 */
			public array $held = [];

			/**
			 * Every lock taken, in order.
			 *
			 * @var array<int, string>
			 */
			public array $taken = [];

			public function isLocked(string $path, int $type): bool {
				return isset($this->held[$path]);
			}

			public function acquireLock(string $path, int $type, ?string $readablePath = null): void {
				if (isset($this->held[$path]) === true) {
					throw new LockedException($path, null, null, $readablePath);
				}

				$this->held[$path] = $type;
				$this->taken[] = $path;
			}

			public function releaseLock(string $path, int $type): void {
				unset($this->held[$path]);
			}

			public function changeLock(string $path, int $targetType): void {
				$this->held[$path] = $targetType;
			}

			public function releaseAll(): void {
				$this->held = [];
			}
		};
	}//end lockingProvider()

	/**
	 * Every OpenRegister call of every test reads and writes unscoped, as the import must.
	 *
	 * @return void
	 */
	protected function assertPostConditions(): void {
		$this->assertSame([], $this->scopedCalls, 'every OpenRegister call passes _rbac: false and _multitenancy: false');
	}//end assertPostConditions()

	/**
	 * The properties a schema declares, from the register and every register fragment.
	 *
	 * @param int $schema The schema id.
	 *
	 * @return array<int, string>
	 */
	private function declaredProperties(int $schema): array {
		if (self::$declared === null) {
			$dir = CmdbTestSupport::appRoot() . '/lib/Settings';
			$register = json_decode((string)file_get_contents($dir . '/softwarecatalogus_register.json'), true);
			$merge = new \ReflectionMethod(SettingsService::class, 'deepMergeConfig');
			$files = glob($dir . '/register.d/*.json');
			sort($files);
			foreach ($files as $file) {
				$register = $merge->invoke(null, $register, json_decode((string)file_get_contents($file), true));
			}

			$ids = ['module' => self::MODULE, 'organization' => self::ORGANIZATION, 'usage' => self::USAGE, 'contactPerson' => self::CONTACT_PERSON];
			self::$declared = [];
			self::$definitions = [];
			foreach ($ids as $slug => $id) {
				self::$declared[$id] = array_keys($register['components']['schemas'][$slug]['properties']);
				self::$definitions[$id] = $register['components']['schemas'][$slug]['properties'];
			}
		}

		return array_values(array_diff((self::$declared[$schema] ?? []), ($this->undeclared[$schema] ?? [])));
	}//end declaredProperties()

	/**
	 * Note an OpenRegister call that would be scoped by RBAC or multitenancy.
	 *
	 * @param string $method The method.
	 * @param bool $rbac The `_rbac` argument.
	 * @param bool $multitenancy The `_multitenancy` argument.
	 *
	 * @return void
	 */
	private function noteScope(string $method, bool $rbac, bool $multitenancy): void {
		if ($rbac !== false || $multitenancy !== false) {
			$this->scopedCalls[] = $method;
		}
	}//end noteScope()

	// ------------------------------------------------------------------
	// Doubles
	// ------------------------------------------------------------------

	/**
	 * An entity as OpenRegister returns it.
	 *
	 * @param string $uuid The uuid.
	 * @param array<string, mixed> $data The object data.
	 * @param array<string, mixed> $state The archive and trash markers, serialised under `@self`.
	 * @param string|null $schema The schema id, as OpenRegister's own reads set it.
	 *
	 * @return ObjectEntityInterface
	 */
	private function entity(string $uuid, array $data, array $state = [], ?string $schema = null): ObjectEntityInterface {
		return new class($uuid, $data, $state, $schema) implements ObjectEntityInterface {
			/**
			 * Constructor.
			 *
			 * @param string $uuid The uuid.
			 * @param array<string, mixed> $data The data.
			 * @param array<string, mixed> $state The markers.
			 * @param string|null $schema The schema id.
			 */
			public function __construct(
				private string $uuid,
				private array $data,
				private array $state,
				private ?string $schema,
			) {
			}

			public function getUuid(): ?string {
				return $this->uuid;
			}

			public function getObject(): array {
				return $this->data;
			}

			public function getRegister(): ?string {
				return '20';
			}

			public function getSchema(): ?string {
				return $this->schema;
			}

			public function getOrganisation(): ?string {
				return null;
			}

			public function getOwner(): ?string {
				return null;
			}

			public function jsonSerialize(): array {
				return array_merge($this->data, ['@self' => array_merge(['archived' => null, 'deleted' => null], $this->state)]);
			}
		};
	}//end entity()

	/**
	 * The in-memory OpenRegister.
	 *
	 * @return ObjectServiceInterface
	 */
	private function objectService(): ObjectServiceInterface {
		$service = $this->createMock(ObjectServiceInterface::class);
		$service->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): ObjectEntityInterface {
				$this->noteScope(method: 'saveObject', rbac: $_rbac, multitenancy: $_multitenancy);
				$schema = (int)$schema;
				if ($this->beforeSave !== null) {
					($this->beforeSave)($schema, $object);
				}

				// OpenRegister refuses a data write to an archived object (ObjectStateWriteException).
				if ($uuid !== null && empty($this->states[$schema][$uuid]['archived']) === false) {
					throw new RuntimeException('write to an archived object');
				}

				$create = ($uuid === null);
				if ($create === true) {
					$uuid = sprintf('00000000-0000-4000-8000-%012d', count($this->saves) + 1);
				}

				$object['id'] = $uuid;
				$this->store[$schema][$uuid] = $object;
				$this->saves[] = ['schema' => $schema, 'uuid' => $uuid, 'data' => $object, 'create' => $create];
				return $this->entity(uuid: $uuid, data: $object);
			}
		);
		$service->method('searchObjects')->willReturnCallback(
			function (array $query = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->noteScope(method: 'searchObjects', rbac: $_rbac, multitenancy: $_multitenancy);
				$this->searches[] = $query;
				$schema = (int)($query['@self']['schema'] ?? 0);
				$limit = (int)($query['_limit'] ?? 30);
				$offset = (int)($query['_offset'] ?? 0);
				$filters = array_filter($query, fn ($key): bool => $key !== '@self' && str_starts_with((string)$key, '_') === false, ARRAY_FILTER_USE_KEY);
				// OpenRegister turns a filter on a property the schema does not declare into `1 = 0`.
				if (array_diff(array_keys($filters), $this->declaredProperties(schema: $schema)) !== []) {
					return [];
				}

				$found = [];
				foreach (($this->store[$schema] ?? []) as $uuid => $data) {
					foreach ($filters as $field => $value) {
						if (in_array($field, $this->ignoredFilters, true) === false && (string)($data[$field] ?? '') !== (string)$value) {
							continue 2;
						}
					}

					$state = ($this->states[$schema][$uuid] ?? []);
					// The list search loses every soft-deleted row, `_includeDeleted` or not:
					// MagicSearchHandler::convertRowToObjectEntity() parses the JSON `_deleted`
					// marker as a date, fails, and skips the row (OpenRegister beta ff4dad5c).
					if (empty($state['deleted']) === false) {
						continue;
					}

					if (self::inLens(query: $query, uuid: (string)$uuid, state: $state) === false) {
						continue;
					}

					// The same converter hydrates no `_archived`: a search result never carries the archive marker.
					$found[] = $this->entity(uuid: $uuid, data: $data);
				}

				return array_slice($found, $offset, $limit);
			}
		);
		$service->method('deleteObject')->willReturnCallback(
			function (string $uuid, $register = null, $schema = null, bool $_rbac = true, bool $_multitenancy = true): bool {
				$this->noteScope(method: 'deleteObject', rbac: $_rbac, multitenancy: $_multitenancy);
				if ($this->deleteRefusedFor === $uuid) {
					return false;
				}

				$this->changeState(verb: 'delete', uuid: $uuid, reason: null);
				return true;
			}
		);
		$service->method('find')->willReturnCallback(
			function ($id, ?array $_extend = [], bool $files = false, $register = null, $schema = null, bool $_rbac = true, bool $_multitenancy = true): ?ObjectEntityInterface {
				$this->noteScope(method: 'find', rbac: $_rbac, multitenancy: $_multitenancy);
				if ($this->findFailure !== null) {
					throw $this->findFailure;
				}
				$data = ($this->store[(int)$schema][(string)$id] ?? null);
				if ($data === null) {
					return null;
				}

				return $this->entity(uuid: (string)$id, data: $data);
			}
		);

		return $service;
	}//end objectService()

	/**
	 * Whether a working or archived object is in the list a query asks for, as OpenRegister's lenses decide.
	 *
	 * Archived objects are left out unless `_archived` asks for them (`true`
	 * only the archive, `any` both); `_ids` narrows to those uuids.
	 *
	 * @param array<string, mixed> $query The search query.
	 * @param string $uuid The object.
	 * @param array<string, mixed> $state Its markers.
	 *
	 * @return bool
	 */
	private static function inLens(array $query, string $uuid, array $state): bool {
		if (isset($query['_ids']) === true && in_array($uuid, (array)$query['_ids'], true) === false) {
			return false;
		}

		$archived = (empty($state['archived']) === false);
		$lens = ($query['_archived'] ?? null);
		if ($lens === 'any') {
			return true;
		}

		if (filter_var($lens, FILTER_VALIDATE_BOOLEAN) === true) {
			return $archived;
		}

		return $archived === false;
	}//end inLens()

	/**
	 * Archive, unarchive, restore or soft-delete a stored object, as OpenRegister's handlers do.
	 *
	 * @param string $verb archive, unarchive, restore or delete.
	 * @param string $uuid The object.
	 * @param string|null $reason The archive reason.
	 *
	 * @return array{uuid: string, archived: array<string, mixed>|null}
	 */
	public function changeState(string $verb, string $uuid, ?string $reason): array {
		if ($this->beforeTransition !== null) {
			($this->beforeTransition)($verb, $uuid);
		}

		$schema = 0;
		foreach ($this->store as $id => $objects) {
			if (isset($objects[$uuid]) === true) {
				$schema = $id;
			}
		}

		$this->assertNotSame(0, $schema, 'no stored object ' . $uuid);
		$this->transitions[] = [$verb, $schema, $uuid, $reason];
		$state = ($this->states[$schema][$uuid] ?? []);
		if ($verb !== 'restore' && empty($state['deleted']) === false) {
			// The archive handler and the delete resolve the object without the trash.
			throw new \OCP\AppFramework\Db\DoesNotExistException('object is in the trash');
		}

		match ($verb) {
			'archive' => $state['archived'] = ($state['archived'] ?? null) ?: ['by' => 'admin', 'at' => '2026-10-09T00:00:00+00:00', 'reason' => $reason],
			'unarchive' => $state['archived'] = null,
			'restore' => $state['deleted'] = null,
			'delete' => $state['deleted'] = ['deletedBy' => 'admin', 'deletedAt' => '2026-10-09T00:00:00+00:00'],
		};
		$this->states[$schema][$uuid] = $state;

		return ['uuid' => $uuid, 'archived' => ($state['archived'] ?? null)];
	}//end changeState()

	/**
	 * OpenRegister's archive handler and object mapper over the in-memory store.
	 *
	 * @return array{0: object, 1: object}
	 */
	private function archiveDoubles(): array {
		$test = $this;
		$handler = new class($test) {
			/**
			 * Constructor.
			 *
			 * @param CmdbExportImportServiceTest $test The test, which holds the markers.
			 */
			public function __construct(
				private CmdbExportImportServiceTest $test,
			) {
			}

			/**
			 * Archive an object.
			 *
			 * @param string $identifier The uuid.
			 * @param string|null $reason The reason.
			 * @param string|null $register Ignored.
			 * @param string|null $schema Ignored.
			 *
			 * @return array<string, mixed>
			 */
			public function archive(string $identifier, ?string $reason = null, ?string $register = null, ?string $schema = null): array {
				return $this->test->changeState(verb: 'archive', uuid: $identifier, reason: $reason);
			}

			/**
			 * Unarchive an object.
			 *
			 * @param string $identifier The uuid.
			 * @param string|null $reason The reason.
			 * @param string|null $register Ignored.
			 * @param string|null $schema Ignored.
			 *
			 * @return array<string, mixed>
			 */
			public function unarchive(string $identifier, ?string $reason = null, ?string $register = null, ?string $schema = null): array {
				return $this->test->changeState(verb: 'unarchive', uuid: $identifier, reason: $reason);
			}
		};
		$mapper = new class($test) {
			/**
			 * Constructor.
			 *
			 * @param CmdbExportImportServiceTest $test The test, which holds the markers.
			 */
			public function __construct(
				private CmdbExportImportServiceTest $test,
			) {
			}

			/**
			 * Take an object out of the trash and answer it as the mapper reads it, archive marker included.
			 *
			 * @param string $uuid The uuid.
			 *
			 * @return object
			 */
			public function restoreObject(string $uuid): object {
				$this->test->changeState(verb: 'restore', uuid: $uuid, reason: null);
				return $this->test->storedEntity(uuid: $uuid);
			}

			/**
			 * The trash: every soft-deleted object, in every register and schema.
			 *
			 * @param int|null $limit Ignored.
			 * @param int|null $offset Ignored.
			 * @param bool $_rbac Must be false.
			 * @param bool $_multitenancy Must be false.
			 *
			 * @return array<int, object>
			 */
			public function findDeletedAcrossAllMagicTables(?int $limit = null, ?int $offset = null, bool $_rbac = true, bool $_multitenancy = true): array {
				return $this->test->trashListing(rbac: $_rbac, multitenancy: $_multitenancy);
			}
		};

		return [$handler, $mapper];
	}//end archiveDoubles()

	/**
	 * A stored object as OpenRegister's mapper reads it by uuid: data plus both markers.
	 *
	 * @param string $uuid The object.
	 *
	 * @return ObjectEntityInterface
	 */
	public function storedEntity(string $uuid): ObjectEntityInterface {
		foreach ($this->store as $schema => $objects) {
			if (isset($objects[$uuid]) === true) {
				return $this->entity(uuid: $uuid, data: $objects[$uuid], state: ($this->states[$schema][$uuid] ?? []), schema: (string)$schema);
			}
		}

		$this->fail('no stored object ' . $uuid);
	}//end storedEntity()

	/**
	 * The trash as MagicMapper::findDeletedAcrossAllMagicTables() lists it.
	 *
	 * Its rows go through MagicMapper::rowToObjectEntity(): uuid, register,
	 * schema and the data, with every string that parses as JSON decoded
	 * ("2" becomes 2), and no archive or trash marker.
	 *
	 * @param bool $rbac The `_rbac` argument.
	 * @param bool $multitenancy The `_multitenancy` argument.
	 *
	 * @return array<int, ObjectEntityInterface>
	 */
	public function trashListing(bool $rbac, bool $multitenancy): array {
		$this->noteScope(method: 'findDeletedAcrossAllMagicTables', rbac: $rbac, multitenancy: $multitenancy);
		$this->trashReads++;
		if ($this->trashFailures > 0) {
			$this->trashFailures--;
			throw new RuntimeException('trash listing failed');
		}

		$listed = [];
		foreach ($this->states as $schema => $states) {
			foreach ($states as $uuid => $state) {
				if (empty($state['deleted']) === true || isset($this->store[$schema][$uuid]) === false) {
					continue;
				}

				$data = array_map(
					static function (mixed $value): mixed {
						if (is_string($value) === true) {
							$decoded = json_decode($value, true);
							if (json_last_error() === JSON_ERROR_NONE) {
								return $decoded;
							}
						}

						return $value;
					},
					$this->store[$schema][$uuid]
				);
				$listed[] = $this->entity(uuid: (string)$uuid, data: $data, schema: (string)$schema);
			}
		}

		return $listed;
	}//end trashListing()

	/**
	 * OpenRegister's schema mapper over the declared properties.
	 *
	 * @return object
	 */
	private function schemaMapper(): object {
		$test = $this;
		return new class($test) {
			/**
			 * Constructor.
			 *
			 * @param CmdbExportImportServiceTest $test The test, for the declared properties.
			 */
			public function __construct(
				private CmdbExportImportServiceTest $test,
			) {
			}

			/**
			 * A schema with the declared properties.
			 *
			 * @param int|string $id The schema id.
			 * @param array<mixed>|null $_extend Ignored.
			 * @param bool $_rbac Must be false.
			 * @param bool $_multitenancy Must be false.
			 *
			 * @return object
			 */
			public function find(int|string $id, ?array $_extend = [], bool $_rbac = true, bool $_multitenancy = true): object {
				$properties = $this->test->schemaProperties(schema: (int)$id, rbac: $_rbac, multitenancy: $_multitenancy);
				return new class($properties) {
					/**
					 * Constructor.
					 *
					 * @param array<string, mixed> $properties The properties.
					 */
					public function __construct(
						private array $properties,
					) {
					}

					/**
					 * The properties.
					 *
					 * @return array<string, mixed>
					 */
					public function getProperties(): array {
						return $this->properties;
					}
				};
			}
		};
	}//end schemaMapper()

	/**
	 * The declared property definitions of a schema, for the schema mapper double.
	 *
	 * @param int $schema The schema id.
	 * @param bool $rbac The `_rbac` argument.
	 * @param bool $multitenancy The `_multitenancy` argument.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function schemaProperties(int $schema, bool $rbac, bool $multitenancy): array {
		$this->noteScope(method: 'SchemaMapper::find', rbac: $rbac, multitenancy: $multitenancy);
		$definitions = [];
		foreach ($this->declaredProperties(schema: $schema) as $name) {
			$definitions[$name] = ($this->redefined[$schema][$name] ?? self::$definitions[$schema][$name] ?? ['type' => 'string']);
		}

		return $definitions;
	}//end schemaProperties()

	/**
	 * The Contacts bridge over a fake address book.
	 *
	 * @return StackiqContactSyncService
	 */
	private function contactSync(): StackiqContactSyncService {
		$sync = $this->createMock(StackiqContactSyncService::class);
		$sync->method('isAvailable')->willReturnCallback(fn (): bool => $this->contactsEnabled);
		$sync->method('searchContacts')->willThrowException(new \LogicException('owners are only searched in the named address book'));
		$sync->method('searchNamedAddressBook')->willReturnCallback(
			function (string $query, string $addressBookUri, array $properties): array {
				$found = [];
				foreach ($this->contacts as $uid => $contact) {
					$haystack = $contact['name'];
					if ($properties === ['UID']) {
						$haystack = (string)$uid;
					}

					if ($properties === ['EMAIL']) {
						$haystack = implode(' ', array_merge([$contact['email']], $contact['emails'] ?? []));
					}

					if (($contact['book'] ?? 'personal') === $addressBookUri
						&& str_contains(mb_strtolower($haystack), mb_strtolower($query)) === true
					) {
						$found[] = ['uid' => $uid, 'name' => $contact['name'], 'email' => $contact['email'], 'emails' => array_merge([$contact['email']], $contact['emails'] ?? [])];
					}
				}

				return $found;
			}
		);
		$sync->method('syncToContacts')->willThrowException(new \LogicException('owners go into the named address book, not the first writable one'));
		$sync->method('syncToNamedAddressBook')->willReturnCallback(
			function (string $objectType, array $record, string $addressBookUri, string $displayName, ?string $fixedUid = null): ?string {
				$this->addressBooks[$addressBookUri] = $displayName;
				$email = (string)($record['email'] ?? '');
				foreach ($this->contacts as $uid => $contact) {
					if ($fixedUid === null && $email !== '' && ($contact['book'] ?? 'personal') === $addressBookUri && strcasecmp($contact['email'], $email) === 0) {
						return $uid;
					}
				}

				$uid = ($fixedUid ?? 'contact-' . (count($this->contacts) + 1));
				$this->contacts[$uid] = [
					'name' => trim(($record['voornaam'] ?? '') . ' ' . ($record['achternaam'] ?? '')),
					'email' => $email,
					'book' => $addressBookUri,
				];
				return $uid;
			}
		);

		return $sync;
	}//end contactSync()

	/**
	 * A ProgressTracker on an in-memory distributed cache.
	 *
	 * @return ProgressTracker
	 */
	private function progressTracker(): ProgressTracker {
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn ($key) => ($this->cache[$key] ?? null));
		$cache->method('set')->willReturnCallback(
			function ($key, $value): bool {
				if ($this->cacheFailure !== null) {
					$failure = $this->cacheFailure;
					$this->cacheFailure = null;
					throw $failure;
				}

				$this->cache[$key] = $value;
				return true;
			}
		);
		$cache->method('remove')->willReturnCallback(
			function ($key): bool {
				unset($this->cache[$key]);
				return true;
			}
		);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);

		$factory->method('isAvailable')->willReturn(true);

		return new ProgressTracker(
			cacheFactory: $factory,
			userSession: $this->createMock(IUserSession::class),
			logger: $this->logger(),
			config: $this->createConfiguredMock(IConfig::class, ['getSystemValueString' => '\\OC\\Memcache\\Redis']),
			appConfig: $this->createMock(IAppConfig::class)
		);
	}//end progressTracker()

	/**
	 * An IL10N that returns the English source with its parameters filled in.
	 *
	 * @return IL10N
	 */
	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text, $parameters = []): string => vsprintf($text, (array)$parameters));
		return $l10n;
	}//end l10n()

	/**
	 * A logger that keeps every line.
	 *
	 * @return AbstractLogger
	 */
	private function logger(): AbstractLogger {
		$lines = &$this->logLines;
		return new class($lines) extends AbstractLogger {
			/**
			 * Constructor.
			 *
			 * @param array<int, string> $lines The collected lines.
			 */
			public function __construct(
				private array &$lines,
			) {
			}

			/**
			 * Keep a line.
			 *
			 * @param mixed $level The level.
			 * @param string|\Stringable $message The message.
			 * @param array<mixed> $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				array_walk_recursive(
					$context,
					function (&$value): void {
						if (is_object($value) === true) {
							$value = get_class($value) . ($value instanceof \Throwable ? ': ' . $value->getMessage() : '');
						}
					}
				);
				$this->lines[] = $message . ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
			}
		};
	}//end logger()

	/**
	 * A reader that hands out given rows, for tests that do not need the fixture.
	 *
	 * The workbook has the archive sheet, listing $archived; null is a workbook without the sheet.
	 *
	 * @param array<int, array{sheet: string, row: int, cells: array<string, mixed>}> $rows The rows.
	 * @param array<int, string>|null $archived The APPIDs on the archive sheet, or null when there is no such sheet.
	 * @param array<int, int> $uncachedRows The archive sheet's rows whose APPID formula has no cached value.
	 *
	 * @return CmdbWorkbookReader
	 */
	private function rowsReader(array $rows, ?array $archived = [], array $uncachedRows = [], array $uncachedKeyRows = [], array $absentSheets = []): CmdbWorkbookReader {
		return new class($rows, $archived, $uncachedRows, $uncachedKeyRows, $absentSheets) extends CmdbWorkbookReader {
			/**
			 * Constructor.
			 *
			 * @param array<int, array<string, mixed>> $rows The rows.
			 * @param array<int, string>|null $archived The archive sheet's APPIDs, or null.
			 * @param array<int, int> $uncachedRows The archive sheet's rows without a cached APPID.
			 * @param array<string, array<int, int>> $uncachedKeyRows The CMDB rows dropped as empty, per sheet.
			 * @param array<int, string> $absentSheets The source sheets the workbook lacks.
			 */
			public function __construct(
				private array $rows,
				private ?array $archived,
				private array $uncachedRows,
				private array $uncachedKeyRows = [],
				private array $absentSheets = [],
			) {
			}

			/**
			 * The given rows.
			 *
			 * @param string $path Ignored.
			 * @param CmdbImportProfile $profile Ignored.
			 *
			 * @return array<string, mixed>
			 */
			public function read(string $path, CmdbImportProfile $profile): array {
				$archive = ['sheet' => 'Gearchiveerde Applicaties', 'present' => ($this->archived !== null), 'keyColumnMissing' => false, 'appIds' => ($this->archived ?? []), 'uncachedRows' => $this->uncachedRows];
				return [
					'rows' => $this->rows,
					'importWarnings' => [],
					'date1904' => false,
					'archive' => $archive,
					'uncachedKeyRows' => $this->uncachedKeyRows,
					'absentSheets' => $this->absentSheets,
				];
			}
		};
	}//end rowsReader()

	/**
	 * The service under test.
	 *
	 * @param CmdbWorkbookReader|null $reader The reader; null is the real one.
	 * @param string|null $profileDir A profile directory other than the shipped one.
	 * @param array<string, mixed> $config The voorzieningen config.
	 * @param FakeMigrationPackService|false|null $packs OpenRegister's pack store: null is an empty one, false none at all.
	 *
	 * @return CmdbExportImportService
	 */
	private function service(
		?CmdbWorkbookReader $reader = null,
		?string $profileDir = null,
		array $config = ['register' => '20'],
		FakeMigrationPackService|false|null $packs = null
	): CmdbExportImportService {
		$objectService = $this->objectService();
		$schemaMapper = $this->schemaMapper();
		if ($packs === null) {
			$packs = new FakeMigrationPackService();
		}

		[$archiveHandler, $objectMapper] = $this->archiveDoubles();
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturnCallback(fn (string $id): bool => $id === \OCP\L10N\IFactory::class || ($packs !== false && $id === CmdbImportPackStore::SERVICE_CLASS));
		$container->method('get')->willReturnCallback(
			function (string $id) use ($objectService, $schemaMapper, $packs, $archiveHandler, $objectMapper) {
				if ($id === \OCP\L10N\IFactory::class) {
					return CmdbTestSupport::translationFactory(test: $this);
				}

				if ($id === ObjectServiceInterface::class) {
					return $objectService;
				}

				if ($id === CmdbExportImportService::SCHEMA_MAPPER_CLASS) {
					return $schemaMapper;
				}

				if ($packs !== false && $id === CmdbImportPackStore::SERVICE_CLASS) {
					return $packs;
				}

				if ($this->archiveServicesAvailable === true && $id === CmdbExportImportService::ARCHIVE_HANDLER_CLASS) {
					return $archiveHandler;
				}

				if ($this->archiveServicesAvailable === true && $id === CmdbExportImportService::OBJECT_MAPPER_CLASS) {
					return $objectMapper;
				}

				throw new RuntimeException('not in this container: ' . $id);
			}
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getVoorzieningenConfig')->willReturn($config);
		$settings->method('getSchemaIdForObjectType')->willReturnCallback(
			fn (string $type): ?int => ['module' => self::MODULE, 'organization' => self::ORGANIZATION, 'usage' => self::USAGE, 'contactPerson' => self::CONTACT_PERSON][$type] ?? null
		);

		$this->tracker = $this->progressTracker();

		return new CmdbExportImportService(
			container: $container,
			settingsService: $settings,
			contactSync: $this->contactSync(),
			progressTracker: $this->tracker,
			profile: new CmdbImportProfile(container: $container, directory: $profileDir),
			reader: ($reader ?? new CmdbWorkbookReader()),
			normaliser: new CmdbRowNormaliser(),
			l10n: $this->l10n(),
			logger: $this->logger(),
			lockingProvider: $this->locks,
			userSession: $this->adminSession(),
			ownerIdentity: $this->ownerIdentity()
		);
	}//end service()

	/**
	 * The owner identity with a fixed instance secret.
	 *
	 * @return CmdbOwnerIdentity
	 */
	private function ownerIdentity(): CmdbOwnerIdentity {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturnCallback(fn (string $key, string $default = ''): string => $key === 'secret' ? 'instance-secret' : $default);
		return new CmdbOwnerIdentity(config: $config, logger: $this->createMock(LoggerInterface::class));
	}//end ownerIdentity()

	/**
	 * A session signed in as "admin".
	 *
	 * @return IUserSession
	 */
	private function adminSession(): IUserSession {
		$user = $this->createMock(\OCP\IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		return $session;
	}//end adminSession()

	/**
	 * Skip unless the fixture can be read.
	 *
	 * @return string The fixture path.
	 */
	private function fixture(): string {
		if (CmdbTestSupport::loadPhpSpreadsheet() === false) {
			$this->markTestSkipped('PhpSpreadsheet not found: set OPENREGISTER_DIR to an OpenRegister app with its vendor/ installed.');
		}

		return CmdbTestSupport::fixtures() . '/topdesk-export-anonymised.xlsx';
	}//end fixture()

	/**
	 * A synthetic application row of a CMDB sheet.
	 *
	 * @param string $appId The APPID.
	 * @param array<string, mixed> $cells Overrides.
	 * @param int $row The row number.
	 * @param string $sheet The sheet.
	 * @param array<int, string> $uncached Columns whose formula has no cached value.
	 *
	 * @return array{sheet: string, row: int, cells: array<string, mixed>, uncached: array<int, string>}
	 */
	private function row(string $appId, array $cells = [], int $row = 2, string $sheet = 'Beheerde Applicaties CMDB', array $uncached = []): array {
		return [
			'sheet' => $sheet,
			'row' => $row,
			'cells' => array_merge(
				['APPID' => $appId, 'Applicatie Code' => 'APP-' . $appId, 'Applicatie Naam' => 'Applicatie ' . $appId, 'Vendor' => 'Fabfrikant', 'Applicatie Status' => 'In productie'],
				$cells
			),
			'uncached' => $uncached,
		];
	}//end row()

	/**
	 * The stored objects of a schema.
	 *
	 * @param int $schema The schema id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function objects(int $schema): array {
		return array_values($this->store[$schema]);
	}//end objects()

	/**
	 * Seed an organisation.
	 *
	 * @param string $uuid The uuid.
	 * @param string $name The name.
	 * @param string $type The type.
	 * @param string $status The status.
	 *
	 * @return void
	 */
	private function seedOrganisation(string $uuid, string $name, string $type, string $status = 'Active'): void {
		$this->store[self::ORGANIZATION][$uuid] = ['id' => $uuid, 'name' => $name, 'type' => $type, 'status' => $status];
	}//end seedOrganisation()

	// ------------------------------------------------------------------
	// Task 5: municipality, manufacturer, module upsert, usage
	// ------------------------------------------------------------------

	/**
	 * The sanitised export creates two modules, two suppliers, two usages and the municipality.
	 *
	 * @return void
	 */
	public function testTheFixtureCreatesModulesUsagesAndSuppliers(): void {
		$path = $this->fixture();
		$before = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-1 second');
		$report = $this->service()->import(path: $path, options: ['municipalityName' => 'Gemeente Voorbeeldstad', 'operationId' => 'cmdb-test-0001']);

		$this->assertTrue($report['success']);
		$this->assertFalse($report['cancelled']);
		$this->assertSame('cmdb-test-0001', $report['operationId']);
		$this->assertSame(['rowsRead' => 2, 'processed' => 2, 'created' => 2, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'failed' => 0, 'archived' => 0, 'unarchived' => 0, 'deleted' => 0, 'restored' => 0, 'warnings' => 0, 'unpublished' => 0], $report['summary']);
		$this->assertSame('Gemeente Voorbeeldstad', $report['municipality']['name']);
		$this->assertTrue($report['municipality']['created']);
		$this->assertSame(['No municipality named "Gemeente Voorbeeldstad" was found, so it was created. Check the name if you meant an existing one.'], array_column($report['importWarnings'], 'message'), 'only the warning that the municipality was created');
		// "Webapplicatie" is an application kind, not a hosting model: kept as the kind, no hosting model, no warning.
		$this->assertSame([], $report['rows'][0]['warnings']);

		$municipality = $report['municipality']['uuid'];
		$this->assertSame('Municipality', $this->store[self::ORGANIZATION][$municipality]['type']);
		$this->assertSame('Active', $this->store[self::ORGANIZATION][$municipality]['status']);

		$suppliers = array_filter($this->objects(self::ORGANIZATION), fn (array $o): bool => $o['type'] === 'Supplier');
		$this->assertEqualsCanonicalizing(['Aangetekend B.V.', 'Fabfrikant'], array_column($suppliers, 'name'));
		// The organisation read rule shows a supplier publicly only with registeredBy Supplier and status Active.
		$this->assertSame(['Supplier', 'Supplier'], array_column($suppliers, 'registeredBy'));
		$this->assertSame(['Active', 'Active'], array_values(array_column($suppliers, 'status')));

		$modules = [];
		foreach ($this->objects(self::MODULE) as $module) {
			$modules[$module['externalNumber']] = $module;
		}

		$this->assertSame(['1234', '2'], array_map('strval', array_keys($modules)));
		$onbeh = $modules[1234];
		$this->assertSame('topdesk:' . $municipality . ':1234', $onbeh['externalKey']);
		$this->assertSame('AIA-AangetekendMailen', $onbeh['externalId']);
		$this->assertSame('Aangetekend Mailen', $onbeh['name']);
		$this->assertSame('Mailen', $onbeh['shortDescription']);
		$this->assertSame('Application', $onbeh['type']);
		$this->assertSame('2023-07-04', $onbeh['externalCreatedAt']);
		$this->assertSame('2026-07-29', $onbeh['externalModifiedAt']);
		$this->assertSame('Functionele omschrijving test123', $onbeh['longDescription']);
		$this->assertArrayNotHasKey('bbnLevel', $onbeh, '"NB" means unknown');
		$this->assertArrayNotHasKey('cloudDienstverleningsmodel', $onbeh);
		$this->assertSame('Webapplicatie', $onbeh['applicationType']);
		$publication = new \DateTimeImmutable($onbeh['publicationDate']);
		$this->assertGreaterThanOrEqual($before, $publication);
		$this->assertLessThanOrEqual(new \DateTimeImmutable('now'), $publication);

		$beheerd = $modules[2];
		$this->assertSame($onbeh['publicationDate'], $beheerd['publicationDate'], 'one start time for the whole import');
		$this->assertSame('topdesk:' . $municipality . ':2', $beheerd['externalKey']);
		$this->assertSame('APP-test123', $beheerd['externalId']);
		$this->assertSame('naamtest123', $beheerd['name']);
		$this->assertSame('Naamtest', $beheerd['shortDescription'], 'Roepnaam wins over Nickname');
		$this->assertSame('Accomodatieplanning.', $beheerd['longDescription']);
		$this->assertSame(['SaaS'], $beheerd['cloudDienstverleningsmodel']);
		$this->assertSame('Saas', $beheerd['applicationType']);
		$this->assertSame('BBN2', $beheerd['bbnLevel']);

		$supplierByName = array_column($suppliers, 'id', 'name');
		$this->assertSame($supplierByName['Aangetekend B.V.'], $onbeh['provider']);
		$this->assertSame($supplierByName['Fabfrikant'], $beheerd['provider']);

		$usages = $this->objects(self::USAGE);
		$this->assertCount(2, $usages);
		$usageByModule = array_column($usages, null, 'module');
		$aia = $usageByModule[$onbeh['id']];
		$this->assertSame($municipality, $aia['consumer']);
		$this->assertSame('Planned', $aia['status']);
		$this->assertSame('Beheer geregeld: nee / H10 / H10 Accounting', $aia['interneAnnotation']);
		$this->assertSame('2036-01-01', $aia['startDateOutPhased'], 'the end-of-life date is stored as the file has it');
		$this->assertArrayNotHasKey('timeClassification', $aia);
		$this->assertSame($supplierByName['Aangetekend B.V.'], $aia['provider']);
		$app = $usageByModule[$beheerd['id']];
		$this->assertSame('In production', $app['status']);
		$this->assertSame('Tolerate', $app['timeClassification']);
		$this->assertSame('2046-02-01', $app['startDateOutPhased']);
		$this->assertSame('Beheer geregeld: ja / B10 / B10 Maatschappelijke Ontwikkeling', $app['interneAnnotation']);
		$this->assertArrayNotHasKey('technicalOwner', $app);

		$this->assertSame($onbeh['id'], $report['rows'][0]['moduleUuid']);
		$this->assertSame($aia['id'], $report['rows'][0]['usageUuid']);
		$this->assertSame(
			['Onbeh Applicaties CMDB', 2, '1234', 'Aangetekend Mailen', 'created'],
			[$report['rows'][0]['sheet'], $report['rows'][0]['row'], $report['rows'][0]['appId'], $report['rows'][0]['name'], $report['rows'][0]['outcome']]
		);
		$this->assertSame('Beheerde Applicaties CMDB', $report['rows'][1]['sheet']);
	}//end testTheFixtureCreatesModulesUsagesAndSuppliers()

	/**
	 * The same export again: 0 created, 2 unchanged, no save at all, one municipality.
	 *
	 * @return void
	 */
	public function testReimportingTheSameExportChangesNothing(): void {
		$path = $this->fixture();
		$service = $this->service();
		$service->import(path: $path, options: ['municipalityName' => 'Gemeente Voorbeeldstad']);
		$counts = array_map('count', $this->store);
		$savesAfterFirst = count($this->saves);

		$report = $service->import(path: $path, options: ['municipalityName' => '  gemeente   VOORBEELDSTAD ']);

		$this->assertSame(0, $report['summary']['created']);
		$this->assertSame(2, $report['summary']['unchanged']);
		$this->assertFalse($report['municipality']['created']);
		$this->assertSame($savesAfterFirst, count($this->saves), 'no saveObject() call for unchanged objects');
		$this->assertSame($counts, array_map('count', $this->store));
		$municipalities = array_filter($this->objects(self::ORGANIZATION), fn (array $o): bool => $o['type'] === 'Municipality');
		$this->assertCount(1, $municipalities);
	}//end testReimportingTheSameExportChangesNothing()

	/**
	 * Two municipalities with the same name are refused as ambiguous, naming both, and nothing is written.
	 *
	 * @return void
	 */
	public function testAnAmbiguousMunicipalityNameIsRefused(): void {
		$this->seedOrganisation(uuid: 'muni-bergen-nh', name: 'Gemeente Bergen', type: 'Municipality');
		$this->seedOrganisation(uuid: 'muni-bergen-l', name: 'gemeente  bergen', type: 'Municipality');

		try {
			$this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1')]))->import(path: '', options: ['municipalityName' => 'Gemeente Bergen']);
			$this->fail('MUNICIPALITY_AMBIGUOUS expected');
		} catch (CmdbImportException $e) {
			$this->assertSame('MUNICIPALITY_AMBIGUOUS', $e->getErrorCode());
			$this->assertSame(422, $e->getHttpStatus());
			$this->assertSame(['matches' => ['muni-bergen-nh', 'muni-bergen-l']], $e->getDetails());
		}

		$this->assertSame([], $this->saves);
	}//end testAnAmbiguousMunicipalityNameIsRefused()

	/**
	 * Without OpenRegister's pack store, and nothing stored there before, the import runs the shipped mapping and says so once.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	public function testWithoutThePackStoreTheShippedMappingIsUsedWithAWarning(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Rotterdam', type: 'Municipality');

		$report = $this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1')]), packs: false)
			->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$this->assertSame(1, $report['summary']['created']);
		$this->assertSame(
			[['sheet' => '', 'message' => "OpenRegister's migration pack store is not available, so this import used the mapping that ships with stackiq."]],
			$report['importWarnings']
		);
		$this->assertSame('In production', $this->objects(self::USAGE)[0]['status']);
	}//end testWithoutThePackStoreTheShippedMappingIsUsedWithAWarning()

	/**
	 * A pack edited in OpenRegister is what the import runs: its lookup, its concat separator and a new date column.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	public function testAPackEditedInOpenRegisterIsWhatTheImportRuns(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Rotterdam', type: 'Municipality');
		$packs = new FakeMigrationPackService();
		$usage = (new CmdbImportPackMarker())->seed(
			shipped: json_decode((string)file_get_contents(CmdbTestSupport::appRoot() . '/lib/Settings/cmdb-import/topdesk-usage.json'), true)
		);
		$usage['fieldMappings'][0]['transform']['map']['In productie'] = 'Planned';
		$usage['fieldMappings'][3]['transform']['separator'] = ' | ';
		$usage['fieldMappings'][] = [
			'source' => 'In productie sinds',
			'target' => 'startDateInProduction',
			'transform' => ['type' => 'date', 'sourceFormat' => '!Y-m-d', 'targetFormat' => 'Y-m-d'],
		];
		$packs->put(definition: $usage);

		$report = $this->service(
			reader: $this->rowsReader(rows: [$this->row(appId: '1', cells: ['Cluster' => 'B10', 'In productie sinds' => 45111])]),
			packs: $packs
		)->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$this->assertSame([], $report['importWarnings'], 'the packs came from OpenRegister');
		$this->assertSame([], $report['rows'][0]['warnings']);
		$stored = $this->objects(self::USAGE)[0];
		$this->assertSame('Planned', $stored['status'], 'the edited lookup');
		$this->assertSame('Beheer geregeld: ja | B10', $stored['interneAnnotation'], 'empty parts dropped on the edited separator');
		$this->assertSame('2023-07-04', $stored['startDateInProduction'], 'the new column was converted from an Excel date');
		$this->assertSame(
			['create stackiq-topdesk-module', 'create stackiq-topdesk-manufacturer', 'create stackiq-topdesk-business-owner'],
			$packs->writes,
			'the other sheet packs were seeded on the first import'
		);
	}//end testAPackEditedInOpenRegisterIsWhatTheImportRuns()

	/**
	 * A plain mapping of the note after the concat one, from an empty column, leaves the concatenated note clean.
	 *
	 * The engine skips a mapping whose column is empty, so the concatenated
	 * value is the one kept and its empty parts are still dropped.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	public function testAPlainNoteMappingFromAnEmptyColumnKeepsTheNoteClean(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Rotterdam', type: 'Municipality');
		$packs = new FakeMigrationPackService();
		$usage = (new CmdbImportPackMarker())->seed(
			shipped: json_decode((string)file_get_contents(CmdbTestSupport::appRoot() . '/lib/Settings/cmdb-import/topdesk-usage.json'), true)
		);
		$usage['fieldMappings'][] = ['source' => 'Opmerking', 'target' => 'interneAnnotation', 'transform' => ['type' => 'trim']];
		$packs->put(definition: $usage);

		$this->service(
			reader: $this->rowsReader(rows: [$this->row(appId: '1', cells: ['Cluster' => 'B10', 'Opmerking' => ''])]),
			packs: $packs
		)->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$this->assertSame('Beheer geregeld: ja / B10', $this->objects(self::USAGE)[0]['interneAnnotation']);
	}//end testAPlainNoteMappingFromAnEmptyColumnKeepsTheNoteClean()

	/**
	 * A name that matches no municipality creates it, and the report warns about that.
	 *
	 * @return void
	 */
	public function testANewMunicipalityIsCreatedWithAWarning(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Rotterdam', type: 'Municipality');

		$report = $this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1')]))->import(path: '', options: ['municipalityName' => 'Gemeente Rotterdm']);

		$this->assertTrue($report['municipality']['created']);
		$this->assertSame(
			[['sheet' => '', 'message' => 'No municipality named "Gemeente Rotterdm" was found, so it was created. Check the name if you meant an existing one.']],
			$report['importWarnings']
		);
	}//end testANewMunicipalityIsCreatedWithAWarning()

	/**
	 * A municipality or supplier name never matches a merge tombstone or an inactive organisation.
	 *
	 * @return void
	 */
	public function testTombstonedAndInactiveOrganisationsAreNotMatched(): void {
		$this->seedOrganisation(uuid: 'muni-merged', name: 'Gemeente Voorbeeldstad', type: 'Municipality', status: 'merged');
		$this->seedOrganisation(uuid: 'muni-live', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		$this->seedOrganisation(uuid: 'sup-merged', name: 'Fabfrikant', type: 'Supplier', status: 'merged');
		$this->seedOrganisation(uuid: 'sup-inactive', name: 'Fabfrikant', type: 'Supplier', status: 'Inactive');
		$this->seedOrganisation(uuid: 'sup-live', name: 'Fabfrikant', type: 'Supplier');

		$report = $this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1')]))->import(path: '', options: ['municipalityName' => 'Gemeente Voorbeeldstad']);

		$this->assertSame('muni-live', $report['municipality']['uuid']);
		$this->assertFalse($report['municipality']['created']);
		$module = $this->objects(self::MODULE)[0];
		$this->assertSame('sup-live', $module['provider']);
		$this->assertSame('muni-live', $this->objects(self::USAGE)[0]['consumer']);
	}//end testTombstonedAndInactiveOrganisationsAreNotMatched()

	/**
	 * A supplier known only as inactive is created anew, and a merged municipality uuid is refused.
	 *
	 * @return void
	 */
	public function testOnlyRetiredMatchesMeanANewSupplierAndAMergedUuidIsRefused(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		$this->seedOrganisation(uuid: 'sup-inactive', name: 'Fabfrikant', type: 'Supplier', status: 'Inactive');
		$this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1')]))->import(path: '', options: ['municipalityUuid' => 'muni-1']);
		$provider = $this->objects(self::MODULE)[0]['provider'];
		$this->assertNotSame('sup-inactive', $provider);
		$this->assertSame('Active', $this->store[self::ORGANIZATION][$provider]['status']);

		$this->seedOrganisation(uuid: 'muni-merged', name: 'Gemeente Oud', type: 'Municipality', status: 'merged');
		$saves = count($this->saves);
		try {
			$this->service(reader: $this->rowsReader(rows: [$this->row(appId: '2')]))->import(path: '', options: ['municipalityUuid' => 'muni-merged']);
			$this->fail('MUNICIPALITY_INVALID expected');
		} catch (CmdbImportException $e) {
			$this->assertSame('MUNICIPALITY_INVALID', $e->getErrorCode());
		}

		$this->assertCount($saves, $this->saves);
	}//end testOnlyRetiredMatchesMeanANewSupplierAndAMergedUuidIsRefused()

	/**
	 * A module schema without externalKey stops the import before reading: no duplicates of every record.
	 *
	 * @return void
	 */
	public function testAModuleSchemaWithoutTheMatchPropertiesStopsTheImport(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		$this->undeclared[self::MODULE] = ['externalKey', 'externalNumber'];

		try {
			$this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1')]))->import(path: '', options: ['municipalityUuid' => 'muni-1']);
			$this->fail('SCHEMA_OUTDATED expected');
		} catch (CmdbImportException $e) {
			$this->assertSame('SCHEMA_OUTDATED', $e->getErrorCode());
			$this->assertSame(503, $e->getHttpStatus());
			$this->assertSame(['schema' => 'module', 'missing' => ['externalKey', 'externalNumber']], $e->getDetails());
		}

		$this->assertSame([], $this->saves);
		$this->assertSame([], $this->searches, 'refused before any search');
	}//end testAModuleSchemaWithoutTheMatchPropertiesStopsTheImport()

	/**
	 * A module schema from before 0.3.8 stops the import: it has the match properties, but no applicationType and
	 * no admin-only update rule on externalKey.
	 *
	 * @return void
	 */
	public function testAModuleSchemaFromBefore038StopsTheImport(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		$this->undeclared[self::MODULE] = ['applicationType'];
		$this->redefined[self::MODULE]['externalKey'] = ['type' => 'string', 'maxLength' => 200];

		try {
			$this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1')]))->import(path: '', options: ['municipalityUuid' => 'muni-1']);
			$this->fail('SCHEMA_OUTDATED expected');
		} catch (CmdbImportException $e) {
			$this->assertSame('SCHEMA_OUTDATED', $e->getErrorCode());
			$this->assertSame(['schema' => 'module', 'missing' => ['applicationType', 'externalKey.authorization.update']], $e->getDetails());
		}

		$this->assertSame([], $this->saves);

		// The rule alone is missing, as on a 0.3.7 module that has applicationType from elsewhere.
		$this->undeclared = [];
		try {
			$this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1')]))->import(path: '', options: ['municipalityUuid' => 'muni-1']);
			$this->fail('SCHEMA_OUTDATED expected for the missing rule alone');
		} catch (CmdbImportException $e) {
			$this->assertSame(['schema' => 'module', 'missing' => ['externalKey.authorization.update']], $e->getDetails());
		}

		$this->assertSame([], $this->saves);
	}//end testAModuleSchemaFromBefore038StopsTheImport()

	/**
	 * A search on a property the schema does not declare yields nothing, as in OpenRegister.
	 *
	 * Guards the double itself: without this, a test could pass on a filter OpenRegister would never apply.
	 *
	 * @return void
	 */
	public function testSearchWithUndeclaredPropertyYieldsNothing(): void {
		$this->store[self::MODULE]['mod-1'] = ['id' => 'mod-1', 'name' => 'Een', 'externalKey' => 'k'];
		$objectService = $this->objectService();

		$this->assertCount(1, $objectService->searchObjects(query: ['@self' => ['schema' => self::MODULE], 'externalKey' => 'k'], _rbac: false, _multitenancy: false));
		$this->undeclared[self::MODULE] = ['externalKey'];
		$this->assertSame([], $objectService->searchObjects(query: ['@self' => ['schema' => self::MODULE], 'externalKey' => 'k'], _rbac: false, _multitenancy: false));
	}//end testSearchWithUndeclaredPropertyYieldsNothing()

	/**
	 * A re-import matches every module of a catalogue larger than one search page.
	 *
	 * @return void
	 */
	public function testAReimportMatchesBeyondTheFirstSearchPage(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		$rows = [];
		for ($index = 1; $index <= 11; $index++) {
			$rows[] = $this->row(appId: (string)$index, row: ($index + 1));
		}

		$this->service(reader: $this->rowsReader(rows: $rows))->import(path: '', options: ['municipalityUuid' => 'muni-1']);
		$report = $this->service(reader: $this->rowsReader(rows: $rows))->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$this->assertSame(11, $report['summary']['unchanged']);
		$this->assertCount(11, $this->store[self::MODULE]);
		$this->assertCount(11, $this->store[self::USAGE]);
	}//end testAReimportMatchesBeyondTheFirstSearchPage()

	/**
	 * A filter OpenRegister does not apply never widens a match: the import checks every candidate itself.
	 *
	 * @return void
	 */
	public function testAnUnappliedFilterDoesNotWidenTheMatch(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		$rows = [$this->row(appId: '1', row: 2), $this->row(appId: '2', row: 3), $this->row(appId: '3', row: 4)];
		$this->service(reader: $this->rowsReader(rows: $rows))->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$this->ignoredFilters = ['externalKey', 'module'];
		$report = $this->service(reader: $this->rowsReader(rows: $rows))->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$this->assertSame(['unchanged', 'unchanged', 'unchanged'], array_column($report['rows'], 'outcome'));
		$this->assertCount(3, $this->store[self::MODULE]);
	}//end testAnUnappliedFilterDoesNotWidenTheMatch()

	/**
	 * Every match search names the register and the schema, and filters on the match fields.
	 *
	 * @return void
	 */
	public function testTheMatchSearchesNameTheirScopeAndFilters(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		$this->service(reader: $this->rowsReader(rows: [$this->row(appId: '7')]))->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$filtersBySchema = [];
		foreach ($this->searches as $query) {
			$this->assertSame(self::REGISTER, $query['@self']['register']);
			$filters = array_filter($query, fn ($key): bool => $key !== '@self' && str_starts_with((string)$key, '_') === false, ARRAY_FILTER_USE_KEY);
			$filtersBySchema[$query['@self']['schema']][] = $filters;
		}

		$this->assertContains(['externalKey' => 'topdesk:muni-1:7'], $filtersBySchema[self::MODULE]);
		$this->assertContains(['consumer' => 'muni-1', 'module' => array_key_first($this->store[self::MODULE])], $filtersBySchema[self::USAGE]);
		$this->assertContains(['type' => 'Supplier'], $filtersBySchema[self::ORGANIZATION]);
	}//end testTheMatchSearchesNameTheirScopeAndFilters()

	/**
	 * The match key is the APPID: a changed Applicatie Code updates the same module.
	 *
	 * @return void
	 */
	public function testTheKeyIsTheAppIdNotTheCode(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		$this->service(reader: $this->rowsReader(rows: [$this->row(appId: '42', cells: ['Applicatie Code' => 'APP-Oud'])]))
			->import(path: '', options: ['municipalityUuid' => 'muni-1']);
		$uuid = array_key_first($this->store[self::MODULE]);

		$report = $this->service(reader: $this->rowsReader(rows: [$this->row(appId: '42', cells: ['Applicatie Code' => 'App-Nieuw'])]))
			->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$this->assertSame('updated', $report['rows'][0]['outcome']);
		$this->assertCount(1, $this->store[self::MODULE]);
		$this->assertSame('App-Nieuw', $this->store[self::MODULE][$uuid]['externalId']);
		$this->assertSame('topdesk:muni-1:42', $this->store[self::MODULE][$uuid]['externalKey']);
		$this->assertSame('42', $this->store[self::MODULE][$uuid]['externalNumber']);
	}//end testTheKeyIsTheAppIdNotTheCode()

	/**
	 * A changed Applicatie Naam updates the module; website, publicationDate and depublicationDate stay.
	 *
	 * @return void
	 */
	public function testAChangedNameUpdatesOnlyTheMappedFields(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		$service = $this->service(reader: $this->rowsReader(rows: [$this->row(appId: '2', cells: ['Applicatie Naam' => 'naamtest123'])]));
		$service->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$uuid = array_key_first($this->store[self::MODULE]);
		$this->store[self::MODULE][$uuid]['website'] = 'https://voorbeeld.example';
		$this->store[self::MODULE][$uuid]['depublicationDate'] = '2026-10-02T00:00:00+00:00';
		$published = $this->store[self::MODULE][$uuid]['publicationDate'];

		$service = $this->service(reader: $this->rowsReader(rows: [$this->row(appId: '2', cells: ['Applicatie Naam' => 'naamtest124'])]));
		$report = $service->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$this->assertSame('updated', $report['rows'][0]['outcome']);
		$this->assertCount(1, $this->store[self::MODULE]);
		$module = $this->store[self::MODULE][$uuid];
		$this->assertSame('naamtest124', $module['name']);
		$this->assertSame('https://voorbeeld.example', $module['website']);
		$this->assertSame($published, $module['publicationDate']);
		$this->assertSame('2026-10-02T00:00:00+00:00', $module['depublicationDate']);
		$this->assertCount(1, $this->store[self::USAGE], 'still one usage');
	}//end testAChangedNameUpdatesOnlyTheMappedFields()

	/**
	 * An existing module without publicationDate does not get one on update.
	 *
	 * @return void
	 */
	public function testAnUpdateNeverWritesPublicationDate(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		$this->store[self::MODULE]['mod-1'] = ['id' => 'mod-1', 'name' => 'Oud', 'externalKey' => 'topdesk:muni-1:1'];

		$report = $this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1')]))->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$this->assertSame('updated', $report['rows'][0]['outcome']);
		$this->assertArrayNotHasKey('publicationDate', $this->store[self::MODULE]['mod-1']);
		$this->assertArrayNotHasKey('type', $this->store[self::MODULE]['mod-1'], 'type is create-only');
		$this->assertSame('Applicatie 1', $this->store[self::MODULE]['mod-1']['name']);
	}//end testAnUpdateNeverWritesPublicationDate()

	/**
	 * With publish false a created module gets no publicationDate and is counted; with true it gets the start time.
	 *
	 * An update leaves publicationDate as it was either way.
	 *
	 * @return void
	 */
	public function testPublishDecidesThePublicationDateOfCreatedModulesOnly(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		$this->store[self::MODULE]['mod-old'] = [
			'id' => 'mod-old',
			'name' => 'Oud',
			'externalKey' => 'topdesk:muni-1:1',
			'publicationDate' => '2026-01-01T00:00:00+00:00',
		];
		$rows = [$this->row(appId: '1', row: 2), $this->row(appId: '2', row: 3), $this->row(appId: '3', row: 4)];

		$report = $this->service(reader: $this->rowsReader(rows: $rows))->import(path: '', options: ['municipalityUuid' => 'muni-1', 'publish' => false]);

		$this->assertSame(['updated', 'created', 'created'], array_column($report['rows'], 'outcome'));
		$this->assertSame(2, $report['summary']['unpublished']);
		$this->assertSame('2026-01-01T00:00:00+00:00', $this->store[self::MODULE]['mod-old']['publicationDate'], 'an update keeps it');
		foreach ([$report['rows'][1]['moduleUuid'], $report['rows'][2]['moduleUuid']] as $uuid) {
			$this->assertArrayNotHasKey('publicationDate', $this->store[self::MODULE][$uuid]);
		}

		$report = $this->service(reader: $this->rowsReader(rows: [$this->row(appId: '4', row: 2)]))->import(path: '', options: ['municipalityUuid' => 'muni-1', 'publish' => true]);

		$this->assertSame(0, $report['summary']['unpublished']);
		$published = $this->store[self::MODULE][$report['rows'][0]['moduleUuid']]['publicationDate'];
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $published);
		$this->assertSame('2026-01-01T00:00:00+00:00', $this->store[self::MODULE]['mod-old']['publicationDate']);

		$report = $this->service(reader: $this->rowsReader(rows: [$this->row(appId: '5', row: 2)]))->import(path: '', options: ['municipalityUuid' => 'muni-1']);
		$this->assertArrayHasKey('publicationDate', $this->store[self::MODULE][$report['rows'][0]['moduleUuid']], 'publish defaults to true');
	}//end testPublishDecidesThePublicationDateOfCreatedModulesOnly()

	/**
	 * A module whose import key was set to this municipality's but that only another organisation uses is not taken over.
	 *
	 * The row is skipped as a conflict, the module and its usage stay as they are, and no second module or usage is created.
	 *
	 * @return void
	 */
	public function testAnImportKeyOnAnotherOrganisationsModuleIsAConflict(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		$this->seedOrganisation(uuid: 'muni-2', name: 'Gemeente Anderstad', type: 'Municipality');
		$foreign = ['id' => 'mod-foreign', 'name' => 'Van Anderstad', 'externalKey' => 'topdesk:muni-1:1', 'website' => 'https://anderstad.example'];
		$this->store[self::MODULE]['mod-foreign'] = $foreign;
		$this->store[self::USAGE]['usage-foreign'] = ['id' => 'usage-foreign', 'consumer' => 'muni-2', 'module' => 'mod-foreign'];
		$before = [count($this->store[self::MODULE]), count($this->store[self::USAGE])];

		foreach ([true, false] as $updateExisting) {
			$report = $this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1')]))->import(
				path: '',
				options: ['municipalityUuid' => 'muni-1', 'updateExisting' => $updateExisting]
			);

			$this->assertSame('skipped', $report['rows'][0]['outcome']);
			$this->assertSame(['conflict: the application with this import key is used by another organisation, so it is not changed'], $report['rows'][0]['reasons']);
			$this->assertNull($report['rows'][0]['moduleUuid']);
			$this->assertNull($report['rows'][0]['usageUuid']);
		}

		$this->assertSame($foreign, $this->store[self::MODULE]['mod-foreign'], 'the other organisation\'s module is unchanged');
		$this->assertSame($before, [count($this->store[self::MODULE]), count($this->store[self::USAGE])], 'no module and no usage is created');
		$this->assertSame(['muni-1', 'muni-2'], array_keys($this->store[self::ORGANIZATION]), 'the row\'s vendor creates no supplier');
		$conflicts = array_filter($this->logLines, static fn (string $line): bool => str_contains($line, 'another organisation uses'));
		$this->assertCount(2, $conflicts);
	}//end testAnImportKeyOnAnotherOrganisationsModuleIsAConflict()

	/**
	 * A module found by its import key is updated when this municipality uses it, also when others use it too, or when nobody does yet.
	 *
	 * @return void
	 */
	public function testAModuleThisMunicipalityUsesOrNobodyUsesIsUpdated(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		$this->seedOrganisation(uuid: 'muni-2', name: 'Gemeente Anderstad', type: 'Municipality');
		$this->store[self::MODULE]['mod-shared'] = ['id' => 'mod-shared', 'name' => 'Oud', 'externalKey' => 'topdesk:muni-1:1'];
		$this->store[self::USAGE]['usage-other'] = ['id' => 'usage-other', 'consumer' => 'muni-2', 'module' => 'mod-shared'];
		$this->store[self::USAGE]['usage-own'] = ['id' => 'usage-own', 'consumer' => 'muni-1', 'module' => 'mod-shared'];
		$this->store[self::MODULE]['mod-new'] = ['id' => 'mod-new', 'name' => 'Nog niet gebruikt', 'externalKey' => 'topdesk:muni-1:2'];

		$report = $this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1', row: 2), $this->row(appId: '2', row: 3)]))
			->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$this->assertSame(['updated', 'updated'], array_column($report['rows'], 'outcome'));
		$this->assertSame(['mod-shared', 'mod-new'], array_column($report['rows'], 'moduleUuid'));
		$this->assertSame('Applicatie 1', $this->store[self::MODULE]['mod-shared']['name']);
		$this->assertSame('Applicatie 2', $this->store[self::MODULE]['mod-new']['name']);
		$this->assertCount(2, $this->objects(self::MODULE));
	}//end testAModuleThisMunicipalityUsesOrNobodyUsesIsUpdated()

	/**
	 * A re-import follows TOPdesk's status, and sets the TIME classification only when the usage is new or the field is empty.
	 *
	 * An administrator's edit of the TIME classification stays; the status and the phase-out date follow the export.
	 *
	 * @return void
	 */
	public function testAReimportFollowsTheStatusAndKeepsTheTimeClassificationOfAUsage(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		$this->store[self::MODULE]['mod-1'] = ['id' => 'mod-1', 'name' => 'Applicatie 1', 'externalKey' => 'topdesk:muni-1:1'];
		$this->store[self::MODULE]['mod-2'] = ['id' => 'mod-2', 'name' => 'Applicatie 2', 'externalKey' => 'topdesk:muni-1:2'];
		$this->store[self::USAGE]['usage-1'] = [
			'id' => 'usage-1',
			'consumer' => 'muni-1',
			'module' => 'mod-1',
			'status' => 'To be phased out',
			'timeClassification' => 'Migrate',
			'startDateOutPhased' => '2030-01-01',
		];
		$this->store[self::USAGE]['usage-2'] = ['id' => 'usage-2', 'consumer' => 'muni-1', 'module' => 'mod-2'];
		$cells = ['Applicatie Status' => 'In productie', 'Classificatie' => 'Tolereren', 'End-of-Life Functioneel' => 53359];
		$rows = [$this->row(appId: '1', cells: $cells, row: 2), $this->row(appId: '2', cells: $cells, row: 3)];

		$this->service(reader: $this->rowsReader(rows: $rows))->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$this->assertSame('In production', $this->store[self::USAGE]['usage-1']['status'], 'the status follows TOPdesk');
		$this->assertSame('Migrate', $this->store[self::USAGE]['usage-1']['timeClassification'], 'an edited TIME classification stays');
		$this->assertSame('2046-02-01', $this->store[self::USAGE]['usage-1']['startDateOutPhased'], 'the phase-out date follows the export');
		$this->assertSame('In production', $this->store[self::USAGE]['usage-2']['status'], 'an empty status is filled');
		$this->assertSame('Tolerate', $this->store[self::USAGE]['usage-2']['timeClassification'], 'an empty TIME classification is filled');
	}//end testAReimportFollowsTheStatusAndKeepsTheTimeClassificationOfAUsage()

	/**
	 * A municipality uuid must be an organisation of type Municipality.
	 *
	 * @return void
	 */
	public function testTheMunicipalityMustBeAMunicipality(): void {
		$this->seedOrganisation(uuid: 'supplier-1', name: 'Voorbeeld Software B.V.', type: 'Supplier');
		foreach ([['municipalityUuid' => 'supplier-1'], ['municipalityUuid' => 'unknown-uuid']] as $options) {
			try {
				$this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1')]))->import(path: '', options: $options);
				$this->fail('MUNICIPALITY_INVALID expected');
			} catch (CmdbImportException $e) {
				$this->assertSame('MUNICIPALITY_INVALID', $e->getErrorCode());
				$this->assertSame(422, $e->getHttpStatus());
			}
		}

		try {
			$this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1')]))->import(path: '', options: ['municipalityName' => '  ']);
			$this->fail('MUNICIPALITY_REQUIRED expected');
		} catch (CmdbImportException $e) {
			$this->assertSame('MUNICIPALITY_REQUIRED', $e->getErrorCode());
		}

		$this->assertSame([], $this->saves, 'nothing is written');
	}//end testTheMunicipalityMustBeAMunicipality()

	/**
	 * "Fabfrikant", "Fabfrikant " and "FABFRIKANT" are one supplier; an existing supplier is reused.
	 *
	 * @return void
	 */
	public function testAVendorIsOneSupplier(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		$this->seedOrganisation(uuid: 'aangetekend', name: 'Aangetekend B.V.', type: 'Supplier');
		$rows = [
			$this->row(appId: '1', cells: ['Vendor' => 'Fabfrikant'], row: 2),
			$this->row(appId: '2', cells: ['Vendor' => 'Fabfrikant '], row: 3),
			$this->row(appId: '3', cells: ['Vendor' => 'FABFRIKANT'], row: 4),
			$this->row(appId: '4', cells: ['Vendor' => 'aangetekend  b.v.'], row: 5),
			$this->row(appId: '5', cells: ['Vendor' => ''], row: 6),
		];

		$report = $this->service(reader: $this->rowsReader(rows: $rows))->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$this->assertSame(5, $report['summary']['created']);
		$suppliers = array_filter($this->objects(self::ORGANIZATION), fn (array $o): bool => $o['type'] === 'Supplier');
		$this->assertCount(2, $suppliers);
		$fabfrikant = array_values(array_filter($suppliers, fn (array $o): bool => $o['name'] === 'Fabfrikant'))[0]['id'];
		$providers = array_column($this->objects(self::MODULE), 'provider', 'externalNumber');
		$this->assertSame([1 => $fabfrikant, 2 => $fabfrikant, 3 => $fabfrikant, 4 => 'aangetekend'], $providers);
	}//end testAVendorIsOneSupplier()

	/**
	 * A Vendor that is the municipality's own name is the municipality, also where a Supplier of that name exists.
	 *
	 * @return void
	 */
	public function testAVendorNamedAsTheMunicipalityIsTheMunicipality(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		$this->seedOrganisation(uuid: 'supplier-twin', name: 'Gemeente Voorbeeldstad', type: 'Supplier');
		$rows = [
			$this->row(appId: '1', cells: ['Vendor' => 'Gemeente Voorbeeldstad'], row: 2),
			$this->row(appId: '2', cells: ['Vendor' => 'gemeente  voorbeeldstad'], row: 3),
		];

		$this->service(reader: $this->rowsReader(rows: $rows))->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$this->assertSame([1 => 'muni-1', 2 => 'muni-1'], array_column($this->objects(self::MODULE), 'provider', 'externalNumber'));
		$this->assertCount(2, $this->objects(self::ORGANIZATION), 'no organisation is created');
	}//end testAVendorNamedAsTheMunicipalityIsTheMunicipality()

	/**
	 * updateExisting=false reports a match as skipped "exists" and writes nothing, not even a new vendor.
	 *
	 * @return void
	 */
	public function testUpdateExistingFalseSkipsMatches(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		$this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1')]))->import(path: '', options: ['municipalityUuid' => 'muni-1']);
		$saves = count($this->saves);
		$organisations = array_keys($this->store[self::ORGANIZATION]);

		$cells = ['Applicatie Naam' => 'Anders', 'Vendor' => 'Nieuwe Leverancier'];
		$report = $this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1', cells: $cells)]))
			->import(path: '', options: ['municipalityUuid' => 'muni-1', 'updateExisting' => false]);

		$this->assertSame('skipped', $report['rows'][0]['outcome']);
		$this->assertSame(['exists'], $report['rows'][0]['reasons']);
		$this->assertSame($saves, count($this->saves));
		$this->assertSame($organisations, array_keys($this->store[self::ORGANIZATION]), 'the unknown vendor creates no supplier');
	}//end testUpdateExistingFalseSkipsMatches()

	/**
	 * The uuid of the stored module with this APPID.
	 *
	 * @param string $appId The APPID.
	 *
	 * @return string
	 */
	private function moduleOf(string $appId): string {
		foreach ($this->store[self::MODULE] as $uuid => $module) {
			if (($module['externalNumber'] ?? null) === $appId) {
				return (string)$uuid;
			}
		}

		$this->fail('no module with APPID ' . $appId);
	}//end moduleOf()

	/**
	 * The uuid of the stored usage of a module by a consumer.
	 *
	 * @param string $moduleUuid The module.
	 * @param string $consumer The consumer.
	 *
	 * @return string
	 */
	private function usageOf(string $moduleUuid, string $consumer = 'muni-1'): string {
		foreach ($this->store[self::USAGE] as $uuid => $usage) {
			if (($usage['module'] ?? null) === $moduleUuid && ($usage['consumer'] ?? null) === $consumer) {
				return (string)$uuid;
			}
		}

		$this->fail('no usage of ' . $moduleUuid . ' by ' . $consumer);
	}//end usageOf()

	/**
	 * Whether a stored object carries the archive marker, and whether the trash marker.
	 *
	 * @param int $schema The schema id.
	 * @param string $uuid The object.
	 *
	 * @return array{0: bool, 1: bool} Archived, deleted.
	 */
	private function stateOf(int $schema, string $uuid): array {
		$state = ($this->states[$schema][$uuid] ?? []);
		return [empty($state['archived']) === false, empty($state['deleted']) === false];
	}//end stateOf()

	/**
	 * Import APPIDs 1, 7 and 8 for muni-1, as a first import would.
	 *
	 * @return void
	 */
	private function importOneSevenAndEight(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		$rows = [
			$this->row(appId: '1', row: 2),
			$this->row(appId: '7', row: 3, sheet: 'Onbeh Applicaties CMDB'),
			$this->row(appId: '8', row: 4, cells: ['Applicatie Eigenaar (Persoon)' => 'Achternaam, Voornaam']),
		];
		$this->service(reader: $this->rowsReader(rows: $rows))->import(path: '', options: ['municipalityUuid' => 'muni-1', 'missingRecords' => 'keep']);
		$this->transitions = [];
	}//end importOneSevenAndEight()

	/**
	 * A module missing from a newer export, and its usage, are left as they are when records are kept.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-records-missing-from-a-newer-export-shall-be-archived-by-default-or-kept-on-request-req-cmdb-012
	 */
	public function testRecordsMissingFromTheExportStayWhenKept(): void {
		$this->importOneSevenAndEight();
		$modules = $this->store[self::MODULE];
		$usages = $this->store[self::USAGE];

		$report = $this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1', cells: ['Applicatie Naam' => 'Nieuw'])]))
			->import(path: '', options: ['municipalityUuid' => 'muni-1', 'missingRecords' => 'keep']);

		foreach ($modules as $uuid => $module) {
			if ($module['externalNumber'] !== '1') {
				$this->assertSame($module, $this->store[self::MODULE][$uuid]);
			}
		}

		$this->assertSame($usages, $this->store[self::USAGE]);
		$this->assertSame([], $this->transitions, 'nothing is archived or deleted');
		$this->assertSame([], $this->states);
		$this->assertSame(['updated'], array_column($report['rows'], 'outcome'));
		$this->assertSame([], $report['importWarnings'], 'keep does not need the archive sheet');
	}//end testRecordsMissingFromTheExportStayWhenKept()

	/**
	 * By default an application that moved to the archive sheet is archived and one on no sheet is soft-deleted,
	 * usage before module, and the owner contact person stays.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-an-application-missing-from-the-cmdb-sheets-shall-be-archived-when-the-archive-sheet-lists-it-and-soft-deleted-when-no-sheet-does-req-cmdb-015
	 */
	public function testMissingApplicationsAreArchivedOrDeletedByDefault(): void {
		$this->importOneSevenAndEight();
		$contactPersons = $this->store[self::CONTACT_PERSON];
		$this->assertNotSame([], $contactPersons, 'APPID 8 has an owner');
		[$seven, $eight] = [$this->moduleOf(appId: '7'), $this->moduleOf(appId: '8')];

		$report = $this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1')], archived: ['7', '1198']))
			->import(path: '', options: ['municipalityUuid' => 'muni-1', 'operationId' => 'cmdb-archive-01']);

		$this->assertSame([true, false], $this->stateOf(schema: self::MODULE, uuid: $seven));
		$this->assertSame([true, false], $this->stateOf(schema: self::USAGE, uuid: $this->usageOf(moduleUuid: $seven)));
		$this->assertSame([false, true], $this->stateOf(schema: self::MODULE, uuid: $eight));
		$this->assertSame([false, true], $this->stateOf(schema: self::USAGE, uuid: $this->usageOf(moduleUuid: $eight)));
		$this->assertSame([false, false], $this->stateOf(schema: self::MODULE, uuid: $this->moduleOf(appId: '1')));
		$this->assertSame(
			[
				['archive', self::USAGE, $this->usageOf(moduleUuid: $seven)],
				['archive', self::MODULE, $seven],
				['delete', self::USAGE, $this->usageOf(moduleUuid: $eight)],
				['delete', self::MODULE, $eight],
			],
			array_map(static fn (array $t): array => array_slice($t, 0, 3), $this->transitions),
			'the usage is written before its module'
		);
		$this->assertSame('cmdb-import: on sheet "Gearchiveerde Applicaties" (cmdb-archive-01)', $this->transitions[0][3]);
		$this->assertSame($contactPersons, $this->store[self::CONTACT_PERSON], 'contact persons are never touched');

		$reconciled = array_values(array_filter($report['rows'], static fn (array $row): bool => $row['row'] === 0));
		$this->assertSame(
			[
				['sheet' => 'Gearchiveerde Applicaties', 'row' => 0, 'appId' => '7', 'name' => 'Applicatie 7', 'outcome' => 'archived', 'reasons' => [], 'warnings' => [], 'moduleUuid' => $seven, 'usageUuid' => $this->usageOf(moduleUuid: $seven)],
				['sheet' => '', 'row' => 0, 'appId' => '8', 'name' => 'Applicatie 8', 'outcome' => 'deleted', 'reasons' => [], 'warnings' => [], 'moduleUuid' => $eight, 'usageUuid' => $this->usageOf(moduleUuid: $eight)],
			],
			$reconciled
		);
		$this->assertSame(1, $report['summary']['processed'], 'reconciliation entries are not sheet rows');
		$this->assertSame([1, 1], [$report['summary']['archived'], $report['summary']['deleted']]);
		$this->assertStringNotContainsString('Achternaam', (string)json_encode($report));
		$this->assertSame([], $report['importWarnings']);

		$this->transitions = [];
		$again = $this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1')], archived: ['7']))
			->import(path: '', options: ['municipalityUuid' => 'muni-1']);
		$this->assertSame([], $this->transitions, 'an application already in the state the export asks for is left alone');
		$this->assertSame(['unchanged'], array_column($again['rows'], 'outcome'));
	}//end testMissingApplicationsAreArchivedOrDeletedByDefault()

	/**
	 * Without the archive sheet a missing application is archived, never deleted, and the report warns.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-an-application-missing-from-the-cmdb-sheets-shall-be-archived-when-the-archive-sheet-lists-it-and-soft-deleted-when-no-sheet-does-req-cmdb-015
	 */
	public function testWithoutTheArchiveSheetMissingApplicationsAreArchivedNotDeleted(): void {
		$this->importOneSevenAndEight();
		$eight = $this->moduleOf(appId: '8');
		$this->states[self::MODULE][$eight] = ['deleted' => ['deletedBy' => 'admin']];

		$report = $this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1')], archived: null))
			->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$this->assertSame([true, false], $this->stateOf(schema: self::MODULE, uuid: $this->moduleOf(appId: '7')));
		$this->assertSame([true, false], $this->stateOf(schema: self::USAGE, uuid: $this->usageOf(moduleUuid: $this->moduleOf(appId: '7'))));
		$this->assertSame([false, true], $this->stateOf(schema: self::MODULE, uuid: $eight), 'what is in the trash stays there');
		$this->assertSame([true, false], $this->stateOf(schema: self::USAGE, uuid: $this->usageOf(moduleUuid: $eight)));
		$this->assertNotContains('delete', array_column($this->transitions, 0));
		$this->assertNotContains('restore', array_column($this->transitions, 0));
		$this->assertSame('cmdb-import: not in the export (' . $report['operationId'] . ')', $this->transitions[0][3]);
		$this->assertSame(['unchanged', 'archived', 'archived'], array_column($report['rows'], 'outcome'));
		$this->assertSame(
			[['sheet' => 'Gearchiveerde Applicaties', 'message' => 'Sheet "Gearchiveerde Applicaties" was not found, so applications missing from the export are archived, not deleted.']],
			$report['importWarnings']
		);
	}//end testWithoutTheArchiveSheetMissingApplicationsAreArchivedNotDeleted()

	/**
	 * An archive sheet with an APPID the reader could not see is not authoritative for a deletion: the import
	 * archives what it would have deleted, and warns naming the sheet and the row.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-an-application-missing-from-the-cmdb-sheets-shall-be-archived-when-the-archive-sheet-lists-it-and-soft-deleted-when-no-sheet-does-req-cmdb-015
	 */
	public function testAnUncachedArchiveRowArchivesInsteadOfDeleting(): void {
		$this->importOneSevenAndEight();
		[$seven, $eight] = [$this->moduleOf(appId: '7'), $this->moduleOf(appId: '8')];

		// APPID 7 is listed on the archive sheet; APPID 8 is on no sheet, and row 4 of the sheet holds a formula without a cached value.
		$report = $this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1')], archived: ['7'], uncachedRows: [4]))
			->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$this->assertNotContains('delete', array_column($this->transitions, 0), 'nothing is soft-deleted');
		$this->assertSame([true, false], $this->stateOf(schema: self::MODULE, uuid: $seven));
		$this->assertSame([true, false], $this->stateOf(schema: self::MODULE, uuid: $eight), 'APPID 8 may be the unreadable row, so it is archived');
		$this->assertSame([true, false], $this->stateOf(schema: self::USAGE, uuid: $this->usageOf(moduleUuid: $eight)));
		$this->assertSame('cmdb-import: not in the export (' . $report['operationId'] . ')', $this->transitions[2][3]);
		$this->assertSame(
			[['sheet' => 'Gearchiveerde Applicaties', 'message' => 'Sheet "Gearchiveerde Applicaties" has an APPID without a cached value in row 4, so applications missing from the export are archived, not deleted.']],
			$report['importWarnings']
		);
		$this->assertSame([2, 0], [$report['summary']['archived'], $report['summary']['deleted']]);

		// A long list of rows is cut.
		$this->transitions = [];
		$long = $this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1')], archived: ['7'], uncachedRows: range(2, 13)))
			->import(path: '', options: ['municipalityUuid' => 'muni-1', 'missingRecords' => 'archive']);
		$this->assertSame(
			'Sheet "Gearchiveerde Applicaties" has an APPID without a cached value in 12 rows (2, 3, 4, 5, 6, 7, 8, 9, 10, 11, …), so applications missing from the export are archived, not deleted.',
			$long['importWarnings'][0]['message'],
			'the warning counts the rows and cuts the list'
		);

		$few = $this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1')], archived: ['7'], uncachedRows: [3, 9]))
			->import(path: '', options: ['municipalityUuid' => 'muni-1', 'missingRecords' => 'archive']);
		$this->assertStringContainsString('in 2 rows (3, 9), so', $few['importWarnings'][0]['message']);

		// Keeping missing records needs no sheet and gives no warning.
		$kept = $this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1')], archived: ['7'], uncachedRows: [4]))
			->import(path: '', options: ['municipalityUuid' => 'muni-1', 'missingRecords' => 'keep']);
		$this->assertSame([], $kept['importWarnings']);
	}//end testAnUncachedArchiveRowArchivesInsteadOfDeleting()

	/**
	 * A CMDB-sheet row whose APPID the reader could not see is skipped as empty, so its application looks absent:
	 * the import archives what it would have deleted, and warns naming the CMDB sheet and the row.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-an-application-missing-from-the-cmdb-sheets-shall-be-archived-when-the-archive-sheet-lists-it-and-soft-deleted-when-no-sheet-does-req-cmdb-015
	 */
	public function testAnUncachedCmdbRowArchivesInsteadOfDeleting(): void {
		$this->importOneSevenAndEight();
		[$seven, $eight] = [$this->moduleOf(appId: '7'), $this->moduleOf(appId: '8')];

		// APPID 7 is on the readable archive sheet; APPID 8 is on no readable sheet, and row 5 of a CMDB sheet has an APPID formula without a cached value.
		$report = $this->service(
			reader: $this->rowsReader(
				rows: [$this->row(appId: '1'), $this->row(appId: '', cells: ['APPID' => null], row: 5, uncached: ['APPID'])],
				archived: ['7']
			)
		)->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$this->assertNotContains('delete', array_column($this->transitions, 0), 'nothing is soft-deleted');
		$this->assertSame([true, false], $this->stateOf(schema: self::MODULE, uuid: $seven));
		$this->assertSame([true, false], $this->stateOf(schema: self::MODULE, uuid: $eight), 'APPID 8 may be the unreadable row, so it is archived');
		$this->assertSame([true, false], $this->stateOf(schema: self::USAGE, uuid: $this->usageOf(moduleUuid: $eight)));
		$this->assertSame([2, 0], [$report['summary']['archived'], $report['summary']['deleted']]);
		$this->assertSame(
			[['sheet' => 'Beheerde Applicaties CMDB', 'message' => 'Sheet "Beheerde Applicaties CMDB" has an APPID without a cached value in row 5, so applications missing from the export are archived, not deleted.']],
			$report['importWarnings']
		);

		// Keeping missing records needs no warning, and without the unreadable row the same workbook deletes.
		$this->transitions = [];
		$this->states = array_replace_recursive($this->states, [self::MODULE => [$eight => ['archived' => null]], self::USAGE => [$this->usageOf(moduleUuid: $eight) => ['archived' => null]]]);
		$kept = $this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1'), $this->row(appId: '', cells: ['APPID' => null], row: 5, uncached: ['APPID'])], archived: ['7']))
			->import(path: '', options: ['municipalityUuid' => 'muni-1', 'missingRecords' => 'keep']);
		$this->assertSame([], $kept['importWarnings']);
		$this->assertSame([], $this->transitions);

		$readable = $this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1')], archived: ['7']))
			->import(path: '', options: ['municipalityUuid' => 'muni-1']);
		$this->assertSame([false, true], $this->stateOf(schema: self::MODULE, uuid: $eight), 'a readable workbook still soft-deletes what is on no sheet');
		$this->assertSame([], $readable['importWarnings']);
	}//end testAnUncachedCmdbRowArchivesInsteadOfDeleting()

	/**
	 * A CMDB row whose only content is an uncached APPID is dropped by the reader before the service sees a row, and
	 * is handed over as a list of rows: the import still archives instead of deleting, and warns.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-an-application-missing-from-the-cmdb-sheets-shall-be-archived-when-the-archive-sheet-lists-it-and-soft-deleted-when-no-sheet-does-req-cmdb-015
	 */
	public function testAFullyEmptyCmdbRowWithAnUncachedKeyArchivesInsteadOfDeleting(): void {
		$this->importOneSevenAndEight();
		$eight = $this->moduleOf(appId: '8');

		$report = $this->service(
			reader: $this->rowsReader(rows: [$this->row(appId: '1')], archived: ['7'], uncachedKeyRows: ['Beheerde Applicaties CMDB' => [6]])
		)->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$this->assertNotContains('delete', array_column($this->transitions, 0));
		$this->assertSame([true, false], $this->stateOf(schema: self::MODULE, uuid: $eight));
		$this->assertSame(
			[['sheet' => 'Beheerde Applicaties CMDB', 'message' => 'Sheet "Beheerde Applicaties CMDB" has an APPID without a cached value in row 6, so applications missing from the export are archived, not deleted.']],
			$report['importWarnings']
		);
	}//end testAFullyEmptyCmdbRowWithAnUncachedKeyArchivesInsteadOfDeleting()

	/**
	 * A workbook that lacks one of the source sheets is not the whole CMDB: what is on no sheet is archived, not deleted,
	 * and the import names the missing sheet.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-an-application-missing-from-the-cmdb-sheets-shall-be-archived-when-the-archive-sheet-lists-it-and-soft-deleted-when-no-sheet-does-req-cmdb-015
	 */
	public function testAMissingSourceSheetArchivesInsteadOfDeleting(): void {
		$this->importOneSevenAndEight();
		$eight = $this->moduleOf(appId: '8');

		$report = $this->service(
			reader: $this->rowsReader(rows: [$this->row(appId: '1')], archived: ['7'], absentSheets: ['Beheerde Applicaties CMDB'])
		)->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$this->assertNotContains('delete', array_column($this->transitions, 0), 'nothing is soft-deleted');
		$this->assertSame([true, false], $this->stateOf(schema: self::MODULE, uuid: $eight), 'APPID 8 may be on the missing sheet, so it is archived');
		$this->assertSame([true, false], $this->stateOf(schema: self::USAGE, uuid: $this->usageOf(moduleUuid: $eight)));
		$this->assertSame(0, $report['summary']['deleted']);
		$this->assertSame(
			[['sheet' => 'Beheerde Applicaties CMDB', 'message' => 'Sheet "Beheerde Applicaties CMDB" was not found, so applications missing from the export are archived, not deleted.']],
			$report['importWarnings']
		);
	}//end testAMissingSourceSheetArchivesInsteadOfDeleting()

	/**
	 * An extra usage of a key that cannot be archived fails the application's row; its module, written after every usage, stays
	 * as it was, and the other applications are still reconciled.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-an-application-missing-from-the-cmdb-sheets-shall-be-archived-when-the-archive-sheet-lists-it-and-soft-deleted-when-no-sheet-does-req-cmdb-015
	 */
	public function testAFailingExtraUsageFailsTheApplicationBeforeItsModule(): void {
		$this->importOneSevenAndEight();
		[$seven, $eight] = [$this->moduleOf(appId: '7'), $this->moduleOf(appId: '8')];
		$sevenUsage = $this->usageOf(moduleUuid: $seven);
		$this->store[self::USAGE]['usage-second'] = array_merge($this->store[self::USAGE][$sevenUsage], ['id' => 'usage-second']);
		$this->beforeTransition = static function (string $verb, string $uuid): void {
			if ($uuid === 'usage-second') {
				throw new RuntimeException('the extra usage cannot be archived');
			}
		};

		$report = $this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1')], archived: ['7']))
			->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$rows = array_values(array_filter($report['rows'], static fn (array $row): bool => $row['row'] === 0));
		$this->assertSame(['failed', 'deleted'], array_column($rows, 'outcome'));
		$this->assertSame(['step "archive" failed (RuntimeException)'], $rows[0]['reasons']);
		$this->assertSame([true, false], $this->stateOf(schema: self::USAGE, uuid: $sevenUsage), 'the application\'s own usage went first');
		$this->assertSame([false, false], $this->stateOf(schema: self::USAGE, uuid: 'usage-second'), 'the failing extra is unchanged');
		$this->assertSame([false, false], $this->stateOf(schema: self::MODULE, uuid: $seven), 'the module comes after every usage, so it was not reached');
		$this->assertSame([false, true], $this->stateOf(schema: self::MODULE, uuid: $eight), 'the next application is still reconciled');
		$this->assertSame(1, $report['summary']['failed']);
	}//end testAFailingExtraUsageFailsTheApplicationBeforeItsModule()

	/**
	 * A cancelled import reconciles nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-an-application-missing-from-the-cmdb-sheets-shall-be-archived-when-the-archive-sheet-lists-it-and-soft-deleted-when-no-sheet-does-req-cmdb-015
	 */
	public function testACancelledImportDoesNotReconcile(): void {
		$this->importOneSevenAndEight();
		$service = null;
		$this->beforeSave = function (int $schema) use (&$service): void {
			if ($schema === self::MODULE) {
				$service->requestCancel(operationId: 'cmdb-cancel-02');
			}
		};
		$service = $this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1', cells: ['Applicatie Naam' => 'Nieuw']), $this->row(appId: '2', row: 3)]));

		$report = $service->import(path: '', options: ['municipalityUuid' => 'muni-1', 'operationId' => 'cmdb-cancel-02']);

		$this->assertTrue($report['cancelled']);
		$this->assertSame([], $this->transitions);
		$this->assertSame(['updated'], array_column($report['rows'], 'outcome'));
	}//end testACancelledImportDoesNotReconcile()

	/**
	 * A cancel that comes in while the last row is processed stops the import before anything is archived or deleted.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-an-application-missing-from-the-cmdb-sheets-shall-be-archived-when-the-archive-sheet-lists-it-and-soft-deleted-when-no-sheet-does-req-cmdb-015
	 */
	public function testACancelDuringTheLastRowArchivesNothing(): void {
		$this->importOneSevenAndEight();
		$service = null;
		$this->beforeSave = function (int $schema) use (&$service): void {
			if ($schema === self::MODULE) {
				$this->assertTrue($service->requestCancel(operationId: 'cmdb-cancel-03'));
			}
		};
		$service = $this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1', cells: ['Applicatie Naam' => 'Nieuw'])], archived: ['7', '8']));
		$this->trashReads = 0;
		$this->searches = [];

		$report = $service->import(path: '', options: ['municipalityUuid' => 'muni-1', 'operationId' => 'cmdb-cancel-03']);

		$this->assertTrue($report['cancelled']);
		$this->assertSame([], $this->transitions);
		$this->assertSame(0, $this->trashReads, 'the scope was not loaded: the trash was not read');
		$usageScans = array_filter($this->searches, static fn (array $query): bool => ($query['consumer'] ?? null) === 'muni-1' && isset($query['_offset']) === true);
		$this->assertSame([], $usageScans, 'the municipality\'s usages were not paged');
		$this->assertSame(['updated'], array_column($report['rows'], 'outcome'));
		$this->assertSame('cancelled', $this->cache['progress_cmdb-cancel-03']['status']);
		$this->assertArrayNotHasKey('cancel_cmdb-cancel-03', $this->cache);
	}//end testACancelDuringTheLastRowArchivesNothing()

	/**
	 * A cancel during the reconciliation stops it before the next application and keeps what was archived.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-an-application-missing-from-the-cmdb-sheets-shall-be-archived-when-the-archive-sheet-lists-it-and-soft-deleted-when-no-sheet-does-req-cmdb-015
	 */
	public function testACancelDuringTheReconciliationStopsBeforeTheNextApplication(): void {
		$this->importOneSevenAndEight();
		[$seven, $eight] = [$this->moduleOf(appId: '7'), $this->moduleOf(appId: '8')];
		$service = null;
		$this->beforeTransition = function (string $verb, string $uuid) use (&$service, $seven): void {
			if ($uuid === $seven) {
				$this->assertTrue($service->requestCancel(operationId: 'cmdb-cancel-04'));
			}
		};
		$service = $this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1')], archived: ['7', '8']));

		$report = $service->import(path: '', options: ['municipalityUuid' => 'muni-1', 'operationId' => 'cmdb-cancel-04']);

		$this->assertTrue($report['cancelled']);
		$this->assertSame([['archive', $this->usageOf(moduleUuid: $seven)], ['archive', $seven]], array_map(static fn (array $step): array => [$step[0], $step[2]], $this->transitions));
		$this->assertSame([true, false], $this->stateOf(schema: self::MODULE, uuid: $seven), 'what was archived stays archived');
		$this->assertSame([false, false], $this->stateOf(schema: self::MODULE, uuid: $eight));
		$this->assertSame([false, false], $this->stateOf(schema: self::USAGE, uuid: $this->usageOf(moduleUuid: $eight)));
		$this->assertSame(['unchanged', 'archived'], array_column($report['rows'], 'outcome'));
		$this->assertSame('cancelled', $this->cache['progress_cmdb-cancel-04']['status']);
	}//end testACancelDuringTheReconciliationStopsBeforeTheNextApplication()

	/**
	 * Only this municipality's usages and the modules its import created are reconciled.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-an-application-missing-from-the-cmdb-sheets-shall-be-archived-when-the-archive-sheet-lists-it-and-soft-deleted-when-no-sheet-does-req-cmdb-015
	 */
	public function testAnotherMunicipalitysUsagesAreNeverReconciled(): void {
		$this->importOneSevenAndEight();
		$this->seedOrganisation(uuid: 'muni-2', name: 'Gemeente Anders', type: 'Municipality');
		$seven = $this->moduleOf(appId: '7');
		// Another municipality uses the same module (added by hand), and muni-1 uses a hand-made module.
		$this->store[self::USAGE]['usage-other'] = ['id' => 'usage-other', 'consumer' => 'muni-2', 'module' => $seven];
		$this->store[self::MODULE]['module-manual'] = ['id' => 'module-manual', 'name' => 'Handmatig', 'externalKey' => 'topdesk:muni-2:9'];
		$this->store[self::USAGE]['usage-manual'] = ['id' => 'usage-manual', 'consumer' => 'muni-1', 'module' => 'module-manual'];

		$this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1'), $this->row(appId: '8', row: 3)], archived: ['7']))
			->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$this->assertSame([true, false], $this->stateOf(schema: self::USAGE, uuid: $this->usageOf(moduleUuid: $seven)));
		$this->assertSame([true, false], $this->stateOf(schema: self::MODULE, uuid: $seven), 'the module carries this municipality\'s import key');
		$this->assertSame([false, false], $this->stateOf(schema: self::USAGE, uuid: 'usage-other'));
		$this->assertSame([false, false], $this->stateOf(schema: self::USAGE, uuid: 'usage-manual'));
		$this->assertSame([false, false], $this->stateOf(schema: self::MODULE, uuid: 'module-manual'));
		$this->assertSame(2, count($this->transitions));
	}//end testAnotherMunicipalitysUsagesAreNeverReconciled()

	/**
	 * A working pair and a stale pair in the trash under the same import key: the reconciliation acts on the
	 * working one, as the import's own match does, and never restores the stale one into the archive.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-an-application-missing-from-the-cmdb-sheets-shall-be-archived-when-the-archive-sheet-lists-it-and-soft-deleted-when-no-sheet-does-req-cmdb-015
	 */
	public function testAStaleDuplicateIsNeverRestoredByTheReconciliation(): void {
		$this->importOneSevenAndEight();
		$seven = $this->moduleOf(appId: '7');
		$usage = $this->usageOf(moduleUuid: $seven);
		// A stale module deleted by hand, with its own usage, carries the same import key as the working one.
		$this->store[self::MODULE] = ['module-stale' => array_merge($this->store[self::MODULE][$seven], ['id' => 'module-stale'])] + $this->store[self::MODULE];
		$this->store[self::USAGE] = ['usage-stale' => array_merge($this->store[self::USAGE][$usage], ['id' => 'usage-stale', 'module' => 'module-stale'])] + $this->store[self::USAGE];
		$this->states[self::MODULE]['module-stale'] = ['deleted' => ['deletedBy' => 'admin', 'deletedAt' => '2026-10-01T00:00:00+00:00']];
		$this->states[self::USAGE]['usage-stale'] = ['deleted' => ['deletedBy' => 'admin', 'deletedAt' => '2026-10-01T00:00:00+00:00']];

		// APPID 7 is on the archive sheet; APPID 8 is on no sheet.
		$report = $this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1')], archived: ['7']))
			->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$this->assertNotContains('restore', array_column($this->transitions, 0), 'the stale duplicate is not restored');
		$this->assertSame([false, true], $this->stateOf(schema: self::MODULE, uuid: 'module-stale'));
		$this->assertSame([false, true], $this->stateOf(schema: self::USAGE, uuid: 'usage-stale'));
		$this->assertSame([true, false], $this->stateOf(schema: self::MODULE, uuid: $seven));
		$this->assertSame([true, false], $this->stateOf(schema: self::USAGE, uuid: $usage));
		$reconciled = array_values(array_filter($report['rows'], static fn (array $row): bool => $row['row'] === 0));
		$this->assertSame([['7', 'archived', $seven], ['8', 'deleted', $this->moduleOf(appId: '8')]], array_map(static fn (array $row): array => [$row['appId'], $row['outcome'], $row['moduleUuid']], $reconciled));

		// A second run finds the working pair archived and the stale pair in the trash: nothing to do.
		$this->transitions = [];
		$this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1')], archived: ['7']))
			->import(path: '', options: ['municipalityUuid' => 'muni-1']);
		$this->assertSame([], $this->transitions, 'the archived pair is in the state the export asks for, and the stale one is left alone');
		$this->assertSame([false, true], $this->stateOf(schema: self::MODULE, uuid: 'module-stale'));
	}//end testAStaleDuplicateIsNeverRestoredByTheReconciliation()

	/**
	 * Of two usages under one import key, the one of the module the import would match is reconciled, whatever
	 * state the usage is in: the module's state ranks first, the usage's second.
	 *
	 * The working usage is listed before the one in the trash but belongs to the duplicate module in the trash;
	 * it is archived as well (a stray usage stays visible otherwise), and the module in the trash is not restored.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-an-application-missing-from-the-cmdb-sheets-shall-be-archived-when-the-archive-sheet-lists-it-and-soft-deleted-when-no-sheet-does-req-cmdb-015
	 */
	public function testTheModulesStateDecidesWhichDuplicateIsReconciled(): void {
		$this->importOneSevenAndEight();
		$seven = $this->moduleOf(appId: '7');
		$usage = $this->usageOf(moduleUuid: $seven);
		$this->store[self::MODULE]['module-stale'] = array_merge($this->store[self::MODULE][$seven], ['id' => 'module-stale']);
		$this->store[self::USAGE]['usage-stale'] = array_merge($this->store[self::USAGE][$usage], ['id' => 'usage-stale', 'module' => 'module-stale']);
		$this->states[self::MODULE]['module-stale'] = ['deleted' => ['deletedBy' => 'admin']];
		$this->states[self::USAGE][$usage] = ['deleted' => ['deletedBy' => 'admin']];

		$this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1'), $this->row(appId: '8', row: 3)], archived: ['7']))
			->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$this->assertSame([true, false], $this->stateOf(schema: self::MODULE, uuid: $seven), 'the working module is archived');
		$this->assertSame([true, false], $this->stateOf(schema: self::USAGE, uuid: $usage), 'its usage is restored from the trash and archived');
		$this->assertSame([false, true], $this->stateOf(schema: self::MODULE, uuid: 'module-stale'));
		$this->assertSame([true, false], $this->stateOf(schema: self::USAGE, uuid: 'usage-stale'), 'the working usage of the stale module is archived with the key');
	}//end testTheModulesStateDecidesWhichDuplicateIsReconciled()

	/**
	 * A usage in the trash that names another consumer is never reconciled, though the trash is not filtered by consumer.
	 *
	 * The usage points at a working module that carries this municipality's import key; only the consumer
	 * check keeps that module out of the scope, and with it out of the trash.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-an-application-missing-from-the-cmdb-sheets-shall-be-archived-when-the-archive-sheet-lists-it-and-soft-deleted-when-no-sheet-does-req-cmdb-015
	 */
	public function testATrashedUsageOfAnotherConsumerNeverReconcilesItsModule(): void {
		$this->importOneSevenAndEight();
		$this->seedOrganisation(uuid: 'muni-2', name: 'Gemeente Anders', type: 'Municipality');
		$seven = $this->moduleOf(appId: '7');
		$this->store[self::MODULE]['module-nine'] = ['id' => 'module-nine', 'name' => 'Negen', 'externalKey' => 'topdesk:muni-1:9', 'externalNumber' => '9'];
		$this->store[self::USAGE]['usage-other'] = ['id' => 'usage-other', 'consumer' => 'muni-2', 'module' => 'module-nine'];
		$this->states[self::USAGE]['usage-other'] = ['deleted' => ['deletedBy' => 'admin', 'deletedAt' => '2026-10-01T00:00:00+00:00']];

		// APPID 9 is on no sheet, so a scope that took the trashed usage in would soft-delete its module.
		$this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1'), $this->row(appId: '7', row: 3), $this->row(appId: '8', row: 4)], archived: []))
			->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$this->assertSame([false, false], $this->stateOf(schema: self::MODULE, uuid: 'module-nine'), 'the module stays working');
		$this->assertSame([false, true], $this->stateOf(schema: self::USAGE, uuid: 'usage-other'), 'and the other consumer\'s usage stays in the trash');
		$this->assertSame([], $this->transitions);
		$this->assertSame([false, false], $this->stateOf(schema: self::MODULE, uuid: $seven));
	}//end testATrashedUsageOfAnotherConsumerNeverReconcilesItsModule()

	/**
	 * Only a module whose key is exactly this municipality's import key plus an APPID is reconciled.
	 *
	 * A module without an external key, one whose key is the bare prefix, and one whose key has another origin
	 * are used by this municipality but are not what its import created.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-an-application-missing-from-the-cmdb-sheets-shall-be-archived-when-the-archive-sheet-lists-it-and-soft-deleted-when-no-sheet-does-req-cmdb-015
	 */
	public function testOnlyWhatTheImportCreatedIsReconciled(): void {
		$this->importOneSevenAndEight();
		$others = [
			'module-nokey' => ['id' => 'module-nokey', 'name' => 'Zonder sleutel'],
			'module-bare' => ['id' => 'module-bare', 'name' => 'Kale sleutel', 'externalKey' => 'topdesk:muni-1:'],
			'module-origin' => ['id' => 'module-origin', 'name' => 'Andere herkomst', 'externalKey' => 'xxxxxxx:muni-1:9'],
		];
		foreach ($others as $uuid => $module) {
			$this->store[self::MODULE][$uuid] = $module;
			$this->store[self::USAGE]['usage-' . $uuid] = ['id' => 'usage-' . $uuid, 'consumer' => 'muni-1', 'module' => $uuid];
		}

		$this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1'), $this->row(appId: '7', row: 3), $this->row(appId: '8', row: 4)], archived: []))
			->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$this->assertSame([], $this->transitions, 'nothing the import did not create is archived or deleted');
		$this->assertSame([], $this->states);
	}//end testOnlyWhatTheImportCreatedIsReconciled()

	/**
	 * A delete step that OpenRegister refuses, and a restore step that throws, each fail only their own application.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-an-application-missing-from-the-cmdb-sheets-shall-be-archived-when-the-archive-sheet-lists-it-and-soft-deleted-when-no-sheet-does-req-cmdb-015
	 */
	public function testAFailingDeleteOrRestoreStepFailsOnlyItsApplication(): void {
		$this->importOneSevenAndEight();
		[$seven, $eight] = [$this->moduleOf(appId: '7'), $this->moduleOf(appId: '8')];

		// APPID 7 and 8 are on no sheet: OpenRegister refuses to delete the usage of 7, then deletes 8.
		$this->deleteRefusedFor = $this->usageOf(moduleUuid: $seven);
		$report = $this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1')], archived: []))
			->import(path: '', options: ['municipalityUuid' => 'muni-1']);
		$rows = array_values(array_filter($report['rows'], static fn (array $row): bool => $row['row'] === 0));
		$this->assertSame([['7', 'failed'], ['8', 'deleted']], array_map(static fn (array $row): array => [$row['appId'], $row['outcome']], $rows));
		$this->assertSame(['step "delete" failed (RuntimeException)'], $rows[0]['reasons']);
		$this->assertSame([false, false], $this->stateOf(schema: self::MODULE, uuid: $seven), 'the module of the failed application is untouched');
		$this->assertSame([false, true], $this->stateOf(schema: self::MODULE, uuid: $eight));

		// APPID 7 and 8 are in the trash and on the archive sheet: the restore of 7 throws, 8 is restored and archived.
		$this->deleteRefusedFor = null;
		$this->states = [];
		foreach ([$seven, $eight] as $uuid) {
			$this->states[self::MODULE][$uuid] = ['deleted' => ['deletedBy' => 'admin']];
			$this->states[self::USAGE][$this->usageOf(moduleUuid: $uuid)] = ['deleted' => ['deletedBy' => 'admin']];
		}

		$this->beforeTransition = function (string $verb, string $uuid) use ($seven): void {
			if ($verb === 'restore' && $uuid === $this->usageOf(moduleUuid: $seven)) {
				throw new RuntimeException('restore failed');
			}
		};
		$report = $this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1')], archived: ['7', '8']))
			->import(path: '', options: ['municipalityUuid' => 'muni-1']);
		$rows = array_values(array_filter($report['rows'], static fn (array $row): bool => $row['row'] === 0));
		$this->assertSame([['7', 'failed'], ['8', 'archived']], array_map(static fn (array $row): array => [$row['appId'], $row['outcome']], $rows));
		$this->assertSame(['step "restore" failed (RuntimeException)'], $rows[0]['reasons']);
		$this->assertSame([false, true], $this->stateOf(schema: self::MODULE, uuid: $seven), 'the failed application stays in the trash');
		$this->assertSame([true, false], $this->stateOf(schema: self::MODULE, uuid: $eight));
	}//end testAFailingDeleteOrRestoreStepFailsOnlyItsApplication()

	/**
	 * More usages than one page, and more modules than one chunk, are all reconciled.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-an-application-missing-from-the-cmdb-sheets-shall-be-archived-when-the-archive-sheet-lists-it-and-soft-deleted-when-no-sheet-does-req-cmdb-015
	 */
	public function testMoreApplicationsThanOnePageAreAllReconciled(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		$count = 501;
		for ($index = 1; $index <= $count; $index++) {
			$this->store[self::MODULE]['module-' . $index] = ['id' => 'module-' . $index, 'name' => 'Applicatie ' . $index, 'externalKey' => 'topdesk:muni-1:' . $index, 'externalNumber' => (string)$index];
			$this->store[self::USAGE]['usage-' . $index] = ['id' => 'usage-' . $index, 'consumer' => 'muni-1', 'module' => 'module-' . $index];
		}

		// Every application is on no sheet: every one is soft-deleted, the last of both lists included.
		$report = $this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1000')], archived: []))
			->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$this->assertSame($count, $report['summary']['deleted']);
		$this->assertSame([false, true], $this->stateOf(schema: self::MODULE, uuid: 'module-' . $count));
		$this->assertSame([false, true], $this->stateOf(schema: self::USAGE, uuid: 'usage-' . $count));
		$this->assertCount(($count * 2), $this->transitions);
	}//end testMoreApplicationsThanOnePageAreAllReconciled()

	/**
	 * An archived match wins over a deleted one with the same import key, and a working match over an archived one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-an-application-that-returns-to-the-export-shall-be-unarchived-or-restored-never-duplicated-req-cmdb-016
	 */
	public function testTheMatchPrefersWorkingThenArchivedThenDeleted(): void {
		$this->importOneSevenAndEight();
		[$seven, $eight] = [$this->moduleOf(appId: '7'), $this->moduleOf(appId: '8')];
		$sevenUsage = $this->usageOf(moduleUuid: $seven);
		$eightUsage = $this->usageOf(moduleUuid: $eight);
		foreach ([['7', $seven, $sevenUsage], ['8', $eight, $eightUsage]] as [$appId, $module, $usage]) {
			$this->store[self::MODULE]['stale-module-' . $appId] = array_merge($this->store[self::MODULE][$module], ['id' => 'stale-module-' . $appId]);
			$this->store[self::USAGE]['stale-usage-' . $appId] = array_merge($this->store[self::USAGE][$usage], ['id' => 'stale-usage-' . $appId, 'module' => 'stale-module-' . $appId]);
		}

		// APPID 7: the real pair is archived, the stale one deleted. APPID 8: the real pair is working, the stale one archived.
		$gone = ['deleted' => ['deletedBy' => 'admin', 'deletedAt' => '2026-10-01T00:00:00+00:00']];
		$shelved = ['archived' => ['by' => 'admin', 'at' => '2026-10-01T00:00:00+00:00', 'reason' => 'earlier']];
		$this->states[self::MODULE][$seven] = $shelved;
		$this->states[self::USAGE][$sevenUsage] = $shelved;
		$this->states[self::MODULE]['stale-module-7'] = $gone;
		$this->states[self::USAGE]['stale-usage-7'] = $gone;
		$this->states[self::MODULE]['stale-module-8'] = $shelved;
		$this->states[self::USAGE]['stale-usage-8'] = $shelved;
		$counts = [count($this->store[self::MODULE]), count($this->store[self::USAGE])];

		$report = $this->service(reader: $this->rowsReader(rows: [$this->row(appId: '7', row: 2), $this->row(appId: '8', row: 3, sheet: 'Onbeh Applicaties CMDB')]))
			->import(path: '', options: ['municipalityUuid' => 'muni-1', 'missingRecords' => 'keep']);

		$this->assertSame(['unarchived', 'unchanged'], array_column($report['rows'], 'outcome'));
		$this->assertSame([$seven, $eight], array_column($report['rows'], 'moduleUuid'));
		$this->assertSame(
			[['unarchive', $seven], ['unarchive', $sevenUsage]],
			array_map(static fn (array $t): array => [$t[0], $t[2]], $this->transitions),
			'only the preferred pair is revived; nothing is restored from the trash'
		);
		$this->assertSame([false, true], $this->stateOf(schema: self::MODULE, uuid: 'stale-module-7'));
		$this->assertSame([true, false], $this->stateOf(schema: self::MODULE, uuid: 'stale-module-8'));
		$this->assertSame($counts, [count($this->store[self::MODULE]), count($this->store[self::USAGE])], 'no module or usage is created');
	}//end testTheMatchPrefersWorkingThenArchivedThenDeleted()

	/**
	 * With keep and without OpenRegister's archive handler a row whose match is archived fails at the module step.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-an-application-that-returns-to-the-export-shall-be-unarchived-or-restored-never-duplicated-req-cmdb-016
	 */
	public function testKeepWithoutTheArchiveServicesFailsARowWhoseMatchIsArchived(): void {
		$this->importOneSevenAndEight();
		$seven = $this->moduleOf(appId: '7');
		$this->states[self::MODULE][$seven] = ['archived' => ['by' => 'admin', 'at' => '2026-10-01T00:00:00+00:00', 'reason' => 'earlier']];
		$this->archiveServicesAvailable = false;

		$report = $this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1'), $this->row(appId: '7', row: 3, sheet: 'Onbeh Applicaties CMDB')]))
			->import(path: '', options: ['municipalityUuid' => 'muni-1', 'missingRecords' => 'keep']);

		$this->assertSame(['unchanged', 'failed'], array_column($report['rows'], 'outcome'));
		$this->assertSame(['step "module" failed (CmdbImportException)'], $report['rows'][1]['reasons']);
		$this->assertSame([], $this->transitions);
		$this->assertSame(0, $report['summary']['archived'] + $report['summary']['deleted']);
	}//end testKeepWithoutTheArchiveServicesFailsARowWhoseMatchIsArchived()

	/**
	 * Every other usage of a key leaving the export follows the application: a working usage of a module deleted
	 * by hand, and a second usage of the same module. A stale module in the trash is neither restored nor touched.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-an-application-missing-from-the-cmdb-sheets-shall-be-archived-when-the-archive-sheet-lists-it-and-soft-deleted-when-no-sheet-does-req-cmdb-015
	 */
	public function testEveryOtherUsageOfAKeyFollowsTheApplication(): void {
		$this->importOneSevenAndEight();
		[$seven, $eight] = [$this->moduleOf(appId: '7'), $this->moduleOf(appId: '8')];
		$sevenUsage = $this->usageOf(moduleUuid: $seven);
		// APPID 7: a module deleted by hand whose usage stayed working, and a second usage of the working module.
		$this->store[self::MODULE]['module-stale'] = array_merge($this->store[self::MODULE][$seven], ['id' => 'module-stale']);
		$this->states[self::MODULE]['module-stale'] = ['deleted' => ['deletedBy' => 'admin']];
		$this->store[self::USAGE]['usage-stray'] = array_merge($this->store[self::USAGE][$sevenUsage], ['id' => 'usage-stray', 'module' => 'module-stale']);
		$this->store[self::USAGE]['usage-second'] = array_merge($this->store[self::USAGE][$sevenUsage], ['id' => 'usage-second']);
		// APPID 8 also has a stale pair in the trash: nothing to follow.
		$this->store[self::MODULE]['module-stale-8'] = array_merge($this->store[self::MODULE][$eight], ['id' => 'module-stale-8']);
		$this->store[self::USAGE]['usage-stale-8'] = array_merge($this->store[self::USAGE][$this->usageOf(moduleUuid: $eight)], ['id' => 'usage-stale-8', 'module' => 'module-stale-8']);
		$this->states[self::MODULE]['module-stale-8'] = ['deleted' => ['deletedBy' => 'admin']];
		$this->states[self::USAGE]['usage-stale-8'] = ['deleted' => ['deletedBy' => 'admin']];

		// APPID 8 also has a second working module with its own usage, which a first-hit match never reaches.
		$this->store[self::MODULE]['module-dup-8'] = array_merge($this->store[self::MODULE][$eight], ['id' => 'module-dup-8']);
		$this->store[self::USAGE]['usage-dup-8'] = array_merge($this->store[self::USAGE][$this->usageOf(moduleUuid: $eight)], ['id' => 'usage-dup-8', 'module' => 'module-dup-8']);

		// 7 is on the archive sheet, 8 on no sheet.
		$report = $this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1')], archived: ['7']))
			->import(path: '', options: ['municipalityUuid' => 'muni-1']);
		$this->assertSame([[false, true], [false, true]], [$this->stateOf(schema: self::MODULE, uuid: 'module-dup-8'), $this->stateOf(schema: self::USAGE, uuid: 'usage-dup-8')], 'a working duplicate of 8 is deleted with it');

		foreach ([$sevenUsage, 'usage-stray', 'usage-second'] as $usage) {
			$this->assertSame([true, false], $this->stateOf(schema: self::USAGE, uuid: $usage), $usage . ' is archived');
		}

		$this->assertSame([true, false], $this->stateOf(schema: self::MODULE, uuid: $seven));
		$this->assertSame([false, true], $this->stateOf(schema: self::MODULE, uuid: 'module-stale'), 'the stale module stays in the trash');
		$this->assertSame([false, true], $this->stateOf(schema: self::USAGE, uuid: 'usage-stale-8'), 'a usage in the trash is never restored');
		$this->assertNotContains('restore', array_column($this->transitions, 0));
		$this->assertSame([1, 1], [$report['summary']['archived'], $report['summary']['deleted']], 'one report row per application');

		// The same key on no sheet: every usage is soft-deleted, once.
		$this->transitions = [];
		$this->states = array_replace_recursive($this->states, [self::USAGE => [$sevenUsage => ['archived' => null], 'usage-stray' => ['archived' => null], 'usage-second' => ['archived' => null]], self::MODULE => [$seven => ['archived' => null]]]);
		$this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1')], archived: []))
			->import(path: '', options: ['municipalityUuid' => 'muni-1']);
		foreach ([$sevenUsage, 'usage-stray', 'usage-second'] as $usage) {
			$this->assertSame([false, true], $this->stateOf(schema: self::USAGE, uuid: $usage), $usage . ' is soft-deleted');
		}

		$deletes = array_column(array_filter($this->transitions, static fn (array $t): bool => $t[0] === 'delete'), 2);
		$this->assertSame(count($deletes), count(array_unique($deletes)), 'nothing is deleted twice');
	}//end testEveryOtherUsageOfAKeyFollowsTheApplication()

	/**
	 * One application that cannot be archived is a failed row; the others are still reconciled.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-an-application-missing-from-the-cmdb-sheets-shall-be-archived-when-the-archive-sheet-lists-it-and-soft-deleted-when-no-sheet-does-req-cmdb-015
	 */
	public function testAFailingArchiveIsAFailedRowAndTheOthersAreReconciled(): void {
		$this->importOneSevenAndEight();
		$seven = $this->moduleOf(appId: '7');
		$this->beforeTransition = function (string $verb, string $uuid) use ($seven): void {
			if ($uuid === $seven) {
				throw new RuntimeException('Schema "Applicatie 7" does not declare x-openregister-archive');
			}
		};

		$report = $this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1')], archived: ['7', '8']))
			->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$rows = array_values(array_filter($report['rows'], static fn (array $row): bool => $row['row'] === 0));
		$this->assertSame(['failed', 'archived'], array_column($rows, 'outcome'));
		$this->assertSame(['step "archive" failed (RuntimeException)'], $rows[0]['reasons']);
		$this->assertSame([true, false], $this->stateOf(schema: self::USAGE, uuid: $this->usageOf(moduleUuid: $seven)), 'the usage went first');
		$this->assertSame([true, false], $this->stateOf(schema: self::MODULE, uuid: $this->moduleOf(appId: '8')));
		$this->assertSame(1, $report['summary']['failed']);
		$failed = array_values(array_filter($this->logLines, static fn (string $line): bool => str_starts_with($line, 'CmdbExportImportService: row failed')));
		$this->assertCount(1, $failed);
		$this->assertStringNotContainsString('Applicatie 7', $failed[0]);
	}//end testAFailingArchiveIsAFailedRowAndTheOthersAreReconciled()

	/**
	 * Without OpenRegister's archive services an archiving import is refused before the file is read; keep still imports.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-an-application-missing-from-the-cmdb-sheets-shall-be-archived-when-the-archive-sheet-lists-it-and-soft-deleted-when-no-sheet-does-req-cmdb-015
	 */
	public function testMissingArchiveServicesRefuseAnArchivingImport(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		$this->archiveServicesAvailable = false;
		$reader = new class extends CmdbWorkbookReader {
			/**
			 * Constructor.
			 */
			public function __construct() {
			}

			/**
			 * Never reached.
			 *
			 * @param string $path Ignored.
			 * @param CmdbImportProfile $profile Ignored.
			 *
			 * @return array<string, mixed>
			 */
			public function read(string $path, CmdbImportProfile $profile): array {
				throw new RuntimeException('the file was read');
			}
		};

		try {
			$this->service(reader: $reader)->import(path: '', options: ['municipalityUuid' => 'muni-1']);
			$this->fail('ARCHIVE_UNAVAILABLE expected');
		} catch (CmdbImportException $e) {
			$this->assertSame('ARCHIVE_UNAVAILABLE', $e->getErrorCode());
			$this->assertSame(503, $e->getHttpStatus());
		}

		$this->assertSame([], $this->saves);
		$report = $this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1')]))->import(path: '', options: ['municipalityUuid' => 'muni-1', 'missingRecords' => 'keep']);
		$this->assertSame(['created'], array_column($report['rows'], 'outcome'));
	}//end testMissingArchiveServicesRefuseAnArchivingImport()

	/**
	 * An archived application that returns is unarchived and a soft-deleted one restored, before the write; no duplicates.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-an-application-that-returns-to-the-export-shall-be-unarchived-or-restored-never-duplicated-req-cmdb-016
	 */
	public function testAReturningApplicationIsUnarchivedOrRestoredNotDuplicated(): void {
		$this->importOneSevenAndEight();
		[$seven, $eight] = [$this->moduleOf(appId: '7'), $this->moduleOf(appId: '8')];
		$this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1')], archived: ['7']))->import(path: '', options: ['municipalityUuid' => 'muni-1']);
		$modules = count($this->store[self::MODULE]);
		$usages = count($this->store[self::USAGE]);
		$this->transitions = [];

		$rows = [
			$this->row(appId: '1'),
			$this->row(appId: '7', row: 3, sheet: 'Onbeh Applicaties CMDB', cells: ['Applicatie Naam' => 'Terug']),
			$this->row(appId: '8', row: 4),
		];
		$report = $this->service(reader: $this->rowsReader(rows: $rows))->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$this->assertSame(['unchanged', 'unarchived', 'restored'], array_column($report['rows'], 'outcome'));
		$this->assertSame([1, 1], [$report['summary']['unarchived'], $report['summary']['restored']]);
		$this->assertSame([$modules, $usages], [count($this->store[self::MODULE]), count($this->store[self::USAGE])], 'no second module or usage');
		foreach ([$seven, $eight] as $module) {
			$this->assertSame([false, false], $this->stateOf(schema: self::MODULE, uuid: $module));
			$this->assertSame([false, false], $this->stateOf(schema: self::USAGE, uuid: $this->usageOf(moduleUuid: $module)));
		}

		$this->assertSame('Terug', $this->store[self::MODULE][$seven]['name'], 'the row is updated after the unarchive');
		$this->assertSame(['unarchive', 'unarchive', 'restore', 'restore'], array_column($this->transitions, 0));
	}//end testAReturningApplicationIsUnarchivedOrRestoredNotDuplicated()

	/**
	 * A usage archived or soft-deleted on its own, next to a working module, is unarchived or restored when its row returns.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-an-application-that-returns-to-the-export-shall-be-unarchived-or-restored-never-duplicated-req-cmdb-016
	 */
	public function testAReturningUsageIsUnarchivedOrRestoredOnItsOwn(): void {
		$this->importOneSevenAndEight();
		[$seven, $eight] = [$this->moduleOf(appId: '7'), $this->moduleOf(appId: '8')];
		[$usageSeven, $usageEight] = [$this->usageOf(moduleUuid: $seven), $this->usageOf(moduleUuid: $eight)];
		$this->states[self::USAGE][$usageSeven] = ['archived' => ['by' => 'admin', 'at' => '2026-10-01T00:00:00+00:00', 'reason' => 'by hand']];
		$this->states[self::USAGE][$usageEight] = ['deleted' => ['deletedBy' => 'admin', 'deletedAt' => '2026-10-01T00:00:00+00:00']];
		$usages = array_keys($this->store[self::USAGE]);

		$rows = [$this->row(appId: '7', sheet: 'Onbeh Applicaties CMDB'), $this->row(appId: '8', row: 3)];
		$report = $this->service(reader: $this->rowsReader(rows: $rows))->import(path: '', options: ['municipalityUuid' => 'muni-1', 'missingRecords' => 'keep']);

		$this->assertSame(['unarchived', 'restored'], array_column($report['rows'], 'outcome'));
		$this->assertSame([$usageSeven, $usageEight], array_column($report['rows'], 'usageUuid'));
		$this->assertSame($usages, array_keys($this->store[self::USAGE]), 'no second usage');
		$this->assertSame([false, false], $this->stateOf(schema: self::USAGE, uuid: $usageSeven));
		$this->assertSame([false, false], $this->stateOf(schema: self::USAGE, uuid: $usageEight));
		$this->assertSame([['unarchive', $usageSeven], ['restore', $usageEight]], array_map(static fn (array $t): array => [$t[0], $t[2]], $this->transitions));
	}//end testAReturningUsageIsUnarchivedOrRestoredOnItsOwn()

	/**
	 * An application soft-deleted while archived is restored and unarchived when it returns, and updated from the restored object.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-an-application-that-returns-to-the-export-shall-be-unarchived-or-restored-never-duplicated-req-cmdb-016
	 */
	public function testAnApplicationDeletedWhileArchivedIsRestoredAndUnarchived(): void {
		$this->importOneSevenAndEight();
		$seven = $this->moduleOf(appId: '7');
		$usage = $this->usageOf(moduleUuid: $seven);
		$marker = [
			'archived' => ['by' => 'admin', 'at' => '2026-10-01T00:00:00+00:00', 'reason' => 'cmdb-import: on sheet'],
			'deleted' => ['deletedBy' => 'admin', 'deletedAt' => '2026-10-02T00:00:00+00:00'],
		];
		$this->states[self::MODULE][$seven] = $marker;
		$this->states[self::USAGE][$usage] = $marker;
		$counts = [count($this->store[self::MODULE]), count($this->store[self::USAGE])];

		$report = $this->service(reader: $this->rowsReader(rows: [$this->row(appId: '7', sheet: 'Onbeh Applicaties CMDB', cells: ['Applicatie Naam' => 'Terug'])]))
			->import(path: '', options: ['municipalityUuid' => 'muni-1', 'missingRecords' => 'keep']);

		$this->assertSame(['restored'], array_column($report['rows'], 'outcome'));
		$this->assertSame(
			[['restore', $seven], ['unarchive', $seven], ['restore', $usage], ['unarchive', $usage]],
			array_map(static fn (array $t): array => [$t[0], $t[2]], $this->transitions)
		);
		$this->assertSame([false, false], $this->stateOf(schema: self::MODULE, uuid: $seven));
		$this->assertSame([false, false], $this->stateOf(schema: self::USAGE, uuid: $usage));
		$this->assertSame($counts, [count($this->store[self::MODULE]), count($this->store[self::USAGE])], 'no second module or usage');
		$this->assertSame('Terug', $this->store[self::MODULE][$seven]['name']);
		$this->assertSame('7', $this->store[self::MODULE][$seven]['externalNumber'], 'the update starts from the restored object, not from the trash listing');
	}//end testAnApplicationDeletedWhileArchivedIsRestoredAndUnarchived()

	/**
	 * A soft-deleted application the archive sheet lists is restored and archived, and the trash is read once.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-an-application-missing-from-the-cmdb-sheets-shall-be-archived-when-the-archive-sheet-lists-it-and-soft-deleted-when-no-sheet-does-req-cmdb-015
	 */
	public function testADeletedApplicationOnTheArchiveSheetIsRestoredAndArchived(): void {
		$this->importOneSevenAndEight();
		$seven = $this->moduleOf(appId: '7');
		$usage = $this->usageOf(moduleUuid: $seven);
		$this->states[self::MODULE][$seven] = ['deleted' => ['deletedBy' => 'admin', 'deletedAt' => '2026-10-02T00:00:00+00:00']];
		$this->states[self::USAGE][$usage] = ['deleted' => ['deletedBy' => 'admin', 'deletedAt' => '2026-10-02T00:00:00+00:00']];
		$this->trashReads = 0;

		$report = $this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1'), $this->row(appId: '8', row: 3)], archived: ['7']))
			->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$this->assertSame(
			[['restore', $usage], ['archive', $usage], ['restore', $seven], ['archive', $seven]],
			array_map(static fn (array $t): array => [$t[0], $t[2]], $this->transitions)
		);
		$this->assertSame([true, false], $this->stateOf(schema: self::MODULE, uuid: $seven));
		$this->assertSame([true, false], $this->stateOf(schema: self::USAGE, uuid: $usage));
		$reconciled = array_values(array_filter($report['rows'], static fn (array $row): bool => $row['row'] === 0));
		$this->assertSame([['7', 'archived']], array_map(static fn (array $row): array => [$row['appId'], $row['outcome']], $reconciled));
		$this->assertSame(1, $this->trashReads, 'the trash is read once per import, not per row');
	}//end testADeletedApplicationOnTheArchiveSheetIsRestoredAndArchived()

	/**
	 * A trash listing that throws fails its row and is read again by the next row, which restores instead of duplicating.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-an-application-that-returns-to-the-export-shall-be-unarchived-or-restored-never-duplicated-req-cmdb-016
	 */
	public function testAFailedTrashReadIsNotCachedForTheNextRow(): void {
		$this->importOneSevenAndEight();
		$deleted = ['deleted' => ['deletedBy' => 'admin', 'deletedAt' => '2026-10-02T00:00:00+00:00']];
		foreach (['7', '8'] as $appId) {
			$module = $this->moduleOf(appId: $appId);
			$this->states[self::MODULE][$module] = $deleted;
			$this->states[self::USAGE][$this->usageOf(moduleUuid: $module)] = $deleted;
		}

		$counts = [count($this->store[self::MODULE]), count($this->store[self::USAGE])];
		$this->trashReads = 0;
		$this->trashFailures = 1;

		$rows = [$this->row(appId: '8', row: 2), $this->row(appId: '7', row: 3, sheet: 'Onbeh Applicaties CMDB')];
		$report = $this->service(reader: $this->rowsReader(rows: $rows))->import(path: '', options: ['municipalityUuid' => 'muni-1', 'missingRecords' => 'keep']);

		$this->assertSame(['failed', 'restored'], array_column($report['rows'], 'outcome'));
		$this->assertSame(2, $this->trashReads, 'the failed read is not cached');
		$this->assertSame($counts, [count($this->store[self::MODULE]), count($this->store[self::USAGE])], 'no second module or usage');
	}//end testAFailedTrashReadIsNotCachedForTheNextRow()

	/**
	 * With updateExisting false a returning application is skipped as `exists` and stays archived.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-an-application-that-returns-to-the-export-shall-be-unarchived-or-restored-never-duplicated-req-cmdb-016
	 */
	public function testUpdateExistingFalseLeavesAnArchivedMatchArchived(): void {
		$this->importOneSevenAndEight();
		$seven = $this->moduleOf(appId: '7');
		$this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1'), $this->row(appId: '8', row: 3)], archived: ['7']))->import(path: '', options: ['municipalityUuid' => 'muni-1']);
		$modules = $this->store[self::MODULE];
		$this->transitions = [];

		$report = $this->service(reader: $this->rowsReader(rows: [$this->row(appId: '7', sheet: 'Onbeh Applicaties CMDB')]))
			->import(path: '', options: ['municipalityUuid' => 'muni-1', 'updateExisting' => false, 'missingRecords' => 'keep']);

		$this->assertSame(['skipped'], array_column($report['rows'], 'outcome'));
		$this->assertSame(['exists'], $report['rows'][0]['reasons']);
		$this->assertSame([true, false], $this->stateOf(schema: self::MODULE, uuid: $seven));
		$this->assertSame([], $this->transitions);
		$this->assertSame($modules, $this->store[self::MODULE]);
	}//end testUpdateExistingFalseLeavesAnArchivedMatchArchived()

	/**
	 * A module and usage deleted by hand next to the working ones with the same import key stay in the trash.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-an-application-that-returns-to-the-export-shall-be-unarchived-or-restored-never-duplicated-req-cmdb-016
	 */
	public function testAWorkingMatchWinsOverADeletedOneWithTheSameKey(): void {
		$this->importOneSevenAndEight();
		$seven = $this->moduleOf(appId: '7');
		$usage = $this->usageOf(moduleUuid: $seven);
		// Stored ahead of the working ones, so a first-hit match would find them first.
		$this->store[self::MODULE] = ['module-deleted' => array_merge($this->store[self::MODULE][$seven], ['id' => 'module-deleted'])] + $this->store[self::MODULE];
		$this->store[self::USAGE] = ['usage-deleted' => array_merge($this->store[self::USAGE][$usage], ['id' => 'usage-deleted'])] + $this->store[self::USAGE];
		$this->states[self::MODULE]['module-deleted'] = ['deleted' => ['deletedBy' => 'admin', 'deletedAt' => '2026-10-01T00:00:00+00:00']];
		$this->states[self::USAGE]['usage-deleted'] = ['deleted' => ['deletedBy' => 'admin', 'deletedAt' => '2026-10-01T00:00:00+00:00']];
		$counts = [count($this->store[self::MODULE]), count($this->store[self::USAGE])];

		$report = $this->service(reader: $this->rowsReader(rows: [$this->row(appId: '7', sheet: 'Onbeh Applicaties CMDB', cells: ['Applicatie Naam' => 'Terug'])]))
			->import(path: '', options: ['municipalityUuid' => 'muni-1', 'missingRecords' => 'keep']);

		$this->assertSame(['updated'], array_column($report['rows'], 'outcome'));
		$this->assertSame($seven, $report['rows'][0]['moduleUuid']);
		$this->assertSame('Terug', $this->store[self::MODULE][$seven]['name']);
		$this->assertSame([], $this->transitions, 'nothing is restored');
		$this->assertSame([false, true], $this->stateOf(schema: self::MODULE, uuid: 'module-deleted'));
		$this->assertSame([false, true], $this->stateOf(schema: self::USAGE, uuid: 'usage-deleted'));
		$this->assertSame($counts, [count($this->store[self::MODULE]), count($this->store[self::USAGE])], 'no module or usage is created');
	}//end testAWorkingMatchWinsOverADeletedOneWithTheSameKey()

	/**
	 * The fixture round trip of the e2e test: the later export archives 1234 and deletes 2, the original brings both back.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-an-application-missing-from-the-cmdb-sheets-shall-be-archived-when-the-archive-sheet-lists-it-and-soft-deleted-when-no-sheet-does-req-cmdb-015
	 * @spec openspec/changes/cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-an-application-that-returns-to-the-export-shall-be-unarchived-or-restored-never-duplicated-req-cmdb-016
	 */
	public function testTheArchivedApplicationsFixtureArchivesDeletesAndBringsBack(): void {
		$path = $this->fixture();
		$options = ['municipalityName' => 'Gemeente Voorbeeldstad'];
		$this->service()->import(path: $path, options: $options);
		$counts = [count($this->store[self::MODULE]), count($this->store[self::USAGE]), count($this->store[self::CONTACT_PERSON])];
		[$archived, $deleted] = [$this->moduleOf(appId: '1234'), $this->moduleOf(appId: '2')];

		$report = $this->service()->import(path: CmdbTestSupport::fixtures() . '/topdesk-archived-applications.xlsx', options: $options);

		$this->assertSame(['archived', 'deleted'], array_column($report['rows'], 'outcome'));
		$this->assertSame(['1234', '2'], array_column($report['rows'], 'appId'));
		$this->assertSame([true, false], $this->stateOf(schema: self::MODULE, uuid: $archived));
		$this->assertSame([false, true], $this->stateOf(schema: self::MODULE, uuid: $deleted));
		$this->assertSame([], $report['importWarnings']);

		$back = $this->service()->import(path: $path, options: $options);

		$this->assertSame(['unarchived', 'restored'], array_column($back['rows'], 'outcome'));
		$this->assertSame($counts, [count($this->store[self::MODULE]), count($this->store[self::USAGE]), count($this->store[self::CONTACT_PERSON])]);
		$this->assertSame([false, false], $this->stateOf(schema: self::MODULE, uuid: $archived));
		$this->assertSame([false, false], $this->stateOf(schema: self::MODULE, uuid: $deleted));
	}//end testTheArchivedApplicationsFixtureArchivesDeletesAndBringsBack()

	/**
	 * The reconciliation reports its own progress phase and advances per application.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-an-application-missing-from-the-cmdb-sheets-shall-be-archived-when-the-archive-sheet-lists-it-and-soft-deleted-when-no-sheet-does-req-cmdb-015
	 */
	public function testTheReconciliationHasItsOwnProgressPhase(): void {
		$this->importOneSevenAndEight();
		$seen = [];
		$this->beforeTransition = function () use (&$seen): void {
			$progress = $this->tracker->getProgress(operationId: 'cmdb-reconcile-1');
			$seen[] = [$progress['phase'], $progress['processed_items'], $progress['total_items']];
		};

		$this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1')], archived: ['7', '8']))
			->import(path: '', options: ['municipalityUuid' => 'muni-1', 'operationId' => 'cmdb-reconcile-1']);

		// One row, then three usages in scope: APPID 1 (on a CMDB sheet), 7 and 8.
		$this->assertSame([['reconciling', 2, 4], ['reconciling', 2, 4], ['reconciling', 3, 4], ['reconciling', 3, 4]], $seen);
		$this->assertSame('completed', $this->cache['progress_cmdb-reconcile-1']['status']);
		$this->assertSame(4, $this->cache['progress_cmdb-reconcile-1']['processed_items']);
	}//end testTheReconciliationHasItsOwnProgressPhase()

	/**
	 * An unknown Applicatie Status drops only that field and warns with column and value.
	 *
	 * @return void
	 */
	public function testAnUnknownStatusDropsOnlyThatField(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		$report = $this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1', cells: ['Applicatie Status' => 'Onbekende status'])]))
			->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$this->assertSame('created', $report['rows'][0]['outcome']);
		$this->assertCount(1, $report['rows'][0]['warnings']);
		$this->assertStringContainsString('"Applicatie Status"', $report['rows'][0]['warnings'][0]);
		$this->assertStringContainsString('Onbekende status', $report['rows'][0]['warnings'][0]);
		$this->assertSame(1, $report['summary']['warnings']);
		$usage = $this->objects(self::USAGE)[0];
		$this->assertArrayNotHasKey('status', $usage);
		$this->assertCount(1, $this->store[self::MODULE]);
	}//end testAnUnknownStatusDropsOnlyThatField()

	/**
	 * The sheet a row comes from records whether maintenance is arranged, in the usage's internal note;
	 * empty Cluster or Afdeling leave no empty part behind.
	 *
	 * @return void
	 */
	public function testTheSheetRecordsWhetherMaintenanceIsArranged(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		$rows = [
			$this->row(appId: '1', cells: ['Cluster' => 'H10', 'Applicatie Eigenaar (Afdeling)' => 'H10 Accounting'], sheet: 'Onbeh Applicaties CMDB'),
			$this->row(appId: '2', cells: ['Cluster' => '', 'Applicatie Eigenaar (Afdeling)' => 'B10 Ontwikkeling'], row: 3),
			$this->row(appId: '3', cells: ['Cluster' => '', 'Applicatie Eigenaar (Afdeling)' => ''], row: 4),
		];

		$this->service(reader: $this->rowsReader(rows: $rows))->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$notes = array_column($this->objects(self::USAGE), 'interneAnnotation');
		$this->assertSame(['Beheer geregeld: nee / H10 / H10 Accounting', 'Beheer geregeld: ja / B10 Ontwikkeling', 'Beheer geregeld: ja'], $notes);
	}//end testTheSheetRecordsWhetherMaintenanceIsArranged()

	/**
	 * "NB" in BNN Classificatie means empty (no field, no warning); an end-of-life date is stored as the file has it, 2036-01-01 included.
	 *
	 * @return void
	 */
	public function testNbMeansEmptyAndEndOfLifeIsStoredAsIs(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		$rows = [
			$this->row(appId: '1', cells: ['BNN Classificatie' => 'NB', 'End-of-Life Functioneel' => 49675]),
			$this->row(appId: '2', cells: ['BNN Classificatie' => 'BBN 3', 'End-of-Life Functioneel' => 53359, 'Classificatie' => 'Migreren'], row: 3),
		];

		$report = $this->service(reader: $this->rowsReader(rows: $rows))->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$this->assertSame(0, $report['summary']['warnings']);
		$modules = array_column($this->objects(self::MODULE), null, 'externalNumber');
		$this->assertArrayNotHasKey('bbnLevel', $modules[1]);
		$this->assertSame('BBN3', $modules[2]['bbnLevel']);
		$usages = array_column($this->objects(self::USAGE), null, 'module');
		$this->assertSame('2036-01-01', $usages[$modules[1]['id']]['startDateOutPhased']);
		$this->assertSame('2046-02-01', $usages[$modules[2]['id']]['startDateOutPhased']);
		$this->assertSame('Migrate', $usages[$modules[2]['id']]['timeClassification']);
	}//end testNbMeansEmptyAndEndOfLifeIsStoredAsIs()

	/**
	 * The values the municipality's real export holds map without a warning: BNN 1/2/2+, the extra
	 * statuses, the numbered TIME class, and application kinds that are not a hosting model.
	 *
	 * @return void
	 */
	public function testTheValuesOfTheRealExportMapWithoutWarnings(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		$rows = [
			$this->row(appId: '1', cells: ['BNN Classificatie' => '1', 'Applicatiesoort' => 'Client/server', 'Applicatie Status' => 'Moet verwijderd worden', 'Classificatie' => '1. Tolereren (wordt ingelezen)']),
			$this->row(appId: '2', cells: ['BNN Classificatie' => '2', 'Applicatiesoort' => 'Saas', 'Applicatie Status' => 'Wordt getest'], row: 3),
			$this->row(appId: '3', cells: ['BNN Classificatie' => '2+', 'Applicatiesoort' => 'Beheertool', 'Applicatie Status' => 'Stand-by voor continuïteit'], row: 4),
			$this->row(appId: '4', cells: ['Applicatie Status' => 'Besteld'], row: 5),
			$this->row(appId: '5', cells: ['Applicatie Status' => 'Verwijderd'], row: 6),
		];

		$report = $this->service(reader: $this->rowsReader(rows: $rows))->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$this->assertSame(0, $report['summary']['warnings']);
		$modules = array_column($this->objects(self::MODULE), null, 'externalNumber');
		$this->assertSame(['BBN1', 'BBN2', 'BBN2+'], [$modules[1]['bbnLevel'], $modules[2]['bbnLevel'], $modules[3]['bbnLevel']]);
		$this->assertSame(['Client/server', 'Saas', 'Beheertool'], [$modules[1]['applicationType'], $modules[2]['applicationType'], $modules[3]['applicationType']]);
		$this->assertArrayNotHasKey('cloudDienstverleningsmodel', $modules[1], 'Client/server is no hosting model');
		$this->assertSame(['SaaS'], $modules[2]['cloudDienstverleningsmodel']);
		$usages = array_column($this->objects(self::USAGE), null, 'module');
		$status = array_map(fn (int $appId): string => $usages[$modules[$appId]['id']]['status'], [1, 2, 3, 4, 5]);
		$this->assertSame(['To be phased out', 'Acquisition', 'In production', 'Acquisition', 'Phased out'], $status);
		$this->assertSame('Tolerate', $usages[$modules[1]['id']]['timeClassification']);
	}//end testTheValuesOfTheRealExportMapWithoutWarnings()

	/**
	 * A formula without a cached value reads as empty and warns on its row; the row is still imported.
	 *
	 * @return void
	 */
	public function testAFormulaWithoutACachedValueWarns(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		$report = $this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1', cells: ['Roepnaam' => null], uncached: ['Roepnaam'])]))
			->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$this->assertSame('created', $report['rows'][0]['outcome']);
		$this->assertSame(['Column "Roepnaam": formula without a cached value, read as empty'], $report['rows'][0]['warnings']);
		$this->assertArrayNotHasKey('shortDescription', $this->objects(self::MODULE)[0]);
	}//end testAFormulaWithoutACachedValueWarns()

	/**
	 * A test-only module pack that maps one more column changes the import without code.
	 *
	 * @return void
	 */
	public function testAPackChangeChangesTheMapping(): void {
		$directory = sys_get_temp_dir() . '/stackiq-cmdb-pack-' . bin2hex(random_bytes(4));
		mkdir($directory);
		foreach (glob(CmdbTestSupport::appRoot() . '/lib/Settings/cmdb-import/*.json') as $file) {
			copy($file, $directory . '/' . basename($file));
		}

		$pack = json_decode((string)file_get_contents($directory . '/topdesk-module.json'), true);
		$pack['fieldMappings'][] = ['source' => 'Software Suite', 'target' => 'licentietype', 'transform' => ['type' => 'trim']];
		file_put_contents($directory . '/topdesk-module.json', json_encode($pack));

		try {
			$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
			$this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1', cells: ['Software Suite' => 'Suite'])]), profileDir: $directory)
				->import(path: '', options: ['municipalityUuid' => 'muni-1']);
		} finally {
			array_map('unlink', glob($directory . '/*.json'));
			rmdir($directory);
		}

		$this->assertSame('Suite', $this->objects(self::MODULE)[0]['licentietype']);
	}//end testAPackChangeChangesTheMapping()

	// ------------------------------------------------------------------
	// Task 6: the owner as contact person
	// ------------------------------------------------------------------

	/**
	 * Each row's Applicatie Eigenaar (Persoon) becomes the usage's business owner, by display name; a
	 * function in that column is used as the display name too; the function becomes the role.
	 *
	 * @return void
	 */
	public function testTheOwnerBecomesTheBusinessOwner(): void {
		$path = $this->fixture();
		$report = $this->service()->import(path: $path, options: ['municipalityName' => 'Gemeente Voorbeeldstad']);
		$municipality = $report['municipality']['uuid'];

		$this->assertEqualsCanonicalizing(['Voornaam Achternaam', 'Teamleider Applicatiebeheer'], array_column($this->contacts, 'name'));
		$this->assertSame(['', ''], array_column($this->contacts, 'email'), 'the CMDB sheets carry no e-mail address');
		$this->assertSame(['stackiq-cmdb-owners' => 'Stackiq CMDB owners'], $this->addressBooks, 'new owner contacts go into the dedicated address book only');

		$people = $this->objects(self::CONTACT_PERSON);
		$this->assertCount(2, $people);
		$uidByName = array_flip(array_map(fn (array $c): string => $c['name'], $this->contacts));
		$byUid = array_column($people, null, 'contactsUid');
		$this->assertSame(
			['contactsUid' => $uidByName['Voornaam Achternaam'], 'organization' => $municipality, 'role' => 'Afdelingshoofd'],
			array_diff_key($byUid[$uidByName['Voornaam Achternaam']], ['id' => true])
		);
		$this->assertSame('Teamleider Applicatiebeheer', $byUid[$uidByName['Teamleider Applicatiebeheer']]['role']);

		$usages = array_column($this->objects(self::USAGE), null, 'module');
		$this->assertSame($byUid[$uidByName['Voornaam Achternaam']]['id'], $usages[$report['rows'][0]['moduleUuid']]['businessOwner']);
		$this->assertSame($byUid[$uidByName['Teamleider Applicatiebeheer']]['id'], $usages[$report['rows'][1]['moduleUuid']]['businessOwner']);
	}//end testTheOwnerBecomesTheBusinessOwner()

	/**
	 * The same owner on two rows is one contact person, referenced by both usages.
	 *
	 * @return void
	 */
	public function testTheSameOwnerOnTwoRowsIsOneContactPerson(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		$owner = ['Applicatie Eigenaar (Persoon)' => 'Achternaam, Voornaam', 'Applicatie Eigenaar (Functie)' => 'Afdelingshoofd'];
		$this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1', cells: $owner, row: 2), $this->row(appId: '2', cells: $owner, row: 3)]))
			->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$this->assertCount(1, $this->objects(self::CONTACT_PERSON));
		$owners = array_unique(array_column($this->objects(self::USAGE), 'businessOwner'));
		$this->assertSame([$this->objects(self::CONTACT_PERSON)[0]['id']], array_values($owners));
	}//end testTheSameOwnerOnTwoRowsIsOneContactPerson()

	/**
	 * An owner imported twice is one contact and one contact person; a near-namesake is not reused.
	 *
	 * @return void
	 */
	public function testAnOwnerByNameIsMatchedExactly(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		// A contact whose name merely contains the owner's name must not match.
		$this->contacts['contact-other'] = ['name' => 'Voornaam Achternaam-Anders', 'email' => '', 'book' => 'stackiq-cmdb-owners'];
		$rows = [$this->row(appId: '1', cells: ['Applicatie Eigenaar (Persoon)' => 'Achternaam, Voornaam'])];

		$this->service(reader: $this->rowsReader(rows: $rows))->import(path: '', options: ['municipalityUuid' => 'muni-1']);
		$this->service(reader: $this->rowsReader(rows: $rows))->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$this->assertCount(2, $this->contacts, 'one new contact next to the near-namesake');
		$people = $this->objects(self::CONTACT_PERSON);
		$this->assertCount(1, $people);
		$this->assertNotSame('contact-other', $people[0]['contactsUid']);
		$this->assertArrayNotHasKey('role', $people[0]);
		$this->assertSame($people[0]['id'], $this->objects(self::USAGE)[0]['businessOwner']);
	}//end testAnOwnerByNameIsMatchedExactly()

	/**
	 * A namesake in the admin's personal address book is never linked; a contact in the owners' address book is reused.
	 *
	 * @return void
	 */
	public function testOwnersAreMatchedOnlyInTheOwnersAddressBook(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		$this->contacts['contact-personal'] = ['name' => 'Voornaam Achternaam', 'email' => ''];
		$this->contacts['contact-owner'] = ['name' => 'Teamleider Applicatiebeheer', 'email' => '', 'book' => 'stackiq-cmdb-owners'];
		$rows = [
			$this->row(appId: '1', cells: ['Applicatie Eigenaar (Persoon)' => 'Achternaam, Voornaam'], row: 2),
			$this->row(appId: '2', cells: ['Applicatie Eigenaar (Persoon)' => 'Teamleider Applicatiebeheer'], row: 3),
		];

		$this->service(reader: $this->rowsReader(rows: $rows))->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$uids = array_column($this->objects(self::CONTACT_PERSON), 'contactsUid');
		$this->assertNotContains('contact-personal', $uids, 'a personal contact with the same name is not linked');
		$this->assertContains('contact-owner', $uids, 'the contact in the owners\' address book is reused');
		$this->assertCount(3, $this->contacts, 'one new contact, in the owners\' address book');
		$created = array_diff_key($this->contacts, ['contact-personal' => true, 'contact-owner' => true]);
		$this->assertSame(['stackiq-cmdb-owners'], array_values(array_unique(array_column($created, 'book'))));
	}//end testOwnersAreMatchedOnlyInTheOwnersAddressBook()

	/**
	 * With an employee number the owner is matched on it: two namesakes stay two owners, and a renamed owner keeps its contact.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-10-30-cmdb-import-owner-identity/tasks.md#task-2
	 */
	public function testAnOwnerWithAnEmployeeNumberIsMatchedOnItNotOnTheName(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		$first = ['Applicatie Eigenaar (Persoon)' => 'Achternaam, Voornaam', 'Applicatie Eigenaar (Personeelsnummer)' => '1001'];
		$namesake = ['Applicatie Eigenaar (Persoon)' => 'Achternaam, Voornaam', 'Applicatie Eigenaar (Personeelsnummer)' => '2002'];
		$this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1', cells: $first, row: 2), $this->row(appId: '2', cells: $namesake, row: 3)]))
			->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$this->assertCount(2, $this->contacts, 'two namesakes with their own number are two contacts');
		$this->assertCount(2, $this->objects(self::CONTACT_PERSON));
		$uid = $this->ownerIdentity()->contactUid(employeeNumber: '1001', municipalityUuid: 'muni-1');
		$this->assertArrayHasKey($uid, $this->contacts);
		$this->assertStringNotContainsString('1001', (string)$uid, 'the number is not in the contact UID');

		// The owner changed their name; Excel stored the number as a decimal.
		$renamed = ['Applicatie Eigenaar (Persoon)' => 'Nieuwenaam, Voornaam', 'Applicatie Eigenaar (Personeelsnummer)' => '1001.0'];
		$this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1', cells: $renamed, row: 2)]))
			->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$this->assertCount(2, $this->contacts, 'the renamed owner is the same contact');
		$people = array_column($this->objects(self::CONTACT_PERSON), null, 'contactsUid');
		$this->assertSame($people[$uid]['id'], $this->objects(self::USAGE)[0]['businessOwner']);
		$this->assertNull($this->ownerIdentity()->contactUid(employeeNumber: ' ', municipalityUuid: 'muni-1'));
		$this->assertNotSame($uid, $this->ownerIdentity()->contactUid(employeeNumber: '1001', municipalityUuid: 'muni-2'), 'scoped to the municipality');
	}//end testAnOwnerWithAnEmployeeNumberIsMatchedOnItNotOnTheName()

	/**
	 * Two namesakes with their own number and one shared mailbox stay two owners: the e-mail address never overrides the number.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-10-30-cmdb-import-owner-identity/tasks.md#task-2
	 */
	public function testASharedEmailAddressDoesNotMergeOwnersWithTheirOwnNumber(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		$first = ['Applicatie Eigenaar (Persoon)' => 'Achternaam, Voornaam', 'Applicatie Eigenaar (E-mail)' => 'team@example.org', 'Applicatie Eigenaar (Personeelsnummer)' => '1001'];
		$namesake = ['Applicatie Eigenaar (Persoon)' => 'Achternaam, Voornaam', 'Applicatie Eigenaar (E-mail)' => 'team@example.org', 'Applicatie Eigenaar (Personeelsnummer)' => '2002'];
		$this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1', cells: $first, row: 2), $this->row(appId: '2', cells: $namesake, row: 3)]))
			->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$identity = $this->ownerIdentity();
		$this->assertEqualsCanonicalizing(
			[$identity->contactUid(employeeNumber: '1001', municipalityUuid: 'muni-1'), $identity->contactUid(employeeNumber: '2002', municipalityUuid: 'muni-1')],
			array_keys($this->contacts)
		);
		$this->assertCount(2, $this->objects(self::CONTACT_PERSON));
	}//end testASharedEmailAddressDoesNotMergeOwnersWithTheirOwnNumber()

	/**
	 * An owner known by e-mail address gets the employee-number contact once the number arrives; the old contact stays.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-10-30-cmdb-import-owner-identity/tasks.md#task-2
	 */
	public function testAnOwnerFirstKnownByEmailGetsTheNumberContact(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		$this->contacts['contact-email'] = ['name' => 'Voornaam Achternaam', 'email' => 'v.achternaam@example.org', 'book' => 'stackiq-cmdb-owners'];
		$owner = ['Applicatie Eigenaar (Persoon)' => 'Achternaam, Voornaam', 'Applicatie Eigenaar (E-mail)' => 'v.achternaam@example.org', 'Applicatie Eigenaar (Personeelsnummer)' => '1001'];
		$this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1', cells: $owner)]))
			->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$uid = $this->ownerIdentity()->contactUid(employeeNumber: '1001', municipalityUuid: 'muni-1');
		$this->assertArrayHasKey('contact-email', $this->contacts, 'the earlier contact stays');
		$this->assertArrayHasKey($uid, $this->contacts);
		$people = array_column($this->objects(self::CONTACT_PERSON), null, 'contactsUid');
		$this->assertSame($people[$uid]['id'], $this->objects(self::USAGE)[0]['businessOwner']);
	}//end testAnOwnerFirstKnownByEmailGetsTheNumberContact()

	/**
	 * A placeholder in the number column is no employee number: owners with "-" stay apart by name.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-10-30-cmdb-import-owner-identity/tasks.md#task-2
	 */
	public function testAPlaceholderInTheNumberColumnIsNoEmployeeNumber(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		$first = ['Applicatie Eigenaar (Persoon)' => 'Eerste, Voornaam', 'Applicatie Eigenaar (Personeelsnummer)' => '-'];
		$second = ['Applicatie Eigenaar (Persoon)' => 'Tweede, Voornaam', 'Applicatie Eigenaar (Personeelsnummer)' => '-'];
		$this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1', cells: $first, row: 2), $this->row(appId: '2', cells: $second, row: 3)]))
			->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$this->assertCount(2, $this->contacts, 'two owners, not one contact for "-"');
		$this->assertSame([], array_filter(array_keys($this->contacts), fn (string $uid): bool => str_starts_with($uid, CmdbOwnerIdentity::UID_PREFIX)));
	}//end testAPlaceholderInTheNumberColumnIsNoEmployeeNumber()

	/**
	 * An owner without a number never takes a contact identified by an employee number, by e-mail address or by name.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-10-30-cmdb-import-owner-identity/tasks.md#task-2
	 */
	public function testAnOwnerWithoutANumberNeverTakesANumberContact(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		$numbered = ['Applicatie Eigenaar (Persoon)' => 'Achternaam, Voornaam', 'Applicatie Eigenaar (E-mail)' => 'team@example.org', 'Applicatie Eigenaar (Personeelsnummer)' => '1001'];
		$byEmail = ['Applicatie Eigenaar (Persoon)' => 'Anders, Iemand', 'Applicatie Eigenaar (E-mail)' => 'team@example.org'];
		$byName = ['Applicatie Eigenaar (Persoon)' => 'Achternaam, Voornaam'];
		$rows = [$this->row(appId: '1', cells: $numbered, row: 2), $this->row(appId: '2', cells: $byEmail, row: 3), $this->row(appId: '3', cells: $byName, row: 4)];
		$this->service(reader: $this->rowsReader(rows: $rows))->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$this->assertCount(3, $this->contacts, 'the number contact, one for the shared mailbox, one for the namesake');
		$this->assertCount(3, $this->objects(self::CONTACT_PERSON));
	}//end testAnOwnerWithoutANumberNeverTakesANumberContact()

	/**
	 * Any e-mail address of an owner contact matches, not only its first one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-10-30-cmdb-import-owner-identity/tasks.md#task-2
	 */
	public function testASecondEmailAddressOfAContactMatches(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		$this->contacts['contact-two'] = ['name' => 'Voornaam Achternaam', 'email' => 'eerste@example.org', 'emails' => ['tweede@example.org'], 'book' => 'stackiq-cmdb-owners'];
		$owner = ['Applicatie Eigenaar (Persoon)' => 'Achternaam, Voornaam', 'Applicatie Eigenaar (E-mail)' => 'Tweede@example.org'];
		$this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1', cells: $owner)]))->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$this->assertSame(['contact-two'], array_keys($this->contacts));
	}//end testASecondEmailAddressOfAContactMatches()

	/**
	 * Without an employee number an e-mail address matches the owner, and is stored on the new contact.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-10-30-cmdb-import-owner-identity/tasks.md#task-2
	 */
	public function testAnOwnerWithOnlyAnEmailAddressIsMatchedOnIt(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		$this->contacts['contact-namesake'] = ['name' => 'Voornaam Achternaam', 'email' => '', 'book' => 'stackiq-cmdb-owners'];
		$owner = ['Applicatie Eigenaar (Persoon)' => 'Achternaam, Voornaam', 'Applicatie Eigenaar (E-mail)' => 'v.achternaam@example.org'];
		$rows = [$this->row(appId: '1', cells: $owner)];

		$this->service(reader: $this->rowsReader(rows: $rows))->import(path: '', options: ['municipalityUuid' => 'muni-1']);
		$this->service(reader: $this->rowsReader(rows: $rows))->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$this->assertCount(2, $this->contacts, 'one new contact next to the namesake without an e-mail address');
		$created = array_diff_key($this->contacts, ['contact-namesake' => true]);
		$this->assertSame(['v.achternaam@example.org'], array_column($created, 'email'));
		$this->assertCount(1, $this->objects(self::CONTACT_PERSON));
	}//end testAnOwnerWithOnlyAnEmailAddressIsMatchedOnIt()

	/**
	 * Renaming the e-mail and employee number columns in OpenRegister is all an export with other column names needs.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-10-30-cmdb-import-owner-identity/tasks.md#task-1
	 */
	public function testRenamedOwnerColumnsInOpenRegisterAreRead(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Rotterdam', type: 'Municipality');
		$packs = new FakeMigrationPackService();
		$owner = (new CmdbImportPackMarker())->seed(
			shipped: json_decode((string)file_get_contents(CmdbTestSupport::appRoot() . '/lib/Settings/cmdb-import/topdesk-business-owner.json'), true)
		);
		$owner['fieldMappings'][2]['source'] = 'E-mailadres eigenaar';
		$owner['fieldMappings'][3]['source'] = 'Pers.nr. eigenaar';
		$packs->put(definition: $owner);

		$cells = ['Applicatie Eigenaar (Persoon)' => 'Achternaam, Voornaam', 'E-mailadres eigenaar' => 'v.achternaam@example.org', 'Pers.nr. eigenaar' => '1001'];
		$report = $this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1', cells: $cells)]), packs: $packs)
			->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$this->assertSame([], $report['rows'][0]['warnings']);
		$uid = $this->ownerIdentity()->contactUid(employeeNumber: '1001', municipalityUuid: 'muni-1');
		$this->assertSame([$uid], array_keys($this->contacts), 'matched on the renamed employee number column');
		$this->assertSame('v.achternaam@example.org', $this->contacts[$uid]['email'], 'the renamed e-mail column');
	}//end testRenamedOwnerColumnsInOpenRegisterAreRead()

	/**
	 * No technical owner is written, whatever the row holds.
	 *
	 * @return void
	 */
	public function testNoTechnicalOwnerIsWritten(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		$this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1', cells: ['FB contactpersoon 1' => 'Achternaam, Voornaam'])]))
			->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$this->assertArrayNotHasKey('technicalOwner', $this->objects(self::USAGE)[0]);
		$this->assertArrayNotHasKey('businessOwner', $this->objects(self::USAGE)[0]);
		$this->assertSame([], $this->objects(self::CONTACT_PERSON));
		$this->assertSame([], $this->contacts);
	}//end testNoTechnicalOwnerIsWritten()

	/**
	 * With Contacts disabled the modules and usages are saved without owners, with a warning on each row that has an owner.
	 *
	 * @return void
	 */
	public function testContactsDisabledDoesNotBlockTheImport(): void {
		$path = $this->fixture();
		$this->contactsEnabled = false;
		$report = $this->service()->import(path: $path, options: ['municipalityName' => 'Gemeente Voorbeeldstad']);

		$this->assertSame(2, $report['summary']['created']);
		$this->assertCount(2, $this->objects(self::USAGE));
		$this->assertSame([], $this->objects(self::CONTACT_PERSON));
		$this->assertContains('Owners skipped: Nextcloud Contacts is unavailable', $report['rows'][0]['warnings']);
		$this->assertSame(['Owners skipped: Nextcloud Contacts is unavailable'], $report['rows'][1]['warnings']);
	}//end testContactsDisabledDoesNotBlockTheImport()

	/**
	 * An imported contact person has no e-mail and no username, so neither user-provisioning path picks it up.
	 *
	 * @return void
	 */
	public function testAnImportedContactPersonIsNeverAUser(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		$this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1', cells: ['Applicatie Eigenaar (Persoon)' => 'Achternaam, Voornaam', 'Applicatie Eigenaar (Functie)' => 'Afdelingshoofd'])]))
			->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$people = $this->objects(self::CONTACT_PERSON);
		$this->assertNotEmpty($people);
		foreach ($people as $person) {
			$this->assertSame([], array_diff(array_keys($person), ['id', 'contactsUid', 'organization', 'role']));
		}

		// No username key, so OrganizationSyncService::performUserSync(), which selects contact persons
		// with a username, never picks one up; the key list above pins that.
		// ContactpersoonService provisions a user from the e-mail on the object; given exactly what the
		// import stored, it stops before it ever looks up or creates a user.
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');
		$listener = new \OCA\Stackiq\Service\ContactpersoonService(
			contactPersonHandler: $this->createMock(\OCA\Stackiq\Service\Stackiq\ContactPersonHandler::class),
			groupHandler: $this->createMock(\OCA\Stackiq\Service\Stackiq\GroupHandler::class),
			hierarchyHandler: $this->createMock(\OCA\Stackiq\Service\Stackiq\HierarchyHandler::class),
			logger: $this->logger(),
			container: $container,
			appManager: $this->createMock(\OCP\App\IAppManager::class),
			config: $this->createMock(\OCP\IAppConfig::class),
			settingsService: $this->createMock(SettingsService::class)
		);
		foreach ($people as $person) {
			$object = new class($person) {
				/**
				 * Constructor.
				 *
				 * @param array<string, mixed> $data The stored contact person.
				 */
				public function __construct(
					private array $data,
				) {
				}

				public function getId(): string {
					return (string)$this->data['id'];
				}

				public function getObject(): array {
					return $this->data;
				}
			};
			$this->assertFalse($listener->processContactpersoon(contactPersonObject: $object), 'no user is provisioned for an imported contact person');
		}
	}//end testAnImportedContactPersonIsNeverAUser()

	/**
	 * An import logs who ran it, on which file and municipality, with which updateExisting and publish, and the counts.
	 *
	 * @return void
	 */
	public function testAnImportLeavesAnAuditRecord(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		$this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1')]))->import(
			path: '',
			options: ['municipalityUuid' => 'muni-1', 'updateExisting' => false, 'publish' => false, 'operationId' => 'cmdb-audit-01', 'fileName' => 'C:\\Users\\beheer\\export.xlsx']
		);

		$audit = '"operationId":"cmdb-audit-01","uid":"admin","fileName":"export.xlsx","municipality":"muni-1","municipalityCreated":false,"updateExisting":false,"publish":false,"missingRecords":"archive"';
		$started = array_values(array_filter($this->logLines, static fn (string $line): bool => str_starts_with($line, 'CmdbExportImportService: import started')));
		$finished = array_values(array_filter($this->logLines, static fn (string $line): bool => str_starts_with($line, 'CmdbExportImportService: import finished')));
		$this->assertCount(1, $started);
		$this->assertCount(1, $finished);
		$this->assertStringContainsString($audit . ',"rows":1}', $started[0]);
		$this->assertStringContainsString($audit . ',"summary":{"rowsRead":1,"processed":1,"created":1,', $finished[0]);
	}//end testAnImportLeavesAnAuditRecord()

	/**
	 * Neither the report nor any log line names an owner or repeats a cell value, even when an exception quotes them.
	 *
	 * @return void
	 */
	public function testNoPersonDataInReportOrLog(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		$owner = ['Applicatie Eigenaar (Persoon)' => 'Achternaam, Voornaam', 'Applicatie Eigenaar (Functie)' => 'Afdelingshoofd'];
		$this->beforeSave = function (int $schema, array $data): void {
			if ($schema === self::MODULE && ($data['externalNumber'] ?? '') === '2') {
				throw new RuntimeException("Property email=letter.achternaam@gemeente.nl invalid for 'Geheime Applicatie' of Achternaam, Voornaam\nSQL: INSERT ...");
			}

			if ($schema === self::USAGE && ($data['module'] ?? '') !== '' && count($this->objects(self::USAGE)) === 1) {
				throw new RuntimeException('usage refused for Achternaam, Voornaam');
			}
		};
		$rows = [
			$this->row(appId: '1', cells: $owner, row: 2),
			$this->row(appId: '2', cells: array_merge($owner, ['Applicatie Naam' => 'Geheime Applicatie']), row: 3),
			$this->row(appId: '3', cells: $owner, row: 4),
		];
		$report = $this->service(reader: $this->rowsReader(rows: $rows))->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$this->assertSame(['created', 'failed', 'failed'], array_column($report['rows'], 'outcome'), 'both injected failures ran');
		$this->assertSame(['step "module" failed (RuntimeException)'], $report['rows'][1]['reasons']);
		$this->assertSame(['step "usage" failed (RuntimeException)'], $report['rows'][2]['reasons']);

		// The row's own name is in the report by design; the log and the reasons must not repeat it.
		$reasons = json_encode(array_column($report['rows'], 'reasons'), JSON_UNESCAPED_UNICODE);
		$log = implode("\n", $this->logLines);
		foreach (['Achternaam', 'Voornaam', 'letter.achternaam', 'gemeente.nl', 'Geheime Applicatie', 'INSERT'] as $secret) {
			$this->assertStringNotContainsString($secret, $reasons . "\n" . $log);
		}

		foreach (['Achternaam', 'Voornaam', 'letter.achternaam'] as $personData) {
			$this->assertStringNotContainsString($personData, (string)json_encode($report, JSON_UNESCAPED_UNICODE));
		}

		$this->assertStringContainsString('Property email=<e-mail> invalid for', $log, 'the log keeps the message with the data taken out');
	}//end testNoPersonDataInReportOrLog()

	// ------------------------------------------------------------------
	// Task 7: row isolation, report, progress and cancel
	// ------------------------------------------------------------------

	/**
	 * A failing module save fails only its row, naming the step.
	 *
	 * @return void
	 */
	public function testOneBadRowDoesNotStopTheOthers(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		$this->beforeSave = function (int $schema, array $data): void {
			if ($schema === self::MODULE && ($data['externalNumber'] ?? '') === '2') {
				throw new RuntimeException('Validation failed for name');
			}
		};
		$rows = [$this->row(appId: '1', row: 2), $this->row(appId: '2', row: 3), $this->row(appId: '3', row: 4)];

		$report = $this->service(reader: $this->rowsReader(rows: $rows))->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$this->assertSame(['created', 'failed', 'created'], array_column($report['rows'], 'outcome'));
		$this->assertStringStartsWith('step "module" failed', $report['rows'][1]['reasons'][0]);
		$this->assertSame(['rowsRead' => 3, 'processed' => 3, 'created' => 2, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'failed' => 1, 'archived' => 0, 'unarchived' => 0, 'deleted' => 0, 'restored' => 0, 'warnings' => 0, 'unpublished' => 0], $report['summary']);
		$this->assertCount(2, $this->store[self::MODULE]);
	}//end testOneBadRowDoesNotStopTheOthers()

	/**
	 * A duplicate APPID (also across the two sheets), a missing APPID and a missing Applicatie Naam are skipped with their reasons.
	 *
	 * @return void
	 */
	public function testRowsAreSkippedWithTheirReasons(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		$rows = [
			$this->row(appId: '2', row: 2),
			$this->row(appId: '2', row: 7),
			$this->row(appId: '', row: 8),
			$this->row(appId: '9', cells: ['Applicatie Naam' => ' '], row: 10),
			$this->row(appId: '2', row: 2, sheet: 'Onbeh Applicaties CMDB'),
		];

		$report = $this->service(reader: $this->rowsReader(rows: $rows))->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$this->assertSame(
			[
				['created', []],
				['skipped', ['duplicate APPID in file']],
				['skipped', ['missing APPID']],
				['skipped', ['missing Applicatie Naam']],
				['skipped', ['duplicate APPID in file']],
			],
			array_map(fn (array $row): array => [$row['outcome'], $row['reasons']], $report['rows'])
		);
		$this->assertCount(1, $this->store[self::MODULE]);
	}//end testRowsAreSkippedWithTheirReasons()

	/**
	 * APPIDs that differ only in case or a non-breaking space are one application, in one upload and across imports.
	 *
	 * @return void
	 */
	public function testAppIdsMatchWhateverTheirCaseOrTrailingSpace(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		$first = $this->service(reader: $this->rowsReader(rows: [$this->row(appId: 'APP-1', row: 2), $this->row(appId: "app-1\u{00A0}", row: 3)]))
			->import(path: '', options: ['municipalityUuid' => 'muni-1']);
		$this->assertSame(['created', 'skipped'], array_column($first['rows'], 'outcome'));
		$this->assertSame(['duplicate APPID in file'], $first['rows'][1]['reasons']);

		$second = $this->service(reader: $this->rowsReader(rows: [$this->row(appId: "App-1\u{00A0}", row: 2)]))
			->import(path: '', options: ['municipalityUuid' => 'muni-1']);
		// The next export spells it differently: the same module, its APPID and name as now written.
		$this->assertSame('updated', $second['rows'][0]['outcome']);
		$this->assertSame('App-1', $this->objects(self::MODULE)[0]['externalNumber']);
		$this->assertCount(1, $this->store[self::MODULE]);
		$this->assertSame('topdesk:muni-1:app-1', $this->objects(self::MODULE)[0]['externalKey']);
	}//end testAppIdsMatchWhateverTheirCaseOrTrailingSpace()

	/**
	 * An APPID on both CMDB sheets is imported from "Beheerde Applicaties CMDB", whichever sheet comes first.
	 *
	 * @return void
	 */
	public function testTheBeheerdeRowWinsOverTheOnbehRow(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		$rows = [
			$this->row(appId: '8', cells: ['Applicatie Naam' => 'Onbeheerd'], row: 2, sheet: 'Onbeh Applicaties CMDB'),
			$this->row(appId: '8', cells: ['Applicatie Naam' => 'Beheerd'], row: 5, sheet: 'Beheerde Applicaties CMDB'),
		];

		$report = $this->service(reader: $this->rowsReader(rows: $rows))->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$this->assertSame(['skipped', 'created'], array_column($report['rows'], 'outcome'));
		$this->assertSame(['duplicate APPID in file'], $report['rows'][0]['reasons']);
		$this->assertSame(['APPID 8 is also on sheet "Beheerde Applicaties CMDB", which wins; this row is not imported'], $report['rows'][0]['warnings']);
		$this->assertSame('Beheerd', $this->objects(self::MODULE)[0]['name']);
		$this->assertStringStartsWith('Beheer geregeld: ja', $this->objects(self::USAGE)[0]['interneAnnotation']);
	}//end testTheBeheerdeRowWinsOverTheOnbehRow()

	/**
	 * A row skipped for a missing name does not take its APPID: a later row with that APPID is imported.
	 *
	 * @return void
	 */
	public function testASkippedRowLeavesItsAppIdToALaterRow(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		$rows = [$this->row(appId: '5', cells: ['Applicatie Naam' => ''], row: 2), $this->row(appId: '5', row: 3)];

		$report = $this->service(reader: $this->rowsReader(rows: $rows))->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$this->assertSame(['skipped', 'created'], array_column($report['rows'], 'outcome'));
		$this->assertSame(['missing Applicatie Naam'], $report['rows'][0]['reasons']);
	}//end testASkippedRowLeavesItsAppIdToALaterRow()

	/**
	 * The import runs as a cmdb_import operation with per-row progress; afterwards its statistics hold the report.
	 *
	 * @return void
	 */
	public function testProgressIsRecordedAndHoldsTheReport(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		$seen = [];
		$this->beforeSave = function (int $schema) use (&$seen): void {
			if ($schema === self::MODULE) {
				$seen[] = $this->tracker->getProgress(operationId: 'cmdb-progress-1')['processed_items'];
			}
		};
		$rows = [$this->row(appId: '1', row: 2), $this->row(appId: '2', row: 3)];

		$report = $this->service(reader: $this->rowsReader(rows: $rows))->import(path: '', options: ['municipalityUuid' => 'muni-1', 'operationId' => 'cmdb-progress-1']);

		$this->assertSame([0, 1], $seen, 'progress advances after every row');
		$stored = $this->cache['progress_cmdb-progress-1'];
		$this->assertSame('cmdb_import', $stored['operation_type']);
		$this->assertSame('completed', $stored['status']);
		$this->assertSame(array_merge($report, ['rowsStored' => 2, 'rowsTruncated' => false]), $stored['statistics']['report']);
	}//end testProgressIsRecordedAndHoldsTheReport()

	/**
	 * A closed browser tab does not stop a running import.
	 *
	 * @return void
	 */
	public function testAnImportKeepsRunningWhenTheClientGoesAway(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		$previous = ignore_user_abort(false);

		try {
			$this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1')]))->import(path: '', options: ['municipalityUuid' => 'muni-1']);
			$this->assertSame(1, ignore_user_abort());
		} finally {
			ignore_user_abort((bool)$previous);
		}
	}//end testAnImportKeepsRunningWhenTheClientGoesAway()

	/**
	 * An unknown municipality uuid is MUNICIPALITY_INVALID; OpenRegister failing to look it up is not.
	 *
	 * @return void
	 */
	public function testAFailingMunicipalityLookupIsNotAnInvalidMunicipality(): void {
		$this->findFailure = new \OCP\AppFramework\Db\DoesNotExistException('no such object');
		try {
			$this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1')]))->import(path: '', options: ['municipalityUuid' => 'muni-unknown']);
			$this->fail('MUNICIPALITY_INVALID expected');
		} catch (CmdbImportException $e) {
			$this->assertSame('MUNICIPALITY_INVALID', $e->getErrorCode());
		}

		$this->findFailure = new RuntimeException('database went away');
		try {
			$this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1')]))->import(path: '', options: ['municipalityUuid' => 'muni-1']);
			$this->fail('the infrastructure error should propagate');
		} catch (CmdbImportException $e) {
			$this->fail('an infrastructure error is not ' . $e->getErrorCode());
		} catch (RuntimeException $e) {
			$this->assertSame('database went away', $e->getMessage());
		}

		$this->assertStringContainsString('the municipality could not be looked up {"exception":"RuntimeException"}', implode("\n", $this->logLines));
		$this->assertSame([], $this->saves);
	}//end testAFailingMunicipalityLookupIsNotAnInvalidMunicipality()

	/**
	 * A second import of the same register while the first runs is refused with IMPORT_IN_PROGRESS and writes nothing.
	 *
	 * @return void
	 */
	public function testASecondImportWhileOneRunsIsRefused(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		$second = $this->service(reader: $this->rowsReader(rows: [$this->row(appId: '9', row: 2)]));
		$refusal = null;
		$savesDuringSecond = null;
		$this->beforeSave = function (int $schema) use ($second, &$refusal, &$savesDuringSecond): void {
			if ($schema !== self::MODULE || $refusal !== null) {
				return;
			}

			$before = count($this->saves);
			try {
				$second->import(path: '', options: ['municipalityUuid' => 'muni-1']);
			} catch (CmdbImportException $e) {
				$refusal = $e;
			}

			$savesDuringSecond = (count($this->saves) - $before);
		};

		$report = $this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1', row: 2), $this->row(appId: '2', row: 3)]))
			->import(path: '', options: ['municipalityUuid' => 'muni-1']);

		$this->assertInstanceOf(CmdbImportException::class, $refusal, 'the second import was refused');
		$this->assertSame('IMPORT_IN_PROGRESS', $refusal->getErrorCode());
		$this->assertSame(409, $refusal->getHttpStatus());
		$this->assertSame(0, $savesDuringSecond);
		$this->assertSame(2, $report['summary']['created'], 'the first import ran on');
		$this->assertSame([], $this->locks->held, 'the lock is released when the import returns');
		$this->assertSame(['stackiq/cmdb-import/register-' . self::REGISTER], array_unique($this->locks->taken));
	}//end testASecondImportWhileOneRunsIsRefused()

	/**
	 * The lock is released when the import throws, so the next import runs.
	 *
	 * @return void
	 */
	public function testTheLockIsReleasedWhenTheImportThrows(): void {
		try {
			$this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1')]))->import(path: '', options: ['municipalityUuid' => 'no-such-municipality']);
			$this->fail('MUNICIPALITY_INVALID expected');
		} catch (CmdbImportException $e) {
			$this->assertSame('MUNICIPALITY_INVALID', $e->getErrorCode());
		}

		$this->assertSame([], $this->locks->held);
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		$report = $this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1')]))->import(path: '', options: ['municipalityUuid' => 'muni-1']);
		$this->assertSame(1, $report['summary']['created']);
	}//end testTheLockIsReleasedWhenTheImportThrows()

	/**
	 * A cancel after row 1 of 3 keeps row 1 and reports cancelled with one processed row.
	 *
	 * @return void
	 */
	public function testACancelStopsBetweenRows(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		$service = null;
		$this->beforeSave = function (int $schema) use (&$service): void {
			if ($schema === self::USAGE) {
				$this->assertTrue($service->requestCancel(operationId: 'cmdb-cancel-01'));
			}
		};
		$rows = [$this->row(appId: '1', row: 2), $this->row(appId: '2', row: 3), $this->row(appId: '3', row: 4)];
		$service = $this->service(reader: $this->rowsReader(rows: $rows));

		$report = $service->import(path: '', options: ['municipalityUuid' => 'muni-1', 'operationId' => 'cmdb-cancel-01']);

		$this->assertTrue($report['cancelled']);
		$this->assertSame(1, $report['summary']['processed']);
		$this->assertSame(3, $report['summary']['rowsRead']);
		$this->assertCount(1, $report['rows']);
		$this->assertCount(1, $this->store[self::MODULE], 'row 1 stays');
		$this->assertSame('cancelled', $this->cache['progress_cmdb-cancel-01']['status']);
		$this->assertSame(array_merge($report, ['rowsStored' => 1, 'rowsTruncated' => false]), $this->cache['progress_cmdb-cancel-01']['statistics']['report']);
	}//end testACancelStopsBetweenRows()

	/**
	 * Cancel answers false for an unknown id, a malformed id or another operation type.
	 *
	 * @return void
	 */
	public function testCancelNeedsACmdbOperation(): void {
		$service = $this->service(reader: $this->rowsReader(rows: []));
		$this->tracker->startOperation(operationType: 'archimate_import', operationId: 'cmdb-not-mine-1');

		$this->assertFalse($service->requestCancel(operationId: 'cmdb-unknown-1'));
		$this->assertFalse($service->requestCancel(operationId: 'archimate_import_abcdefgh'));
		$this->assertFalse($service->requestCancel(operationId: 'cmdb-not-mine-1'));
	}//end testCancelNeedsACmdbOperation()

	/**
	 * Cancel answers false for an import that already finished, and leaves no cancel flag behind.
	 *
	 * @return void
	 */
	public function testCancelNeedsARunningImport(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		$service = $this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1', row: 2)]));
		$service->import(path: '', options: ['municipalityUuid' => 'muni-1', 'operationId' => 'cmdb-finished-1']);

		$this->assertFalse($service->requestCancel(operationId: 'cmdb-finished-1'));
		$this->assertArrayNotHasKey('cancel_cmdb-finished-1', $this->cache);
	}//end testCancelNeedsARunningImport()

	/**
	 * A cancel that arrives after the last row's check, with records kept, leaves no flag that a later run with the same id would inherit.
	 *
	 * @return void
	 */
	public function testACancelAfterTheLastCheckLeavesNoFlag(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		$service = null;
		$this->beforeSave = function (int $schema) use (&$service): void {
			if ($schema === self::USAGE) {
				$this->assertTrue($service->requestCancel(operationId: 'cmdb-late-cancel-1'));
			}
		};
		$service = $this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1', row: 2)]));

		$report = $service->import(path: '', options: ['municipalityUuid' => 'muni-1', 'operationId' => 'cmdb-late-cancel-1', 'missingRecords' => 'keep']);

		$this->assertFalse($report['cancelled'], 'the only row was already done');
		$this->assertSame('completed', $this->cache['progress_cmdb-late-cancel-1']['status']);
		$this->assertArrayNotHasKey('cancel_cmdb-late-cancel-1', $this->cache);
	}//end testACancelAfterTheLastCheckLeavesNoFlag()

	/**
	 * A run that reuses an id is not stopped by a cancel flag an earlier run left behind.
	 *
	 * @return void
	 */
	public function testAReusedIdIgnoresALeftoverCancel(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		$this->cache['cancel_cmdb-reused-0001'] = true;
		$service = $this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1', row: 2), $this->row(appId: '2', row: 3)]));

		$report = $service->import(path: '', options: ['municipalityUuid' => 'muni-1', 'operationId' => 'cmdb-reused-0001']);

		$this->assertSame('cmdb-reused-0001', $report['operationId']);
		$this->assertFalse($report['cancelled']);
		$this->assertSame(2, $report['summary']['processed']);
		$this->assertArrayNotHasKey('cancel_cmdb-reused-0001', $this->cache);
	}//end testAReusedIdIgnoresALeftoverCancel()

	/**
	 * An id whose operation is still running is replaced, so the live run keeps its record and owner.
	 *
	 * @return void
	 */
	public function testTheIdOfARunningOperationIsReplaced(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		$service = $this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1', row: 2)]));
		$this->tracker->startOperation(operationType: 'cmdb_import', options: ['total_items' => 7], ownerUid: 'other-admin', operationId: 'cmdb-live-00001');

		$report = $service->import(path: '', options: ['municipalityUuid' => 'muni-1', 'operationId' => 'cmdb-live-00001']);

		$this->assertNotSame('cmdb-live-00001', $report['operationId']);
		$this->assertMatchesRegularExpression(CmdbExportImportService::OPERATION_ID_PATTERN, $report['operationId']);
		$live = $this->cache['progress_cmdb-live-00001'];
		$this->assertSame('running', $live['status']);
		$this->assertSame('other-admin', $live['owner_uid']);
		$this->assertSame(7, $live['total_items']);
		$this->assertSame('completed', $this->cache['progress_' . $report['operationId']]['status']);
	}//end testTheIdOfARunningOperationIsReplaced()

	/**
	 * An id with a trailing newline does not match the pattern: it is replaced, and cancel refuses it.
	 *
	 * @return void
	 */
	public function testAnIdWithATrailingNewlineIsRefused(): void {
		$this->assertSame(0, preg_match(CmdbExportImportService::OPERATION_ID_PATTERN, "cmdb-12345678\n"));
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		$service = $this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1', row: 2)]));

		$report = $service->import(path: '', options: ['municipalityUuid' => 'muni-1', 'operationId' => "cmdb-12345678\n"]);

		$this->assertNotSame("cmdb-12345678\n", $report['operationId']);
		$this->assertFalse($service->requestCancel(operationId: "cmdb-12345678\n"));
		$this->assertArrayNotHasKey("cancel_cmdb-12345678\n", $this->cache);
	}//end testAnIdWithATrailingNewlineIsRefused()

	/**
	 * A failure outside a row stops the operation as failed instead of leaving it running.
	 *
	 * @return void
	 */
	public function testAFailureOutsideARowMarksTheOperationFailed(): void {
		$this->seedOrganisation(uuid: 'muni-1', name: 'Gemeente Voorbeeldstad', type: 'Municipality');
		$rows = [$this->row(appId: '1', row: 2), $this->row(appId: '2', row: 3)];
		$service = $this->service(reader: $this->rowsReader(rows: $rows));
		$this->beforeSave = function (int $schema): void {
			if ($schema === self::USAGE) {
				// The progress write after this row fails, outside every row boundary.
				$this->cacheFailure = new \Error("cache went away writing Applicatie 2 for owner jan.jansen@example.org\nsecond line");
			}
		};

		try {
			$service->import(path: '', options: ['municipalityUuid' => 'muni-1', 'operationId' => 'cmdb-failing-1']);
			$this->fail('the import should have thrown');
		} catch (\Error $e) {
			$this->assertStringStartsWith('cache went away', $e->getMessage());
		}

		$stored = $this->cache['progress_cmdb-failing-1'];
		$this->assertSame('failed', $stored['status']);
		$this->assertSame(CmdbExportImportService::FAILED_RUN_MESSAGE, $stored['errors'][0]['message'], 'a code and a generic message, never the exception text');
		$this->assertStringNotContainsString('jan.jansen', json_encode($stored));

		$failed = array_values(array_filter($this->logLines, static fn (string $line): bool => str_starts_with($line, 'CmdbExportImportService: import failed')));
		$this->assertCount(1, $failed);
		$this->assertStringContainsString('"exception":"Error"', $failed[0]);
		$this->assertStringContainsString('<e-mail>', $failed[0]);
		$this->assertStringNotContainsString('jan.jansen', $failed[0]);
		$this->assertStringNotContainsString('second line', $failed[0]);
	}//end testAFailureOutsideARowMarksTheOperationFailed()

	/**
	 * Without a mapping engine, or without configuration, nothing is read or written.
	 *
	 * @return void
	 */
	public function testMissingEngineOrConfigurationStopsBeforeReading(): void {
		try {
			$this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1')]), config: [])->import(path: '', options: ['municipalityName' => 'Gemeente Voorbeeldstad']);
			$this->fail('NOT_CONFIGURED expected');
		} catch (CmdbImportException $e) {
			$this->assertSame('NOT_CONFIGURED', $e->getErrorCode());
			$this->assertSame(503, $e->getHttpStatus());
		}

		$base = $this->service(reader: $this->rowsReader(rows: [$this->row(appId: '1')]));
		$reflection = new \ReflectionClass($base);
		$args = [];
		foreach ($reflection->getConstructor()->getParameters() as $parameter) {
			$property = $reflection->getProperty($parameter->getName());
			$args[$parameter->getName()] = $property->getValue($base);
		}

		$withoutEngine = new class(...$args) extends CmdbExportImportService {
			public const ENGINE_CLASS = 'OCA\OpenRegister\Service\MigrationPack\NoSuchEngine';
		};

		try {
			$withoutEngine->import(path: '', options: ['municipalityName' => 'Gemeente Voorbeeldstad']);
			$this->fail('MAPPING_UNAVAILABLE expected');
		} catch (CmdbImportException $e) {
			$this->assertSame('MAPPING_UNAVAILABLE', $e->getErrorCode());
			$this->assertSame(503, $e->getHttpStatus());
		}

		$this->assertSame([], $this->saves);
	}//end testMissingEngineOrConfigurationStopsBeforeReading()

	/**
	 * Person names split as TOPdesk writes them ("Achternaam, Voornaam").
	 *
	 * @return void
	 */
	public function testPersonNamesSplit(): void {
		$this->assertSame(['voornaam' => 'Voornaam', 'achternaam' => 'Achternaam'], CmdbExportImportService::splitPersonName(name: 'Achternaam,  Voornaam '));
		$this->assertSame(['voornaam' => '', 'achternaam' => 'Functioneel Beheer'], CmdbExportImportService::splitPersonName(name: 'Functioneel  Beheer'));
	}//end testPersonNamesSplit()
}//end class
