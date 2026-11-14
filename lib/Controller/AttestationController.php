<?php

/**
 * Confirmation rounds for Stackiq.
 *
 * @category  Controller
 * @package   OCA\Stackiq\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 * @spec      openspec/changes/landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-001-an-information-manager-starts-a-confirmation-round-for-a-scope
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Controller;

use InvalidArgumentException;
use OCA\Stackiq\Service\AttestationRoundAccess;
use OCA\Stackiq\Service\AttestationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Starts a confirmation round, for an admin or a maintainer of the organisation.
 *
 * @spec openspec/changes/landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-001-an-information-manager-starts-a-confirmation-round-for-a-scope
 */
class AttestationController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                 $appName     The app name.
	 * @param IRequest               $request     The request.
	 * @param IUserSession           $userSession The signed-in user.
	 * @param AttestationRoundAccess $access      Who may start a round.
	 * @param AttestationService     $attestation Creates the round and its requests.
	 * @param LoggerInterface        $logger      The logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly AttestationRoundAccess $access,
		private readonly AttestationService $attestation,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Start a round over `scopeSchema` (usage or module) of `organisation`, due `deadline`.
	 *
	 * The caller is checked before anything is read. The answer lists the
	 * entries no request could be sent for.
	 *
	 * @return JSONResponse 201 with the round, its request count and the unassigned entries; 400, 401, 403 or 500.
	 *
	 * @spec openspec/changes/landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-001-an-information-manager-starts-a-confirmation-round-for-a-scope
	 */
	#[NoAdminRequired]
	public function start(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$organisation = (string) $this->request->getParam('organisation', '');
		if ($this->access->mayStart(user: $user, organisationUuid: $organisation) === false) {
			return new JSONResponse(['message' => 'Not authorised to start a round for this organisation'], Http::STATUS_FORBIDDEN);
		}

		try {
			$result = $this->attestation->start(
				scopeSchema: (string) $this->request->getParam('scopeSchema', ''),
				organisationId: $organisation,
				deadline: (string) $this->request->getParam('deadline', ''),
				name: trim((string) $this->request->getParam('name', '')),
				startedBy: $user->getUID()
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (Throwable $e) {
			$this->logger->error('[AttestationController] starting a round failed: ' . $e->getMessage());
			return new JSONResponse(['message' => 'The round could not be started'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($result, Http::STATUS_CREATED);
	}//end start()
}//end class
