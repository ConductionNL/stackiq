<?php

/**
 * Where a loaded CMDB mapping pack came from, as a refusal names it.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service\Cmdb
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

namespace OCA\Stackiq\Service\Cmdb;

/**
 * The label a refusal names a pack by, and the details it carries.
 *
 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
 */
final class CmdbPackOrigin {
	/**
	 * Constructor.
	 *
	 * @param string $label The pack as the message names it: its file, or its OpenRegister slug and id.
	 * @param array<string, mixed> $details The exception details: the OpenRegister pack and its id, for a stored pack.
	 */
	public function __construct(
		public readonly string $label,
		public readonly array $details = [],
	) {
	}//end __construct()

	/**
	 * A pack read from its shipped file.
	 *
	 * @param string $fileName The file name the profile names.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	public static function file(string $fileName): self {
		return new self(label: 'CMDB mapping pack ' . $fileName);
	}//end file()

	/**
	 * A pack read from OpenRegister's pack store.
	 *
	 * @param array<string, mixed> $stored The stored pack as the store answers it.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	public static function stored(array $stored): self {
		return new self(
			label: 'OpenRegister migration pack ' . $stored['slug'] . ' (id ' . $stored['id'] . ')',
			details: ['pack' => $stored['slug'], 'packId' => $stored['id']]
		);
	}//end stored()
}//end class
