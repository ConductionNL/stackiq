<?php

/**
 * Golden test of the test event listener's log lines.
 *
 * Pins, for a login event and for any other event, every log call with its
 * level, message and context; timestamps are masked.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\EventListener
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\EventListener;

use OCA\Stackiq\EventListener\TestEventListener;
use OCP\EventDispatcher\Event;
use OCP\IUser;
use OCP\User\Events\UserLoggedInEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Records log calls with masked timestamps.
 */
class TestEventLogRecorder extends AbstractLogger {
	/**
	 * The recorded calls.
	 *
	 * @var array<int, array>
	 */
	public array $records = [];

	/**
	 * Record one log call.
	 *
	 * @param mixed              $level   The level.
	 * @param string|\Stringable $message The message.
	 * @param array              $context The context.
	 *
	 * @return void
	 */
	public function log($level, string|\Stringable $message, array $context=[]): void {
		foreach (['timestamp', 'microtime'] as $key) {
			if (array_key_exists($key, $context) === true) {
				$context[$key] = '<' . $key . '>';
			}
		}

		$this->records[] = [(string)$level, (string)$message, $context];
	}
}

/**
 * Golden test of TestEventListener.
 */
class TestEventListenerGoldenTest extends TestCase {
	/**
	 * The log lines for a login and for another event match the golden.
	 *
	 * @return void
	 */
	public function testLogLinesMatchTheGolden(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$user->method('getDisplayName')->willReturn('Alice');
		$user->method('getEMailAddress')->willReturn('alice@example.org');
		$login = $this->createMock(UserLoggedInEvent::class);
		$login->method('getUser')->willReturn($user);

		$out = [];
		foreach (['login' => $login, 'other' => new Event()] as $name => $event) {
			$logger = new TestEventLogRecorder();
			(new TestEventListener($logger))->handle($event);
			$records = $logger->records;
			foreach ($records as $index => $record) {
				if (isset($record[2]['eventClass']) === true) {
					$records[$index][2]['eventClass'] = ($record[2]['eventClass'] === Event::class) ? 'Event' : 'login event';
				}
			}

			$out[$name] = $records;
		}

		$path = dirname(__DIR__, 2) . '/fixtures/events/test-event-listener.json';
		$this->assertSame(
			json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR),
			json_decode((string)json_encode($out, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR)
		);
	}//end testLogLinesMatchTheGolden()
}//end class
