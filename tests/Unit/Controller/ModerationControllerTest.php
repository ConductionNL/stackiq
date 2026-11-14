<?php

/**
 * Unit tests for ModerationController: each endpoint passes its arguments to the moderation service and answers its result, or 400 with the reason when the service refuses.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\Stackiq\Controller\ModerationController;
use OCA\Stackiq\Service\ModerationService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ModerationController.
 *
 * @covers \OCA\Stackiq\Controller\ModerationController
 */
final class ModerationControllerTest extends TestCase {

	/**
	 * The moderation service.
	 *
	 * @var ModerationService&MockObject
	 */
	private ModerationService&MockObject $moderation;

	/**
	 * The controller under test.
	 *
	 * @return ModerationController The controller.
	 */
	private function controller(): ModerationController {
		$this->moderation = $this->createMock(ModerationService::class);
		return new ModerationController($this->createMock(IRequest::class), $this->moderation);
	}//end controller()

	/**
	 * The pending list defaults to organisations and answers the service result.
	 *
	 * @return void
	 */
	public function testPendingDefaultsToOrganisations(): void {
		$controller = $this->controller();
		$this->moderation->expects($this->once())->method('listPending')->with('organization')
			->willReturn(['ok' => true, 'results' => [['uuid' => 'o-1']]]);

		$response = $controller->pending();

		self::assertSame(200, $response->getStatus());
		self::assertSame(['ok' => true, 'results' => [['uuid' => 'o-1']]], $response->getData());
	}//end testPendingDefaultsToOrganisations()

	/**
	 * A refused pending list answers 400 with only the reason.
	 *
	 * @return void
	 */
	public function testARefusedPendingListIsA400(): void {
		$controller = $this->controller();
		$this->moderation->method('listPending')->with('module')
			->willReturn(['ok' => false, 'reason' => 'Type is not moderated']);

		$response = $controller->pending(type: 'module');

		self::assertSame(400, $response->getStatus());
		self::assertSame(['message' => 'Type is not moderated'], $response->getData());
	}//end testARefusedPendingListIsA400()

	/**
	 * Approve passes the uuid and type and answers the service result.
	 *
	 * @return void
	 */
	public function testApprovePassesTheUuidAndType(): void {
		$controller = $this->controller();
		$this->moderation->expects($this->once())->method('approve')->with('o-1', 'organization')
			->willReturn(['ok' => true, 'status' => 'approved']);

		self::assertSame(['ok' => true, 'status' => 'approved'], $controller->approve(uuid: 'o-1')->getData());
	}//end testApprovePassesTheUuidAndType()

	/**
	 * A refused approval answers 400 with the reason.
	 *
	 * @return void
	 */
	public function testARefusedApprovalIsA400(): void {
		$controller = $this->controller();
		$this->moderation->method('approve')->willReturn(['ok' => false, 'reason' => 'Not pending']);

		$response = $controller->approve(uuid: 'o-1', type: 'organization');

		self::assertSame(400, $response->getStatus());
		self::assertSame(['message' => 'Not pending'], $response->getData());
	}//end testARefusedApprovalIsA400()

	/**
	 * Reject passes the uuid and type, and a refusal answers 400 with the reason.
	 *
	 * @return void
	 */
	public function testRejectPassesTheUuidAndType(): void {
		$controller = $this->controller();
		$this->moderation->expects($this->exactly(2))->method('reject')->with('o-2', 'module')
			->willReturnOnConsecutiveCalls(['ok' => true, 'status' => 'rejected'], ['ok' => false, 'reason' => 'Gone']);

		self::assertSame(['ok' => true, 'status' => 'rejected'], $controller->reject(uuid: 'o-2', type: 'module')->getData());

		$refused = $controller->reject(uuid: 'o-2', type: 'module');
		self::assertSame(400, $refused->getStatus());
		self::assertSame(['message' => 'Gone'], $refused->getData());
	}//end testRejectPassesTheUuidAndType()
}//end class
