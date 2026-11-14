<?php

/**
 * Unit tests for ClaimedUsageController.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\Stackiq\Controller\ClaimedUsageController;
use OCA\Stackiq\Service\ClaimedUsageService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The claimed-usage endpoints map the service's outcome to HTTP, and refuse without a session.
 *
 * @covers \OCA\Stackiq\Controller\ClaimedUsageController
 *
 * @spec openspec/changes/supplier-accepts-claimed-usage/specs/aanbod-listings/spec.md#requirement-a-supplier-answers-the-usages-claimed-of-its-products-req-010
 */
class ClaimedUsageControllerTest extends TestCase {

	private ClaimedUsageService&MockObject $claims;

	private bool $signedIn = true;

	private ClaimedUsageController $controller;

	/**
	 * Set up the controller.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->claims = $this->createMock(ClaimedUsageService::class);
		$user = $this->createMock(IUser::class);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->signedIn === true ? $user : null);

		$this->controller = new ClaimedUsageController(
			request: $this->createMock(IRequest::class),
			claims: $this->claims,
			userSession: $session
		);

	}//end setUp()

	/**
	 * The list answers the service's claims.
	 *
	 * @return void
	 */
	public function testIndexListsTheClaims(): void {
		$listed = ['status' => 'ok', 'organisation' => 'org-centric', 'results' => [['id' => 'usage-1']]];
		$this->claims->method('claimed')->willReturn($listed);

		$response = $this->controller->index();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($listed, $response->getData());

	}//end testIndexListsTheClaims()

	/**
	 * Accept and decline pass their answer and map refusals to 403, 404, 400 and 503.
	 *
	 * @return void
	 */
	public function testAnswersMapToHttp(): void {
		$this->claims->expects($this->exactly(5))->method('answer')->willReturnCallback(
			fn (string $uuid, string $answer): array => match ($uuid) {
				'ok' => ['status' => 'ok', 'usage' => ['providerResponse' => $answer]],
				'theirs' => ['status' => 'forbidden'],
				'gone' => ['status' => 'not_found'],
				'down' => ['status' => 'unavailable'],
				default => ['status' => 'invalid'],
			}
		);

		$accepted = $this->controller->accept(uuid: 'ok');
		self::assertSame(Http::STATUS_OK, $accepted->getStatus());
		self::assertSame('accepted', $accepted->getData()['usage']['providerResponse']);
		self::assertSame('declined', $this->controller->decline(uuid: 'ok')->getData()['usage']['providerResponse']);
		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller->accept(uuid: 'theirs')->getStatus());
		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller->decline(uuid: 'gone')->getStatus());
		self::assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $this->controller->accept(uuid: 'down')->getStatus());

	}//end testAnswersMapToHttp()

	/**
	 * Without a session nothing reaches the service.
	 *
	 * @return void
	 */
	public function testNoSessionIsRefused(): void {
		$this->signedIn = false;
		$this->claims->expects($this->never())->method('answer');
		$this->claims->expects($this->never())->method('claimed');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->index()->getStatus());
		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->accept(uuid: 'ok')->getStatus());
		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->decline(uuid: 'ok')->getStatus());

	}//end testNoSessionIsRefused()

	/**
	 * Every action is open to signed-in users; the service decides who may answer.
	 *
	 * @return void
	 */
	public function testEveryActionDeclaresNoAdminRequired(): void {
		foreach (['index', 'accept', 'decline'] as $method) {
			$attributes = (new ReflectionMethod(ClaimedUsageController::class, $method))->getAttributes(NoAdminRequired::class);
			self::assertCount(1, $attributes, $method);
		}

	}//end testEveryActionDeclaresNoAdminRequired()
}//end class
