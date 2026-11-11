<?php

/**
 * Unit tests for ModuleEventProcessor.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Event routing for organisation objects; the routing rules are pinned here.
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\ModuleEventProcessor;
use OCA\Stackiq\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Which organisation events reach the organisation sync, and how failures are contained.
 *
 * @covers \OCA\Stackiq\Service\ModuleEventProcessor
 *
 * @spec exclude Event routing for organisation objects; the routing rules are pinned here.
 */
class ModuleEventProcessorTest extends TestCase {

	private const SCHEMAS = [
		'organization' => 12,
		'contactPerson' => 13,
		'contactgegevens' => 14,
		'usage' => 15,
	];

	/**
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settings;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Records the objects handed to processSpecificOrganization().
	 *
	 * @var object
	 */
	private object $sync;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->container = $this->createMock(originalClassName: ContainerInterface::class);
		$this->settings = $this->createMock(originalClassName: SettingsService::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->sync = new class {
			/**
			 * @var array<int,object>
			 */
			public array $processed = [];

			/**
			 * @var \Throwable|null
			 */
			public ?\Throwable $fail = null;

			/**
			 * Record or fail.
			 *
			 * @param object $object The organisation.
			 *
			 * @return void
			 */
			public function processSpecificOrganization(object $object): void {
				if ($this->fail !== null) {
					throw $this->fail;
				}

				$this->processed[] = $object;
			}
		};
	}//end setUp()

	/**
	 * The processor under test.
	 *
	 * @return ModuleEventProcessor
	 */
	private function processor(): ModuleEventProcessor {
		return new ModuleEventProcessor(container: $this->container, settings: $this->settings, logger: $this->logger);
	}//end processor()

	/**
	 * An object double with schema, uuid and body.
	 *
	 * @param int|string          $schema The schema id.
	 * @param array<string,mixed> $data   The body.
	 * @param string              $uuid   The uuid.
	 *
	 * @return object
	 */
	private function object(int|string $schema, array $data, string $uuid = 'org-1'): object {
		return new class($schema, $data, $uuid) {
			/**
			 * Hold the values.
			 *
			 * @param int|string          $schema The schema id.
			 * @param array<string,mixed> $data   The body.
			 * @param string              $uuid   The uuid.
			 */
			public function __construct(
				private int|string $schema,
				private array $data,
				private string $uuid
			) {
			}

			/**
			 * @return int|string
			 */
			public function getSchema(): int|string {
				return $this->schema;
			}

			/**
			 * @return array<string,mixed>
			 */
			public function getObject(): array {
				return $this->data;
			}

			/**
			 * @return string
			 */
			public function getUuid(): string {
				return $this->uuid;
			}
		};
	}//end object()

	/**
	 * Serve the sync service (and optionally an object service) from the container.
	 *
	 * @param object|null $objectService The object service, if any.
	 *
	 * @return void
	 */
	private function serve(?object $objectService = null): void {
		$this->container->method('get')->willReturnCallback(
			function (string $id) use ($objectService) {
				if ($id === 'OCA\Stackiq\Service\OrganizationSyncService') {
					return $this->sync;
				}

				if ($id === 'OCA\OpenRegister\Service\ObjectService' && $objectService !== null) {
					return $objectService;
				}

				throw new RuntimeException('unknown service ' . $id);
			}
		);
	}//end serve()

	/**
	 * The four schema ids are resolved by object type.
	 *
	 * @return void
	 */
	public function testSchemaIdsAreResolvedPerObjectType(): void {
		$this->settings->method('getSchemaIdForObjectType')->willReturnCallback(
			static fn (string $objectType): ?int => self::SCHEMAS[$objectType] ?? null
		);

		$this->assertSame(expected: self::SCHEMAS, actual: $this->processor()->resolveSchemaIds());
	}//end testSchemaIdsAreResolvedPerObjectType()

	/**
	 * A created active organisation is synced, whatever the case of its status.
	 *
	 * @return void
	 */
	public function testCreatedActiveOrganisationIsSynced(): void {
		$this->serve();
		$org = $this->object(schema: '12', data: ['status' => 'Actief']);

		$this->processor()->processOrganisatieCreated($org, self::SCHEMAS);
		$this->processor()->processOrganisatieCreated($this->object(schema: 12, data: ['status' => 'ACTIVE'], uuid: 'org-2'), self::SCHEMAS);

		$this->assertCount(expectedCount: 2, haystack: $this->sync->processed);
		$this->assertSame(expected: $org, actual: $this->sync->processed[0]);
	}//end testCreatedActiveOrganisationIsSynced()

	/**
	 * Inactive organisations, other schemas and an unconfigured schema are ignored on create.
	 *
	 * @return void
	 */
	public function testCreateIgnoresInactiveAndForeignObjects(): void {
		$this->serve();
		$processor = $this->processor();

		$processor->processOrganisatieCreated($this->object(schema: 12, data: ['status' => 'Inactief']), self::SCHEMAS);
		$processor->processOrganisatieCreated($this->object(schema: 12, data: []), self::SCHEMAS);
		$processor->processOrganisatieCreated($this->object(schema: 13, data: ['status' => 'Actief']), self::SCHEMAS);
		$processor->processOrganisatieCreated($this->object(schema: 12, data: ['status' => 'Actief']), ['organization' => null]);

		$this->assertSame(expected: [], actual: $this->sync->processed);
	}//end testCreateIgnoresInactiveAndForeignObjects()

	/**
	 * A failing sync on create is logged, not thrown.
	 *
	 * @return void
	 */
	public function testCreateSyncFailureIsLogged(): void {
		$this->serve();
		$this->sync->fail = new RuntimeException('sync broke');
		$this->logger->expects($this->once())->method('error')
			->with('Stackiq: Failed to process organization creation', ['objectId' => 'org-1', 'exception' => 'sync broke']);

		$this->processor()->processOrganisatieCreated($this->object(schema: 12, data: ['status' => 'actief']), self::SCHEMAS);
	}//end testCreateSyncFailureIsLogged()

	/**
	 * An update to another schema is not handled.
	 *
	 * @return void
	 */
	public function testUpdateOfAnotherSchemaIsNotHandled(): void {
		$this->assertFalse(condition: $this->processor()->processOrganisatieUpdated($this->object(schema: 13, data: []), null, self::SCHEMAS));
		$this->assertFalse(condition: $this->processor()->processOrganisatieUpdated($this->object(schema: 12, data: []), null, ['organization' => null]));
	}//end testUpdateOfAnotherSchemaIsNotHandled()

	/**
	 * An organisation that becomes active is reloaded with its contacts and synced.
	 *
	 * @return void
	 */
	public function testBecomingActiveSyncsTheOrganisationWithContacts(): void {
		$loaded = $this->object(schema: 12, data: ['status' => 'Actief', 'contactpersonen' => []]);
		$objectService = new class($loaded) {
			/**
			 * @var array<string,mixed>
			 */
			public array $call = [];

			/**
			 * Hold the answer.
			 *
			 * @param object $answer The loaded object.
			 */
			public function __construct(private object $answer) {
			}

			/**
			 * Record the lookup.
			 *
			 * @param mixed ...$args Named arguments.
			 *
			 * @return object
			 */
			public function find(...$args): object {
				$this->call = $args;
				return $this->answer;
			}
		};
		$this->serve(objectService: $objectService);
		$this->settings->method('getVoorzieningenConfig')->willReturn(['register' => 'r1', 'organisatie_schema' => 's1']);

		$handled = $this->processor()->processOrganisatieUpdated(
			$this->object(schema: 12, data: ['status' => 'Actief']),
			$this->object(schema: 12, data: ['status' => 'Inactief']),
			self::SCHEMAS
		);

		$this->assertTrue(condition: $handled);
		$this->assertSame(
			expected: ['id' => 'org-1', 'register' => 'r1', 'schema' => 's1', '_extend' => ['contactpersonen'], '_rbac' => false, '_multitenancy' => false],
			actual: $objectService->call
		);
		$this->assertSame(expected: [$loaded], actual: $this->sync->processed);
	}//end testBecomingActiveSyncsTheOrganisationWithContacts()

	/**
	 * An organisation that was already active, or is not active, is handled without a sync.
	 *
	 * @return void
	 */
	public function testUnchangedOrInactiveUpdateDoesNotSync(): void {
		$this->serve();
		$processor = $this->processor();

		$this->assertTrue(
			condition: $processor->processOrganisatieUpdated(
				$this->object(schema: 12, data: ['status' => 'Actief']),
				$this->object(schema: 12, data: ['status' => 'actief']),
				self::SCHEMAS
			)
		);
		$this->assertTrue(condition: $processor->processOrganisatieUpdated($this->object(schema: 12, data: ['status' => 'Inactief']), null, self::SCHEMAS));
		$this->assertSame(expected: [], actual: $this->sync->processed);
	}//end testUnchangedOrInactiveUpdateDoesNotSync()

	/**
	 * A failing reload on update is logged, not thrown.
	 *
	 * @return void
	 */
	public function testUpdateReloadFailureIsLogged(): void {
		$this->serve();
		$this->settings->method('getVoorzieningenConfig')->willReturn([]);
		$this->logger->expects($this->once())->method('error')
			->with('Stackiq: Failed to process organization update', $this->callback(callback: static fn (array $ctx): bool => $ctx['objectId'] === 'org-1'));

		$updated = $this->object(schema: 12, data: ['status' => 'Active']);
		$this->assertTrue(condition: $this->processor()->processOrganisatieUpdated($updated, null, self::SCHEMAS));
	}//end testUpdateReloadFailureIsLogged()

	/**
	 * A deleted organisation is synced; another schema is not handled.
	 *
	 * @return void
	 */
	public function testDeletedOrganisationIsSynced(): void {
		$this->serve();
		$org = $this->object(schema: 12, data: []);

		$this->assertTrue(condition: $this->processor()->processOrganisatieDeleted($org, self::SCHEMAS));
		$this->assertFalse(condition: $this->processor()->processOrganisatieDeleted($this->object(schema: 14, data: []), self::SCHEMAS));
		$this->assertSame(expected: [$org], actual: $this->sync->processed);
	}//end testDeletedOrganisationIsSynced()

	/**
	 * A failing sync on delete is logged and still reported as handled.
	 *
	 * @return void
	 */
	public function testDeleteSyncFailureIsLogged(): void {
		$this->serve();
		$this->sync->fail = new RuntimeException('gone');
		$this->logger->expects($this->once())->method('error')
			->with('Stackiq: Failed to process organization deletion', ['objectId' => 'org-1', 'exception' => 'gone']);

		$this->assertTrue(condition: $this->processor()->processOrganisatieDeleted($this->object(schema: 12, data: []), self::SCHEMAS));
	}//end testDeleteSyncFailureIsLogged()
}//end class
