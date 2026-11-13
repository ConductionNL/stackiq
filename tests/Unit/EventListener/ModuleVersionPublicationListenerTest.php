<?php

/**
 * Which publication call each OpenRegister object event makes, and that a failure is logged.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Stackiq\EventListener\ModuleVersionPublicationListener;
use OCA\Stackiq\Service\ModuleVersionPublicationService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for ModuleVersionPublicationListener.
 *
 * @covers \OCA\Stackiq\EventListener\ModuleVersionPublicationListener
 */
final class ModuleVersionPublicationListenerTest extends TestCase {

	/**
	 * The publication service.
	 *
	 * @var ModuleVersionPublicationService&MockObject
	 */
	private ModuleVersionPublicationService $publication;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The listener under test.
	 *
	 * @var ModuleVersionPublicationListener
	 */
	private ModuleVersionPublicationListener $listener;

	/**
	 * Wire the listener.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->publication = $this->createMock(originalClassName: ModuleVersionPublicationService::class);
		$this->logger      = $this->createMock(originalClassName: LoggerInterface::class);
		$this->listener    = new ModuleVersionPublicationListener(publication: $this->publication, logger: $this->logger);
	}//end setUp()

	/**
	 * An update mirrors the new object with the old one as its previous version.
	 *
	 * @return void
	 */
	public function testAnUpdatePassesTheNewAndThePreviousObject(): void {
		$new = $this->createMock(originalClassName: ObjectEntity::class);
		$old = $this->createMock(originalClassName: ObjectEntity::class);
		$this->publication->expects($this->once())->method('objectSaved')->with($new, $old)->willReturn(1);
		$this->publication->expects($this->never())->method('objectDeleted');
		$this->logger->expects($this->never())->method('error');

		$this->listener->handle(new ObjectUpdatedEvent($new, $old));
	}//end testAnUpdatePassesTheNewAndThePreviousObject()

	/**
	 * A create mirrors the object without a previous version.
	 *
	 * @return void
	 */
	public function testACreatePassesTheObjectWithoutAPrevious(): void {
		$object = $this->createMock(originalClassName: ObjectEntity::class);
		$this->publication->expects($this->once())->method('objectSaved')->with($object, null)->willReturn(1);
		$this->publication->expects($this->never())->method('objectDeleted');

		$this->listener->handle(new ObjectCreatedEvent($object));
	}//end testACreatePassesTheObjectWithoutAPrevious()

	/**
	 * A delete removes the object's publication.
	 *
	 * @return void
	 */
	public function testADeleteRemovesThePublication(): void {
		$object = $this->createMock(originalClassName: ObjectEntity::class);
		$this->publication->expects($this->once())->method('objectDeleted')->with($object)->willReturn(1);
		$this->publication->expects($this->never())->method('objectSaved');

		$this->listener->handle(new ObjectDeletedEvent($object));
	}//end testADeleteRemovesThePublication()

	/**
	 * Any other event is ignored.
	 *
	 * @return void
	 */
	public function testAnotherEventIsIgnored(): void {
		$this->publication->expects($this->never())->method('objectSaved');
		$this->publication->expects($this->never())->method('objectDeleted');
		$this->logger->expects($this->never())->method('error');

		$this->listener->handle(new Event());
	}//end testAnotherEventIsIgnored()

	/**
	 * A failing publication is logged with its message and does not escape the listener.
	 *
	 * @return void
	 */
	public function testAFailureIsLoggedNotThrown(): void {
		$this->publication->method('objectDeleted')->willThrowException(exception: new RuntimeException(message: 'register offline'));
		$this->logger->expects($this->once())->method('error')->with(
			'ModuleVersionPublicationListener: could not mirror the publication',
			['error' => 'register offline']
		);

		$this->listener->handle(new ObjectDeletedEvent($this->createMock(originalClassName: ObjectEntity::class)));
	}//end testAFailureIsLoggedNotThrown()
}//end class
