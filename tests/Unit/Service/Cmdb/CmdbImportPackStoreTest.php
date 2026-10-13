<?php

/**
 * Tests for the CMDB import packs in OpenRegister's migration-pack store:
 * seeding, the edited/unedited marker, the automatic update of an unedited
 * pack, and the fail-closed answers for a renamed pack or a failing store.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\Cmdb
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Cmdb;

require_once __DIR__ . '/../../Support/CmdbTestSupport.php';
require_once __DIR__ . '/../../Support/FakeMigrationPackService.php';

use OCA\Stackiq\Exception\CmdbImportException;
use OCA\Stackiq\Service\Cmdb\CmdbImportPackMarker;
use OCA\Stackiq\Service\Cmdb\CmdbImportPackStore;
use OCA\Stackiq\Tests\Unit\Support\CmdbTestSupport;
use OCA\Stackiq\Tests\Unit\Support\FakeMigrationPackService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * CmdbImportPackStore against an in-memory MigrationPackService.
 */
class CmdbImportPackStoreTest extends TestCase {
	/**
	 * The fake OpenRegister service.
	 *
	 * @var FakeMigrationPackService
	 */
	private FakeMigrationPackService $service;

	/**
	 * The app config values the store wrote.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * A fresh service and config per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = new FakeMigrationPackService();
		$this->config = [];
	}//end setUp()

	/**
	 * The store on the fake service, with an app config.
	 *
	 * @return CmdbImportPackStore
	 */
	private function store(): CmdbImportPackStore {
		return new CmdbImportPackStore(
			container: $this->service->container(test: $this, appConfig: FakeMigrationPackService::appConfig(test: $this, values: $this->config))
		);
	}//end store()

	/**
	 * The shipped usage pack.
	 *
	 * @return array<string, mixed>
	 */
	private function shippedUsage(): array {
		return json_decode((string)file_get_contents(CmdbTestSupport::appRoot() . '/lib/Settings/cmdb-import/topdesk-usage.json'), true);
	}//end shippedUsage()

	/**
	 * The shipped usage pack as a later release would ship it: one more status value, a newer version.
	 *
	 * @return array<string, mixed>
	 */
	private function newerUsage(): array {
		$pack = $this->shippedUsage();
		$pack['version'] = '1.1.0';
		$pack['fieldMappings'][0]['transform']['map']['Gepland'] = 'Planned';
		return $pack;
	}//end newerUsage()

	/**
	 * Without OpenRegister's service the store is not available and refuses to resolve.
	 *
	 * @return void
	 */
	public function testWithoutTheServiceTheStoreIsNotAvailable(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn(false);
		$store = new CmdbImportPackStore(container: $container);

		$this->assertFalse($store->isAvailable());
		$this->assertSame([], $store->recordedPackIds(), 'no app config: nothing recorded');

		$this->expectException(CmdbImportException::class);
		$this->expectExceptionMessage('OpenRegister MigrationPackService is not available');
		$store->resolve(shipped: $this->shippedUsage());
	}//end testWithoutTheServiceTheStoreIsNotAvailable()

	/**
	 * An OpenRegister whose service has every method but whose pack writes lack the CSRF and slug guards is not used.
	 *
	 * Released OpenRegister versions have the service, so the methods alone do
	 * not show that the packs are safe to run. Nothing is read or written there,
	 * and once ids were recorded the shipped files are refused too.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	public function testAnOpenRegisterWithoutTheHardenedStoreIsNotAvailable(): void {
		$this->service->failure = new \RuntimeException('an unhardened store must not be asked');
		$store = new CmdbImportPackStore(
			container: $this->service->container(test: $this, appConfig: FakeMigrationPackService::appConfig(test: $this, values: $this->config)),
			hardenedClass: 'OCA\\OpenRegister\\Exception\\NotInThisOpenRegister'
		);

		$this->assertFalse($store->isAvailable());
		$this->assertSame([], $this->service->writes);
		$store->assertShippedFallbackAllowed();

		$this->config[CmdbImportPackStore::CONFIG_KEY] = '{"stackiq-topdesk-usage":43}';
		$this->expectException(CmdbImportException::class);
		$this->expectExceptionMessage('holds the CMDB import packs stackiq-topdesk-usage (id 43)');
		$store->assertShippedFallbackAllowed();
	}//end testAnOpenRegisterWithoutTheHardenedStoreIsNotAvailable()

	/**
	 * The hardened store is the one OpenRegister ships with the CSRF-protected pack writes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	public function testTheHardenedStoreIsMarkedByTheSlugLock(): void {
		$this->assertSame('OCA\\OpenRegister\\Exception\\BuiltinPackSlugLockedException', CmdbImportPackStore::HARDENED_CLASS);
		$this->assertTrue($this->store()->isAvailable());
	}//end testTheHardenedStoreIsMarkedByTheSlugLock()

	/**
	 * Two first loads at once: the one whose create loses to the other's seed uses the pack the other stored.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	public function testASeedThatLosesARaceUsesThePackTheOtherRequestStored(): void {
		$this->service->beforeCreate = function (array $definition): void {
			$this->service->beforeCreate = null;
			$this->service->put(definition: $definition, id: 77);
		};

		$resolved = $this->store()->resolve(shipped: $this->shippedUsage());

		$this->assertSame(77, $resolved['id']);
		$this->assertSame(CmdbImportPackStore::FOUND, $resolved['outcome']);
		$this->assertSame(false, $resolved['edited']);
		$this->assertSame([], $this->service->writes, 'this request wrote nothing');
		$this->assertSame('{"stackiq-topdesk-usage":77}', $this->config[CmdbImportPackStore::CONFIG_KEY]);
	}//end testASeedThatLosesARaceUsesThePackTheOtherRequestStored()

	/**
	 * A create that fails while no pack has the slug is still MAPPING_UNAVAILABLE naming the pack.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	public function testAFailingSeedWithNoPackStoredIsMappingUnavailable(): void {
		$this->service->beforeCreate = static function (array $definition): void {
			throw new \RuntimeException('database is gone');
		};

		try {
			$this->store()->resolve(shipped: $this->shippedUsage());
			$this->fail('MAPPING_UNAVAILABLE expected');
		} catch (CmdbImportException $e) {
			$this->assertSame('MAPPING_UNAVAILABLE', $e->getErrorCode());
			$this->assertSame('OpenRegister migration pack stackiq-topdesk-usage could not be seeded: RuntimeException', $e->getMessage());
		}
	}//end testAFailingSeedWithNoPackStoredIsMappingUnavailable()

	/**
	 * A missing pack is created as a built-in pack with the marker, and its row id is recorded.
	 *
	 * @return void
	 */
	public function testAMissingPackIsSeededWithTheMarker(): void {
		$shipped = $this->shippedUsage();

		$stored = $this->store()->resolve(shipped: $shipped);

		$this->assertSame(CmdbImportPackStore::SEEDED, $stored['outcome']);
		$this->assertSame(['create stackiq-topdesk-usage'], $this->service->writes);
		$this->assertTrue($this->service->rows[$stored['id']]['builtin']);
		$definition = $this->service->definition(id: $stored['id']);
		$this->assertSame(
			['shippedVersion' => '1.0.0', 'shippedHash' => (new CmdbImportPackMarker())->hash(pack: $shipped)],
			$definition[CmdbImportPackMarker::KEY]
		);
		$this->assertSame($shipped, (new CmdbImportPackMarker())->strip(definition: $definition), 'the pack itself is stored as shipped');
		$this->assertFalse((new CmdbImportPackMarker())->isEdited(definition: $definition));
		$this->assertSame(['stackiq-topdesk-usage' => $stored['id']], json_decode($this->config[CmdbImportPackStore::CONFIG_KEY], true));
		$this->assertSame(['stackiq-topdesk-usage' => $stored['id']], $this->store()->recordedPackIds());
	}//end testAMissingPackIsSeededWithTheMarker()

	/**
	 * An unedited pack that matches the shipped one is found and not written.
	 *
	 * @return void
	 */
	public function testAnUneditedCurrentPackIsLeftAlone(): void {
		$shipped = $this->shippedUsage();
		$id = $this->service->put(definition: (new CmdbImportPackMarker())->seed(shipped: $shipped));

		$stored = $this->store()->resolve(shipped: $shipped);

		$this->assertSame(CmdbImportPackStore::FOUND, $stored['outcome']);
		$this->assertSame($id, $stored['id']);
		$this->assertSame([], $this->service->writes);
	}//end testAnUneditedCurrentPackIsLeftAlone()

	/**
	 * An unedited pack seeded from an older release is replaced by the newer shipped pack.
	 *
	 * @return void
	 */
	public function testAnUneditedPackFollowsANewerShippedPack(): void {
		$id = $this->service->put(definition: (new CmdbImportPackMarker())->seed(shipped: $this->shippedUsage()));
		$newer = $this->newerUsage();

		$stored = $this->store()->resolve(shipped: $newer);

		$this->assertSame(CmdbImportPackStore::UPDATED, $stored['outcome']);
		$this->assertSame(['update ' . $id], $this->service->writes);
		$this->assertSame('1.1.0', $stored['version']);
		$definition = $this->service->definition(id: $id);
		$this->assertSame('Planned', $definition['fieldMappings'][0]['transform']['map']['Gepland']);
		$this->assertSame('1.1.0', $definition[CmdbImportPackMarker::KEY]['shippedVersion']);
		$this->assertFalse((new CmdbImportPackMarker())->isEdited(definition: $definition));
		$this->assertFalse((new CmdbImportPackMarker())->isNewerShipped(definition: $definition, shipped: $newer));
	}//end testAnUneditedPackFollowsANewerShippedPack()

	/**
	 * A stored pack without an `updatedAt` cannot be updated under the race guard, so it is kept as stored.
	 *
	 * @return void
	 */
	public function testAnUneditedPackWithoutAnUpdatedAtIsKept(): void {
		$id = $this->service->put(definition: (new CmdbImportPackMarker())->seed(shipped: $this->shippedUsage()));
		$this->service->withoutUpdatedAt[] = $id;

		$stored = $this->store()->resolve(shipped: $this->newerUsage());
		$this->assertSame(CmdbImportPackStore::FOUND, $stored['outcome']);
		$this->assertSame([], $this->service->writes, 'no unguarded update');
		$this->assertSame('1.0.0', $stored['version']);

		$view = $this->store()->find(shipped: $this->newerUsage());
		$this->assertFalse($view['updatePending'], 'the import does not replace it, so the view does not say so');
		$this->assertSame('1.0.0', $view['version']);
	}//end testAnUneditedPackWithoutAnUpdatedAtIsKept()

	/**
	 * Reading a pack for the view answers what the next import runs, and writes nothing.
	 *
	 * @return void
	 */
	public function testFindAnswersTheShippedPackWhenTheNextImportReplacesTheStoredOne(): void {
		$id = $this->service->put(definition: (new CmdbImportPackMarker())->seed(shipped: $this->shippedUsage()));
		$newer = $this->newerUsage();

		$view = $this->store()->find(shipped: $newer);

		$this->assertTrue($view['updatePending']);
		$this->assertSame('1.1.0', $view['version']);
		$this->assertSame($newer, $view['pack']);
		$this->assertSame('1.0.0', $view['storedVersion']);
		$this->assertSame(CmdbImportPackStore::FOUND, $view['outcome']);
		$this->assertSame([], $this->service->writes);

		$current = $this->store()->find(shipped: $this->shippedUsage());
		$this->assertFalse($current['updatePending'], 'an unedited current pack is as stored');

		$this->service->edit(id: $id, definition: array_merge($this->service->definition(id: $id), ['description' => 'edited']));
		$edited = $this->store()->find(shipped: $newer);
		$this->assertFalse($edited['updatePending'], 'an edited pack is never replaced');
		$this->assertSame('edited', $edited['pack']['description']);
		$this->assertTrue($edited['newerShipped']);
	}//end testFindAnswersTheShippedPackWhenTheNextImportReplacesTheStoredOne()

	/**
	 * An edited pack is kept when a newer pack ships, and reported as such.
	 *
	 * @return void
	 */
	public function testAnEditedPackIsKeptAndReportsTheNewerShippedPack(): void {
		$edited = (new CmdbImportPackMarker())->seed(shipped: $this->shippedUsage());
		$edited['fieldMappings'][0]['transform']['map']['In beheer'] = 'In production';
		$edited['version'] = '2.0.1';
		$id = $this->service->put(definition: $edited);
		$newer = $this->newerUsage();

		$stored = $this->store()->resolve(shipped: $newer);

		$this->assertSame(CmdbImportPackStore::FOUND, $stored['outcome']);
		$this->assertSame([], $this->service->writes, 'an edit is never overwritten');
		$this->assertSame('2.0.1', $stored['version']);
		$this->assertTrue((new CmdbImportPackMarker())->isEdited(definition: $stored['definition']));
		$this->assertTrue((new CmdbImportPackMarker())->isNewerShipped(definition: $stored['definition'], shipped: $newer));
		$this->assertFalse(
			(new CmdbImportPackMarker())->isNewerShipped(definition: $stored['definition'], shipped: $this->shippedUsage()),
			'edited, but seeded from the pack that still ships'
		);
		$this->assertSame('In production', $this->service->definition(id: $id)['fieldMappings'][0]['transform']['map']['In beheer']);
	}//end testAnEditedPackIsKeptAndReportsTheNewerShippedPack()

	/**
	 * A stored pack without the marker is never updated: nobody knows what was seeded.
	 *
	 * @return void
	 */
	public function testAPackWithoutTheMarkerIsNeverUpdated(): void {
		$this->service->put(definition: $this->shippedUsage());
		$newer = $this->newerUsage();

		$stored = $this->store()->resolve(shipped: $newer);

		$this->assertSame(CmdbImportPackStore::FOUND, $stored['outcome']);
		$this->assertSame([], $this->service->writes);
		$this->assertNull((new CmdbImportPackMarker())->isEdited(definition: $stored['definition']));
		$this->assertFalse((new CmdbImportPackMarker())->isNewerShipped(definition: $stored['definition'], shipped: $newer));
	}//end testAPackWithoutTheMarkerIsNeverUpdated()

	/**
	 * A pack whose recorded row now carries another slug is refused, naming the row and both slugs.
	 *
	 * @return void
	 */
	public function testARenamedPackFailsClosed(): void {
		$renamed = $this->shippedUsage();
		$renamed['id'] = 'my-usage';
		$id = $this->service->put(definition: $renamed);
		$this->config[CmdbImportPackStore::CONFIG_KEY] = (string)json_encode(['stackiq-topdesk-usage' => $id]);

		try {
			$this->store()->resolve(shipped: $this->shippedUsage());
			$this->fail('MAPPING_UNAVAILABLE expected');
		} catch (CmdbImportException $e) {
			$this->assertSame('MAPPING_UNAVAILABLE', $e->getErrorCode());
			$this->assertSame(503, $e->getHttpStatus());
			$this->assertStringContainsString('id ' . $id, $e->getMessage());
			$this->assertStringContainsString('stackiq-topdesk-usage', $e->getMessage());
			$this->assertStringContainsString('my-usage', $e->getMessage());
			$this->assertSame(['pack' => 'stackiq-topdesk-usage', 'packId' => $id], $e->getDetails());
		}

		$this->assertSame([], $this->service->writes, 'no second pack under the old slug');
	}//end testARenamedPackFailsClosed()

	/**
	 * A pack whose recorded row was deleted is seeded again, and the new row id is recorded.
	 *
	 * @return void
	 */
	public function testADeletedPackIsSeededAgain(): void {
		$this->config[CmdbImportPackStore::CONFIG_KEY] = (string)json_encode(['stackiq-topdesk-usage' => 7]);

		$stored = $this->store()->resolve(shipped: $this->shippedUsage());

		$this->assertSame(CmdbImportPackStore::SEEDED, $stored['outcome']);
		$this->assertNotSame(7, $stored['id']);
		$this->assertSame(['stackiq-topdesk-usage' => $stored['id']], json_decode($this->config[CmdbImportPackStore::CONFIG_KEY], true));
	}//end testADeletedPackIsSeededAgain()

	/**
	 * A store that fails on a lookup is MAPPING_UNAVAILABLE naming the pack, never a fallback.
	 *
	 * @return void
	 */
	public function testAFailingStoreIsMappingUnavailable(): void {
		$this->service->failure = new RuntimeException('database gone');

		try {
			$this->store()->resolve(shipped: $this->shippedUsage());
			$this->fail('MAPPING_UNAVAILABLE expected');
		} catch (CmdbImportException $e) {
			$this->assertSame('MAPPING_UNAVAILABLE', $e->getErrorCode());
			$this->assertStringContainsString('stackiq-topdesk-usage', $e->getMessage());
			$this->assertStringContainsString('RuntimeException', $e->getMessage());
			$this->assertStringNotContainsString('database gone', $e->getMessage(), 'the class, not the message');
		}
	}//end testAFailingStoreIsMappingUnavailable()

	/**
	 * An administrator who saves the pack between the store's read and its update keeps the edit.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	public function testAnEditSavedBetweenTheReadAndTheUpdateIsKept(): void {
		$seeded = (new CmdbImportPackMarker())->seed(shipped: $this->shippedUsage());
		$id = $this->service->put(definition: $seeded);
		$edited = $seeded;
		$edited['description'] = 'Edited by the administrator';
		$this->service->beforeUpdate = function (int $updateId) use ($edited): void {
			$this->service->beforeUpdate = null;
			$this->service->edit(id: $updateId, definition: $edited);
		};

		$stored = $this->store()->resolve(shipped: $this->newerUsage());

		$this->assertSame(CmdbImportPackStore::FOUND, $stored['outcome']);
		$this->assertSame([], $this->service->writes, 'the update was refused, not applied');
		$this->assertSame('Edited by the administrator', $this->service->definition(id: $id)['description']);
		$this->assertSame('Edited by the administrator', $stored['pack']['description']);
		$this->assertTrue($stored['edited']);
		$this->assertTrue($stored['newerShipped']);
	}//end testAnEditSavedBetweenTheReadAndTheUpdateIsKept()

	/**
	 * A pack deleted between the read and the update cannot be re-read, so the import stops with MAPPING_UNAVAILABLE.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	public function testAPackDeletedBetweenTheReadAndTheUpdateIsMappingUnavailable(): void {
		$this->service->put(definition: (new CmdbImportPackMarker())->seed(shipped: $this->shippedUsage()));
		$this->service->beforeUpdate = function (int $updateId): void {
			unset($this->service->rows[$updateId]);
		};

		try {
			$this->store()->resolve(shipped: $this->newerUsage());
			$this->fail('MAPPING_UNAVAILABLE expected');
		} catch (CmdbImportException $e) {
			$this->assertSame('MAPPING_UNAVAILABLE', $e->getErrorCode());
			$this->assertStringContainsString('could not be updated: OCP\\AppFramework\\Db\\DoesNotExistException', $e->getMessage());
		}
	}//end testAPackDeletedBetweenTheReadAndTheUpdateIsMappingUnavailable()

	/**
	 * The fake refuses an update read at another time, as OpenRegister does; without a time it overwrites.
	 *
	 * @return void
	 */
	public function testTheFakeRefusesAStaleUpdateAsOpenRegisterDoes(): void {
		$id = $this->service->put(definition: $this->shippedUsage());
		$readAt = $this->service->find(id: $id)->getUpdatedAt()->getTimestamp();
		$this->service->edit(id: $id, definition: $this->newerUsage());

		try {
			$this->service->update(id: $id, definition: $this->shippedUsage(), expectedUpdatedAt: $readAt);
			$this->fail('PackChangedException expected');
		} catch (\OCA\OpenRegister\Exception\PackChangedException $e) {
			$this->assertInstanceOf(\InvalidArgumentException::class, $e, 'the same parent as OpenRegister\'s');
		}

		$this->service->update(id: $id, definition: $this->shippedUsage());
		$this->assertSame(['update ' . $id], $this->service->writes);
	}//end testTheFakeRefusesAStaleUpdateAsOpenRegisterDoes()

	/**
	 * An OpenRegister whose pack controller opens a write route is not the hardened store.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	public function testAnOpenedWriteRouteMakesTheStoreUnavailable(): void {
		$support = __DIR__ . '/../../Support/OpenRegister/';
		require_once $support . 'CsrfOpenedMigrationPacksController.php';
		require_once $support . 'AdminOpenedMigrationPacksController.php';
		$container = $this->service->container(test: $this, appConfig: FakeMigrationPackService::appConfig(test: $this, values: $this->config));

		foreach (['CsrfOpenedMigrationPacksController', 'AdminOpenedMigrationPacksController', 'NoSuchController'] as $class) {
			$store = new CmdbImportPackStore(
				container: $container,
				controllerClass: 'OCA\\Stackiq\\Tests\\Unit\\Support\\OpenRegister\\' . $class
			);
			$this->assertFalse($store->isAvailable(), $class);
		}

		$this->assertSame('OCA\\OpenRegister\\Controller\\MigrationPacksController', CmdbImportPackStore::CONTROLLER_CLASS);
		$this->assertTrue($this->store()->isAvailable(), 'the guarded controller');
	}//end testAnOpenedWriteRouteMakesTheStoreUnavailable()

	/**
	 * The hash ignores the order of an object's keys but not the order of the field mappings.
	 *
	 * @return void
	 */
	public function testTheHashIsCanonical(): void {
		$pack = $this->shippedUsage();
		$reordered = array_reverse($pack, true);
		$reordered['fieldMappings'][0]['transform'] = array_reverse($reordered['fieldMappings'][0]['transform'], true);
		$swapped = $pack;
		$swapped['fieldMappings'] = array_reverse($pack['fieldMappings']);

		$this->assertSame((new CmdbImportPackMarker())->hash(pack: $pack), (new CmdbImportPackMarker())->hash(pack: $reordered));
		$this->assertNotSame((new CmdbImportPackMarker())->hash(pack: $pack), (new CmdbImportPackMarker())->hash(pack: $swapped));
		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (new CmdbImportPackMarker())->hash(pack: $pack));
	}//end testTheHashIsCanonical()
}//end class
