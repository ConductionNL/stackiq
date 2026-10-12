<?php

/**
 * The `x-stackiq` marker on a CMDB import pack stored in OpenRegister.
 *
 * When stackiq seeds a pack into OpenRegister's migration-pack store it adds
 * `x-stackiq: {shippedVersion, shippedHash}` to the definition: the version
 * of the shipped pack and the sha256 of its canonical JSON (every object's
 * keys sorted, recursively; list order kept, because the order of the field
 * mappings matters to the engine). A stored definition that, without the
 * marker, still hashes to `shippedHash` is unedited. OpenRegister's validator
 * ignores keys it does not know, so the marker is stored with the pack.
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
 * Adds, reads and compares the seed marker of a stored pack.
 *
 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
 */
class CmdbImportPackMarker {
	/**
	 * The definition key that records what stackiq seeded.
	 */
	public const KEY = 'x-stackiq';

	/**
	 * The hash stackiq records for a shipped pack: sha256 of its canonical JSON.
	 *
	 * @param array<string, mixed> $pack The pack, without the marker.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	public function hash(array $pack): string {
		$json = json_encode($this->canonical(value: $pack), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
		return hash('sha256', (string)$json);
	}//end hash()

	/**
	 * The shipped pack as it is stored: with the marker.
	 *
	 * @param array<string, mixed> $shipped The shipped pack.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	public function seed(array $shipped): array {
		$definition = $this->strip(definition: $shipped);
		$definition[self::KEY] = [
			'shippedVersion' => (string)($shipped['version'] ?? ''),
			'shippedHash' => $this->hash(pack: $definition),
		];

		return $definition;
	}//end seed()

	/**
	 * A stored definition without the marker: the pack the engine runs.
	 *
	 * @param array<string, mixed> $definition The stored definition.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	public function strip(array $definition): array {
		unset($definition[self::KEY]);
		return $definition;
	}//end strip()

	/**
	 * Whether a stored definition differs from what stackiq seeded.
	 *
	 * @param array<string, mixed> $definition The stored definition.
	 *
	 * @return bool|null Null when it carries no marker, so nobody knows what was seeded.
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	public function isEdited(array $definition): ?bool {
		$recorded = $this->recordedHash(definition: $definition);
		if ($recorded === null) {
			return null;
		}

		return $this->hash(pack: $this->strip(definition: $definition)) !== $recorded;
	}//end isEdited()

	/**
	 * Whether a stored pack nobody edited was seeded from another shipped pack than this one.
	 *
	 * @param array<string, mixed> $definition The stored definition.
	 * @param array<string, mixed> $shipped The shipped pack.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	public function isOutdated(array $definition, array $shipped): bool {
		return $this->isEdited(definition: $definition) === false
			&& $this->recordedHash(definition: $definition) !== $this->hash(pack: $shipped);
	}//end isOutdated()

	/**
	 * Whether an edited stored pack was seeded from a shipped pack that has changed since.
	 *
	 * @param array<string, mixed> $definition The stored definition.
	 * @param array<string, mixed> $shipped The shipped pack.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	public function isNewerShipped(array $definition, array $shipped): bool {
		return $this->isEdited(definition: $definition) === true
			&& $this->recordedHash(definition: $definition) !== $this->hash(pack: $shipped);
	}//end isNewerShipped()

	/**
	 * The hash the marker of a stored definition records.
	 *
	 * @param array<string, mixed> $definition The stored definition.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	private function recordedHash(array $definition): ?string {
		$hash = ($definition[self::KEY]['shippedHash'] ?? null);
		if (is_string($hash) === false || $hash === '') {
			return null;
		}

		return $hash;
	}//end recordedHash()

	/**
	 * A value with every object's keys sorted, recursively.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	private function canonical(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		if (array_is_list($value) === false) {
			ksort($value, SORT_STRING);
		}

		return array_map(fn ($item) => $this->canonical(value: $item), $value);
	}//end canonical()
}//end class
