<?php

/**
 * Contract of AskService
 *
 * The public surface of AskService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Stackiq\Kernel\Contracts
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

namespace OCA\Stackiq\Kernel\Contracts;

use OCA\Stackiq\Exception\AskUnavailableException;

/**
 * Contract of AskService.
 *
 * @category Kernel
 * @package  OCA\Stackiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AskServiceInterface {

	/**
	 * Answer a question about the catalogue from the entries the caller may read.
	 *
	 * @param string      $question  The question.
	 * @param string|null $sessionId An earlier conversation to continue, or null.
	 * @param string      $userId    The caller.
	 *
	 * @return array{sessionId: string|null, reply: string, sources: list<array<string, string>>, searched: list<array<string, string>>}
	 *
	 * @throws AskUnavailableException When the assistant is not available.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on AskService::ask()
	 */
	public function ask(string $question, ?string $sessionId, string $userId): array;
}//end interface
