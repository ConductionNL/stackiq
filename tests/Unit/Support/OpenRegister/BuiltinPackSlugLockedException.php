<?php

/**
 * A stand-in for the exception of OpenRegister's hardened migration-pack store.
 *
 * CmdbImportPackStore uses the store only when this class exists, as it does
 * on an OpenRegister whose pack writes require a CSRF token and lock a
 * built-in pack's slug. The unit tests load it when they hand out the fake
 * service, so the store counts as available.
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
 * A built-in pack's slug cannot change.
 */
class BuiltinPackSlugLockedException extends InvalidPackDefinitionException {
	/**
	 * Constructor, with OpenRegister's signature.
	 *
	 * @param string $message The reason.
	 */
	public function __construct(string $message) {
		parent::__construct(errors: [$message]);
	}//end __construct()
}//end class
