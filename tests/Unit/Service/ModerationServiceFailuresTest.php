<?php

/**
 * Unit tests for the failure paths of ModerationService.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Stackiq\Service\ModerationService;
use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Missing configuration, a missing ObjectService, failed queries, missing
 * entries and failed saves each refuse with their own reason.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Service
 *
 * @covers \OCA\Stackiq\Service\ModerationService
 * @uses   \OCA\Stackiq\Service\ObjectTypeTarget
 * @uses   \OCA\Stackiq\Service\ObjectDataBag
 */
class ModerationServiceFailuresTest extends TestCase {

	private ContainerInterface&MockObject $container;

	private ObjectTypeLocatorInterface&MockObject $locator;

	private LoggerInterface&MockObject $logger;

	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container     = $this->createMock(ContainerInterface::class);
		$this->locator       = $this->createMock(ObjectTypeLocatorInterface::class);
		$this->logger        = $this->createMock(LoggerInterface::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);

	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return ModerationService
	 */
	private function service(): ModerationService {
		return new ModerationService($this->container, $this->locator, $this->logger);

	}//end service()

	/**
	 * Register 3 and schema 7 are configured.
	 *
	 * @return void
	 */
	private function configured(): void {
		$this->locator->method('getRegisterIdForObjectType')->willReturn(3);
		$this->locator->method('getSchemaIdForObjectType')->willReturn(7);

	}//end configured()

	/**
	 * The container hands out the ObjectService double.
	 *
	 * @return void
	 */
	private function withObjectService(): void {
		$this->container->method('get')->with('OCA\\OpenRegister\\Service\\ObjectService')->willReturn($this->objectService);

	}//end withObjectService()

	/**
	 * A pending organisation entry.
	 *
	 * @return ObjectEntity&MockObject
	 */
	private function pendingEntry(): ObjectEntity&MockObject {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn(['id' => 'e1', 'name' => 'X', 'registrationStatus' => 'pending', 'status' => 'pending']);
		$entity->method('getObject')->willReturn(['id' => 'e1', 'name' => 'X', 'registrationStatus' => 'pending', 'status' => 'pending']);
		return $entity;

	}//end pendingEntry()

	/**
	 * Without a register or schema nothing is looked up.
	 *
	 * @return void
	 */
	public function testUnconfiguredTypeIsRefused(): void {
		$this->locator->method('getRegisterIdForObjectType')->willReturn(3);
		$this->locator->method('getSchemaIdForObjectType')->willReturn(null);
		$this->container->expects($this->never())->method('get');

		$this->assertSame(['ok' => false, 'reason' => 'register/schema not configured', 'items' => []], $this->service()->listPending());
		$this->assertSame(['ok' => false, 'reason' => 'register/schema not configured', 'status' => null], $this->service()->approve('e1'));
		$this->assertSame(['ok' => false, 'reason' => 'register/schema not configured', 'status' => null], $this->service()->reject('e1', ModerationService::MODERATED_TYPE_REVIEW));

	}//end testUnconfiguredTypeIsRefused()

	/**
	 * A container that cannot give the ObjectService is logged and refused.
	 *
	 * @return void
	 */
	public function testMissingObjectServiceIsRefused(): void {
		$this->configured();
		$this->container->method('get')->willThrowException(new RuntimeException('no openregister'));
		$this->logger->expects($this->exactly(2))->method('error')
			->with('ModerationService: ObjectService unavailable', ['error' => 'no openregister']);

		$this->assertSame(['ok' => false, 'reason' => 'ObjectService unavailable', 'items' => []], $this->service()->listPending());
		$this->assertSame(['ok' => false, 'reason' => 'ObjectService unavailable', 'status' => null], $this->service()->approve('e1'));

	}//end testMissingObjectServiceIsRefused()

	/**
	 * A failing pending query is logged with its type and refused.
	 *
	 * @return void
	 */
	public function testFailingPendingQueryIsRefused(): void {
		$this->configured();
		$this->withObjectService();
		$this->objectService->expects($this->once())->method('searchObjects')
			->with(['@self' => ['register' => 3, 'schema' => 7], 'status' => 'pending', '_limit' => 500], false, false)
			->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('error')
			->with('ModerationService: listPending failed', ['type' => 'software-review', 'error' => 'db down']);

		$this->assertSame(
			['ok' => false, 'reason' => 'query failed', 'items' => []],
			$this->service()->listPending(ModerationService::MODERATED_TYPE_REVIEW)
		);

	}//end testFailingPendingQueryIsRefused()

	/**
	 * An entry that cannot be found, by exception or by null, is refused unsaved.
	 *
	 * @return void
	 */
	public function testMissingEntryIsRefused(): void {
		$this->configured();
		$this->withObjectService();
		$this->objectService->expects($this->exactly(2))->method('find')
			->willReturnOnConsecutiveCalls($this->throwException(new RuntimeException('gone')), null);
		$this->objectService->expects($this->never())->method('saveObject');

		$this->assertSame(['ok' => false, 'reason' => 'entry not found', 'status' => null], $this->service()->approve('e1'));
		$this->assertSame(['ok' => false, 'reason' => 'entry not found', 'status' => null], $this->service()->reject('e1'));

	}//end testMissingEntryIsRefused()

	/**
	 * A failing save is logged with the action and refused.
	 *
	 * @return void
	 */
	public function testFailingSaveIsRefused(): void {
		$this->configured();
		$this->withObjectService();
		$this->objectService->method('find')->willReturn($this->pendingEntry());
		$this->objectService->expects($this->once())->method('saveObject')
			->with(
				$this->callback(fn (array $data): bool => $data['status'] === 'rejected' && array_key_exists('id', $data) === false),
				[],
				3,
				7
			)
			->willThrowException(new RuntimeException('locked'));
		$this->logger->expects($this->once())->method('error')
			->with('ModerationService: rejected failed', ['type' => 'software-review', 'uuid' => 'e1', 'error' => 'locked']);
		$this->logger->expects($this->never())->method('info');

		$this->assertSame(
			['ok' => false, 'reason' => 'could not update entry', 'status' => null],
			$this->service()->reject('e1', ModerationService::MODERATED_TYPE_REVIEW)
		);

	}//end testFailingSaveIsRefused()
}//end class
