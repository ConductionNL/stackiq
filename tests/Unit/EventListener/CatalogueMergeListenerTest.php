<?php

/**
 * After OpenRegister merges two applications, the catalogue follows the survivor.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\EventListener
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/record-reconciliation/spec.md#requirement-req-rrc-003-after-openregister-merges-two-applications-or-services-every-catalogue-reference-shall-point-at-the-survivor
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\EventListener;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectsMergedEvent;
use OCA\Stackiq\BackgroundJob\CatalogueMergeRelinkJob;
use OCA\Stackiq\EventListener\CatalogueMergeListener;
use OCA\Stackiq\Service\CatalogueMergeRelinker;
use OCA\Stackiq\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The real event class, the real listener, job and relinker; OpenRegister's
 * object service is the double at the edge.
 */
class CatalogueMergeListenerTest extends TestCase {
	private const REGISTER = 5;

	private const SCHEMAS = [
		'module' => 7,
		'catalogService' => 8,
		'usage' => 2,
		'connection' => 9,
		'organization' => 1,
	];

	/**
	 * Objects by schema id.
	 *
	 * @var array<int, array<int, ObjectEntity>>
	 */
	private array $objects = [];

	/**
	 * Captured saves: uuid => data.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Queued jobs.
	 *
	 * @var array<int, array{0: string, 1: mixed}>
	 */
	private array $queued = [];

	/**
	 * Dispatched audit events.
	 *
	 * @var array<int, Event>
	 */
	private array $audit = [];

	/**
	 * The relinker.
	 *
	 * @var CatalogueMergeRelinker
	 */
	private CatalogueMergeRelinker $relinker;

	/**
	 * An object entity double.
	 *
	 * @param string               $uuid   The id.
	 * @param int                  $schema The schema id.
	 * @param array<string, mixed> $data   The object data.
	 *
	 * @return ObjectEntity The double.
	 */
	private function entity(string $uuid, int $schema, array $data): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getUuid')->willReturn($uuid);
		$entity->method('getSchema')->willReturn((string) $schema);
		$entity->method('getRegister')->willReturn((string) self::REGISTER);
		$entity->method('getObject')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * Two applications (orig and dup), a usage and a connection on dup, a
	 * service that lists both, and an unrelated usage.
	 *
	 * @return CatalogueMergeListener The listener.
	 */
	private function listener(): CatalogueMergeListener {
		$this->objects = [
			self::SCHEMAS['module'] => [
				$this->entity('orig', self::SCHEMAS['module'], ['name' => 'Zaaksysteem', 'recordStatus' => 'Active']),
				$this->entity('dup', self::SCHEMAS['module'], ['name' => 'zaaksysteem', 'recordStatus' => 'Merged']),
			],
			self::SCHEMAS['usage'] => [
				$this->entity('u1', self::SCHEMAS['usage'], ['module' => 'dup', 'consumer' => 'gemeente', 'status' => 'In production']),
				$this->entity('u2', self::SCHEMAS['usage'], ['module' => 'other', 'plannedReplacement' => ['id' => 'dup']]),
				$this->entity('u3', self::SCHEMAS['usage'], ['module' => 'other']),
			],
			self::SCHEMAS['connection'] => [
				$this->entity('c1', self::SCHEMAS['connection'], ['moduleA' => 'dup', 'moduleB' => 'x']),
			],
			self::SCHEMAS['catalogService'] => [
				$this->entity('s1', self::SCHEMAS['catalogService'], ['name' => 'Hosting', 'modules' => ['orig', 'dup']]),
			],
			self::SCHEMAS['organization'] => [
				$this->entity('org-a', self::SCHEMAS['organization'], ['name' => 'A']),
			],
		];

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getSchemaIdForObjectType')->willReturnCallback(fn (string $t): ?int => self::SCHEMAS[$t] ?? null);
		$settings->method('getRegisterIdForObjectType')->willReturn(self::REGISTER);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id): ?ObjectEntity {
				foreach ($this->objects as $list) {
					foreach ($list as $entity) {
						if ($entity->getUuid() === $id) {
							return $entity;
						}
					}
				}

				return null;
			}
		);
		$objectService->method('searchObjects')->willReturnCallback(
			function (array $query=[]): array {
				$this->assertSame(self::REGISTER, $query['register']);
				return $this->objects[(int) $query['schema']] ?? [];
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend=[], $register=null, $schema=null, $uuid=null): ObjectEntity {
				$this->saved[(string) $uuid] = $object;
				return $this->createMock(ObjectEntity::class);
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event): void {
				$this->audit[] = $event;
			}
		);

		$jobList = $this->createMock(IJobList::class);
		$jobList->method('add')->willReturnCallback(
			function (string $job, mixed $argument): void {
				$this->queued[] = [$job, $argument];
			}
		);

		$logger         = $this->createMock(LoggerInterface::class);
		$this->relinker = new CatalogueMergeRelinker($settings, $container, $dispatcher, $logger);
		return new CatalogueMergeListener($jobList, $logger);
	}//end listener()

	/**
	 * Run the queued jobs the way cron runs them.
	 *
	 * @return void
	 */
	private function runQueuedJobs(): void {
		foreach ($this->queued as [$class, $argument]) {
			$this->assertSame(CatalogueMergeRelinkJob::class, $class);
			$job = new CatalogueMergeRelinkJob($this->createMock(ITimeFactory::class), $this->relinker);
			$run = new \ReflectionMethod($job, 'run');
			$run->invoke($job, $argument);
		}
	}//end runQueuedJobs()

	/**
	 * The listener only queues; the job moves every reference and marks the loser.
	 *
	 * @return void
	 */
	public function testUsagesAndConnectionsFollowTheSurvivor(): void {
		$listener = $this->listener();
		$listener->handle(new ObjectsMergedEvent(survivorUuid: 'orig', mergedFromUuids: ['dup'], mergeOperationId: 'op-7'));

		$this->assertCount(1, $this->queued, 'the listener queues one job');
		$this->assertSame([], $this->saved, 'and writes nothing itself');

		$this->runQueuedJobs();

		$this->assertSame('orig', $this->saved['u1']['module']);
		$this->assertSame('gemeente', $this->saved['u1']['consumer'], 'other fields stay');
		$this->assertSame('orig', $this->saved['u2']['plannedReplacement']);
		$this->assertSame('orig', $this->saved['c1']['moduleA']);
		$this->assertSame('x', $this->saved['c1']['moduleB']);
		$this->assertArrayNotHasKey('u3', $this->saved, 'an unrelated usage is not written');
		$this->assertSame('orig', $this->saved['dup']['mergedInto']);
		$this->assertArrayNotHasKey('orig', $this->saved, 'the survivor is not written');
	}//end testUsagesAndConnectionsFollowTheSurvivor()

	/**
	 * A service that listed both lists the survivor once.
	 *
	 * @return void
	 */
	public function testAnArrayDoesNotGetTheSurvivorTwice(): void {
		$this->listener()->handle(new ObjectsMergedEvent(survivorUuid: 'orig', mergedFromUuids: ['dup'], mergeOperationId: 'op-7'));
		$this->runQueuedJobs();
		$this->assertSame(['orig'], $this->saved['s1']['modules']);
	}//end testAnArrayDoesNotGetTheSurvivorTwice()

	/**
	 * One audit entry per moved reference, naming the merge operation.
	 *
	 * @return void
	 */
	public function testEachMovedReferenceIsAudited(): void {
		$this->listener()->handle(new ObjectsMergedEvent(survivorUuid: 'orig', mergedFromUuids: ['dup'], mergeOperationId: 'op-7'));
		$this->runQueuedJobs();

		$audits = array_values(array_filter($this->audit, static fn (Event $e): bool => $e instanceof CriticalActionPerformedEvent));
		$this->assertCount(4, $audits, 'u1.module, u2.plannedReplacement, c1.moduleA, s1.modules');
		foreach ($audits as $audit) {
			$this->assertContains('op-7', $audit->getParameters());
		}
	}//end testEachMovedReferenceIsAudited()

	/**
	 * A merge of another schema, and a reversal, change nothing.
	 *
	 * @return void
	 */
	public function testAnotherSchemaOrAReversalChangesNothing(): void {
		$listener = $this->listener();
		$listener->handle(new ObjectsMergedEvent(survivorUuid: 'orig', mergedFromUuids: ['dup'], mergeOperationId: 'op-8', isReversal: true));
		$this->assertSame([], $this->queued, 'a reversal queues nothing');

		$listener->handle(new ObjectsMergedEvent(survivorUuid: 'org-a', mergedFromUuids: ['org-b'], mergeOperationId: 'op-9'));
		$this->runQueuedJobs();
		$this->assertSame([], $this->saved, 'an organisation merge is not this listener\'s');
	}//end testAnotherSchemaOrAReversalChangesNothing()
}//end class
