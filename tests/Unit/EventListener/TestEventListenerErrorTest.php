<?php

/**
 * The test listener logs, rather than throws, when the logger fails while confirming a login.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\EventListener
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\EventListener;

use OCA\Stackiq\EventListener\TestEventListener;
use OCP\IUser;
use OCP\User\Events\UserLoggedInEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the error path of TestEventListener.
 *
 * @covers \OCA\Stackiq\EventListener\TestEventListener
 */
final class TestEventListenerErrorTest extends TestCase {

	/**
	 * A failing confirmation is logged as a processing error with its message.
	 *
	 * @return void
	 */
	public function testAFailingConfirmationIsLoggedAsAnError(): void {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('jan');
		$login = $this->createMock(originalClassName: UserLoggedInEvent::class);
		$login->method('getUser')->willReturn($user);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('debug')->willThrowException(exception: new RuntimeException(message: 'log sink full'));
		$errors = [];
		$logger->expects($this->once())->method('error')->willReturnCallback(
			function (string $message, array $context) use (&$errors): void {
				$errors[] = [$message, $context];
			}
		);

		(new TestEventListener(logger: $logger))->handle($login);

		$this->assertSame(expected: 'Stackiq TestEventListener: Error in event processing', actual: $errors[0][0]);
		$this->assertSame(expected: 'log sink full', actual: $errors[0][1]['exception']);
		$this->assertIsString(actual: $errors[0][1]['trace']);
	}//end testAFailingConfirmationIsLoggedAsAnError()
}//end class
