<?php

/**
 * Characterisation tests for IntakeController::submit().
 *
 * Pins the refusal body and the acknowledgement (which never echoes the
 * stored object) before the acknowledgement is built in its own step.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/open-data-publishing/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\Stackiq\Controller\IntakeController;
use OCA\Stackiq\Service\IntakeService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the anonymous intake endpoint.
 */
class IntakeControllerTest extends TestCase {

	/**
	 * Build the controller on an intake service that answers $result.
	 *
	 * @param array $result The service answer
	 *
	 * @return IntakeController
	 */
	private function controller(array $result): IntakeController {
		$intake = $this->createMock(IntakeService::class);
		$intake->expects($this->once())->method('submit')->with(['name' => 'Gemeente X'])->willReturn($result);
		return new IntakeController($this->createMock(IRequest::class), $intake);

	}//end controller()

	/**
	 * A refused registration is 400 with the reason only.
	 *
	 * @return void
	 */
	public function testRefusalIs400WithTheReason(): void {
		$response = $this->controller(['ok' => false, 'reason' => 'name is required'])->submit(['name' => 'Gemeente X']);

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['message' => 'name is required'], $response->getData());

	}//end testRefusalIs400WithTheReason()

	/**
	 * An accepted registration is 202 with the uuid and status, never the stored object.
	 *
	 * @return void
	 */
	public function testAcceptedIs202AndEchoesNothingElse(): void {
		$response = $this->controller(['ok' => true, 'uuid' => 'u-1', 'status' => 'pending', 'object' => ['secret' => 'x']])->submit(['name' => 'Gemeente X']);

		$this->assertSame(202, $response->getStatus());
		$this->assertSame(
			[
				'ok' => true,
				'uuid' => 'u-1',
				'status' => 'pending',
				'message' => 'Registration received and queued for moderation',
			],
			$response->getData()
		);

	}//end testAcceptedIs202AndEchoesNothingElse()
}//end class
