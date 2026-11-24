<?php

/**
 * Legacy catalogue row mapper
 *
 * Maps one row of an export from the previous catalogue through the four packs of the `vng-swc` profile.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service\LegacyCatalogue
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\LegacyCatalogue;

use OCA\Stackiq\Service\Cmdb\CmdbImportProfile;
use OCA\Stackiq\Service\Cmdb\CmdbRowNormaliser;

/**
 * Maps a row to its supplier, module, version and usage records and the values the matcher reads; a row whose
 * required mapping fails is skipped.
 *
 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-2
 */
final class LegacyCatalogueRowMapper {

	/**
	 * Constructor.
	 *
	 * @param CmdbImportProfile $profile The loaded `vng-swc` profile.
	 * @param object            $engine  OpenRegister's mapping engine, with `mapRow`.
	 *
	 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-2
	 */
	public function __construct(
		private readonly CmdbImportProfile $profile,
		private readonly object $engine,
	) {
	}//end __construct()

	/**
	 * One row mapped through the four packs: the records, the values to match on and the mapping errors.
	 *
	 * @param array<string, mixed> $row The row as the reader returns it.
	 *
	 * @return array<string, mixed> The `sheet`, `row`, `legacyId`, the `records` per target, the `match` values,
	 *                              the mapping `errors` and whether the row is `skipped`.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) The normaliser is stateless.
	 *
	 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-2
	 */
	public function map(array $row): array {
		$cells = (new CmdbRowNormaliser())->normalise(
			cells: ($row['cells'] ?? []),
			dateColumns: $this->profile->dateColumns(),
			idColumns: $this->profile->idColumns(),
			emptyValues: $this->profile->emptyValues()
		);

		$records = [];
		$errors = [];
		$skipped = false;
		foreach ($this->profile->targets() as $target) {
			$result = $this->engine->mapRow($this->profile->pack(target: $target), $cells, (int)($row['row'] ?? 0));
			$records[$target] = ($result['data'] ?? []);
			foreach (($result['errors'] ?? []) as $error) {
				$errors[] = (string)($error['message'] ?? '');
				$skipped = $skipped || str_starts_with((string)($error['message'] ?? ''), 'Required source field');
			}
		}

		return [
			'sheet' => (string)($row['sheet'] ?? ''),
			'row' => (int)($row['row'] ?? 0),
			'legacyId' => trim((string)($cells[$this->profile->keyColumn()] ?? '')),
			'records' => $records,
			'match' => [
				'supplierKvk' => (string)($records['manufacturer']['registrationNumber'] ?? ''),
				'supplierName' => (string)($records['manufacturer']['name'] ?? ''),
				'product' => (string)($records['module']['name'] ?? ''),
				'version' => (string)($records['version']['version'] ?? ''),
			],
			'errors' => $errors,
			'skipped' => $skipped,
		];
	}//end map()
}//end class
