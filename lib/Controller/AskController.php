<?php

/**
 * AskController
 *
 * `POST /api/ask`: a question about the catalogue, answered from the entries
 * the caller may read.
 *
 * @category  Controller
 * @package   OCA\Stackiq\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/insight-assistant-answers-and-action-trail/specs/assistant-insight/spec.md#requirement-req-ask-001-an-answer-lists-the-catalogue-entries-it-used
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Controller;

use OCA\Stackiq\Exception\AskUnavailableException;
use OCA\Stackiq\Kernel\AppIdentity;
use OCA\Stackiq\Kernel\Contracts\AskServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Asks the catalogue; reads only, writes nothing.
 *
 * @spec openspec/changes/insight-assistant-answers-and-action-trail/specs/assistant-insight/spec.md#requirement-req-ask-001-an-answer-lists-the-catalogue-entries-it-used
 */
class AskController extends Controller {

	/**
	 * The longest question taken.
	 */
	private const MAX_QUESTION_LENGTH = 2000;

	/**
	 * Constructor.
	 *
	 * @param IRequest            $request     The request.
	 * @param AskServiceInterface $ask         Searches the catalogue and asks the assistant.
	 * @param IUserSession        $userSession The signed-in user.
	 * @param LoggerInterface     $logger      Reports an assistant failure.
	 *
	 * @spec openspec/changes/insight-assistant-answers-and-action-trail/specs/assistant-insight/spec.md#requirement-req-ask-001-an-answer-lists-the-catalogue-entries-it-used
	 */
	public function __construct(
		IRequest $request,
		private readonly AskServiceInterface $ask,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Answer a question.
	 *
	 * @param string      $question  The question.
	 * @param string|null $sessionId An earlier conversation to continue.
	 *
	 * @return JSONResponse `{sessionId, reply, sources, searched}`; 400 without a question, 503 without the assistant.
	 *
	 * @spec openspec/changes/insight-assistant-answers-and-action-trail/specs/assistant-insight/spec.md#requirement-req-ask-001-an-answer-lists-the-catalogue-entries-it-used
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 20, period: 60)]
	public function ask(string $question = '', ?string $sessionId = null): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$question = trim($question);
		if ($question === '' || mb_strlen($question) > self::MAX_QUESTION_LENGTH) {
			return new JSONResponse(data: ['error' => 'Ask a question of at most 2000 characters.'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		$sessionId = trim((string)$sessionId);
		if ($sessionId === '') {
			$sessionId = null;
		}

		try {
			return new JSONResponse(data: $this->ask->ask(question: $question, sessionId: $sessionId, userId: $user->getUID()));
		} catch (AskUnavailableException $e) {
			return new JSONResponse(data: ['error' => 'The assistant is not available.'], statusCode: Http::STATUS_SERVICE_UNAVAILABLE);
		} catch (Throwable $e) {
			// Hermiq answers a refused or unknown conversation with its own code.
			$status = (int)$e->getCode();
			if (in_array($status, [Http::STATUS_BAD_REQUEST, Http::STATUS_FORBIDDEN, Http::STATUS_NOT_FOUND], true) === false) {
				$status = Http::STATUS_BAD_GATEWAY;
				$this->logger->error('AskController: the assistant could not answer', ['exception' => $e]);
			}

			return new JSONResponse(data: ['error' => 'The assistant could not answer this question.'], statusCode: $status);
		}//end try
	}//end ask()
}//end class
