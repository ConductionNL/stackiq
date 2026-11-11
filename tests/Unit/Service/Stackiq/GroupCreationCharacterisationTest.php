<?php

/**
 * Characterisation test for createGroupIfNotExists of GroupHandler and
 * OrganizationHandler.
 *
 * Pins, for both handlers: an existing group is returned without creating
 * one, a missing group is created and logged, and a failing creation is
 * logged as an error and gives null.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\Stackiq
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/method-decomposition/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Stackiq;

use OCA\Stackiq\Service\Stackiq\GroupHandler;
use OCA\Stackiq\Service\Stackiq\OrganizationHandler;
use OCP\IGroup;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Characterisation of the group creation.
 *
 * @spec openspec/specs/method-decomposition/spec.md
 */
class GroupCreationCharacterisationTest extends TestCase {

	/**
	 * Everything the doubles saw, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $trace = [];

	/**
	 * The handlers.
	 *
	 * @return array<string, array{0: class-string}>
	 */
	public static function handlers(): array {
		return [
			'GroupHandler'        => [GroupHandler::class],
			'OrganizationHandler' => [OrganizationHandler::class],
		];

	}//end handlers()

	/**
	 * An existing group is returned and nothing is created.
	 *
	 * @param class-string $class The handler.
	 *
	 * @return void
	 *
	 * @dataProvider handlers
	 */
	public function testAnExistingGroupIsReturned(string $class): void {
		$group = $this->createMock(originalClassName: IGroup::class);
		$this->assertSame(expected: $group, actual: $this->create(class: $class, existing: $group, plan: 'ok'));
		$this->assertSame(expected: [['groups.get', 'org-a']], actual: $this->trace);

	}//end testAnExistingGroupIsReturned()

	/**
	 * A missing group is created and logged.
	 *
	 * @param class-string $class The handler.
	 *
	 * @return void
	 *
	 * @dataProvider handlers
	 */
	public function testAMissingGroupIsCreated(string $class): void {
		$this->assertInstanceOf(expected: IGroup::class, actual: $this->create(class: $class, existing: null, plan: 'ok'));
		$this->assertSame(
			expected: [['groups.get', 'org-a'], ['groups.createGroup', 'org-a'], ['log.info', 'Created new group', ['groupName' => 'org-a']]],
			actual: $this->trace
		);

	}//end testAMissingGroupIsCreated()

	/**
	 * A failing creation is logged as an error and gives null.
	 *
	 * @param class-string $class The handler.
	 *
	 * @return void
	 *
	 * @dataProvider handlers
	 */
	public function testAFailingCreationGivesNull(string $class): void {
		$this->assertNull(actual: $this->create(class: $class, existing: null, plan: 'throws'));
		$this->assertSame(
			expected: [
				['groups.get', 'org-a'],
				['groups.createGroup', 'org-a'],
				['log.error', 'Failed to create group: backend refused', ['groupName' => 'org-a', 'exception' => 'RuntimeException']],
			],
			actual: $this->trace
		);

	}//end testAFailingCreationGivesNull()

	/**
	 * Run the handler's createGroupIfNotExists with recording doubles.
	 *
	 * @param class-string $class    The handler.
	 * @param IGroup|null  $existing The group the manager already has.
	 * @param string       $plan     ok or throws.
	 *
	 * @return IGroup|null
	 */
	private function create(string $class, ?IGroup $existing, string $plan): ?IGroup {
		$this->trace = [];
		$created     = $this->createMock(originalClassName: IGroup::class);
		$groups      = $this->createMock(originalClassName: IGroupManager::class);
		$groups->method('get')->willReturnCallback(
			function (string $gid) use ($existing): ?IGroup {
				$this->trace[] = ['groups.get', $gid];
				return $existing;
			}
		);
		$groups->method('createGroup')->willReturnCallback(
			function (string $gid) use ($plan, $created): ?IGroup {
				$this->trace[] = ['groups.createGroup', $gid];
				if ($plan === 'throws') {
					throw new \RuntimeException('backend refused');
				}

				return $created;
			}
		);

		$handler = (new \ReflectionClass(objectOrClass: $class))->newInstanceWithoutConstructor();
		foreach (['_groupManager' => $groups, '_logger' => $this->logger()] as $prop => $value) {
			(new \ReflectionProperty(class: $class, property: $prop))->setValue($handler, $value);
		}

		return $handler->createGroupIfNotExists(groupName: 'org-a');

	}//end create()

	/**
	 * A logger that records every line, with exceptions as their class.
	 *
	 * @return AbstractLogger
	 */
	private function logger(): AbstractLogger {
		$trace = &$this->trace;
		return new class($trace) extends AbstractLogger {
			/**
			 * Build the logger.
			 *
			 * @param array $trace The shared trace.
			 */
			public function __construct(private array &$trace) {
			}

			/**
			 * Record one line.
			 *
			 * @param mixed              $level   The level.
			 * @param string|\Stringable $message The message.
			 * @param array              $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				if (isset($context['exception']) === true && $context['exception'] instanceof \Throwable) {
					$context['exception'] = get_class($context['exception']);
				}

				$this->trace[] = ['log.' . $level, (string) $message, $context];
			}
		};

	}//end logger()

}//end class
