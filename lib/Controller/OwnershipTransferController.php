<?php

/**
 * Stackiq OwnershipTransferController
 *
 * `POST /api/ownership-transfers/plan` and `/execute`: move chosen entries
 * from one organisation to another. Only a Nextcloud administrator, or an
 * organisation administrator (the `maintainer` group) who is a member of both
 * the source and the target organisation, may call either; anyone else gets
 * 403 before anything is read.
 *
 * @category Controller
 * @package  OCA\Stackiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/landscape-move-between-organisations/specs/move-between-organisations/spec.md#requirement-req-mbo-002-only-an-administrator-of-both-organisations-moves-entries
 */

declare(strict_types=1);

namespace OCA\Stackiq\Controller;

use OCA\OpenRegister\Service\OrganisationService;
use OCA\Stackiq\Kernel\AppIdentity;
use OCA\Stackiq\Service\OwnershipTransferService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Plan and execute an ownership transfer.
 *
 * @spec openspec/changes/landscape-move-between-organisations/specs/move-between-organisations/spec.md#requirement-req-mbo-002-only-an-administrator-of-both-organisations-moves-entries
 */
class OwnershipTransferController extends Controller {
	/**
	 * The group of organisation administrators.
	 *
	 * @var string
	 */
	private const MAINTAINER_GROUP = 'maintainer';

	/**
	 * The most entries one transfer names.
	 *
	 * @var integer
	 */
	private const MAX_OBJECTS = 500;

	/**
	 * Constructor.
	 *
	 * @param IRequest                 $request             The request
	 * @param OwnershipTransferService $transfers           Plans and moves
	 * @param IUserSession             $userSession         The caller
	 * @param IGroupManager            $groupManager        Admin and maintainer groups
	 * @param OrganisationService      $organisationService The caller's organisations
	 * @param LoggerInterface          $logger              The logger
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly OwnershipTransferService $transfers,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly OrganisationService $organisationService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The dry run of a transfer.
	 *
	 * @return JSONResponse 200 with the plan, 400, 401 or 403.
	 *
	 * @spec openspec/changes/landscape-move-between-organisations/specs/move-between-organisations/spec.md#requirement-req-mbo-003-the-dry-run-shows-what-moves-and-what-stays-behind
	 */
	#[NoAdminRequired]
	public function plan(): JSONResponse {
		return $this->run(execute: false);
	}//end plan()

	/**
	 * Carry out a transfer.
	 *
	 * @return JSONResponse 200 with the plan and the number moved, 400, 401 or 403.
	 *
	 * @spec openspec/changes/landscape-move-between-organisations/specs/move-between-organisations/spec.md#requirement-req-mbo-001-an-administrator-moves-chosen-entries-to-another-organisation-and-they-keep-their-identity
	 */
	#[NoAdminRequired]
	public function execute(): JSONResponse {
		return $this->run(execute: true);
	}//end execute()

	/**
	 * Check the request and the caller, then plan or execute.
	 *
	 * @param bool $execute Whether to move or only plan
	 *
	 * @return JSONResponse
	 */
	private function run(bool $execute): JSONResponse {
		$objects = $this->request->getParam('objects');
		$source = trim((string) $this->request->getParam('sourceOrganisation', ''));
		$target = trim((string) $this->request->getParam('targetOrganisation', ''));
		if (is_array($objects) === false || $objects === [] || count($objects) > self::MAX_OBJECTS || $source === '' || $target === '') {
			return new JSONResponse(
				['message' => 'Name 1 to ' . self::MAX_OBJECTS . ' objects, a sourceOrganisation and a targetOrganisation'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$refusal = $this->refusal(organisations: [$source, $target]);
		if ($refusal !== null) {
			return $refusal;
		}

		if ($execute === true) {
			return new JSONResponse($this->transfers->execute(objects: array_values($objects), source: $source, target: $target));
		}

		return new JSONResponse($this->transfers->plan(objects: array_values($objects), source: $source, target: $target));
	}//end run()

	/**
	 * Null when the caller may administer every one of these organisations, else the refusal.
	 *
	 * @param list<string> $organisations The organisation uuids
	 *
	 * @return JSONResponse|null
	 */
	private function refusal(array $organisations): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$uid = $user->getUID();
		if ($this->groupManager->isAdmin($uid) === true) {
			return null;
		}

		if ($this->groupManager->isInGroup($uid, self::MAINTAINER_GROUP) === false) {
			return $this->forbidden(uid: $uid, reason: 'not an organisation administrator');
		}

		$memberOf = $this->memberships();
		foreach ($organisations as $organisation) {
			if (in_array($organisation, $memberOf, true) === false) {
				return $this->forbidden(uid: $uid, reason: 'not a member of both organisations');
			}
		}

		return null;
	}//end refusal()

	/**
	 * The uuids of the caller's organisations; empty when they cannot be read.
	 *
	 * @return list<string>
	 */
	private function memberships(): array {
		try {
			$uuids = [];
			foreach ($this->organisationService->getUserOrganisations() as $organisation) {
				$uuids[] = (string) $organisation->getUuid();
			}

			return $uuids;
		} catch (\Throwable $e) {
			$this->logger->error('Stackiq: could not read the caller organisations for a transfer', ['error' => $e->getMessage()]);
			return [];
		}
	}//end memberships()

	/**
	 * The 403 of a refused transfer, logged.
	 *
	 * @param string $uid    The caller
	 * @param string $reason Why
	 *
	 * @return JSONResponse
	 */
	private function forbidden(string $uid, string $reason): JSONResponse {
		$this->logger->warning('Stackiq: ownership transfer refused', ['uid' => $uid, 'reason' => $reason]);
		return new JSONResponse(['message' => 'Only an administrator of both organisations may move entries'], Http::STATUS_FORBIDDEN);
	}//end forbidden()
}//end class
