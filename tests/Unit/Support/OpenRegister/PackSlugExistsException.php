<?php

/**
 * A stand-in for OpenRegister's exception for a slug that another pack has.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Exception
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

/**
 * Another pack has the slug.
 */
class PackSlugExistsException extends InvalidPackDefinitionException {
	/**
	 * Constructor, with OpenRegister's signature.
	 *
	 * @param string $packSlug The slug that is taken.
	 */
	public function __construct(string $packSlug) {
		parent::__construct(errors: ['A migration pack with id "' . $packSlug . '" already exists']);
	}//end __construct()
}//end class
