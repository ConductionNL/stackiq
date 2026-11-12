<?php

/**
 * Characterisation tests for AangebodenGebruikController::getGebruiksWhereDeelnemers.
 *
 * Pins the status-code mapping, the log calls and the failure body before
 * the endpoint is split into named steps.
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

use OCA\Stackiq\Controller\AangebodenGebruikController;
use OCA\Stackiq\Service\AangebodenGebruikService;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the deelnemers listing endpoint.
 */
final class AangebodenGebruikControllerDeelnemersTest extends TestCase {

	/**
	 * The service double.
	 *
	 * @var AangebodenGebruikService|MockObject
	 */
	private AangebodenGebruikService|MockObject $gebruikSvc;

	/**
	 * Every log call as [level, message, context].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array}>
	 */
	private array $logs = [];

	/**
	 * Build the controller with a recording logger.
	 *
	 * @return AangebodenGebruikController
	 */
	private function makeController(): AangebodenGebruikController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(['limit' => '5']);
		$request->method('getParam')->willReturn(null);

		$this->gebruikSvc = $this->createMock(AangebodenGebruikService::class);
		$logger           = $this->createMock(LoggerInterface::class);
		foreach (['info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logs[] = [$level, $message, $context];
				}
			);
		}

		return new AangebodenGebruikController(
			'stackiq',
			$request,
			$this->createMock(IUserSession::class),
			$this->gebruikSvc,
			$logger,
			$this->createMock(IGroupManager::class)
		);

	}//end makeController()

	/**
	 * Result shapes and the status code each maps to.
	 *
	 * @return array<string, array{0: array, 1: int}>
	 */
	public static function statusCases(): array {
		return [
			'success' => [['success' => true, 'count' => 3, 'organisation' => 'org-1'], 200],
			'not found' => [['success' => false, 'error' => 'Gebruik object not found'], 404],
			'not allowed' => [['success' => false, 'error' => 'Operation not allowed for this user'], 403],
			'other error' => [['success' => false, 'error' => 'Something else'], 500],
			'no error key' => [['success' => false], 500],
		];

	}//end statusCases()

	/**
	 * Each service result maps to its status code, with the result as body,
	 * a started and a completed info log.
	 *
	 * @param array $result   The service result
	 * @param int   $expected The expected status
	 *
	 * @return void
	 *
	 * @dataProvider statusCases
	 */
	public function testStatusCodeAndLogs(array $result, int $expected): void {
		$controller = $this->makeController();
		$this->gebruikSvc->expects($this->once())->method('getGebruiksWhereDeelnemers')->willReturn($result);

		$response = $controller->getGebruiksWhereDeelnemers();

		$this->assertSame($expected, $response->getStatus());
		$this->assertSame($result, $response->getData());
		$this->assertSame(
			[
				[
					'info',
					'API: Getting gebruiks where active org is in deelnemers',
					[
						'endpoint' => '/api/aangeboden-gebruik/deelnemers',
						'method' => 'GET',
						'query_params' => ['limit' => '5'],
					],
				],
				[
					'info',
					'API: Deelnemers gebruiks request completed',
					[
						'success' => $result['success'],
						'gebruiks_count' => $result['count'] ?? 0,
						'organisation' => $result['organisation'] ?? 'unknown',
					],
				],
			],
			$this->logs
		);

	}//end testStatusCodeAndLogs()

	/**
	 * A throwing service yields the 500 failure body and an error log.
	 *
	 * @return void
	 */
	public function testExceptionYieldsFailureBody(): void {
		$controller = $this->makeController();
		$this->gebruikSvc->method('getGebruiksWhereDeelnemers')->willThrowException(new \RuntimeException('kaput'));

		$response = $controller->getGebruiksWhereDeelnemers();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(
			[
				'success' => false,
				'error' => 'Internal server error: kaput',
				'gebruiks' => [],
				'count' => 0,
			],
			$response->getData()
		);
		$this->assertCount(2, $this->logs);
		$this->assertSame('error', $this->logs[1][0]);
		$this->assertSame('API: Failed to get deelnemers gebruiks', $this->logs[1][1]);
		$this->assertSame(['error', 'trace'], array_keys($this->logs[1][2]));
		$this->assertSame('kaput', $this->logs[1][2]['error']);

	}//end testExceptionYieldsFailureBody()
}//end class
