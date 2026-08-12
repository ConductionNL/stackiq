<?php

/**
 * Softwarecatalog PublicationController.
 *
 * Open-data publish / depublish actions for catalog entries
 * (dienst/module/koppeling/organisatie). "Publish" sets the OpenRegister
 * `publicatiedatum` so the schema's `{group:public, match:{publicatiedatum:
 * {$lte:$now}}}` read predicate exposes the entry anonymously (the live OR RBAC
 * publication model — NOT the deprecated/removed `@self.published` predicate and
 * NOT an app-local flag). "Depublish" sets `depublicatiedatum`, withdrawing it.
 *
 * AUTH (ADR-005): authenticated-only (`#[NoAdminRequired]`) AND a per-object
 * ownership guard — admin, or an aanbod-beheerder whose organisation owns the
 * entry (`_organisation` / `aanbieder` match). Without the guard any
 * authenticated user could publish arbitrary entries by uuid (IDOR / OWASP
 * A01:2021), so the guard is enforced server-side on every call.
 *
 * @category  Controller
 * @package   OCA\SoftwareCatalog\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/specs/open-data-publishing/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Controller;

use OCA\SoftwareCatalog\AppInfo\Application;
use OCA\SoftwareCatalog\Service\PublicationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Publish / depublish catalog entries as open data.
 */
class PublicationController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param IUserSession $userSession The user session (auth guard).
	 * @param IGroupManager $groupManager Group membership (role check).
	 * @param IConfig $config Per-user organisation lookup.
	 * @param PublicationService $publicationService The publication service.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly IConfig $config,
		private readonly PublicationService $publicationService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Publish a catalog entry as open data (set publicatiedatum).
	 *
	 * @param string $objectType The catalog object type.
	 * @param string $uuid The entry uuid.
	 * @param string|null $when Optional ISO-8601 publication moment.
	 *
	 * @return JSONResponse `{ok, publicatiedatum}` or an error/403.
	 *
	 * @NoAdminRequired
	 * @spec            openspec/specs/open-data-publishing/spec.md
	 */
	#[NoAdminRequired]
	public function publish(string $objectType, string $uuid, ?string $when = null): JSONResponse {
		$guard = $this->authorizeEntry(objectType: $objectType, uuid: $uuid);
		if ($guard instanceof JSONResponse) {
			return $guard;
		}

		$result = $this->publicationService->publish($objectType, $uuid, $when);
		if ($result['ok'] === false) {
			return new JSONResponse(data: ['message' => $result['reason']], statusCode: Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(data: $result);
	}//end publish()

	/**
	 * Depublish a catalog entry (set depublicatiedatum, clear publicatiedatum).
	 *
	 * @param string $objectType The catalog object type.
	 * @param string $uuid The entry uuid.
	 *
	 * @return JSONResponse `{ok, publicatiedatum}` or an error/403.
	 *
	 * @NoAdminRequired
	 * @spec            openspec/specs/open-data-publishing/spec.md
	 */
	#[NoAdminRequired]
	public function depublish(string $objectType, string $uuid): JSONResponse {
		$guard = $this->authorizeEntry(objectType: $objectType, uuid: $uuid);
		if ($guard instanceof JSONResponse) {
			return $guard;
		}

		$result = $this->publicationService->depublish($objectType, $uuid);
		if ($result['ok'] === false) {
			return new JSONResponse(data: ['message' => $result['reason']], statusCode: Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(data: $result);
	}//end depublish()

	/**
	 * Per-object authorization (IDOR guard). Returns a JSONResponse to short-
	 * circuit on failure, or null when the caller may mutate the entry.
	 *
	 * A caller may publish/depublish an entry only when they are an admin, OR an
	 * aanbod-beheerder whose organisation owns the entry. A peer-sourced
	 * (federated) entry is never publishable locally.
	 *
	 * @param string $objectType The catalog object type.
	 * @param string $uuid The entry uuid.
	 *
	 * @return JSONResponse|null Error response, or null when authorized.
	 *
	 * @spec openspec/specs/open-data-publishing/spec.md
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Complexity 11 (threshold 10). Every branch is
	 * an independent fail-closed IDOR guard — not logged in, entry not found, entry is a
	 * federated peer mirror, caller is not admin, caller's organisation does not own the entry —
	 * each returning its own JSONResponse. Collapsing them would either merge distinct refusal
	 * reasons or hide a guard behind a helper, both of which make the authorization path harder
	 * to audit than the complexity score is worth.
	 *
	 * @SuppressWarnings(PHPMD.NPathComplexity) NPath 384 (threshold 200) is the product of those
	 * same independent guard clauses; the guards are sequential and flat, not deeply nested.
	 */
	private function authorizeEntry(string $objectType, string $uuid): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Not logged in'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		if ($this->publicationService->isPublishableType($objectType) === false) {
			return new JSONResponse(
				data: ['message' => 'Object type is not publishable'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		$entry = $this->publicationService->resolveEntry($objectType, $uuid);
		if ($entry === null) {
			return new JSONResponse(data: ['message' => 'Entry not found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		$data = $entry['data'];

		// Peer-sourced (federated) entries are read-only locally.
		$source = $data['_source'] ?? null;
		if (is_array($source) === true && trim((string)($source['instance'] ?? '')) !== '') {
			return new JSONResponse(
				data: ['message' => 'Peer-sourced entries cannot be published locally'],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		$groupNames = array_map(
			static function (IGroup $group) {
				return $group->getGID();
			},
			$this->groupManager->getUserGroups(user: $user)
		);

		if (in_array('admin', $groupNames, true) === true) {
			return null;
		}

		// Non-admins must be an aanbod-beheerder that owns the entry.
		if (in_array('aanbod-beheerder', $groupNames, true) === false) {
			return $this->forbidden(objectType: $objectType, uuid: $uuid, uid: $user->getUID());
		}

		$orgUuid = (string)$this->config->getUserValue(
			userId: $user->getUID(),
			appName: 'core',
			key: 'organisation'
		);

		$ownerOrg = (string)($data['_organisation'] ?? $data['aanbieder'] ?? '');
		if ($orgUuid === '' || $ownerOrg === '' || $orgUuid !== $ownerOrg) {
			return $this->forbidden(objectType: $objectType, uuid: $uuid, uid: $user->getUID());
		}

		return null;
	}//end authorizeEntry()

	/**
	 * Build a logged 403 forbidden response.
	 *
	 * @param string $objectType The object type.
	 * @param string $uuid The entry uuid.
	 * @param string $uid The caller uid.
	 *
	 * @return JSONResponse The 403 response.
	 */
	private function forbidden(string $objectType, string $uuid, string $uid): JSONResponse {
		$this->logger->warning(
			'PublicationController: publish refused (not entry owner)',
			['objectType' => $objectType, 'uuid' => $uuid, 'uid' => $uid]
		);
		return new JSONResponse(
			data: ['message' => 'You do not have permission to publish this entry'],
			statusCode: Http::STATUS_FORBIDDEN
		);
	}//end forbidden()
}//end class
