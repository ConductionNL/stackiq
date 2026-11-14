<?php

/**
 * A maintenance window whose owner resolution cannot be queued is logged,
 * not thrown.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\EventListener
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/maintenance-and-supplier-roadmap/spec.md#requirement-req-msr-003-the-owners-of-every-usage-are-notified
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\EventListener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\Stackiq\EventListener\MaintenanceRecipientsListener;
use OCA\Stackiq\Service\MaintenanceRecipientService;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Failure paths of MaintenanceRecipientsListener::handle().
 */
class MaintenanceRecipientsListenerFailureTest extends TestCase {

	/**
	 * A failing window check or a failing queue write logs the uuid and the error.
	 *
	 * @return void
	 */
	public function testAFailureToQueueIsLogged(): void {
		$window = $this->createMock(ObjectEntity::class);
		$window->method('getUuid')->willReturn('w-1');
		$window->method('getRegister')->willReturn('7');
		$window->method('getSchema')->willReturn('40');

		$recipients = $this->createMock(MaintenanceRecipientService::class);
		$recipients->method('isMaintenanceWindow')->willReturnOnConsecutiveCalls(
			$this->throwException(new \RuntimeException('schema lookup failed')),
			true
		);
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects($this->once())->method('add')->willThrowException(new \RuntimeException('job list down'));
		$errors = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context) use (&$errors): void {
				$errors[] = [$message, $context];
			}
		);

		$listener = new MaintenanceRecipientsListener($recipients, $jobs, $logger);
		$listener->handle(new ObjectCreatedEvent($window));
		$listener->handle(new ObjectCreatedEvent($window));

		$this->assertSame(
			[
				['MaintenanceRecipientsListener: could not queue the owner resolution', ['uuid' => 'w-1', 'error' => 'schema lookup failed']],
				['MaintenanceRecipientsListener: could not queue the owner resolution', ['uuid' => 'w-1', 'error' => 'job list down']],
			],
			$errors
		);

	}//end testAFailureToQueueIsLogged()
}//end class
