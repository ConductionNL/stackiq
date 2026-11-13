<?php

/**
 * Organisation field re-pointing
 *
 * How an organisation merge re-points one business-level organisation field
 * (a scalar field and an optional array-of-uuid field) of a schema.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/organisation-merge/spec.md#requirement-execute-must-re-point-every-relation-type-while-preserving-every-unrelated-field-on-each-object
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

/**
 * Re-points the organisation fields of one schema's objects from the source to the target organisation.
 *
 * @category Service
 * @package  OCA\Stackiq\Service
 *
 * @spec openspec/specs/organisation-merge/spec.md#requirement-execute-must-re-point-every-relation-type-while-preserving-every-unrelated-field-on-each-object
 */
final class FieldRepoint {

	/**
	 * The OpenRegister object type/schema slug.
	 *
	 * @var string
	 */
	public string $objectType = '';

	/**
	 * The scalar organisation-reference field name.
	 *
	 * @var string
	 */
	public string $field = '';

	/**
	 * An additional array-of-uuid field name, or null.
	 *
	 * @var string|null
	 */
	public ?string $arrayField = null;

	/**
	 * The source organisation UUID.
	 *
	 * @var string
	 */
	public string $source = '';

	/**
	 * The target organisation UUID.
	 *
	 * @var string
	 */
	public string $target = '';

	/**
	 * The object's data with every reference to the source re-pointed to the target.
	 *
	 * @param array<string, mixed> $data The object's data.
	 *
	 * @return array<string, mixed>|null The re-pointed data, or null when the object does not reference the source.
	 *
	 * @spec openspec/specs/organisation-merge/spec.md#requirement-execute-must-re-point-every-relation-type-while-preserving-every-unrelated-field-on-each-object
	 */
	public function apply(array $data): ?array {
		$repointed = $this->withScalarRepointed(data: $data);
		$base      = $repointed ?? $data;

		$newArray = $this->repointedList(data: $base);
		if ($newArray === null) {
			return $repointed;
		}

		$base[$this->arrayField] = $newArray;
		return $base;
	}//end apply()

	/**
	 * The object's data with its scalar field re-pointed, when it names the source.
	 *
	 * @param array<string, mixed> $data The object's data.
	 *
	 * @return array<string, mixed>|null The re-pointed data, or null when the field does not name the source.
	 */
	private function withScalarRepointed(array $data): ?array {
		if (($data[$this->field] ?? null) !== $this->source) {
			return null;
		}

		$data[$this->field] = $this->target;
		return $data;
	}//end withScalarRepointed()

	/**
	 * The array field with every source entry replaced by the target.
	 *
	 * @param array<string, mixed> $data The object's data.
	 *
	 * @return array<int, mixed>|null The new list, or null when there is no such list or it holds no source entry.
	 */
	private function repointedList(array $data): ?array {
		if ($this->arrayField === null || is_array($data[$this->arrayField] ?? null) === false) {
			return null;
		}

		return $this->replaceSource(entries: $data[$this->arrayField]);
	}//end repointedList()

	/**
	 * A list with every source entry replaced by the target.
	 *
	 * @param array<int|string, mixed> $entries The list.
	 *
	 * @return array<int, mixed>|null The new list, or null when it holds no source entry.
	 */
	private function replaceSource(array $entries): ?array {
		if (in_array($this->source, $entries, true) === false) {
			return null;
		}

		$newArray = [];
		foreach ($entries as $entry) {
			$newArray[] = $this->replacementFor(entry: $entry);
		}

		return $newArray;
	}//end replaceSource()

	/**
	 * One list entry, re-pointed when it is the source.
	 *
	 * @param mixed $entry The entry.
	 *
	 * @return mixed The target for the source, the entry otherwise.
	 */
	private function replacementFor(mixed $entry): mixed {
		if ($entry === $this->source) {
			return $this->target;
		}

		return $entry;
	}//end replacementFor()
}//end class
