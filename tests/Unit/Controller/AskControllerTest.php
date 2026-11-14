<?php

/**
 * AskController Test
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/insight-assistant-answers-and-action-trail/specs/assistant-insight/spec.md#requirement-req-ask-001-an-answer-lists-the-catalogue-entries-it-used
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use Exception;
use OCA\Stackiq\Controller\AskController;
use OCA\Stackiq\Exception\AskUnavailableException;
use OCA\Stackiq\Kernel\Contracts\AskServiceInterface;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * 400 without a question, 503 without the assistant, the answer as given,
 * hermiq's own refusal codes passed on, rate limited per user.
 *
 * @covers \OCA\Stackiq\Controller\AskController
 * @uses   \OCA\Stackiq\Exception\AskUnavailableException
 *
 * @spec openspec/changes/insight-assistant-answers-and-action-trail/specs/assistant-insight/spec.md#requirement-req-ask-001-an-answer-lists-the-catalogue-entries-it-used
 */
class AskControllerTest extends TestCase {

	/**
	 * The ask service.
	 *
	 * @var AskServiceInterface&MockObject
	 */
	private $ask;

	/**
	 * The controller for a signed-in user, or for nobody.
	 *
	 * @param bool $signedIn Whether a user is signed in.
	 *
	 * @return AskController
	 */
	private function controller(bool $signedIn = true): AskController {
		$this->ask ??= $this->createMock(AskServiceInterface::class);
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($signedIn === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
		}

		$session->method('getUser')->willReturn($user);

		return new AskController(
			request: $this->createMock(IRequest::class),
			ask: $this->ask,
			userSession: $session,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end controller()

	/**
	 * The answer goes back as the service gives it, asked as the caller.
	 *
	 * @return void
	 */
	public function testTheAnswerIsReturned(): void {
		$this->ask = $this->createMock(AskServiceInterface::class);
		$answer = ['sessionId' => 's-1', 'reply' => 'See [E1].', 'sources' => [['ref' => 'E1']], 'searched' => [['ref' => 'E1']]];
		$this->ask->expects($this->once())->method('ask')->with('Welk contract?', 's-1', 'alice')->willReturn($answer);

		$response = $this->controller()->ask(question: '  Welk contract?  ', sessionId: 's-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame($answer, $response->getData());
	}//end testTheAnswerIsReturned()

	/**
	 * An empty or overlong question is a bad request and nothing is asked.
	 *
	 * @return void
	 */
	public function testAnEmptyOrOverlongQuestionIsRefused(): void {
		$this->ask = $this->createMock(AskServiceInterface::class);
		$this->ask->expects($this->never())->method('ask');

		$this->assertSame(400, $this->controller()->ask(question: '   ')->getStatus());
		$this->assertSame(400, $this->controller()->ask(question: str_repeat('a', 2001))->getStatus());
		$this->assertSame(401, $this->controller(signedIn: false)->ask(question: 'zaak')->getStatus());
	}//end testAnEmptyOrOverlongQuestionIsRefused()

	/**
	 * Without hermiq the answer is 503.
	 *
	 * @return void
	 */
	public function testWithoutTheAssistantTheAnswerIs503(): void {
		$this->ask = $this->createMock(AskServiceInterface::class);
		$this->ask->method('ask')->willThrowException(new AskUnavailableException('The assistant is not available.'));

		$response = $this->controller()->ask(question: 'zaak');

		$this->assertSame(503, $response->getStatus());
		$this->assertSame(['error' => 'The assistant is not available.'], $response->getData());
	}//end testWithoutTheAssistantTheAnswerIs503()

	/**
	 * Hermiq's refusal of a foreign or unknown conversation keeps its code; any other failure is 502.
	 *
	 * @return void
	 */
	public function testAssistantFailuresKeepTheirCodeOrRead502(): void {
		$this->ask = $this->createMock(AskServiceInterface::class);
		$this->ask->method('ask')->willReturnOnConsecutiveCalls(
			$this->throwException(new Exception('Conversation not found', 404)),
			$this->throwException(new Exception('Not your conversation', 403)),
			$this->throwException(new Exception('LLM down', 0))
		);

		$this->assertSame(404, $this->controller()->ask(question: 'zaak', sessionId: 'x')->getStatus());
		$this->assertSame(403, $this->controller()->ask(question: 'zaak', sessionId: 'y')->getStatus());
		$this->assertSame(502, $this->controller()->ask(question: 'zaak')->getStatus());
	}//end testAssistantFailuresKeepTheirCodeOrRead502()

	/**
	 * Any signed-in user may ask, rate limited per user.
	 *
	 * @return void
	 */
	public function testTheRouteIsRateLimitedPerUser(): void {
		$method = new ReflectionMethod(AskController::class, 'ask');
		$this->assertCount(1, $method->getAttributes(NoAdminRequired::class));
		$limits = $method->getAttributes(UserRateLimit::class);
		$this->assertCount(1, $limits);
		$this->assertSame(['limit' => 20, 'period' => 60], $limits[0]->getArguments());
	}//end testTheRouteIsRateLimitedPerUser()
}//end class
