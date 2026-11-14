<?php

/**
 * ArchiMate Model Reader.
 *
 * Reads an ArchiMate exchange file into its categories, each item keyed by
 * identifier with a display name and the fields the round-trip check compares
 * (architecture-round-trip-check, REQ-ART-001). Namespace-agnostic, so a file
 * from Archi, BiZZdesign or stackiq's own export reads the same way. Pure.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/architecture-round-trip-check/specs/archimate-round-trip-check/spec.md#requirement-req-art-001-stackiq-shall-compare-two-exchange-files-by-identifier-per-category
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use DOMDocument;
use DOMElement;
use DOMXPath;
use InvalidArgumentException;

/**
 * Reads the comparable categories of an exchange file.
 *
 * @spec openspec/changes/architecture-round-trip-check/specs/archimate-round-trip-check/spec.md#requirement-req-art-001-stackiq-shall-compare-two-exchange-files-by-identifier-per-category
 */
class ArchiMateModelReader {

	/**
	 * The categories, in report order.
	 */
	public const CATEGORIES = ['elements', 'relationships', 'views', 'viewNodes', 'propertyDefinitions', 'organizations'];

	/**
	 * The XML Schema instance namespace, for `xsi:type`.
	 */
	private const XSI = 'http://www.w3.org/2001/XMLSchema-instance';

	/**
	 * Every category of one file, keyed by identifier, each item as `{name, fields}`.
	 *
	 * @param string $xml   The exchange file.
	 * @param string $label Which file, for the error message.
	 *
	 * @return array<string, array<string, array{name: string, fields: array<string, string>}>>
	 *
	 * @throws InvalidArgumentException When the file is not XML.
	 *
	 * @spec openspec/changes/architecture-round-trip-check/specs/archimate-round-trip-check/spec.md#requirement-req-art-001-stackiq-shall-compare-two-exchange-files-by-identifier-per-category
	 */
	public function read(string $xml, string $label = 'file'): array {
		$document = new DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$loaded   = trim($xml) !== '' && $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE) === true;
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		if ($loaded === false) {
			throw new InvalidArgumentException('The ' . $label . ' is not an ArchiMate exchange file.');
		}

		$xpath       = new DOMXPath($document);
		$definitions = $this->propertyDefinitions(xpath: $xpath);

		return [
			'elements' => $this->elements(xpath: $xpath, definitions: $definitions),
			'relationships' => $this->relationships(xpath: $xpath, definitions: $definitions),
			'views' => $this->views(xpath: $xpath),
			'viewNodes' => $this->viewNodes(xpath: $xpath),
			'propertyDefinitions' => $definitions,
			'organizations' => $this->organizations(xpath: $xpath),
		];
	}//end read()

	/**
	 * The property definitions, keyed by identifier.
	 *
	 * @param DOMXPath $xpath The file.
	 *
	 * @return array<string, array{name: string, fields: array<string, string>}>
	 */
	private function propertyDefinitions(DOMXPath $xpath): array {
		$items = [];
		foreach ($this->nodes(xpath: $xpath, query: "//*[local-name()='propertyDefinitions']/*[local-name()='propertyDefinition']") as $definition) {
			$name = $this->text(xpath: $xpath, parent: $definition, child: 'name');
			$items[$definition->getAttribute('identifier')] = [
				'name' => $name,
				'fields' => ['name' => $name, 'type' => $definition->getAttribute('type')],
			];
		}

		return $items;
	}//end propertyDefinitions()

	/**
	 * The elements, keyed by identifier.
	 *
	 * @param DOMXPath                                                         $xpath       The file.
	 * @param array<string, array{name: string, fields: array<string, string>}> $definitions The property definitions.
	 *
	 * @return array<string, array{name: string, fields: array<string, string>}>
	 */
	private function elements(DOMXPath $xpath, array $definitions): array {
		$items = [];
		foreach ($this->nodes(xpath: $xpath, query: "//*[local-name()='elements']/*[local-name()='element']") as $element) {
			$name   = $this->text(xpath: $xpath, parent: $element, child: 'name');
			$fields = [
				'type' => $element->getAttributeNS(self::XSI, 'type'),
				'name' => $name,
				'documentation' => $this->text(xpath: $xpath, parent: $element, child: 'documentation'),
			];
			$items[$element->getAttribute('identifier')] = [
				'name' => $name,
				'fields' => array_merge($fields, $this->properties(xpath: $xpath, owner: $element, definitions: $definitions)),
			];
		}

		return $items;
	}//end elements()

	/**
	 * The relationships, keyed by identifier.
	 *
	 * @param DOMXPath                                                         $xpath       The file.
	 * @param array<string, array{name: string, fields: array<string, string>}> $definitions The property definitions.
	 *
	 * @return array<string, array{name: string, fields: array<string, string>}>
	 */
	private function relationships(DOMXPath $xpath, array $definitions): array {
		$items = [];
		foreach ($this->nodes(xpath: $xpath, query: "//*[local-name()='relationships']/*[local-name()='relationship']") as $relationship) {
			$name   = $this->text(xpath: $xpath, parent: $relationship, child: 'name');
			$fields = [
				'type' => $relationship->getAttributeNS(self::XSI, 'type'),
				'source' => $relationship->getAttribute('source'),
				'target' => $relationship->getAttribute('target'),
				'name' => $name,
			];
			$items[$relationship->getAttribute('identifier')] = [
				'name' => $name,
				'fields' => array_merge($fields, $this->properties(xpath: $xpath, owner: $relationship, definitions: $definitions)),
			];
		}

		return $items;
	}//end relationships()

	/**
	 * The views, keyed by identifier, with the sets of node and connection references.
	 *
	 * @param DOMXPath $xpath The file.
	 *
	 * @return array<string, array{name: string, fields: array<string, string>}>
	 */
	private function views(DOMXPath $xpath): array {
		$items = [];
		foreach ($this->nodes(xpath: $xpath, query: "//*[local-name()='views']//*[local-name()='view']") as $view) {
			$name     = $this->text(xpath: $xpath, parent: $view, child: 'name');
			$elements = [];
			foreach ($this->nodes(xpath: $xpath, query: ".//*[local-name()='node'][@elementRef]", context: $view) as $node) {
				$elements[] = $node->getAttribute('elementRef');
			}

			$relations = [];
			foreach ($this->nodes(xpath: $xpath, query: ".//*[local-name()='connection'][@relationshipRef]", context: $view) as $connection) {
				$relations[] = $connection->getAttribute('relationshipRef');
			}

			$items[$view->getAttribute('identifier')] = [
				'name' => $name,
				'fields' => [
					'name' => $name,
					'viewpoint' => $view->getAttribute('viewpoint'),
					'nodes' => $this->set(values: $elements),
					'connections' => $this->set(values: $relations),
				],
			];
		}

		return $items;
	}//end views()

	/**
	 * The view nodes, keyed by identifier: per view, element reference, parent, position and size.
	 *
	 * @param DOMXPath $xpath The file.
	 *
	 * @return array<string, array{name: string, fields: array<string, string>}>
	 */
	private function viewNodes(DOMXPath $xpath): array {
		$items = [];
		foreach ($this->nodes(xpath: $xpath, query: "//*[local-name()='views']//*[local-name()='view']//*[local-name()='node']") as $node) {
			$parent = $node->parentNode;
			$parentId = '';
			if ($parent instanceof DOMElement && $parent->localName === 'node') {
				$parentId = $parent->getAttribute('identifier');
			}

			$view = $node->parentNode;
			while ($view instanceof DOMElement && $view->localName !== 'view') {
				$view = $view->parentNode;
			}

			$viewId = '';
			if ($view instanceof DOMElement) {
				$viewId = $view->getAttribute('identifier');
			}

			$name = $this->text(xpath: $xpath, parent: $node, child: 'label');
			if ($name === '') {
				$name = $node->getAttribute('elementRef');
			}

			$items[$node->getAttribute('identifier')] = [
				'name' => $name,
				'fields' => [
					'view' => $viewId,
					'elementRef' => $node->getAttribute('elementRef'),
					'parent' => $parentId,
					'position' => $node->getAttribute('x') . ',' . $node->getAttribute('y'),
					'size' => $node->getAttribute('w') . 'x' . $node->getAttribute('h'),
				],
			];
		}

		return $items;
	}//end viewNodes()

	/**
	 * The organization folders, keyed by their path of labels, each with the items directly under it.
	 *
	 * @param DOMXPath $xpath The file.
	 *
	 * @return array<string, array{name: string, fields: array<string, string>}>
	 */
	private function organizations(DOMXPath $xpath): array {
		$items = [];
		foreach ($this->nodes(xpath: $xpath, query: "//*[local-name()='organizations']//*[local-name()='item'][*[local-name()='label']]") as $folder) {
			$path = [];
			for ($node = $folder; $node instanceof DOMElement && $node->localName === 'item'; $node = $node->parentNode) {
				array_unshift($path, $this->text(xpath: $xpath, parent: $node, child: 'label'));
			}

			$members = [];
			foreach ($this->nodes(xpath: $xpath, query: "./*[local-name()='item'][@identifierRef]", context: $folder) as $member) {
				$members[] = $member->getAttribute('identifierRef');
			}

			$key = implode(' / ', $path);
			$items[$key] = ['name' => $key, 'fields' => ['items' => $this->set(values: $members)]];
		}

		return $items;
	}//end organizations()

	/**
	 * Property values by property definition name: `property:<name>` => value(s).
	 *
	 * @param DOMXPath                                                         $xpath       The file.
	 * @param DOMElement                                                       $owner       The element or relationship.
	 * @param array<string, array{name: string, fields: array<string, string>}> $definitions The property definitions.
	 *
	 * @return array<string, string>
	 */
	private function properties(DOMXPath $xpath, DOMElement $owner, array $definitions): array {
		$values = [];
		foreach ($this->nodes(xpath: $xpath, query: "./*[local-name()='properties']/*[local-name()='property']", context: $owner) as $property) {
			$reference = $property->getAttribute('propertyDefinitionRef');
			$name      = $reference;
			if (isset($definitions[$reference]) === true && $definitions[$reference]['name'] !== '') {
				$name = $definitions[$reference]['name'];
			}

			$values['property:' . $name] = $this->text(xpath: $xpath, parent: $property, child: 'value');
		}

		ksort($values);

		return $values;
	}//end properties()

	/**
	 * The texts of the named children, every language, as one comparable string.
	 *
	 * @param DOMXPath   $xpath  The file.
	 * @param DOMElement $parent The parent.
	 * @param string     $child  The child's local name.
	 *
	 * @return string
	 */
	private function text(DOMXPath $xpath, DOMElement $parent, string $child): string {
		$texts = [];
		foreach ($this->nodes(xpath: $xpath, query: "./*[local-name()='" . $child . "']", context: $parent) as $node) {
			$language = $node->getAttributeNS('http://www.w3.org/XML/1998/namespace', 'lang');
			$text     = trim($node->textContent);
			if ($language !== '') {
				$texts[$language] = $text;
				continue;
			}

			$texts[''] = $text;
		}

		if ($texts === []) {
			return '';
		}

		if (count($texts) === 1) {
			return (string) reset($texts);
		}

		ksort($texts);
		$parts = [];
		foreach ($texts as $language => $text) {
			$parts[] = $language . ':' . $text;
		}

		return implode(' | ', $parts);
	}//end text()

	/**
	 * A set of references as one comparable string: sorted, without doubles.
	 *
	 * @param list<string> $values The references.
	 *
	 * @return string
	 */
	private function set(array $values): string {
		$values = array_values(array_unique($values));
		sort($values);

		return implode(',', $values);
	}//end set()

	/**
	 * The elements an XPath query finds.
	 *
	 * @param DOMXPath        $xpath   The file.
	 * @param string          $query   The query.
	 * @param DOMElement|null $context Where a relative query starts.
	 *
	 * @return list<DOMElement>
	 */
	private function nodes(DOMXPath $xpath, string $query, ?DOMElement $context = null): array {
		$found = $xpath->query($query, $context);
		if ($found === false) {
			return [];
		}

		$elements = [];
		foreach ($found as $node) {
			if ($node instanceof DOMElement) {
				$elements[] = $node;
			}
		}

		return $elements;
	}//end nodes()
}//end class
