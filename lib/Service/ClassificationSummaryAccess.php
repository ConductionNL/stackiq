<?php

/**
 * Who may read how organisations classify an application.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/archive/2026-11-24-security-baseline-classification/specs/baseline-classification/spec.md#requirement-req-bcl-004-municipalities-shall-see-how-organisations-classify-an-application-without-names-and-not-below-three
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use OCA\Stackiq\Kernel\Contracts\OpenRegisterLookupInterface;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * Nextcloud admins, members of `ambtenaar`, and users whose active
 * organisation is a municipality or a collaboration. Anyone else, and a
 * caller without an active organisation, is refused.
 *
 * @spec openspec/changes/archive/2026-11-24-security-baseline-classification/specs/baseline-classification/spec.md#requirement-req-bcl-004-municipalities-shall-see-how-organisations-classify-an-application-without-names-and-not-below-three
 */
class ClassificationSummaryAccess {

	/**
	 * Organisation types whose members may read the summary.
	 *
	 * @var list<string>
	 */
	public const SUMMARY_ORGANISATION_TYPES = ['Municipality', 'Collaboration'];

	/**
	 * Constructor.
	 *
	 * @param UsageClassificationService  $classification The organisation's type.
	 * @param OpenRegisterLookupInterface $lookup         The caller's active organisation.
	 * @param IUserSession                $userSession    The caller.
	 * @param IGroupManager               $groupManager   The caller's groups.
	 *
	 * @spec openspec/changes/archive/2026-11-24-security-baseline-classification/specs/baseline-classification/spec.md#requirement-req-bcl-004-municipalities-shall-see-how-organisations-classify-an-application-without-names-and-not-below-three
	 */
	public function __construct(
		private readonly UsageClassificationService $classification,
		private readonly OpenRegisterLookupInterface $lookup,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * The signed-in caller's user id, or null when nobody is signed in.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/archive/2026-11-24-security-baseline-classification/specs/baseline-classification/spec.md#requirement-req-bcl-004-municipalities-shall-see-how-organisations-classify-an-application-without-names-and-not-below-three
	 */
	public function callerId(): ?string {
		return $this->userSession->getUser()?->getUID();
	}//end callerId()

	/**
	 * Whether the user may read the summary: admin, ambtenaar, or an active municipality or collaboration.
	 *
	 * @param string $userId The caller.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-11-24-security-baseline-classification/specs/baseline-classification/spec.md#requirement-req-bcl-004-municipalities-shall-see-how-organisations-classify-an-application-without-names-and-not-below-three
	 */
	public function mayReadSummary(string $userId): bool {
		if ($this->groupManager->isAdmin($userId) === true || $this->groupManager->isInGroup($userId, 'ambtenaar') === true) {
			return true;
		}

		$organisation = (string) $this->lookup->activeOrganisationUuid();
		if ($organisation === '') {
			return false;
		}

		return in_array($this->classification->organisationTypeOf(organisationId: $organisation), self::SUMMARY_ORGANISATION_TYPES, true);
	}//end mayReadSummary()
}//end class
