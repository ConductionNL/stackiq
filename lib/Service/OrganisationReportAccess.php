<?php

/**
 * Who may read an organisation's reports.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md#requirement-report-and-csv-export-are-scoped-to-the-requesters-authorised-organisations
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;

/**
 * The organisation check every organisation-scoped report shares.
 *
 * `admin` and `ambtenaar` may read any organisation's report; every other
 * user only their own active organisation's. A report synthesises another
 * organisation's usage and contract data, which `vendor-visibility-rbac`
 * does not grant beyond the caller's own organisation.
 *
 * @spec openspec/specs/vendor-visibility-rbac/spec.md#requirement-gebruik-beheerder-reads-of-gebruik-objects-must-be-scoped-to-the-caller-s-own-organisation-req-003
 */
class OrganisationReportAccess {
	/**
	 * Constructor.
	 *
	 * @param IGroupManager $groupManager The group manager.
	 * @param IConfig $config Reads the user's active organisation.
	 */
	public function __construct(
		private readonly IGroupManager $groupManager,
		private readonly IConfig $config,
	) {
	}//end __construct()

	/**
	 * Whether the user may read reports of the organisation.
	 *
	 * @param IUser $user The authenticated caller.
	 * @param string $organisationUuid The requested organisation.
	 *
	 * @return bool True when the caller may see the organisation's reports.
	 *
	 * @spec openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md#requirement-report-and-csv-export-are-scoped-to-the-requesters-authorised-organisations
	 * @spec openspec/changes/landscape-completeness-score/specs/catalogue-data-quality/spec.md#requirement-req-cdq-003-a-data-quality-page-shows-how-complete-and-current-an-organisation-s-register-is
	 */
	public function mayRead(IUser $user, string $organisationUuid): bool {
		$groupNames = array_map(
			static fn (IGroup $group): string => $group->getGID(),
			$this->groupManager->getUserGroups(user: $user)
		);

		if (in_array('admin', $groupNames, true) === true || in_array('ambtenaar', $groupNames, true) === true) {
			return true;
		}

		$orgUuid = (string)$this->config->getUserValue(
			userId: $user->getUID(),
			appName: 'core',
			key: 'organisation'
		);

		return $orgUuid !== '' && $orgUuid === $organisationUuid;
	}//end mayRead()
}//end class
