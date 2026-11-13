<?php

/**
 * Characterisation tests for the event dispatch of StackiqEventListener.
 *
 * Pins what the listener does with the events the lifecycle golden test
 * does not cover: the lock, unlock and revert events it ignores, and events
 * of any other type.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\EventListener
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/method-decomposition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\EventListener;

require_once __DIR__ . '/openregister-debug-event-doubles.php';

use OCA\OpenRegister\Event\ObjectLockedEvent;
use OCA\OpenRegister\Event\ObjectRevertedEvent;
use OCA\OpenRegister\Event\ObjectUnlockedEvent;
use OCA\Stackiq\EventListener\StackiqEventListener;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;

/**
 * Cases for the events the listener ignores.
 *
 * @covers \OCA\Stackiq\EventListener\StackiqEventListener
 *
 * @spec openspec/specs/method-decomposition/spec.md
 */
class StackiqEventListenerDispatchTest extends TestCase {

	/**
	 * Everything the logger saw, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $trace = [];

	/**
	 * Dispatch an event with a recording logger and no other services.
	 *
	 * @param Event $event The event.
	 *
	 * @return array The log lines.
	 */
	private function dispatch(Event $event): array {
		$this->trace = [];
		$trace       = &$this->trace;
		$logger      = new class($trace) extends AbstractLogger {
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
				$this->trace[] = [$level, (string) $message, $context];
			}
		};

		$listener = new StackiqEventListener($this->createMock(originalClassName: ContainerInterface::class));
		(new \ReflectionMethod($listener, 'dispatchEvent'))->invoke($listener, $event, ['logger' => $logger, 'contactSvc' => null, 'settings' => null]);

		return $this->trace;

	}//end dispatch()

	/**
	 * Lock, unlock and revert events are logged as ignored.
	 *
	 * @return void
	 */
	public function testLockUnlockAndRevertAreIgnored(): void {
		foreach ([new ObjectLockedEvent([]), new ObjectUnlockedEvent([]), new ObjectRevertedEvent([])] as $event) {
			$this->assertSame(
				[['debug', 'Stackiq: Ignoring object lifecycle event', ['eventType' => get_class($event)]]],
				$this->dispatch(event: $event)
			);
		}

	}//end testLockUnlockAndRevertAreIgnored()

	/**
	 * Any other event is passed over silently.
	 *
	 * @return void
	 */
	public function testOtherEventsAreSilentlyPassedOver(): void {
		$this->assertSame([], $this->dispatch(event: new Event()));

	}//end testOtherEventsAreSilentlyPassedOver()
}//end class
