<?php

/**
 * ArchiMate Import Scope for Stackiq
 *
 * The state one ArchiMate import run shares between its transformation steps.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service\ArchiMate
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/archimate-import/spec.md#requirement-the-system-shall-parse-an-archimate-amef-file-into-catalog-objects-req-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\ArchiMate;

/**
 * Immutable import state: the model, its property definitions, the raw section
 * lookups, the element lookup for views, the section being transformed and the
 * raw properties of the item being flattened. Each with… method returns a copy.
 *
 * @spec openspec/specs/archimate-import/spec.md#requirement-the-system-shall-parse-an-archimate-amef-file-into-catalog-objects-req-001
 */
final class ArchiMateImportScope {

	/**
	 * Raw items per section name, keyed by identifier.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $lookups = [];

	/**
	 * Elements by identifier, for splicing into view nodes.
	 *
	 * @var array<string, mixed>
	 */
	private array $elementsLookup = [];

	/**
	 * The schema type of the section being transformed (element, view, ...).
	 *
	 * @var string
	 */
	private string $section = '';

	/**
	 * The raw property list of the item being flattened.
	 *
	 * @var array<int|string, mixed>
	 */
	private array $properties = [];

	/**
	 * Start a scope for one model.
	 *
	 * @param string               $modelIdentifier The model identifier every object links to.
	 * @param array<string, mixed> $propDefMap      Property definition ref => property name.
	 *
	 * @spec openspec/specs/archimate-import/spec.md#requirement-the-system-shall-parse-an-archimate-amef-file-into-catalog-objects-req-001
	 */
	public function __construct(
		private readonly string $modelIdentifier,
		private readonly array $propDefMap,
	) {
	}//end __construct()

	/**
	 * The model identifier.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/archimate-import/spec.md#requirement-the-system-shall-parse-an-archimate-amef-file-into-catalog-objects-req-001
	 */
	public function modelIdentifier(): string {
		return $this->modelIdentifier;
	}//end modelIdentifier()

	/**
	 * The property definition map.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/archimate-import/spec.md#requirement-the-system-shall-parse-an-archimate-amef-file-into-catalog-objects-req-001
	 */
	public function propDefMap(): array {
		return $this->propDefMap;
	}//end propDefMap()

	/**
	 * The raw items of one section, keyed by identifier.
	 *
	 * @param string $sectionName The section name (elements, relationships, ...).
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/archimate-import/spec.md#requirement-the-system-shall-parse-an-archimate-amef-file-into-catalog-objects-req-001
	 */
	public function lookup(string $sectionName): array {
		return $this->lookups[$sectionName] ?? [];
	}//end lookup()

	/**
	 * The elements lookup for views.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/archimate-import/spec.md#requirement-the-system-shall-parse-an-archimate-amef-file-into-catalog-objects-req-001
	 */
	public function elementsLookup(): array {
		return $this->elementsLookup;
	}//end elementsLookup()

	/**
	 * The schema type of the section being transformed.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/archimate-import/spec.md#requirement-the-system-shall-parse-an-archimate-amef-file-into-catalog-objects-req-001
	 */
	public function section(): string {
		return $this->section;
	}//end section()

	/**
	 * The raw property list of the item being flattened.
	 *
	 * @return array<int|string, mixed>
	 *
	 * @spec openspec/specs/archimate-import/spec.md#requirement-the-system-shall-parse-an-archimate-amef-file-into-catalog-objects-req-001
	 */
	public function properties(): array {
		return $this->properties;
	}//end properties()

	/**
	 * A copy with the raw section lookups.
	 *
	 * @param array<string, array<string, mixed>> $lookups Raw items per section name.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/archimate-import/spec.md#requirement-the-system-shall-parse-an-archimate-amef-file-into-catalog-objects-req-001
	 */
	public function withLookups(array $lookups): self {
		$copy          = clone $this;
		$copy->lookups = $lookups;
		return $copy;
	}//end withLookups()

	/**
	 * A copy with an elements lookup for views.
	 *
	 * @param array<string, mixed> $elementsLookup Elements by identifier.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/archimate-import/spec.md#requirement-the-system-shall-parse-an-archimate-amef-file-into-catalog-objects-req-001
	 */
	public function withElementsLookup(array $elementsLookup): self {
		$copy = clone $this;
		$copy->elementsLookup = $elementsLookup;
		return $copy;
	}//end withElementsLookup()

	/**
	 * A copy for one section.
	 *
	 * @param string $section The schema type of the section.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/archimate-import/spec.md#requirement-the-system-shall-parse-an-archimate-amef-file-into-catalog-objects-req-001
	 */
	public function withSection(string $section): self {
		$copy          = clone $this;
		$copy->section = $section;
		return $copy;
	}//end withSection()

	/**
	 * A copy for flattening one item's raw properties.
	 *
	 * @param array<int|string, mixed> $properties The raw property list.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/archimate-import/spec.md#requirement-the-system-shall-parse-an-archimate-amef-file-into-catalog-objects-req-001
	 */
	public function withProperties(array $properties): self {
		$copy = clone $this;
		$copy->properties = $properties;
		return $copy;
	}//end withProperties()
}//end class
