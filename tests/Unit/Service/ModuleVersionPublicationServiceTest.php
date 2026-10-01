<?php

/**
 * A module's publication copied onto its versions, and only when it differs.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/publication-field-rules/specs/publication-field-rules/spec.md#requirement-req-pfr-002-a-module-version-is-public-only-while-its-application-is
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\Stackiq\EventListener\ModuleVersionPublicationListener;
use OCA\Stackiq\Service\ModuleVersionPublicationService;
use OCA\Stackiq\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Asserts what the mirror writes, and that the listener reaches it.
 */
class ModuleVersionPublicationServiceTest extends TestCase {

	/**
	 * The object service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * An object with the six accessors of OpenRegister's entity contract.
	 *
	 * @param string               $uuid   The id.
	 * @param string               $schema The schema id.
	 * @param array<string, mixed> $data   The data.
	 *
	 * @return ObjectEntityInterface
	 */
	private static function entity(string $uuid, string $schema, array $data): ObjectEntityInterface {
		return new class ($uuid, $schema, $data) implements ObjectEntityInterface {

			/**
			 * Constructor.
			 *
			 * @param string               $uuid   The id.
			 * @param string               $schema The schema id.
			 * @param array<string, mixed> $data   The data.
			 */
			public function __construct(private string $uuid, private string $schema, private array $data) {
			}//end __construct()

			/**
			 * @return string|null
			 */
			public function getUuid(): ?string {
				return $this->uuid;
			}//end getUuid()

			/**
			 * @return array<string, mixed>
			 */
			public function getObject(): array {
				return $this->data;
			}//end getObject()

			/**
			 * @return string|null
			 */
			public function getRegister(): ?string {
				return '20';
			}//end getRegister()

			/**
			 * @return string|null
			 */
			public function getSchema(): ?string {
				return $this->schema;
			}//end getSchema()

			/**
			 * @return string|null
			 */
			public function getOrganisation(): ?string {
				return null;
			}//end getOrganisation()

			/**
			 * @return string|null
			 */
			public function getOwner(): ?string {
				return null;
			}//end getOwner()

			/**
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return $this->data;
			}//end jsonSerialize()
		};
	}//end entity()

	/**
	 * The service, with module schema 43 and moduleVersion schema 46.
	 *
	 * @return ModuleVersionPublicationService
	 */
	private function service(): ModuleVersionPublicationService {
		$settings = $this->getMockBuilder(SettingsService::class)
			->disableOriginalConstructor()
			->onlyMethods(['getSchemaIdForObjectType', 'getRegisterIdForObjectType'])
			->getMock();
		$settings->method('getSchemaIdForObjectType')->willReturnMap([['module', 43], ['moduleVersion', 46]]);
		$settings->method('getRegisterIdForObjectType')->willReturn(20);

		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$container     = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->objects);

		return new ModuleVersionPublicationService(settingsService: $settings, container: $container, logger: $this->createMock(LoggerInterface::class));
	}//end service()

	/**
	 * Publishing a module writes its date onto the versions that differ, and leaves the one already in step.
	 *
	 * @return void
	 */
	public function testAPublishedModuleReachesTheVersionsThatDiffer(): void {
		$service = $this->service();
		$stale   = self::entity('v-1', '46', ['module' => 'm-1', 'version' => '1.0']);
		$current = self::entity('v-2', '46', ['module' => 'm-1', 'version' => '2.0', 'modulePublicationDate' => '2026-09-01T00:00:00+00:00', 'moduleRegisteredBy' => 'Municipality']);
		$this->objects->method('searchObjects')->willReturn([$stale, $current]);

		$written = [];
		$this->objects->expects($this->once())->method('saveObject')->willReturnCallback(
			function (...$args) use (&$written, $stale) {
				$written[] = $args;
				return $stale;
			}
		);

		$module = self::entity('m-1', '43', ['name' => 'Zaaksysteem', 'publicationDate' => '2026-09-01T00:00:00+00:00', 'registeredBy' => 'Municipality']);
		$this->assertSame(1, $service->objectSaved(object: $module));
		$this->assertSame('v-1', $written[0][4], 'only the stale version is written');
		$this->assertSame('2026-09-01T00:00:00+00:00', $written[0][0]['modulePublicationDate']);
		$this->assertSame('Municipality', $written[0][0]['moduleRegisteredBy']);
		$this->assertSame('1.0', $written[0][0]['version'], 'the version keeps its own data');
	}//end testAPublishedModuleReachesTheVersionsThatDiffer()

	/**
	 * Depublishing a module clears the date on its versions, so they stop being public.
	 *
	 * @return void
	 */
	public function testADepublishedModuleClearsItsVersions(): void {
		$service = $this->service();
		$this->objects->method('searchObjects')->willReturn([self::entity('v-1', '46', ['module' => 'm-1', 'modulePublicationDate' => '2026-09-01T00:00:00+00:00', 'moduleRegisteredBy' => 'Municipality'])]);
		$this->objects->expects($this->once())->method('saveObject')->with($this->callback(static fn (array $data): bool => $data['modulePublicationDate'] === null));

		$service->objectSaved(object: self::entity('m-1', '43', ['registeredBy' => 'Municipality']));
	}//end testADepublishedModuleClearsItsVersions()

	/**
	 * A new version reads its module; a version already in step is not written, which ends the event chain.
	 *
	 * @return void
	 */
	public function testAVersionReadsItsModuleAndStopsWhenInStep(): void {
		$service = $this->service();
		$this->objects->method('find')->willReturn(self::entity('m-1', '43', ['registeredBy' => 'Supplier']));
		$this->objects->expects($this->once())->method('saveObject')->with($this->callback(static fn (array $data): bool => $data['moduleRegisteredBy'] === 'Supplier'));

		$this->assertSame(1, $service->objectSaved(object: self::entity('v-9', '46', ['module' => 'm-1'])));
		$this->assertSame(0, $service->objectSaved(object: self::entity('v-9', '46', ['module' => 'm-1', 'modulePublicationDate' => null, 'moduleRegisteredBy' => 'Supplier'])));
		$this->assertSame(0, $service->objectSaved(object: self::entity('x-1', '99', ['module' => 'm-1'])), 'other schemas are ignored');
	}//end testAVersionReadsItsModuleAndStopsWhenInStep()

	/**
	 * The listener hands a created object to the service: the wiring from the caller.
	 *
	 * @return void
	 */
	public function testTheListenerReachesTheService(): void {
		$service = $this->getMockBuilder(ModuleVersionPublicationService::class)
			->disableOriginalConstructor()
			->onlyMethods(['objectSaved'])
			->getMock();
		$object = $this->getMockBuilder(\OCA\OpenRegister\Db\ObjectEntity::class)->disableOriginalConstructor()->getMockForAbstractClass();
		$service->expects($this->once())->method('objectSaved')->with($object)->willReturn(0);

		(new ModuleVersionPublicationListener(publication: $service, logger: $this->createMock(LoggerInterface::class)))->handle(new ObjectCreatedEvent($object));

		$app = (string) file_get_contents(__DIR__ . '/../../../lib/AppInfo/Application.php');
		$this->assertStringContainsString('registerEventListener(ObjectCreatedEvent::class, ModuleVersionPublicationListener::class)', $app);
		$this->assertStringContainsString('registerEventListener(ObjectUpdatedEvent::class, ModuleVersionPublicationListener::class)', $app);
		$this->assertStringContainsString('OCA\\Stackiq\\Repair\\BackfillModuleVersionPublication', (string) file_get_contents(__DIR__ . '/../../../appinfo/info.xml'));
	}//end testTheListenerReachesTheService()
}//end class
