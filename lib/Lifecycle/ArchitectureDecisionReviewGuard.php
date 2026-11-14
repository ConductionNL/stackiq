<?php

/**
 * Architecture Decision Review Guard.
 *
 * The second pair of eyes on an architecture decision. OpenRegister runs this
 * guard for every lifecycle transition of `architectureDecision` that names it
 * in `requires`, for a named transition and for a direct edit of `status`
 * alike, and answers a denial with a 403 and the guard's message.
 *
 * The guard only reads: it never writes the object or anything else.
 *
 * @category  Lifecycle
 * @package   OCA\Stackiq\Lifecycle
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/architecture-decision-register/specs/architecture-decision-register/spec.md#requirement-req-adreg-002-a-decision-shall-be-reviewed-by-a-second-person-through-a-declared-lifecycle
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Lifecycle;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Enforces the review rules of an architecture decision (design D4).
 *
 * | Action          | Allowed when                                              |
 * |-----------------|-----------------------------------------------------------|
 * | submit          | `reviewer` names a Nextcloud user who is not the caller   |
 * | accept, reject  | the caller is the `reviewer`                              |
 * | supersede       | `supersededBy` points at a decision in status accepted    |
 *
 * Any other action passes.
 */
class ArchitectureDecisionReviewGuard implements LifecycleGuardInterface {
	/**
	 * The register that holds architecture decisions.
	 */
	public const REGISTER = 'stackiq';

	/**
	 * The architecture decision schema.
	 */
	public const SCHEMA = 'architectureDecision';

	/**
	 * The status a superseding decision must have.
	 */
	public const ACCEPTED = 'accepted';

	/**
	 * Constructor.
	 *
	 * @param IUserManager $userManager Answers whether the reviewer is a user.
	 * @param ContainerInterface $container Resolves OpenRegister's object service lazily.
	 * @param LoggerInterface $logger Logs a superseding decision that could not be read.
	 */
	public function __construct(
		private readonly IUserManager $userManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Authorise (or deny) a transition of an architecture decision.
	 *
	 * @param array<string, mixed> $object The decision payload OpenRegister passes.
	 * @param string $action The transition action.
	 * @param string $userId The uid of the caller.
	 *
	 * @return GuardResult
	 *
	 * @spec openspec/changes/architecture-decision-register/tasks.md#task-2-review-guard
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		return match ($action) {
			'submit' => $this->checkSubmit(reviewer: self::reviewerOf(object: $object), userId: $userId),
			'accept', 'reject' => $this->checkConclude(reviewer: self::reviewerOf(object: $object), userId: $userId),
			'supersede' => $this->checkSupersede(supersededBy: ($object['supersededBy'] ?? null)),
			default => GuardResult::allow(),
		};
	}//end check()

	/**
	 * Submit needs a reviewer who is a Nextcloud user other than the caller.
	 *
	 * @param string $reviewer The decision's reviewer uid.
	 * @param string $userId The caller.
	 *
	 * @return GuardResult
	 *
	 * @spec openspec/changes/architecture-decision-register/tasks.md#task-2-review-guard
	 */
	private function checkSubmit(string $reviewer, string $userId): GuardResult {
		if ($reviewer === '') {
			return GuardResult::deny(message: 'Name a reviewer before you submit the decision.');
		}

		if ($reviewer === $userId) {
			return GuardResult::deny(message: 'The reviewer must be someone other than you.');
		}

		if ($this->userManager->userExists($reviewer) === false) {
			return GuardResult::deny(message: 'The reviewer is not a user of this Nextcloud.');
		}

		return GuardResult::allow();
	}//end checkSubmit()

	/**
	 * Only the reviewer accepts or rejects.
	 *
	 * @param string $reviewer The decision's reviewer uid.
	 * @param string $userId The caller.
	 *
	 * @return GuardResult
	 *
	 * @spec openspec/changes/architecture-decision-register/tasks.md#task-2-review-guard
	 */
	private function checkConclude(string $reviewer, string $userId): GuardResult {
		if ($reviewer === '' || $reviewer !== $userId) {
			return GuardResult::deny(message: 'Only the reviewer of the decision can accept or reject it.');
		}

		return GuardResult::allow();
	}//end checkConclude()

	/**
	 * Supersede needs a superseding decision that is accepted.
	 *
	 * @param mixed $supersededBy The `supersededBy` value: a uuid, or the related object.
	 *
	 * @return GuardResult
	 *
	 * @spec openspec/changes/architecture-decision-register/tasks.md#task-2-review-guard
	 */
	private function checkSupersede(mixed $supersededBy): GuardResult {
		if ($this->statusOf(decision: $supersededBy) !== self::ACCEPTED) {
			return GuardResult::deny(
				message: 'A decision can only be superseded by an accepted decision. Set "Superseded by" to an accepted decision first.'
			);
		}

		return GuardResult::allow();
	}//end checkSupersede()

	/**
	 * The status of a related decision: read from the embedded object, else fetched by its uuid.
	 *
	 * Fails closed: a decision that cannot be read has no status.
	 *
	 * @param mixed $decision A uuid string, or the related object as an array.
	 *
	 * @return string The status, or '' when unknown.
	 */
	private function statusOf(mixed $decision): string {
		if (is_array($decision) === true && is_string($decision['status'] ?? null) === true) {
			return $decision['status'];
		}

		$uuid = self::uuidOf(decision: $decision);
		if ($uuid === '') {
			return '';
		}

		try {
			$found = $this->objectService()->find(id: $uuid, register: self::REGISTER, schema: self::SCHEMA);
		} catch (Throwable $e) {
			$this->logger->warning(
				'ArchitectureDecisionReviewGuard: superseding decision {uuid} could not be read: {message}',
				['uuid' => $uuid, 'message' => $e->getMessage()]
			);
			return '';
		}

		if ($found === null) {
			return '';
		}

		$status = ($found->getObject()['status'] ?? '');
		if (is_string($status) === false) {
			return '';
		}

		return $status;
	}//end statusOf()

	/**
	 * OpenRegister's object service, resolved when a superseding decision must be read.
	 *
	 * @return ObjectServiceInterface
	 */
	private function objectService(): ObjectServiceInterface {
		return $this->container->get('OCA\OpenRegister\Service\ObjectService');
	}//end objectService()

	/**
	 * The uuid of a related decision.
	 *
	 * @param mixed $decision A uuid string, or the related object as an array.
	 *
	 * @return string
	 */
	private static function uuidOf(mixed $decision): string {
		if (is_string($decision) === true) {
			return trim($decision);
		}

		if (is_array($decision) === false) {
			return '';
		}

		foreach ([($decision['id'] ?? null), ($decision['uuid'] ?? null), ($decision['@self']['id'] ?? null)] as $candidate) {
			if (is_string($candidate) === true && trim($candidate) !== '') {
				return trim($candidate);
			}
		}

		return '';
	}//end uuidOf()

	/**
	 * The reviewer uid of a decision payload.
	 *
	 * @param array<string, mixed> $object The decision payload.
	 *
	 * @return string
	 */
	private static function reviewerOf(array $object): string {
		$reviewer = ($object['reviewer'] ?? '');
		if (is_string($reviewer) === false) {
			return '';
		}

		return trim($reviewer);
	}//end reviewerOf()
}//end class
