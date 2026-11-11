<?php

/**
 * Characterisation tests for ReviewController::submit().
 *
 * Pins the refusal codes (401 for an anonymous caller, 400 otherwise) and
 * the 202 acknowledgement before the responses are built in their own steps.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/catalog-ratings/spec.md#requirement-the-submitting-users-identity-must-be-bound-server-side-and-must-not-be-accepted-from-client-input
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\Stackiq\Controller\ReviewController;
use OCA\Stackiq\Service\ReviewAggregateService;
use OCA\Stackiq\Service\ReviewService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the review submission endpoint.
 */
class ReviewControllerSubmitTest extends TestCase {

	/**
	 * Submit through a review service that answers $result.
	 *
	 * @param array $result The service answer
	 *
	 * @return array{0: int, 1: mixed} Status and body
	 */
	private function submit(array $result): array {
		$reviews = $this->createMock(ReviewService::class);
		$reviews->expects($this->once())->method('submit')->with(['waardering' => 4], 'module', 'm-1')->willReturn($result);
		$controller = new ReviewController($this->createMock(IRequest::class), $reviews, $this->createMock(ReviewAggregateService::class));
		$response   = $controller->submit(review: ['waardering' => 4], subjectType: 'module', subjectId: 'm-1');
		return [$response->getStatus(), $response->getData()];

	}//end submit()

	/**
	 * An anonymous caller is 401, any other refusal 400, both with the reason only.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$this->assertSame([401, ['message' => 'not authenticated']], $this->submit(['ok' => false, 'reason' => 'not authenticated']));
		$this->assertSame([400, ['message' => 'waardering must be 1-5']], $this->submit(['ok' => false, 'reason' => 'waardering must be 1-5']));

	}//end testRefusals()

	/**
	 * An accepted review is 202 with the uuid and status only.
	 *
	 * @return void
	 */
	public function testAccepted(): void {
		$this->assertSame(
			[
				202,
				[
					'ok' => true,
					'uuid' => 'r-1',
					'status' => 'pending',
					'message' => 'Review received and queued for moderation',
				],
			],
			$this->submit(['ok' => true, 'uuid' => 'r-1', 'status' => 'pending', 'object' => ['user' => 'x']])
		);

	}//end testAccepted()
}//end class
