<?php

/**
 * The user and organisation one beheerder assignment is about.
 *
 * @category  Handler
 * @package   OCA\Stackiq\Service\Stackiq
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/sc-handlers/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\Stackiq;

/**
 * The username being processed and the organisation it belongs to, carried
 * through the beheerder and manager steps of HierarchyHandler.
 *
 * @category  Handler
 * @package   OCA\Stackiq\Service\Stackiq
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/sc-handlers/spec.md
 */
final class BeheerderAssignment {
	/**
	 * Constructor.
	 *
	 * @param string $username         The username being processed.
	 * @param string $organizationUuid The organisation UUID.
	 */
	public function __construct(
		public readonly string $username,
		public readonly string $organizationUuid,
	) {
	}//end __construct()
}//end class
