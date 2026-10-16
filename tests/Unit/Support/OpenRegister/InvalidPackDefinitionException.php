<?php

/**
 * A stand-in for OpenRegister's invalid-definition exception, with the same parent.
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
 * A definition OpenRegister refuses.
 */
class InvalidPackDefinitionException extends MigrationPackRefusedException {
	/**
	 * Constructor, with OpenRegister's signature.
	 *
	 * @param array<int, string> $errors The reasons the definition is refused.
	 */
	public function __construct(private readonly array $errors) {
		parent::__construct(message: 'Invalid migration pack definition: ' . implode('; ', $errors));
	}//end __construct()

	/**
	 * The reasons the definition is refused.
	 *
	 * @return array<int, string>
	 */
	public function getErrors(): array {
		return array_values($this->errors);
	}//end getErrors()
}//end class
