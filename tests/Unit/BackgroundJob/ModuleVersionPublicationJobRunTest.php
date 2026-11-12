<?php

/**
 * Characterisation tests for ModuleVersionPublicationJob::run.
 *
 * Pins, per argument, whether the job clears or copies the publication of a
 * module onto its versions, when it queues itself again, and every call it
 * makes, in order.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\BackgroundJob
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/publication-field-rules/spec.md#requirement-req-pfr-002-a-module-version-is-public-only-while-its-application-is
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\BackgroundJob\ModuleVersionPublicationJob;
use OCA\Stackiq\Service\ModuleVersionPublicationService;
use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;

/**
 * Cases for the publication copy job.
 *
 * @covers \OCA\Stackiq\BackgroundJob\ModuleVersionPublicationJob
 *
 * @spec openspec/specs/publication-field-rules/spec.md#requirement-req-pfr-002-a-module-version-is-public-only-while-its-application-is
 */
class ModuleVersionPublicationJobRunTest extends TestCase {

	/**
	 * Everything the doubles saw, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $trace = [];

	/**
	 * Per-case behaviour of the doubles.
	 *
	 * @var array<string, mixed>
	 */
	private array $plan = [];

	/**
	 * Run the job on an argument.
	 *
	 * @param mixed $argument The job argument.
	 * @param array $plan     The doubles' behaviour: find (module|missing|error|absent), cleared, copied.
	 *
	 * @return array The trace.
	 */
	private function runJob(mixed $argument, array $plan): array {
		$this->trace = [];
		$this->plan  = array_merge(['find' => 'module', 'cleared' => 0, 'copied' => 0], $plan);

		$module = $this->createMock(originalClassName: ObjectEntityInterface::class);
		$module->method('getUuid')->willReturn('the-module');

		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function ($id, $_extend=[], $files=false, $register=null, $schema=null, $_rbac=true, $_multitenancy=true) use ($module) {
				$this->trace[] = ['find', $id, $register, $schema, $_rbac, $_multitenancy];
				if ($this->plan['find'] === 'missing') {
					throw new DoesNotExistException('gone');
				}

				if ($this->plan['find'] === 'error') {
					throw new \RuntimeException('read failed');
				}

				return $module;
			}
		);

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($objects) {
				$this->trace[] = ['container.get', $id];
				if ($this->plan['find'] === 'absent') {
					return new \stdClass();
				}

				return $objects;
			}
		);

		$locator = $this->createMock(originalClassName: ObjectTypeLocatorInterface::class);
		$locator->method('getRegisterIdForObjectType')->willReturn(4);
		$locator->method('getSchemaIdForObjectType')->willReturn(5);

		$publication = $this->createMock(originalClassName: ModuleVersionPublicationService::class);
		$publication->method('clearVersions')->willReturnCallback(
			function (string $moduleUuid) {
				$this->trace[] = ['clearVersions', $moduleUuid];
				return ['cleared' => 2, 'failed' => $this->plan['cleared']];
			}
		);
		$publication->method('backfillModule')->willReturnCallback(
			function (ObjectEntityInterface $module) {
				$this->trace[] = ['backfillModule', $module->getUuid()];
				return ['copied' => 2, 'failed' => $this->plan['copied']];
			}
		);

		$jobs = $this->createMock(originalClassName: IJobList::class);
		$jobs->method('scheduleAfter')->willReturnCallback(
			function (string $job, int $runAfter, $argument) {
				$this->trace[] = ['scheduleAfter', $job, $runAfter, $argument];
			}
		);

		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('getTime')->willReturn(1000);

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

		$job = new ModuleVersionPublicationJob($time, $publication, $locator, $container, $logger, $jobs);
		(new \ReflectionMethod($job, 'run'))->invoke($job, $argument);

		return $this->trace;

	}//end runJob()

	/**
	 * The calls that read the module.
	 *
	 * @return array
	 */
	private static function read(): array {
		return [['container.get', ObjectServiceInterface::class], ['find', 'm-1', 4, 5, false, false]];

	}//end read()

	/**
	 * An argument without a module id does nothing.
	 *
	 * @return void
	 */
	public function testArgumentWithoutModuleDoesNothing(): void {
		foreach ([null, 'm-1', [], ['module' => ''], ['module' => 5], ['deleted' => true]] as $argument) {
			$this->assertSame([], $this->runJob(argument: $argument, plan: []), var_export($argument, true));
		}

	}//end testArgumentWithoutModuleDoesNothing()

	/**
	 * A deleted module clears its versions without reading it; only a strict true counts as deleted.
	 *
	 * @return void
	 */
	public function testDeletedModuleClearsItsVersions(): void {
		$this->assertSame([['clearVersions', 'm-1']], $this->runJob(argument: ['module' => 'm-1', 'deleted' => true], plan: []));

		$this->assertSame(
			[['clearVersions', 'm-1'], ['scheduleAfter', ModuleVersionPublicationJob::class, 1300, ['module' => 'm-1', 'deleted' => true, 'attempt' => 2]]],
			$this->runJob(argument: ['module' => 'm-1', 'deleted' => true], plan: ['cleared' => 1])
		);

		$this->assertSame(
			array_merge(self::read(), [['backfillModule', 'the-module']]),
			$this->runJob(argument: ['module' => 'm-1', 'deleted' => 'yes'], plan: [])
		);

	}//end testDeletedModuleClearsItsVersions()

	/**
	 * A module that exists has its publication copied, a failed copy is queued again until the last try.
	 *
	 * @return void
	 */
	public function testExistingModuleIsCopied(): void {
		$this->assertSame(array_merge(self::read(), [['backfillModule', 'the-module']]), $this->runJob(argument: ['module' => 'm-1'], plan: []));

		$this->assertSame(
			array_merge(
				self::read(),
				[['backfillModule', 'the-module'], ['scheduleAfter', ModuleVersionPublicationJob::class, 1300, ['module' => 'm-1', 'deleted' => false, 'attempt' => 3]]]
			),
			$this->runJob(argument: ['module' => 'm-1', 'deleted' => false, 'attempt' => 2], plan: ['copied' => 2])
		);

		$this->assertSame(
			array_merge(
				self::read(),
				[
					['backfillModule', 'the-module'],
					[
						'log',
						'critical',
						'ModuleVersionPublicationJob: gave up copying the publication onto the versions; save the module again to retry',
						['module' => 'm-1', 'attempts' => 3, 'failed' => 1],
					],
				]
			),
			$this->runJob(argument: ['module' => 'm-1', 'attempt' => 3], plan: ['copied' => 1])
		);

	}//end testExistingModuleIsCopied()

	/**
	 * A module that no longer exists has its versions cleared.
	 *
	 * @return void
	 */
	public function testMissingModuleClearsItsVersions(): void {
		$this->assertSame(array_merge(self::read(), [['clearVersions', 'm-1']]), $this->runJob(argument: ['module' => 'm-1'], plan: ['find' => 'missing']));

	}//end testMissingModuleClearsItsVersions()

	/**
	 * A module that cannot be read, or OpenRegister being absent, is logged and tried again.
	 *
	 * @return void
	 */
	public function testUnreadableModuleIsTriedAgain(): void {
		$this->assertSame(
			array_merge(
				self::read(),
				[
					['log', 'error', 'ModuleVersionPublicationJob: could not read the module; its versions are tried again later', ['module' => 'm-1', 'error' => 'read failed']],
					['scheduleAfter', ModuleVersionPublicationJob::class, 1300, ['module' => 'm-1', 'attempt' => 2]],
				]
			),
			$this->runJob(argument: ['module' => 'm-1'], plan: ['find' => 'error'])
		);

		$this->assertSame(
			[
				['container.get', ObjectServiceInterface::class],
				['log', 'error', 'ModuleVersionPublicationJob: could not read the module; its versions are tried again later', ['module' => 'm-1', 'error' => "OpenRegister's object service is not available"]],
				['scheduleAfter', ModuleVersionPublicationJob::class, 1300, ['module' => 'm-1', 'attempt' => 2]],
			],
			$this->runJob(argument: ['module' => 'm-1'], plan: ['find' => 'absent'])
		);

	}//end testUnreadableModuleIsTriedAgain()
}//end class
