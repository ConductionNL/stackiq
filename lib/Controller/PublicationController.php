<?php

/**
 * Stackiq PublicationController.
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
 * @package   OCA\Stackiq\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/open-data-publishing/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Controller;

use OCA\Stackiq\AppInfo\AppIdentity;
use OCA\Stackiq\Service\PublicationService;
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
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
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
	 * The guards run in this order, each fail-closed with its own response:
	 * not logged in (401); then the entry (entryRefusal): type not publishable
	 * (400), entry not found (404), peer-sourced (403); then the caller
	 * (callerRefusal): admin passes, anyone else must be an aanbod-beheerder of
	 * the owning organisation (403 otherwise).
	 *
	 * @param string $objectType The catalog object type.
	 * @param string $uuid The entry uuid.
	 *
	 * @return JSONResponse|null Error response, or null when authorized.
	 *
	 * @spec openspec/specs/open-data-publishing/spec.md
	 */
	private function authorizeEntry(string $objectType, string $uuid): ?JSONResponse {
		$check = new PublicationAccessCheck(objectType: $objectType, uuid: $uuid);
		$check->user = $this->userSession->getUser();
		if ($check->user === null) {
			return $this->refusal(message: 'Not logged in', statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$refusal = $this->entryRefusal(check: $check);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->callerRefusal(check: $check);
	}//end authorizeEntry()

	/**
	 * Refuse an entry that cannot be published: its type is not publishable,
	 * it does not exist, or it is peer-sourced. Keeps the entry data on the check.
	 *
	 * @param PublicationAccessCheck $check The check.
	 *
	 * @return JSONResponse|null Error response, or null when the entry may be published.
	 *
	 * @spec openspec/specs/open-data-publishing/spec.md
	 */
	private function entryRefusal(PublicationAccessCheck $check): ?JSONResponse {
		if ($this->publicationService->isPublishableType($check->objectType) === false) {
			return $this->refusal(message: 'Object type is not publishable', statusCode: Http::STATUS_BAD_REQUEST);
		}

		$entry = $this->publicationService->resolveEntry($check->objectType, $check->uuid);
		if ($entry === null) {
			return $this->refusal(message: 'Entry not found', statusCode: Http::STATUS_NOT_FOUND);
		}

		$check->data = $entry['data'];

		// Peer-sourced (federated) entries are read-only locally.
		if ($this->isPeerSourced(data: $check->data) === true) {
			return $this->refusal(message: 'Peer-sourced entries cannot be published locally', statusCode: Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end entryRefusal()

	/**
	 * Refuse a caller who is neither an admin nor an aanbod-beheerder of the organisation that owns the entry.
	 *
	 * @param PublicationAccessCheck $check The check, with the caller and the entry data.
	 *
	 * @return JSONResponse|null The logged 403, or null when the caller may publish.
	 *
	 * @spec openspec/specs/open-data-publishing/spec.md
	 */
	private function callerRefusal(PublicationAccessCheck $check): ?JSONResponse {
		$groupNames = array_map(
			static function (IGroup $group) {
				return $group->getGID();
			},
			$this->groupManager->getUserGroups(user: $check->user)
		);

		if (in_array('admin', $groupNames, true) === true) {
			return null;
		}

		// Non-admins must be an aanbod-beheerder that owns the entry.
		if (in_array('aanbod-beheerder', $groupNames, true) === false || $this->ownsEntry(check: $check) === false) {
			return $this->forbidden(check: $check);
		}

		return null;
	}//end callerRefusal()

	/**
	 * Whether the caller's organisation is the entry's owner (`_organisation`, else `provider`); both must be set.
	 *
	 * @param PublicationAccessCheck $check The check, with the caller and the entry data.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/open-data-publishing/spec.md
	 */
	private function ownsEntry(PublicationAccessCheck $check): bool {
		$orgUuid = (string)$this->config->getUserValue(
			userId: $check->user->getUID(),
			appName: 'core',
			key: 'organisation'
		);

		$ownerOrg = (string)($check->data['_organisation'] ?? $check->data['provider'] ?? '');
		return $orgUuid !== '' && $ownerOrg !== '' && $orgUuid === $ownerOrg;
	}//end ownsEntry()

	/**
	 * Whether an entry comes from a peer: its `_source` names a non-blank instance.
	 *
	 * @param array<string, mixed> $data The entry data.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/open-data-publishing/spec.md
	 */
	private function isPeerSourced(array $data): bool {
		$source = $data['_source'] ?? null;
		return is_array($source) === true && trim((string)($source['instance'] ?? '')) !== '';
	}//end isPeerSourced()

	/**
	 * A refusal with a message.
	 *
	 * @param string $message The message.
	 * @param int $statusCode The HTTP status.
	 *
	 * @return JSONResponse
	 */
	private function refusal(string $message, int $statusCode): JSONResponse {
		return new JSONResponse(data: ['message' => $message], statusCode: $statusCode);
	}//end refusal()

	/**
	 * Build a logged 403 forbidden response.
	 *
	 * @param PublicationAccessCheck $check The check, with the entry and the caller.
	 *
	 * @return JSONResponse The 403 response.
	 */
	private function forbidden(PublicationAccessCheck $check): JSONResponse {
		$this->logger->warning(
			'PublicationController: publish refused (not entry owner)',
			['objectType' => $check->objectType, 'uuid' => $check->uuid, 'uid' => $check->user->getUID()]
		);
		return $this->refusal(message: 'You do not have permission to publish this entry', statusCode: Http::STATUS_FORBIDDEN);
	}//end forbidden()
}//end class
