<?php

/**
 * Unit tests for ObjectActionResponder: a missing id is refused with 400, a performed action's envelope maps to 200, 404, 403 or 500, the request params are forwarded without the path parameter, and a thrown exception becomes a 500 with the action's empty result.
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

use Exception;
use OCA\Stackiq\Controller\ObjectAction;
use OCA\Stackiq\Controller\ObjectActionResponder;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ObjectActionResponder.
 *
 * @covers \OCA\Stackiq\Controller\ObjectActionResponder
 * @uses   \OCA\Stackiq\Controller\ObjectAction
 */
final class ObjectActionResponderTest extends TestCase {

	/**
	 * The definition of a delete-like action on an aanbod object.
	 *
	 * @var array<string,mixed>
	 */
	private const DEFINITION = [
		'started'        => 'deny started',
		'completed'      => 'deny completed',
		'failed'         => 'deny failed',
		'emptyResult'    => ['deleted' => false],
		'idLabel'        => 'Aanbod UUID',
		'idParam'        => 'uuid',
		'notFoundError'  => 'Aanbod object not found',
		'endpoint'       => '/api/aanbod/%s/deny',
		'method'         => 'DELETE',
		'logIdKey'       => 'aanbod_uuid',
		'reportsDeleted' => true,
	];

	/**
	 * A responder over a request with the given params and logger.
	 *
	 * @param array<string,mixed>  $params The request params.
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return ObjectActionResponder The responder.
	 */
	private function responder(array $params = [], ?LoggerInterface $logger = null): ObjectActionResponder {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);

		return new ObjectActionResponder(($logger ?? $this->createMock(LoggerInterface::class)), $request);
	}//end responder()

	/**
	 * An empty id is refused with 400 and the action is never performed.
	 *
	 * @return void
	 */
	public function testAnEmptyIdIsRefused(): void {
		$response = $this->responder()->respond(
			new ObjectAction(id: '', definition: self::DEFINITION),
			static fn (): array => throw new \LogicException('must not run')
		);

		self::assertSame(400, $response->getStatus());
		self::assertSame(
			['success' => false, 'error' => 'Aanbod UUID is required', 'deleted' => false],
			$response->getData()
		);
	}//end testAnEmptyIdIsRefused()

	/**
	 * A successful action answers 200 with its result, and receives the params minus the path parameter.
	 *
	 * @return void
	 */
	public function testASuccessIsAnsweredWithItsResult(): void {
		$seen     = null;
		$response = $this->responder(['uuid' => 'a-1', 'reason' => 'duplicate', '_route' => 'x'])->respond(
			new ObjectAction(id: 'a-1', definition: self::DEFINITION),
			static function (array $options) use (&$seen): array {
				$seen = $options;
				return ['success' => true, 'deleted' => true];
			}
		);

		self::assertSame(200, $response->getStatus());
		self::assertSame(['success' => true, 'deleted' => true], $response->getData());
		self::assertSame(['reason' => 'duplicate', '_route' => 'x'], $seen);
	}//end testASuccessIsAnsweredWithItsResult()

	/**
	 * Without request params the action receives no options.
	 *
	 * @return void
	 */
	public function testNoParamsForwardNoOptions(): void {
		$seen = null;
		$this->responder()->respond(
			new ObjectAction(id: 'a-1', definition: self::DEFINITION),
			static function (array $options) use (&$seen): array {
				$seen = $options;
				return ['success' => true];
			}
		);

		self::assertSame([], $seen);
	}//end testNoParamsForwardNoOptions()

	/**
	 * A failed envelope maps to 404 for the not-found error, 403 for a refusal and 500 otherwise.
	 *
	 * @return void
	 */
	public function testAFailureMapsToItsStatus(): void {
		$cases = [
			'Aanbod object not found'                => 404,
			'Operation not allowed for this user'    => 403,
			'Refused: Operation not allowed (group)' => 403,
			'Database unavailable'                   => 500,
		];

		foreach ($cases as $error => $status) {
			$response = $this->responder()->respond(
				new ObjectAction(id: 'a-1', definition: self::DEFINITION),
				static fn (): array => ['success' => false, 'error' => $error]
			);
			self::assertSame($status, $response->getStatus(), $error);
			self::assertSame(['success' => false, 'error' => $error], $response->getData());
		}
	}//end testAFailureMapsToItsStatus()

	/**
	 * A thrown exception answers 500 with its message and the action's empty result, and is logged as an error.
	 *
	 * @return void
	 */
	public function testAThrownExceptionIsA500(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'deny failed',
			$this->callback(
				static fn (array $context): bool => $context['aanbod_uuid'] === 'a-1'
					&& $context['error'] === 'connection lost'
					&& is_string($context['trace'])
			)
		);

		$response = $this->responder(logger: $logger)->respond(
			new ObjectAction(id: 'a-1', definition: self::DEFINITION),
			static fn (): array => throw new Exception('connection lost')
		);

		self::assertSame(500, $response->getStatus());
		self::assertSame(
			['success' => false, 'error' => 'Internal server error: connection lost', 'deleted' => false],
			$response->getData()
		);
	}//end testAThrownExceptionIsA500()

	/**
	 * The start and completion are logged with the endpoint, method, id, success, deleted flag and status.
	 *
	 * @return void
	 */
	public function testStartAndCompletionAreLogged(): void {
		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			static function (string $message, array $context) use (&$logged): void {
				$logged[] = [$message, $context];
			}
		);

		$this->responder(logger: $logger)->respond(
			new ObjectAction(id: 'a-1', definition: self::DEFINITION),
			static fn (): array => ['success' => false, 'error' => 'Aanbod object not found']
		);

		self::assertSame(
			[
				['deny started', ['endpoint' => '/api/aanbod/a-1/deny', 'method' => 'DELETE', 'aanbod_uuid' => 'a-1']],
				['deny completed', ['aanbod_uuid' => 'a-1', 'success' => false, 'deleted' => false, 'status_code' => 404]],
			],
			$logged
		);
	}//end testStartAndCompletionAreLogged()

	/**
	 * An action that does not report deletion logs no deleted flag.
	 *
	 * @return void
	 */
	public function testTheDeletedFlagIsLoggedOnlyWhenReported(): void {
		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			static function (string $message, array $context) use (&$logged): void {
				$logged[$message] = $context;
			}
		);

		$this->responder(logger: $logger)->respond(
			new ObjectAction(id: 'a-1', definition: ['reportsDeleted' => false] + self::DEFINITION),
			static fn (): array => ['success' => true, 'deleted' => true]
		);

		self::assertSame(['aanbod_uuid' => 'a-1', 'success' => true, 'status_code' => 200], $logged['deny completed']);
	}//end testTheDeletedFlagIsLoggedOnlyWhenReported()
}//end class
