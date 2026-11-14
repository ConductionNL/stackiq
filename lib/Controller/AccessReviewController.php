<?php

/**
 * The access review of an organisation's people.
 *
 * @category  Controller
 * @package   OCA\Stackiq\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-003-a-functional-administrator-shall-review-the-access-of-their-organisations-people
 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-004-a-reviewer-shall-confirm-or-withdraw-a-persons-access-and-the-decision-shall-be-recorded
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Controller;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\RoleGrantSync;
use OCA\Stackiq\Service\RoleGroupMap;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Lists who holds which role in an organisation, and records Keep access and Remove role.
 *
 * Who reviews whom (D5 of the design): a Nextcloud admin reviews any
 * organisation; a member of `functioneel-beheerder` or
 * `organisatie-beheerder` reviews their own active organisation, the user
 * value `core`/`organisation`; everyone else is refused. Each method checks
 * that first, before it reads a single contact person, and refuses when the
 * check itself fails. A reviewer never confirms or changes their own access.
 *
 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-003-a-functional-administrator-shall-review-the-access-of-their-organisations-people
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The reviewer check, the register reads and writes and the role
 * sources each need their own collaborator.
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Every constructor argument is a boundary the controller works across.
 */
class AccessReviewController extends Controller {

	/**
	 * The role groups whose members review their own organisation.
	 *
	 * @var list<string>
	 */
	public const REVIEWER_GROUPS = ['functioneel-beheerder', 'organisatie-beheerder'];

	/**
	 * The most contact persons one organisation's review reads.
	 *
	 * @var int
	 */
	private const LIST_LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param string                        $appName        The app name.
	 * @param IRequest                      $request        The request.
	 * @param IUserSession                  $userSession    The signed-in reviewer.
	 * @param IGroupManager                 $groupManager   Tells admins and reviewer group members.
	 * @param IUserManager                  $userManager    Finds each contact person's account.
	 * @param IConfig                       $config         Reads the reviewer's active organisation.
	 * @param RegisterConfigReaderInterface $registerConfig Finds the contact person register and OpenRegister.
	 * @param RoleGrantSync                 $roleGrantSync  The roles each person derives and from where.
	 * @param RoleGroupMap                  $roleGroupMap   The roles and the review interval.
	 * @param ITimeFactory                  $timeFactory    The current time.
	 * @param IL10N                         $l10n           Translates the refusals.
	 * @param LoggerInterface               $logger         Logs failures.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly IUserManager $userManager,
		private readonly IConfig $config,
		private readonly RegisterConfigReaderInterface $registerConfig,
		private readonly RoleGrantSync $roleGrantSync,
		private readonly RoleGroupMap $roleGroupMap,
		private readonly ITimeFactory $timeFactory,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The people of an organisation who have an account, with their roles, sources and review state.
	 *
	 * Refused with 401 without a user and 403 for anyone but a Nextcloud admin
	 * or a reviewer whose active organisation this is, before any read.
	 *
	 * @param string $organisationUuid The organisation to review.
	 *
	 * @return JSONResponse The rows, or a refusal.
	 *
	 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-003-a-functional-administrator-shall-review-the-access-of-their-organisations-people
	 */
	#[NoAdminRequired]
	public function index(string $organisationUuid): JSONResponse {
		$reviewer = $this->userSession->getUser();
		if ($reviewer === null) {
			return $this->refusal(message: $this->l10n->t('Not authenticated'), status: Http::STATUS_UNAUTHORIZED);
		}

		$organisationUuid = trim($organisationUuid);
		if ($organisationUuid === '' || $this->mayReview(reviewer: $reviewer, organisationUuid: $organisationUuid) === false) {
			return $this->refusal(message: $this->l10n->t('You may not review the access of this organisation.'), status: Http::STATUS_FORBIDDEN);
		}

		try {
			$rows = $this->rows(reviewer: $reviewer, organisationUuid: $organisationUuid);
		} catch (Throwable $e) {
			$this->logger->error('AccessReviewController: listing failed', ['organisation' => $organisationUuid, 'exception' => $e]);
			return $this->refusal(message: $this->l10n->t('The access review could not be read.'), status: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(
			[
				'organisation' => $organisationUuid,
				'reviewIntervalDays' => $this->roleGroupMap->reviewIntervalDays(),
				'results' => $rows,
			]
		);
	}//end index()

	/**
	 * Keep access: record that the reviewer confirmed the person's access now.
	 *
	 * @param string $contactPersonId The contact person under review.
	 *
	 * @return JSONResponse The recorded review, or a refusal.
	 *
	 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-004-a-reviewer-shall-confirm-or-withdraw-a-persons-access-and-the-decision-shall-be-recorded
	 */
	#[NoAdminRequired]
	public function confirm(string $contactPersonId): JSONResponse {
		return $this->decide(
			contactPersonId: $contactPersonId,
			decision: fn (IUser $reviewer, ReviewedContactPerson $subject): JSONResponse => $this->recordConfirmation(reviewer: $reviewer, subject: $subject)
		);
	}//end confirm()

	/**
	 * Remove role: take a role off the person's contact person and out of its role group.
	 *
	 * Only a role written on the contact person can be removed here. A role
	 * that comes from a mapped group or the organisation type is refused with
	 * 409 and the source named, because removing it here would not stick.
	 *
	 * @param string $contactPersonId The contact person under review.
	 *
	 * @return JSONResponse The withdrawal, or a refusal.
	 *
	 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-004-a-reviewer-shall-confirm-or-withdraw-a-persons-access-and-the-decision-shall-be-recorded
	 */
	#[NoAdminRequired]
	public function withdraw(string $contactPersonId): JSONResponse {
		return $this->decide(
			contactPersonId: $contactPersonId,
			decision: fn (IUser $reviewer, ReviewedContactPerson $subject): JSONResponse => $this->recordWithdrawal(reviewer: $reviewer, subject: $subject)
		);
	}//end withdraw()

	/**
	 * Run a review decision after every check that guards it.
	 *
	 * In order: a signed-in user (401); a reviewer at all, before any read
	 * (403); the contact person exists (404); the reviewer may review its
	 * organisation (403); it is not the reviewer's own (403).
	 *
	 * @param string   $contactPersonId The contact person under review.
	 * @param callable $decision        Records the decision for the reviewer and the person.
	 *
	 * @return JSONResponse The decision's answer, or a refusal.
	 */
	private function decide(string $contactPersonId, callable $decision): JSONResponse {
		$reviewer = $this->userSession->getUser();
		if ($reviewer === null) {
			return $this->refusal(message: $this->l10n->t('Not authenticated'), status: Http::STATUS_UNAUTHORIZED);
		}

		if ($this->isReviewer(reviewer: $reviewer) === false) {
			return $this->refusal(message: $this->l10n->t('You may not review this person\'s access.'), status: Http::STATUS_FORBIDDEN);
		}

		try {
			$subject = $this->findContactPerson(contactPersonId: trim($contactPersonId));
			if ($subject === null) {
				return $this->refusal(message: $this->l10n->t('Contact person not found.'), status: Http::STATUS_NOT_FOUND);
			}

			if ($this->mayReview(reviewer: $reviewer, organisationUuid: $subject->organisationUuid) === false) {
				return $this->refusal(message: $this->l10n->t('You may not review this person\'s access.'), status: Http::STATUS_FORBIDDEN);
			}

			if ($subject->isAccountOf(user: $reviewer) === true) {
				return $this->refusal(message: $this->l10n->t('You cannot review your own access.'), status: Http::STATUS_FORBIDDEN);
			}

			return $decision($reviewer, $subject);
		} catch (Throwable $e) {
			$this->logger->error('AccessReviewController: decision failed', ['contactPerson' => $contactPersonId, 'exception' => $e]);
			return $this->refusal(message: $this->l10n->t('The access review could not be saved.'), status: Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try
	}//end decide()

	/**
	 * Record Keep access on the contact person through OpenRegister.
	 *
	 * @param IUser                 $reviewer The reviewer.
	 * @param ReviewedContactPerson $subject  The person confirmed.
	 *
	 * @return JSONResponse The recorded review.
	 */
	private function recordConfirmation(IUser $reviewer, ReviewedContactPerson $subject): JSONResponse {
		$review = $this->reviewStamp(reviewer: $reviewer);
		$this->patchContactPerson(subject: $subject, data: $review);

		return new JSONResponse(
			[
				'id' => $subject->id,
				'accessReviewedAt' => $review['accessReviewedAt'],
				'accessReviewedBy' => $review['accessReviewedBy'],
				'due' => false,
			]
		);
	}//end recordConfirmation()

	/**
	 * Record Remove role: the role leaves the contact person, then its role group.
	 *
	 * @param IUser                 $reviewer The reviewer.
	 * @param ReviewedContactPerson $subject  The person whose role is withdrawn.
	 *
	 * @return JSONResponse The withdrawal, or a refusal naming where the role comes from.
	 */
	private function recordWithdrawal(IUser $reviewer, ReviewedContactPerson $subject): JSONResponse {
		$role = $this->roleGroupMap->canonicalRole(roleOrGroup: (string) $this->request->getParam('role', ''));
		if ($role === null) {
			return $this->refusal(message: $this->l10n->t('Choose a catalogue role to remove.'), status: Http::STATUS_BAD_REQUEST);
		}

		$remaining = $subject->rolesWithout(roleGroupMap: $this->roleGroupMap, role: $role);
		if ($remaining === null) {
			return $this->notOnTheContactPerson(subject: $subject, role: $role);
		}

		$review = $this->reviewStamp(reviewer: $reviewer);
		$this->patchContactPerson(subject: $subject, data: array_merge(['roles' => $remaining], $review));

		$removedFromGroup = false;
		$account = $subject->account(userManager: $this->userManager);
		if ($account !== null) {
			$after = array_merge($subject->data, ['roles' => $remaining]);
			$removedFromGroup = $this->roleGrantSync->revokeIfUnderived(user: $account, role: $role, contactPerson: $after);
		}

		return new JSONResponse(
			[
				'id' => $subject->id,
				'role' => $role,
				'roles' => $remaining,
				'removedFromGroup' => $removedFromGroup,
				'accessReviewedAt' => $review['accessReviewedAt'],
				'accessReviewedBy' => $review['accessReviewedBy'],
			]
		);
	}//end recordWithdrawal()

	/**
	 * The refusal of a withdrawal of a role the contact person does not carry.
	 *
	 * @param ReviewedContactPerson $subject The person.
	 * @param string                $role    The canonical role.
	 *
	 * @return JSONResponse 409 naming the group or organisation type the role comes from, or 404 when they do not hold it.
	 */
	private function notOnTheContactPerson(ReviewedContactPerson $subject, string $role): JSONResponse {
		$account = $subject->account(userManager: $this->userManager);
		$sources = [];
		if ($account !== null) {
			$sources = ($this->roleGrantSync->roleSourcesFor(user: $account, contactPerson: $subject->data)[$role] ?? []);
		}

		foreach ($sources as $source) {
			if ($source['type'] === RoleGrantSync::SOURCE_GROUP) {
				return $this->refusal(
					message: $this->l10n->t('This role comes from the group %s. Change that group instead.', [$source['group']]),
					status: Http::STATUS_CONFLICT,
					extra: ['sources' => $sources]
				);
			}
		}

		foreach ($sources as $source) {
			if ($source['type'] === RoleGrantSync::SOURCE_ORGANISATION_TYPE) {
				return $this->refusal(
					message: $this->l10n->t('This role comes from the organisation type %s. Change the role mapping instead.', [$source['organisationType']]),
					status: Http::STATUS_CONFLICT,
					extra: ['sources' => $sources]
				);
			}
		}

		return $this->refusal(message: $this->l10n->t('This person does not hold this role.'), status: Http::STATUS_NOT_FOUND);
	}//end notOnTheContactPerson()

	/**
	 * Whether the user may review the organisation's access.
	 *
	 * Fails closed: any error while checking refuses.
	 *
	 * @param IUser  $reviewer         The signed-in user.
	 * @param string $organisationUuid The organisation.
	 *
	 * @return bool True for a Nextcloud admin, or a reviewer group member whose active organisation it is.
	 *
	 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-003-a-functional-administrator-shall-review-the-access-of-their-organisations-people
	 */
	private function mayReview(IUser $reviewer, string $organisationUuid): bool {
		try {
			if ($this->groupManager->isAdmin($reviewer->getUID()) === true) {
				return true;
			}

			if ($organisationUuid === '' || $this->inReviewerGroup(reviewer: $reviewer) === false) {
				return false;
			}

			$active = trim((string) $this->config->getUserValue($reviewer->getUID(), 'core', 'organisation', ''));
			return $active !== '' && $active === $organisationUuid;
		} catch (Throwable $e) {
			$this->logger->warning('AccessReviewController: reviewer check failed, refusing', ['exception' => $e]);
			return false;
		}
	}//end mayReview()

	/**
	 * Whether the user may review any organisation at all.
	 *
	 * Fails closed: any error while checking refuses.
	 *
	 * @param IUser $reviewer The signed-in user.
	 *
	 * @return bool True for a Nextcloud admin or a reviewer group member.
	 */
	private function isReviewer(IUser $reviewer): bool {
		try {
			return $this->groupManager->isAdmin($reviewer->getUID()) === true || $this->inReviewerGroup(reviewer: $reviewer) === true;
		} catch (Throwable $e) {
			$this->logger->warning('AccessReviewController: reviewer check failed, refusing', ['exception' => $e]);
			return false;
		}
	}//end isReviewer()

	/**
	 * Whether the user is in `functioneel-beheerder` or `organisatie-beheerder`.
	 *
	 * @param IUser $reviewer The signed-in user.
	 *
	 * @return bool True for a member of a reviewer group.
	 */
	private function inReviewerGroup(IUser $reviewer): bool {
		$groups = $this->groupManager->getUserGroups($reviewer);
		if (is_array($groups) === false) {
			return false;
		}

		$groupIds = array_map(
			static fn (IGroup $group): string => $group->getGID(),
			$groups
		);

		return array_intersect(self::REVIEWER_GROUPS, $groupIds) !== [];
	}//end inReviewerGroup()

	/**
	 * The review rows of an organisation.
	 *
	 * @param IUser  $reviewer         The reviewer, to mark their own row.
	 * @param string $organisationUuid The organisation.
	 *
	 * @return list<array<string, mixed>> One row per contact person with an account.
	 */
	private function rows(IUser $reviewer, string $organisationUuid): array {
		$people = $this->objectService()->searchObjects(
			query: [
				'@self' => ['register' => $this->contactPersonRegister(), 'schema' => $this->contactPersonSchema()],
				'organization' => $organisationUuid,
				'_limit' => self::LIST_LIMIT,
			],
			_rbac: false,
			_multitenancy: false
		);

		if (is_array($people) === false) {
			return [];
		}

		$organisationType = null;
		$rows = [];
		foreach ($people as $entity) {
			$subject = ReviewedContactPerson::fromEntity(entity: $entity);
			// The filter above may not scope: re-check, so no other organisation's person is listed.
			if ($subject === null || $subject->organisationUuid !== $organisationUuid) {
				continue;
			}

			$account = $subject->account(userManager: $this->userManager);
			if ($account === null) {
				continue;
			}

			$organisationType ??= $this->organisationType(organisationUuid: $organisationUuid);
			$rows[] = $this->row(reviewer: $reviewer, subject: $subject, account: $account, organisationType: $organisationType);
		}

		return $rows;
	}//end rows()

	/**
	 * One review row.
	 *
	 * @param IUser                 $reviewer         The reviewer.
	 * @param ReviewedContactPerson $subject          The person.
	 * @param IUser                 $account          The person's account.
	 * @param string                $organisationType The organisation's type.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function row(IUser $reviewer, ReviewedContactPerson $subject, IUser $account, string $organisationType): array {
		$withType = array_merge(
			$subject->data,
			['organisation' => ['id' => $subject->organisationUuid, 'type' => $organisationType]]
		);

		$roles = [];
		foreach ($this->roleGrantSync->roleSourcesFor(user: $account, contactPerson: $withType) as $role => $sources) {
			$roles[] = [
				'role' => $role,
				'group' => $this->roleGroupMap->roleGroup(role: $role),
				'sources' => $sources,
				'removable' => in_array(RoleGrantSync::SOURCE_CONTACT_PERSON, array_column($sources, 'type'), true),
			];
		}

		$lastLogin = $account->getLastLogin();
		$reviewedAt = $subject->reviewedAt();

		return [
			'id' => $subject->id,
			'username' => $account->getUID(),
			'displayName' => $account->getDisplayName(),
			'roles' => $roles,
			'lastLogin' => $this->isoDate(timestamp: $lastLogin),
			'enabled' => $account->isEnabled(),
			'accessReviewedAt' => $reviewedAt,
			'accessReviewedBy' => $subject->reviewedBy(),
			'due' => $this->isDue(reviewedAt: $reviewedAt),
			'self' => $subject->isAccountOf(user: $reviewer),
		];
	}//end row()

	/**
	 * Whether a person's review is due under the configured interval.
	 *
	 * @param string|null $reviewedAt When the access was last reviewed.
	 *
	 * @return bool True when never reviewed, unreadable, or older than the interval.
	 *
	 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-003-a-functional-administrator-shall-review-the-access-of-their-organisations-people
	 */
	private function isDue(?string $reviewedAt): bool {
		if ($reviewedAt === null) {
			return true;
		}

		$reviewed = strtotime($reviewedAt);
		if ($reviewed === false) {
			return true;
		}

		$interval = $this->roleGroupMap->reviewIntervalDays() * 86400;
		return ($reviewed + $interval) < $this->timeFactory->getTime();
	}//end isDue()

	/**
	 * The review fields written for a decision now.
	 *
	 * @param IUser $reviewer The reviewer.
	 *
	 * @return array{accessReviewedAt: string, accessReviewedBy: string}
	 */
	private function reviewStamp(IUser $reviewer): array {
		return [
			'accessReviewedAt' => (string) $this->isoDate(timestamp: $this->timeFactory->getTime()),
			'accessReviewedBy' => $reviewer->getUID(),
		];
	}//end reviewStamp()

	/**
	 * Merge fields onto the contact person through OpenRegister, so its audit trail records the change.
	 *
	 * Register RBAC is lifted for this one write: the reviewer check above
	 * is what authorises it, and the `roles` property only lets
	 * gebruik-beheerder and admin change it, which a functional
	 * administrator withdrawing a role is not.
	 *
	 * @param ReviewedContactPerson $subject The person.
	 * @param array<string, mixed>  $data    The fields to merge.
	 *
	 * @return void
	 */
	private function patchContactPerson(ReviewedContactPerson $subject, array $data): void {
		$this->objectService()->patchObject(
			objectId: $subject->id,
			data: $data,
			register: $this->contactPersonRegister(),
			schema: $this->contactPersonSchema(),
			_rbac: false,
			_multitenancy: false
		);
	}//end patchContactPerson()

	/**
	 * The contact person under review.
	 *
	 * @param string $contactPersonId Its id or UUID.
	 *
	 * @return ReviewedContactPerson|null The person, or null when absent.
	 */
	private function findContactPerson(string $contactPersonId): ?ReviewedContactPerson {
		if ($contactPersonId === '') {
			return null;
		}

		$entity = $this->objectService()->find(
			id: $contactPersonId,
			_extend: [],
			files: false,
			register: $this->contactPersonRegister(),
			schema: $this->contactPersonSchema(),
			_rbac: false,
			_multitenancy: false
		);
		if ($entity === null) {
			return null;
		}

		return ReviewedContactPerson::fromEntity(entity: $entity);
	}//end findContactPerson()

	/**
	 * The type of the organisation.
	 *
	 * @param string $organisationUuid The organisation.
	 *
	 * @return string The stored type, or '' when the organisation is not found.
	 */
	private function organisationType(string $organisationUuid): string {
		$registerId = $this->registerConfig->getRegisterIdForObjectType('organization');
		$schemaId = $this->registerConfig->getSchemaIdForObjectType('organization');
		if ($registerId === null || $schemaId === null) {
			throw new RuntimeException('The organisation register or schema is not configured.');
		}

		$entity = $this->objectService()->find(
			id: $organisationUuid,
			_extend: [],
			files: false,
			register: $registerId,
			schema: $schemaId,
			_rbac: false,
			_multitenancy: false
		);
		$type = ($entity?->getObject()['type'] ?? '');
		if (is_string($type) === false) {
			return '';
		}

		return $type;
	}//end organisationType()

	/**
	 * OpenRegister's object service.
	 *
	 * @return ObjectServiceInterface The service.
	 *
	 * @throws RuntimeException When OpenRegister is not available.
	 */
	private function objectService(): ObjectServiceInterface {
		$objectService = $this->registerConfig->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available.');
		}

		return $objectService;
	}//end objectService()

	/**
	 * The register of contact persons.
	 *
	 * @return int The register id.
	 *
	 * @throws RuntimeException When it is not configured.
	 */
	private function contactPersonRegister(): int {
		$registerId = $this->registerConfig->getRegisterIdForObjectType('contactPerson');
		if ($registerId === null) {
			throw new RuntimeException('The contact person register is not configured.');
		}

		return $registerId;
	}//end contactPersonRegister()

	/**
	 * The schema of contact persons.
	 *
	 * @return int The schema id.
	 *
	 * @throws RuntimeException When it is not configured.
	 */
	private function contactPersonSchema(): int {
		$schemaId = $this->registerConfig->getSchemaIdForObjectType('contactPerson');
		if ($schemaId === null) {
			throw new RuntimeException('The contact person schema is not configured.');
		}

		return $schemaId;
	}//end contactPersonSchema()

	/**
	 * A Unix time as an ISO 8601 date-time in UTC.
	 *
	 * @param int $timestamp The time; 0 or less means never.
	 *
	 * @return string|null The date-time, or null for never.
	 */
	private function isoDate(int $timestamp): ?string {
		if ($timestamp <= 0) {
			return null;
		}

		return (new DateTimeImmutable('@' . $timestamp))->format(DateTimeInterface::ATOM);
	}//end isoDate()

	/**
	 * A refusal with a translated message.
	 *
	 * @param string               $message The message.
	 * @param int                  $status  The HTTP status.
	 * @param array<string, mixed> $extra   More fields for the body.
	 *
	 * @return JSONResponse The refusal.
	 */
	private function refusal(string $message, int $status, array $extra = []): JSONResponse {
		return new JSONResponse(array_merge(['message' => $message], $extra), $status);
	}//end refusal()
}//end class
