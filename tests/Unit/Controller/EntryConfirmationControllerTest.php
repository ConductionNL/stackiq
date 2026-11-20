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
	/**
	 * The controller.
	 *
	 * @param EntryConfirmationServiceInterface $service  The service.
	 * @param bool                              $signedIn The signed in.
	 *
	 * @return EntryConfirmationController
	 */
	private function controller(EntryConfirmationServiceInterface $service, bool $signedIn = true): EntryConfirmationController {
		$session = $this->createMock(originalClassName: IUserSession::class);
		$user = null;
		if ($signedIn === true) {
			$user = $this->createMock(originalClassName: IUser::class);
		}

		$session->method('getUser')->willReturn($user);

		return new EntryConfirmationController(
			appName: 'stackiq',
			request: $this->createMock(originalClassName: IRequest::class),
			confirmation: $service,
			userSession: $session
		);
	}//end controller()

	/**
	 * The route confirms the entry.
	 *
	 * @return void
	 */
	public function testTheRouteConfirmsTheEntry(): void {
		$service = $this->createMock(originalClassName: EntryConfirmationServiceInterface::class);
		$service->expects(self::once())->method('confirm')->with('usage', 'u-1')->willReturn(['lastConfirmedAt' => '2026-11-14T09:30:00+00:00']);

		$response = $this->controller(service: $service)->confirm('usage', 'u-1');

		self::assertSame(expected: 200, actual: $response->getStatus());
		self::assertSame(expected: ['lastConfirmedAt' => '2026-11-14T09:30:00+00:00'], actual: $response->getData());
	}//end testTheRouteConfirmsTheEntry()

	/**
	 * The route answers the refusal.
	 *
	 * @return void
	 */
	public function testTheRouteAnswersTheRefusal(): void {
		$service = $this->createMock(originalClassName: EntryConfirmationServiceInterface::class);
		$refusal = new EntryConfirmationRefused(reason: 'forbidden', status: 403, message: 'You may not change this entry.');
		$service->method('confirm')->willThrowException($refusal);

		$response = $this->controller(service: $service)->confirm('module', 'm-1');

		self::assertSame(expected: 403, actual: $response->getStatus());
		self::assertSame(expected: ['error' => 'forbidden', 'message' => 'You may not change this entry.'], actual: $response->getData());
	}//end testTheRouteAnswersTheRefusal()

	/**
	 * Without a session nothing is confirmed.
	 *
	 * @return void
	 */
	public function testWithoutASessionNothingIsConfirmed(): void {
		$service = $this->createMock(originalClassName: EntryConfirmationServiceInterface::class);
		$service->expects(self::never())->method('confirm');

		$response = $this->controller(service: $service, signedIn: false)->confirm(kind: 'module', id: 'm-1');
		self::assertSame(expected: 401, actual: $response->getStatus());
	}//end testWithoutASessionNothingIsConfirmed()
}//end class
