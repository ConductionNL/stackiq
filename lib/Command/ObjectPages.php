<?php

/**
 * Reads every object of a schema in pages, for the occ commands.
 *
 * @category Command
 * @package  OCA\Stackiq\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Command;

use Throwable;

/**
 * Pages through OpenRegister's findAll() and resolves each row to its payload.
 *
 * A blind array cast of an ObjectEntity loses every field, so each row is
 * resolved through jsonSerialize()/getObject(); a row that yields no array is
 * returned as an empty payload.
 *
 * @spec openspec/specs/archimate-import/spec.md
 */
class ObjectPages {

	/**
	 * How many rows to read per page. Never "unlimited".
	 */
	private const BATCH_SIZE = 200;

	/**
	 * Build the reader.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 */
	public function __construct(
		private readonly object $objectService,
	) {
	}//end __construct()

	/**
	 * Every row payload of a schema, in explicit limit/offset pages.
	 *
	 * @param string $register The register slug.
	 * @param string $schema   The schema slug.
	 *
	 * @return array<int, array<string, mixed>> Every row payload.
	 *
	 * @spec openspec/specs/archimate-import/spec.md
	 */
	public function all(string $register, string $schema): array {
		return $this->collect(scope: ['register' => $register, 'schema' => $schema, 'system' => false]);
	}//end all()

	/**
	 * Every row payload of a schema, read without RBAC and organisation scoping.
	 *
	 * @param string $register The register slug.
	 * @param string $schema   The schema slug.
	 *
	 * @return array<int, array<string, mixed>> Every row payload.
	 *
	 * @spec openspec/specs/archimate-import/spec.md
	 */
	public function allAsSystem(string $register, string $schema): array {
		return $this->collect(scope: ['register' => $register, 'schema' => $schema, 'system' => true]);
	}//end allAsSystem()

	/**
	 * Every row payload in scope, page by page.
	 *
	 * @param array{register: string, schema: string, system: bool} $scope The scope.
	 *
	 * @return array<int, array<string, mixed>> Every row payload.
	 */
	private function collect(array $scope): array {
		$rows   = [];
		$offset = 0;

		do {
			$page = $this->page(scope: $scope, offset: $offset);
			$size = count($page);
			foreach ($page as $row) {
				$rows[] = $this->payload(row: $row);
			}

			$offset += self::BATCH_SIZE;
		} while ($size === self::BATCH_SIZE);

		return $rows;
	}//end collect()

	/**
	 * Resolve one findAll() row to its schema payload.
	 *
	 * @param mixed $row One result row.
	 *
	 * @return array<string, mixed> The payload, empty when unusable.
	 */
	private function payload(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === false) {
			return [];
		}

		return $this->entityPayload(row: $row);
	}//end payload()

	/**
	 * The first array an entity row gives through jsonSerialize(), then getObject().
	 *
	 * @param object $row One entity row.
	 *
	 * @return array<string, mixed> The payload, empty when neither gives an array.
	 */
	private function entityPayload(object $row): array {
		foreach (['jsonSerialize', 'getObject'] as $method) {
			$out = $this->callPayload(row: $row, method: $method);
			if ($out !== null) {
				return $out;
			}
		}

		return [];
	}//end entityPayload()

	/**
	 * What one payload method of an entity row gives, when it is an array.
	 *
	 * @param object $row One entity row.
	 * @param string $method The payload method.
	 *
	 * @return array<string, mixed>|null The payload, or null when it fails or gives no array.
	 */
	private function callPayload(object $row, string $method): ?array {
		try {
			$out = $row->$method();
		} catch (Throwable $e) {
			return null;
		}

		if (is_array($out) === true) {
			return $out;
		}

		return null;
	}//end callPayload()

	/**
	 * One page of rows.
	 *
	 * @param array{register: string, schema: string, system: bool} $scope  The scope.
	 * @param int                                                   $offset The offset.
	 *
	 * @return array<int, mixed> The rows, empty past the end.
	 */
	private function page(array $scope, int $offset): array {
		$query   = ['limit' => self::BATCH_SIZE, 'offset' => $offset];
		$scoped = $this->objectService->setRegister($scope['register'])->setSchema($scope['schema']);
		$scoping = $scope['system'] === false;
		$page    = $scoped->findAll($query, $scoping, $scoping);

		if (is_array($page) === false) {
			return [];
		}

		return array_values($page);
	}//end page()
}//end class
