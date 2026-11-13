<?php

/**
 * What OrganisatieService creates one organisation entity from.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/organisation-parent-hierarchy-rbac-fix/specs/organisatie-service/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

/**
 * The data mapped for OpenRegister and the uuid of the organisation to create,
 * carried through the steps of OrganisatieService's create-then-link sequence.
 *
 * @spec openspec/changes/organisation-parent-hierarchy-rbac-fix/specs/organisatie-service/spec.md
 */
final class OrganisationCreation {
	/**
	 * Constructor.
	 *
	 * @param array  $mappedData       The organisation data mapped for OpenRegister.
	 * @param string $organizationUuid The organisation UUID.
	 */
	public function __construct(
		public readonly array $mappedData,
		public readonly string $organizationUuid,
	) {
	}//end __construct()
}//end class
