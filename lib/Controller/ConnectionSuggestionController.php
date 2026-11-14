<?php

/**
 * Connection suggestions of a usage.
 *
 * @category  Controller
 * @package   OCA\Stackiq\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/connections-derived-dependencies/specs/derived-connections/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Controller;

use OCA\Stackiq\Service\ConnectionSuggestionService;
use OCA\Stackiq\Service\OpenRegisterLookup;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Lists, accepts and dismisses the derived connections of a usage. Every
 * method first checks that the caller's active organisation is the usage's
 * consumer or a participant; anyone else gets 403 and nothing changes.
 *
 * @spec openspec/changes/connections-derived-dependencies/specs/derived-connections/spec.md#requirement-req-dcn-003-only-the-organisation-that-owns-the-usage-accepts-or-dismisses-its-suggestions
 */
class ConnectionSuggestionController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string                      $appName     The app id.
	 * @param IRequest                    $request     The request.
	 * @param ConnectionSuggestionService $suggestions Derives, accepts and dismisses suggestions.
	 * @param OpenRegisterLookup          $lookup      The caller's active organisation.
	 * @param LoggerInterface             $logger      Logger for failures.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ConnectionSuggestionService $suggestions,
		private readonly OpenRegisterLookup $lookup,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The suggestions for a usage.
	 *
	 * @param string $uuid The usage.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/connections-derived-dependencies/specs/derived-connections/spec.md#requirement-req-dcn-001-stackiq-suggests-the-known-connections-of-an-application-inside-the-organisations-landscape
	 */
	#[NoAdminRequired]
	public function index(string $uuid): JSONResponse {
		return $this->withOwnedUsage(
			uuid: $uuid,
			action: fn (array $usage): array => ['results' => $this->suggestions->suggestFor(usage: $usage)]
		);
	}//end index()

	/**
	 * Accept suggestions by id (body `ids`).
	 *
	 * @param string $uuid The usage.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/connections-derived-dependencies/specs/derived-connections/spec.md#requirement-req-dcn-003-only-the-organisation-that-owns-the-usage-accepts-or-dismisses-its-suggestions
	 */
	#[NoAdminRequired]
	public function accept(string $uuid): JSONResponse {
		return $this->withOwnedUsage(
			uuid: $uuid,
			action: fn (array $usage): array => ['accepted' => $this->suggestions->accept(usage: $usage, ids: $this->ids())]
		);
	}//end accept()

	/**
	 * Dismiss suggestions by id (body `ids`).
	 *
	 * @param string $uuid The usage.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/connections-derived-dependencies/specs/derived-connections/spec.md#requirement-req-dcn-003-only-the-organisation-that-owns-the-usage-accepts-or-dismisses-its-suggestions
	 */
	#[NoAdminRequired]
	public function dismiss(string $uuid): JSONResponse {
		return $this->withOwnedUsage(
			uuid: $uuid,
			action: fn (array $usage): array => ['dismissed' => $this->suggestions->dismiss(usage: $usage, ids: $this->ids())]
		);
	}//end dismiss()

	/**
	 * Run an action on a usage the caller's organisation owns: 404 for an
	 * unknown usage, 403 for another organisation, 500 when the action fails.
	 *
	 * @param string   $uuid   The usage.
	 * @param callable $action Gets the usage, answers the response body.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/connections-derived-dependencies/specs/derived-connections/spec.md#requirement-req-dcn-003-only-the-organisation-that-owns-the-usage-accepts-or-dismisses-its-suggestions
	 */
	private function withOwnedUsage(string $uuid, callable $action): JSONResponse {
		$usage = $this->suggestions->findUsage(usageUuid: $uuid);
		if ($usage === null) {
			return new JSONResponse(['error' => 'Usage not found'], Http::STATUS_NOT_FOUND);
		}

		if ($this->suggestions->mayChange(usage: $usage, organisation: $this->lookup->activeOrganisationUuid()) === false) {
			return new JSONResponse(['error' => 'Only the organisation that owns this usage can do this'], Http::STATUS_FORBIDDEN);
		}

		try {
			return new JSONResponse($action($usage));
		} catch (Throwable $exception) {
			$this->logger->error('ConnectionSuggestionController: ' . $exception->getMessage(), ['usage' => $uuid]);
			return new JSONResponse(['error' => 'Connection suggestions failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end withOwnedUsage()

	/**
	 * The suggestion ids in the request body.
	 *
	 * @return list<string>
	 */
	private function ids(): array {
		$ids = $this->request->getParam('ids', []);
		if (is_array($ids) === false) {
			return [];
		}

		$valid = [];
		foreach ($ids as $id) {
			if (is_scalar($id) === true && (string)$id !== '') {
				$valid[] = (string)$id;
			}
		}

		return $valid;
	}//end ids()
}//end class
