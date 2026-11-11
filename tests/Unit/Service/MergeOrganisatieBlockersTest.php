<?php

/**
 * Characterisation tests for the merge request checks and field re-pointing of MergeOrganisatieService.
 *
 * Pins, through dryRun, every blocker a merge request can meet (and the
 * requests that pass), the audit entry and the counts; and, through
 * execute, which shapes of the scalar and array organisation fields are
 * re-pointed and how the rest of each object is kept.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/organisation-merge/spec.md#requirement-merge-requests-must-be-validated-and-rejected-with-blockers-before-any-write
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\MergeOrganisatieService;
use OCA\Stackiq\Service\ProgressTracker;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\Stackiq\OrganizationHandler;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;

/**
 * Cases for the merge blockers and the field re-pointing.
 *
 * @covers \OCA\Stackiq\Service\MergeOrganisatieService
 *
 * @spec openspec/specs/organisation-merge/spec.md#requirement-merge-requests-must-be-validated-and-rejected-with-blockers-before-any-write
 */
class MergeOrganisatieBlockersTest extends TestCase {

	/**
	 * Everything the doubles saw, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $trace = [];

	/**
	 * An object entity double.
	 *
	 * @param array  $data The object data.
	 * @param string $uuid The uuid.
	 *
	 * @return ObjectEntityInterface
	 */
	private static function entity(array $data, string $uuid): ObjectEntityInterface {
		return new class($data, $uuid) implements ObjectEntityInterface {
			/**
			 * Build the double.
			 *
			 * @param array  $data The object data.
			 * @param string $uuid The uuid.
			 */
			public function __construct(private array $data, private string $uuid) {
			}

			/**
			 * @return array
			 */
			public function getObject(): array {
				return $this->data;
			}

			/**
			 * @return string|null
			 */
			public function getUuid(): ?string {
				return $this->uuid;
			}

			/**
			 * @return string|null
			 */
			public function getRegister(): ?string {
				return '100';
			}

			/**
			 * @return string|null
			 */
			public function getSchema(): ?string {
				return '1';
			}

			/**
			 * @return string|null
			 */
			public function getOrganisation(): ?string {
				return null;
			}

			/**
			 * @return string|null
			 */
			public function getOwner(): ?string {
				return null;
			}

			/**
			 * @return array
			 */
			public function jsonSerialize(): array {
				return $this->data;
			}
		};

	}//end entity()

	/**
	 * Build the service over the given organisations and objects per schema id.
	 *
	 * @param array<string, array> $organisations Organisation data by uuid.
	 * @param array<int, array>    $objects       Object data lists by schema id.
	 *
	 * @return MergeOrganisatieService
	 */
	private function service(array $organisations, array $objects): MergeOrganisatieService {
		$this->trace = [];

		$objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(
			function ($id) use ($organisations) {
				$this->trace[] = ['find', $id];
				if (isset($organisations[$id]) === false) {
					throw new \RuntimeException('missing');
				}

				return self::entity(data: $organisations[$id], uuid: $id);
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($objects) {
				$this->trace[] = ['findAll', $config['_schema']];
				$found = [];
				foreach (($objects[$config['_schema']] ?? []) as $position => $data) {
					$found[] = self::entity(data: $data, uuid: 'obj-' . $config['_schema'] . '-' . $position);
				}

				return $found;
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend=[], $register=null, $schema=null, ?string $uuid=null) {
				$this->trace[] = ['saveObject', $uuid, $object];
				return self::entity(data: $object, uuid: (string) $uuid);
			}
		);

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturn($objectService);
		$apps = $this->createMock(originalClassName: IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(['openregister']);
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getVoorzieningenRegisterId')->willReturn(100);
		$schemaIds = ['organization' => 1, 'usage' => 2, 'contactPerson' => 4, 'connection' => 5];
		$settings->method('getSchemaIdForObjectType')->willReturnCallback(fn (string $type) => ($schemaIds[$type] ?? null));

		$events = $this->createMock(originalClassName: IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(
			function ($event) {
				$this->trace[] = ['audit', get_class($event)];
			}
		);

		$progress = $this->createMock(originalClassName: ProgressTracker::class);
		$progress->method('startOperation')->willReturn('op-1');

		$trace  = &$this->trace;
		$logger = new class($trace) extends AbstractLogger {
			/**
			 * Build the logger.
			 *
			 * @param array $trace The shared trace.
			 */
			public function __construct(private array &$trace) {
			}

			/**
			 * Record a log line.
			 *
			 * @param mixed              $level   The level.
			 * @param string|\Stringable $message The message.
			 * @param array              $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context=[]): void {
				$this->trace[] = ['log', $level, (string) $message, $context];
			}
		};

		$groups = $this->createMock(originalClassName: IGroupManager::class);

		return new MergeOrganisatieService(
			$container,
			$apps,
			$groups,
			$logger,
			$events,
			$settings,
			$this->createMock(originalClassName: \OCA\Stackiq\Service\OrganisatieService::class),
			$progress,
			$this->createMock(originalClassName: OrganizationHandler::class)
		);

	}//end service()

	/**
	 * The blockers dryRun answers.
	 *
	 * @param array  $organisations Organisation data by uuid.
	 * @param string $source        The source uuid.
	 * @param string $target        The target uuid.
	 *
	 * @return array
	 */
	private function blockers(array $organisations, string $source, string $target): array {
		return array_column($this->service(organisations: $organisations, objects: [])->dryRun($source, $target)['blockers'], 'type');

	}//end blockers()

	/**
	 * Every blocker, in order; a self-merge is the only one reported for the same uuid.
	 *
	 * @return void
	 */
	public function testEveryBlockerIsReportedInOrder(): void {
		$active = ['status' => 'actief'];
		$merged = ['status' => 'merged', 'mergedInto' => 'org-x'];

		$this->assertSame(['self-merge'], $this->blockers(organisations: [], source: 'org-a', target: 'org-a'));
		$this->assertSame(['source-not-found', 'target-not-found'], $this->blockers(organisations: [], source: 'org-a', target: 'org-b'));
		$this->assertSame(['source-not-found'], $this->blockers(organisations: ['org-b' => $active], source: 'org-a', target: 'org-b'));
		$this->assertSame(['target-not-found', 'source-already-merged'], $this->blockers(organisations: ['org-a' => $merged], source: 'org-a', target: 'org-b'));
		$this->assertSame(['source-already-merged', 'target-already-merged'], $this->blockers(organisations: ['org-a' => $merged, 'org-b' => $merged], source: 'org-a', target: 'org-b'));
		$this->assertSame(['source-not-found', 'target-already-merged'], $this->blockers(organisations: ['org-b' => ['status' => 'merged']], source: 'org-a', target: 'org-b'));

		$this->assertSame([], $this->blockers(organisations: ['org-a' => ['status' => 'merged', 'mergedInto' => 'org-b'], 'org-b' => $active], source: 'org-a', target: 'org-b'));
		$this->assertSame([], $this->blockers(organisations: ['org-a' => ['mergedInto' => 'org-x'], 'org-b' => ['status' => 'Merged']], source: 'org-a', target: 'org-b'));

	}//end testEveryBlockerIsReportedInOrder()

	/**
	 * A blocked dry run reports zero counts and audits the request.
	 *
	 * @return void
	 */
	public function testABlockedDryRunAuditsAndCountsNothing(): void {
		$preview = $this->service(organisations: [], objects: [])->dryRun('org-a', 'org-b');

		$this->assertSame(['sourceUuid', 'targetUuid', 'counts', 'blockers'], array_keys($preview));
		$this->assertSame(0, array_sum($preview['counts']));
		$this->assertSame(['groupMembers', 'usage', 'contactPerson', 'aanbod', 'module', 'catalogService', 'catalogContract', 'compliancy', 'moduleOwnership', 'catalogServiceOwnership'], array_keys($preview['counts']));
		$this->assertSame(['find', 'org-a'], $this->trace[0]);
		$this->assertSame(['find', 'org-b'], $this->trace[2]);
		$this->assertSame(
			['log', 'info', 'OrganisationMerge audit: organisation-merge.dry-run', $preview + ['audit' => true]],
			$this->trace[4]
		);
		$this->assertSame(['audit', 'OCP\Log\Audit\CriticalActionPerformedEvent'], $this->trace[5]);
		$this->assertCount(6, $this->trace);

	}//end testABlockedDryRunAuditsAndCountsNothing()

	/**
	 * The scalar field and the array field are re-pointed; only matching objects are saved, whole.
	 *
	 * @return void
	 */
	public function testFieldsAreRepointedAndTheRestIsKept(): void {
		$service = $this->service(
			organisations: ['org-a' => ['status' => 'actief'], 'org-b' => ['status' => 'actief']],
			objects: [
				2 => [
					['consumer' => 'org-a', 'participants' => ['org-c', 'org-a', 'org-a'], 'name' => 'both'],
					['consumer' => 'org-c', 'participants' => ['org-a'], 'name' => 'array only'],
					['consumer' => 'org-a', 'participants' => 'org-a', 'name' => 'scalar, array not a list'],
					['consumer' => 'org-c', 'participants' => ['org-c'], 'name' => 'neither'],
					['name' => 'no fields'],
				],
				4 => [
					['organization' => 'org-a', 'participants' => ['org-a'], 'name' => 'no array field'],
					['organization' => 'ORG-A', 'name' => 'other case'],
				],
			]
		);

		$result = $service->execute('org-a', 'org-b', 'admin');

		$this->assertTrue($result['ok']);
		$this->assertSame(3, $result['counts']['usage']);
		$this->assertSame(1, $result['counts']['contactPerson']);
		$saves = array_values(array_filter($this->trace, fn (array $line) => $line[0] === 'saveObject' && str_starts_with((string) $line[1], 'obj-')));
		$this->assertSame(
			[
				['saveObject', 'obj-2-0', ['consumer' => 'org-b', 'participants' => ['org-c', 'org-b', 'org-b'], 'name' => 'both']],
				['saveObject', 'obj-2-1', ['consumer' => 'org-c', 'participants' => ['org-b'], 'name' => 'array only']],
				['saveObject', 'obj-2-2', ['consumer' => 'org-b', 'participants' => 'org-a', 'name' => 'scalar, array not a list']],
				['saveObject', 'obj-4-0', ['organization' => 'org-b', 'participants' => ['org-a'], 'name' => 'no array field']],
			],
			$saves
		);

	}//end testFieldsAreRepointedAndTheRestIsKept()
}//end class
