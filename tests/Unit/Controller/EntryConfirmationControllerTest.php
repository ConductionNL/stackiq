<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\Stackiq\Controller\EntryConfirmationController;
use OCA\Stackiq\Kernel\Contracts\EntryConfirmationServiceInterface;
use OCA\Stackiq\Service\EntryConfirmationRefused;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * REQ-CDQ-002: the Confirm this entry is current route.
 *
 * @covers \OCA\Stackiq\Controller\EntryConfirmationController
 * @uses \OCA\Stackiq\Service\EntryConfirmationRefused
 */
final class EntryConfirmationControllerTest extends TestCase {
	private function controller(EntryConfirmationServiceInterface $service, bool $signedIn = true): EntryConfirmationController {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn ? $this->createMock(IUser::class) : null);

		return new EntryConfirmationController('stackiq', $this->createMock(IRequest::class), $service, $session);
	}//end controller()

	public function testTheRouteConfirmsTheEntry(): void {
		$service = $this->createMock(EntryConfirmationServiceInterface::class);
		$service->expects(self::once())->method('confirm')->with('usage', 'u-1')->willReturn(['lastConfirmedAt' => '2026-11-14T09:30:00+00:00']);

		$response = $this->controller($service)->confirm('usage', 'u-1');

		self::assertSame(200, $response->getStatus());
		self::assertSame(['lastConfirmedAt' => '2026-11-14T09:30:00+00:00'], $response->getData());
	}//end testTheRouteConfirmsTheEntry()

	public function testTheRouteAnswersTheRefusal(): void {
		$service = $this->createMock(EntryConfirmationServiceInterface::class);
		$service->method('confirm')->willThrowException(new EntryConfirmationRefused('forbidden', 403, 'You may not change this entry.'));

		$response = $this->controller($service)->confirm('module', 'm-1');

		self::assertSame(403, $response->getStatus());
		self::assertSame(['error' => 'forbidden', 'message' => 'You may not change this entry.'], $response->getData());
	}//end testTheRouteAnswersTheRefusal()

	public function testWithoutASessionNothingIsConfirmed(): void {
		$service = $this->createMock(EntryConfirmationServiceInterface::class);
		$service->expects(self::never())->method('confirm');

		self::assertSame(401, $this->controller($service, signedIn: false)->confirm('module', 'm-1')->getStatus());
	}//end testWithoutASessionNothingIsConfirmed()
}//end class
