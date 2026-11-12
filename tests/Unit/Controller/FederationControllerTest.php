<?php

/**
 * Unit tests for FederationController: status answers the service's status, and the peer and pull endpoints answer the service result or 400 with the reason.
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

use OCA\Stackiq\Controller\FederationController;
use OCA\Stackiq\Service\Federation\FederationService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for FederationController.
 *
 * @covers \OCA\Stackiq\Controller\FederationController
 */
final class FederationControllerTest extends TestCase {

	/**
	 * The federation service.
	 *
	 * @var FederationService&MockObject
	 */
	private FederationService&MockObject $federation;

	/**
	 * The controller under test.
	 *
	 * @return FederationController The controller.
	 */
	private function controller(): FederationController {
		$this->federation = $this->createMock(FederationService::class);
		return new FederationController($this->createMock(IRequest::class), $this->federation);
	}//end controller()

	/**
	 * Status answers the service's status as it is.
	 *
	 * @return void
	 */
	public function testStatusAnswersTheServiceStatus(): void {
		$controller = $this->controller();
		$this->federation->method('getStatus')->willReturn(['peers' => ['https://a.example'], 'lastPull' => null]);

		$response = $controller->status();

		self::assertSame(200, $response->getStatus());
		self::assertSame(['peers' => ['https://a.example'], 'lastPull' => null], $response->getData());
	}//end testStatusAnswersTheServiceStatus()

	/**
	 * Adding a peer passes the url and answers the result.
	 *
	 * @return void
	 */
	public function testAddPeerPassesTheUrl(): void {
		$controller = $this->controller();
		$this->federation->expects($this->once())->method('addPeer')->with('https://b.example')
			->willReturn(['ok' => true, 'peers' => ['https://b.example']]);

		self::assertSame(['ok' => true, 'peers' => ['https://b.example']], $controller->addPeer(peerUrl: 'https://b.example')->getData());
	}//end testAddPeerPassesTheUrl()

	/**
	 * A refused peer change answers 400 with only the reason; the url defaults to empty.
	 *
	 * @return void
	 */
	public function testARefusedPeerChangeIsA400(): void {
		$controller = $this->controller();
		$this->federation->method('addPeer')->with('')->willReturn(['ok' => false, 'reason' => 'A peer URL is required']);
		$this->federation->method('removePeer')->with('https://c.example')->willReturn(['ok' => false, 'reason' => 'Unknown peer']);

		$added = $controller->addPeer();
		self::assertSame(400, $added->getStatus());
		self::assertSame(['message' => 'A peer URL is required'], $added->getData());

		$removed = $controller->removePeer(peerUrl: 'https://c.example');
		self::assertSame(400, $removed->getStatus());
		self::assertSame(['message' => 'Unknown peer'], $removed->getData());
	}//end testARefusedPeerChangeIsA400()

	/**
	 * Removing a peer answers the result.
	 *
	 * @return void
	 */
	public function testRemovePeerAnswersTheResult(): void {
		$controller = $this->controller();
		$this->federation->expects($this->once())->method('removePeer')->with('https://b.example')
			->willReturn(['ok' => true, 'peers' => []]);

		self::assertSame(['ok' => true, 'peers' => []], $controller->removePeer(peerUrl: 'https://b.example')->getData());
	}//end testRemovePeerAnswersTheResult()

	/**
	 * Pull answers the result, or 400 with the reason.
	 *
	 * @return void
	 */
	public function testPullAnswersTheResultOrTheReason(): void {
		$controller = $this->controller();
		$this->federation->expects($this->exactly(2))->method('pullAllPeers')->willReturnOnConsecutiveCalls(
			['ok' => true, 'pulled' => 3],
			['ok' => false, 'reason' => 'No peers configured']
		);

		self::assertSame(['ok' => true, 'pulled' => 3], $controller->pull()->getData());

		$refused = $controller->pull();
		self::assertSame(400, $refused->getStatus());
		self::assertSame(['message' => 'No peers configured'], $refused->getData());
	}//end testPullAnswersTheResultOrTheReason()
}//end class
