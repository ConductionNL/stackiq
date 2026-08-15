<?php

/**
 * ArchiMate Export Service.
 *
 * @category  Service
 * @package   OCA\SoftwareCatalog\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * @spec openspec/specs/method-decomposition/spec.md
 */

/**
 * ArchiMate Export Service for the SoftwareCatalog app
 *
 * @category  Service
 * @package   OCA\SoftwareCatalog\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Service;

use Psr\Log\LoggerInterface;

/**
 * ArchiMate Export Service
 *
 * Provides generic array → XML conversion helpers for the AMEF export flow.
 * Respects the convention that attributes are stored with a leading underscore
 * and namespaced attributes use a `prefix__name` key (double underscore).
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.LongVariable)
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.MissingImport)
 * @SuppressWarnings(PHPMD.UnusedLocalVariable)
 * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 * @SuppressWarnings(PHPMD.Superglobals)
 * @SuppressWarnings(PHPMD.CamelCaseVariableName)
 * @SuppressWarnings(PHPMD.CamelCaseParameterName)
 */
class ArchiMateExportService {
	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger The logger instance.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Convert an associative array into a SimpleXMLElement tree.
	 *
	 * Conventions handled:
	 * - Keys starting with `_` are written as attributes. Namespaced attributes
	 *   use `prefix__name` and will be emitted as `prefix:name` if the namespace
	 *   exists on the element.
	 * - `_value` key is treated as node text content.
	 * - `_text` key is treated as mixed content text.
	 * - Numeric arrays produce repeated child elements with the same tag name.
	 *
	 * @param array $data The data array to convert.
	 * @param \SimpleXMLElement $xml The XML element to populate.
	 *
	 * @return \SimpleXMLElement The populated XML element.
	 * @spec   openspec/specs/archimate-export/spec.md
	 */
	public function arrayToXml(array $data, \SimpleXMLElement $xml): \SimpleXMLElement {
		// First pass: attributes and text content.
		$addedAttributes = [];
		// Track attributes to avoid duplicates.
		foreach ($data as $key => $value) {
			if ($key === '_value' || $key === '_text') {
				$xml[0] = (string)$value;
				continue;
			}

			if (is_string($key) === true && str_starts_with($key, '_') === true && $key !== '_attributes') {
				// Skip legacy _attributes bag, handle individual underscored keys as attributes.
				$attrKey = substr($key, 1);

				// Skip malformed attribute keys that would create invalid XML
				// (e.g., __propertyDefinitionRef -> :propertyDefinitionRef).
				if (str_starts_with($attrKey, '__') === true || $attrKey === '') {
					continue;
				}

				// Fix double underscores to colons (e.g., xml__lang -> xml:lang).
				$attrKey = str_replace('__', ':', $attrKey);

				// Skip if this attribute was already added.
				if (in_array($attrKey, $addedAttributes) === true) {
					continue;
				}

				[$nsPrefix, $local] = $this->splitNamespacedKey(key: $attrKey);

				if ($nsPrefix !== null) {
					// Namespaced attribute, ensure namespace is declared on element.
					$nsUri = $this->getNamespaceUri(xml: $xml, prefix: $nsPrefix);
					if (empty($nsUri) === false) {
						$xml->addAttribute($nsPrefix . ':' . $local, (string)$value, $nsUri);
						$addedAttributes[] = $nsPrefix . ':' . $local;
					} else {
						// Fallback to non-namespaced if namespace not found.
						$xml->addAttribute($attrKey, (string)$value);
						$addedAttributes[] = $attrKey;
					}
				} else {
					$xml->addAttribute($local, (string)$value);
					$addedAttributes[] = $local;
				}
			}//end if
		}//end foreach

		// Handle legacy _attributes array with duplicate filtering.
		if (isset($data['_attributes']) === true && is_array($data['_attributes']) === true) {
			foreach ($data['_attributes'] as $attrKey => $attrValue) {
				// Skip duplicate attributes with colon prefix or already added attributes.
				if (str_starts_with($attrKey, ':') === true || in_array($attrKey, $addedAttributes) === true) {
					continue;
				}

				// Fix double underscores to colons.
				$cleanAttrKey = str_replace('__', ':', $attrKey);

				// Skip if cleaned version was already added.
				if (in_array($cleanAttrKey, $addedAttributes) === true) {
					continue;
				}

				// Handle namespaced attributes (e.g., xml:lang, xsi:type).
				[$nsPrefix, $local] = $this->splitNamespacedKey(key: $cleanAttrKey);
				if ($nsPrefix !== null) {
					$nsUri = $this->getNamespaceUri(xml: $xml, prefix: $nsPrefix);
					if (empty($nsUri) === false) {
						$xml->addAttribute($nsPrefix . ':' . $local, (string)$attrValue, $nsUri);
						$addedAttributes[] = $nsPrefix . ':' . $local;
						continue;
					}
				}

				$xml->addAttribute($cleanAttrKey, (string)$attrValue);
				$addedAttributes[] = $cleanAttrKey;
			}//end foreach
		}//end if

		// Second pass: children.
		foreach ($data as $key => $value) {
			if ($key === '_value' || $key === '_text' || $key === '_attributes') {
				continue;
			}

			if (is_string($key) === true && str_starts_with($key, '_') === true) {
				// Already handled as attribute.
				continue;
			}

			// Skip colon-prefixed duplicate keys (artifacts from XML-to-JSON parsing).
			if (is_string($key) === true && str_starts_with($key, ':') === true) {
				continue;
			}

			// Skip numeric keys - they indicate array items that should be handled differently.
			if (is_int($key) === true) {
				continue;
			}

			// Skip property-like fields that should be handled by specialized property methods.
			// These fields often appear as direct data but should only be in <properties> structure.
			$propertyLikeFields = [
				'availability',
				'integrity',
				'confidentiality',
				'gemmaType',
				'objectId',
				'bivScoreBbn',
				'belangrijksteReden',
			];
			if (in_array($key, $propertyLikeFields, true) === true) {
				continue;
				// Skip these - they should only appear in proper <properties><property> structure.
			}

			// Special handling for elementProperties and other nested structures - filter out problematic fields.
			$nestedKeys = ['elementProperties', 'properties', 'viewNodes'];
			if (in_array($key, $nestedKeys, true) === true && is_array($value) === true) {
				$value = $this->filterProblematicFields(data: $value, fieldsToRemove: $propertyLikeFields);
			}

			// Ensure key is always a string for XML tag names.
			$tagName = (string)$key;

			if (is_array($value) === true) {
				// Handle list of children.
				if ($this->isList(arr: $value) === true) {
					foreach ($value as $item) {
						$child = $xml->addChild($tagName);
						if (is_array($item) === true) {
							$this->arrayToXml(data: $item, xml: $child);
						} else {
							$child[0] = (string)$item;
						}
					}
				} else {
					$child = $xml->addChild($tagName);
					$this->arrayToXml(data: $value, xml: $child);
				}
			} else {
				// Scalar child node.
				$child = $xml->addChild($tagName);
				$child[0] = (string)$value;
			}
		}//end foreach

		return $xml;
	}//end arrayToXml()

	/**
	 * Check if an array is a numeric list (sequential integer keys).
	 *
	 * @param array $arr The array to check.
	 *
	 * @return bool True if the array is a list.
	 */
	private function isList(array $arr): bool {
		return $arr === [] || array_keys($arr) === range(0, count($arr) - 1);
	}//end isList()

	/**
	 * Split a namespaced key into prefix and local parts.
	 *
	 * @param string $key The key to split.
	 *
	 * @return array Array of [prefix, localName].
	 */
	private function splitNamespacedKey(string $key): array {
		// Convert `xsi__type` to ['xsi', 'type'].
		if (str_contains($key, '__') === true) {
			$parts = explode('__', $key, 2);
			if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') {
				return [$parts[0], $parts[1]];
			}
		}

		// Also handle already-converted colon notation (e.g., 'xml:lang').
		if (str_contains($key, ':') === true) {
			$parts = explode(':', $key, 2);
			if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') {
				return [$parts[0], $parts[1]];
			}
		}

		return [null, $key];
	}//end splitNamespacedKey()

	/**
	 * Recursively filter out problematic fields from nested data structures.
	 *
	 * @param array $data The data structure to filter.
	 * @param array $fieldsToRemove List of field names to remove.
	 *
	 * @return array Filtered data structure.
	 */
	private function filterProblematicFields(array $data, array $fieldsToRemove): array {
		$filtered = [];

		foreach ($data as $key => $value) {
			if (is_string($key) === false) {
				continue;
			}

			$shouldSkip = false;

			// Skip exact matches.
			if (in_array($key, $fieldsToRemove, true) === true) {
				$shouldSkip = true;
			}

			// Skip fields that start with problematic patterns (e.g., "availabilityPrimaryReason").
			foreach ($fieldsToRemove as $fieldPattern) {
				if (str_starts_with($key, $fieldPattern) === true) {
					$shouldSkip = true;
					break;
				}
			}

			// Skip fields with invalid XML tag name characters (parentheses, etc.).
			if (preg_match('/[(),<>\/\\\]/', $key) === 1) {
				$shouldSkip = true;
			}

			if ($shouldSkip === true) {
				continue;
			}

			// Recursively filter nested arrays.
			if (is_array($value) === true) {
				$filtered[$key] = $this->filterProblematicFields(data: $value, fieldsToRemove: $fieldsToRemove);
			} else {
				$filtered[$key] = $value;
			}
		}//end foreach

		return $filtered;
	}//end filterProblematicFields()

	/**
	 * Get the namespace URI for a given prefix from an XML element.
	 *
	 * @param \SimpleXMLElement $xml The XML element to inspect.
	 * @param string $prefix The namespace prefix to look up.
	 *
	 * @return string The namespace URI, or empty string if not found.
	 */
	private function getNamespaceUri(\SimpleXMLElement $xml, string $prefix): string {
		// Well-known namespaces — check first to avoid expensive getDocNamespaces calls.
		static $wellKnown = [
			'xml' => 'http://www.w3.org/XML/1998/namespace',
			'xsi' => 'http://www.w3.org/2001/XMLSchema-instance',
		];

		if (isset($wellKnown[$prefix]) === true) {
			return $wellKnown[$prefix];
		}

		$docNamespaces = $xml->getDocNamespaces(true);
		if ($docNamespaces !== false) {
			$namespaces = $docNamespaces;
		} else {
			$namespaces = [];
		}

		return $namespaces[$prefix] ?? '';
	}//end getNamespaceUri()

	/**
	 * Create a clean ArchiMate XML structure with proper namespaces.
	 *
	 * @param array $modelMetadata Model metadata from the database.
	 *
	 * @return \SimpleXMLElement Root XML element ready for population.
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function createCleanArchiMateXml(array $modelMetadata): \SimpleXMLElement {
		$modelName = $modelMetadata['name'] ?? 'ArchiMate Model';
		$modelId = $modelMetadata['identifier'] ?? 'model-' . uniqid();
		$schemaBase = 'http://www.opengroup.org/xsd/archimate/3.0/';
		$schemaXsd = 'http://www.opengroup.org/xsd/archimate/3.1/archimate3_Diagram.xsd';
		$schemaLoc = $schemaBase . ' ' . $schemaXsd;

		$xmlString = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<model xmlns="http://www.opengroup.org/xsd/archimate/3.0/"
       xmlns:xml="http://www.w3.org/XML/1998/namespace"
       xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
       xsi:schemaLocation="{$schemaLoc}"
       identifier="{$modelId}">
</model>
XML;

		$xml = simplexml_load_string($xmlString);
		if ($xml === null) {
			throw new \RuntimeException('Failed to create base ArchiMate XML structure');
		}

		return $xml;
	}//end createCleanArchiMateXml()

	/**
	 * Generic method to add any collection of objects to XML.
	 *
	 * @param \SimpleXMLElement $xml Root XML element.
	 * @param array $objects Array of objects from database.
	 * @param string $folderName Name for the folder.
	 * @param string $folderId ID for the folder.
	 * @param string $folderType Type attribute for the folder.
	 * @param string $childTagName Tag name for child elements.
	 *
	 * @return void
	 * @spec   openspec/specs/archimate-export/spec.md
	 */
	public function addObjectsToXml(
		\SimpleXMLElement $xml,
		array $objects,
		string $folderName,
		string $folderId,
		string $folderType,
		string $childTagName = 'element',
	): void {
		if (empty($objects) === true) {
			return;
		}

		$folder = $this->createFolderNode(
			parent: $xml,
			name: $folderName,
			id: $folderId,
			type: $folderType
		);

		foreach ($objects as $object) {
			$this->addObjectToFolder(folder: $folder, object: $object, childTagName: $childTagName);
		}
	}//end addObjectsToXml()

	/**
	 * Builds a `<folder>` child node with the standard ArchiMate attributes.
	 *
	 * Extracted as part of task 4.3 — the repeated four-line pattern
	 * (`addChild('folder')` + three `addAttribute` calls) appeared in both
	 * {@see addObjectsToXml()} and {@see addViewsToXml()}.
	 *
	 * @param \SimpleXMLElement $parent The parent XML element.
	 * @param string $name Folder name (e.g. "Application").
	 * @param string $id Folder id (e.g. "folder-elements").
	 * @param string $type Folder type (e.g. "application").
	 *
	 * @return \SimpleXMLElement The newly-added folder node.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-4
	 */
	private function createFolderNode(
		\SimpleXMLElement $parent,
		string $name,
		string $id,
		string $type,
	): \SimpleXMLElement {
		$folder = $parent->addChild('folder');
		$folder->addAttribute('name', $name);
		$folder->addAttribute('id', $id);
		$folder->addAttribute('type', $type);

		return $folder;
	}//end createFolderNode()

	/**
	 * Convenience method for elements.
	 *
	 * @param \SimpleXMLElement $xml The root XML element.
	 * @param array $elements The elements to add.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function addElementsToXml(\SimpleXMLElement $xml, array $elements): void {
		$this->addObjectsToXml(
			xml: $xml,
			objects: $elements,
			folderName: 'Application',
			folderId: 'folder-elements',
			folderType: 'application',
			childTagName: 'element'
		);
	}//end addElementsToXml()

	/**
	 * Convenience method for relationships.
	 *
	 * @param \SimpleXMLElement $xml The root XML element.
	 * @param array $relationships The relationships to add.
	 *
	 * @return void
	 * @spec   openspec/specs/archimate-export/spec.md
	 */
	public function addRelationshipsToXml(\SimpleXMLElement $xml, array $relationships): void {
		$this->addObjectsToXml(
			xml: $xml,
			objects: $relationships,
			folderName: 'Relations',
			folderId: 'folder-relations',
			folderType: 'relations',
			childTagName: 'element'
		);
	}//end addRelationshipsToXml()

	/**
	 * Specialized method for views with custom node handling.
	 *
	 * @param \SimpleXMLElement $xml The root XML element.
	 * @param array $views The views to add.
	 *
	 * @return void
	 * @spec   openspec/specs/archimate-export/spec.md
	 */
	public function addViewsToXml(\SimpleXMLElement $xml, array $views): void {
		$this->logger->debug(
			'Adding views to XML',
			[
				'view_count' => count($views),
				'view_keys' => array_keys($views),
			]
		);

		if (empty($views) === true) {
			$this->logger->warning('No views to process');
			return;
		}

		$folder = $this->createFolderNode(
			parent: $xml,
			name: 'Views',
			id: 'folder-views',
			type: 'diagrams'
		);

		foreach ($views as $view) {
			$this->addViewToFolder(folder: $folder, view: $view);
		}
	}//end addViewsToXml()

	/**
	 * Convenience method for organizations.
	 *
	 * @param \SimpleXMLElement $xml The root XML element.
	 * @param array $organizations The organizations to add.
	 *
	 * @return void
	 * @spec   openspec/specs/archimate-export/spec.md
	 */
	public function addOrganizationsToXml(\SimpleXMLElement $xml, array $organizations): void {
		$this->addObjectsToXml(
			xml: $xml,
			objects: $organizations,
			folderName: 'Organizations',
			folderId: 'folder-organizations',
			folderType: 'business',
			childTagName: 'item'
		);
	}//end addOrganizationsToXml()

	/**
	 * Specialized method to add a view to the views folder with custom node handling.
	 *
	 * @param \SimpleXMLElement $folder The folder XML element.
	 * @param array $view The view data to add.
	 *
	 * @return void
	 */
	private function addViewToFolder(\SimpleXMLElement $folder, array $view): void {
		$viewNode = $folder->addChild('view');

		// Extract view data from different formats.
		$viewData = $this->extractViewData(view: $view);

		if ($viewData === null) {
			$this->logger->warning(
				'No valid view data found',
				[
					'view_keys' => array_keys($view),
					'view_structure' => $view,
				]
			);
			return;
		}

		// DEBUG: Check if this is our target view with nodes.
		$targetId = 'id-1c197dc3-71e5-40dc-8f5d-a96e983b41af';
		if (isset($viewData['_identifier']) === true && $viewData['_identifier'] === $targetId) {
			$nodeCountValue = 0;
			if (is_array($viewData['node'] ?? null) === true) {
				$nodeCountValue = count($viewData['node']);
			}

			$nodeSampleValue = 'NO FIRST NODE';
			if (isset($viewData['node'][0]) === true) {
				$nodeSampleValue = $viewData['node'][0];
			}

			$this->logger->debug(
				'Found target view with specific ID',
				[
					'identifier' => $viewData['_identifier'],
					'raw_view' => $view,
					'extracted_view_data' => $viewData,
					'node_analysis' => [
						'has_node' => isset($viewData['node']) === true,
						'node_count' => $nodeCountValue,
						'node_sample' => $nodeSampleValue,
					],
				]
			);
		}//end if

		$nodeCountValue = 0;
		if (is_array($viewData['node'] ?? null) === true) {
			$nodeCountValue = count($viewData['node']);
		}

		$connectionCountValue = 0;
		if (is_array($viewData['connection'] ?? null) === true) {
			$connectionCountValue = count($viewData['connection']);
		}

		$this->logger->debug(
			'Processing view with custom logic',
			[
				'has_node' => isset($viewData['node']) === true,
				'node_count' => $nodeCountValue,
				'has_connection' => isset($viewData['connection']) === true,
				'connection_count' => $connectionCountValue,
			]
		);

		// Process view attributes and basic properties.
		$this->addViewBasicData(viewNode: $viewNode, viewData: $viewData);

		// Process nodes with special handling.
		if (isset($viewData['node']) === true && is_array($viewData['node']) === true) {
			$this->addViewNodes(viewNode: $viewNode, nodes: $viewData['node']);
		}

		// Process connections with special handling.
		if (isset($viewData['connection']) === true && is_array($viewData['connection']) === true) {
			$this->addViewConnections(viewNode: $viewNode, connections: $viewData['connection']);
		}
	}//end addViewToFolder()

	/**
	 * Extract view data from different possible formats.
	 *
	 * @param array $view The view data to extract from.
	 *
	 * @return array|null The extracted view data, or null if not found.
	 */
	private function extractViewData(array $view): ?array {
		// Format 1: OpenRegister object format with properties.xml_data.
		if (isset($view['properties']['xml_data']) === true) {
			$xmlData = $view['properties']['xml_data'];
			if (is_string($view['properties']['xml_data']) === true) {
				$xmlData = json_decode($view['properties']['xml_data'], true) ?? [];
			}

			if (is_array($xmlData) === true) {
				return $xmlData;
			}

			return null;
		}

		// Format 2: Object with xml_data field (from database).
		if (isset($view['xml_data']) === true) {
			$xmlData = $view['xml_data'];
			if (is_string($view['xml_data']) === true) {
				$xmlData = json_decode($view['xml_data'], true) ?? [];
			}

			if (is_array($xmlData) === true) {
				return $xmlData;
			}

			return null;
		}

		// Format 3: Direct XML data (from convertFromOpenRegisterObjects).
		return $view;
	}//end extractViewData()

	/**
	 * Add basic view data (attributes, name, documentation, properties) to view node.
	 *
	 * @param \SimpleXMLElement $viewNode The view XML node.
	 * @param array $viewData The view data array.
	 *
	 * @return void
	 */
	private function addViewBasicData(\SimpleXMLElement $viewNode, array $viewData): void {
		// Add attributes.
		if (isset($viewData['_attributes']) === true) {
			foreach ($viewData['_attributes'] as $attrKey => $attrValue) {
				if (str_starts_with($attrKey, ':') === true) {
					continue;
					// Skip duplicate attributes with colon prefix.
				}

				$viewNode->addAttribute($attrKey, (string)$attrValue);
			}
		}

		// Add identifier directly if present.
		if (isset($viewData['_identifier']) === true || isset($viewData['identifier']) === true) {
			$identifier = $viewData['_identifier'] ?? $viewData['identifier'];
			$viewNode->addAttribute('identifier', (string)$identifier);
		}

		// Add xsi:type if present.
		foreach (['_xsi__type', 'xsi:type', '_xsi:type'] as $typeKey) {
			if (isset($viewData[$typeKey]) === true) {
				$viewNode->addAttribute('xsi:type', (string)$viewData[$typeKey]);
				break;
			}
		}

		// Add name, documentation, and properties using the generic arrayToXml method.
		// but exclude node and connection arrays to handle them separately.
		$basicData = array_diff_key($viewData, ['node' => true, 'connection' => true]);
		$this->arrayToXml(data: $basicData, xml: $viewNode);
	}//end addViewBasicData()

	/**
	 * Add view nodes with proper nested structure handling.
	 *
	 * @param \SimpleXMLElement $viewNode The view XML node.
	 * @param array $nodes The node data arrays.
	 *
	 * @return void
	 */
	private function addViewNodes(\SimpleXMLElement $viewNode, array $nodes): void {
		foreach ($nodes as $nodeData) {
			$node = $viewNode->addChild('node');
			$this->arrayToXml(data: $nodeData, xml: $node);
		}
	}//end addViewNodes()

	/**
	 * Add view connections with proper nested structure handling.
	 *
	 * @param \SimpleXMLElement $viewNode The view XML node.
	 * @param array $connections The connection data arrays.
	 *
	 * @return void
	 */
	private function addViewConnections(\SimpleXMLElement $viewNode, array $connections): void {
		foreach ($connections as $connectionData) {
			$connection = $viewNode->addChild('connection');
			$this->arrayToXml(data: $connectionData, xml: $connection);
		}
	}//end addViewConnections()

	/**
	 * Generic method to add any object to a folder.
	 *
	 * @param \SimpleXMLElement $folder The folder XML element.
	 * @param array $object The object data.
	 * @param string $childTagName Tag name for child elements.
	 *
	 * @return void
	 */
	private function addObjectToFolder(\SimpleXMLElement $folder, array $object, string $childTagName = 'element'): void {
		$objectNode = $folder->addChild($childTagName);

		// Handle different data formats:.
		// 1. OpenRegister object format with properties.xml_data.
		// 2. Direct XML data from convertFromOpenRegisterObjects.
		// 3. Raw object data as fallback.
		if (isset($object['properties']['xml_data']) === true) {
			// Format 1: OpenRegister object format.
			$xmlData = $object['properties']['xml_data'];
			if (is_string($object['properties']['xml_data']) === true) {
				$xmlData = json_decode($object['properties']['xml_data'], true) ?? [];
			}

			if (is_array($xmlData) === true) {
				$this->arrayToXml(data: $xmlData, xml: $objectNode);
			}
		} elseif (isset($object['xml_data']) === true) {
			// Format 2: Object with xml_data field (from database).
			$xmlData = $object['xml_data'];
			if (is_string($object['xml_data']) === true) {
				$xmlData = json_decode($object['xml_data'], true) ?? [];
			}

			if (is_array($xmlData) === true) {
				$this->arrayToXml(data: $xmlData, xml: $objectNode);
			}
		} else {
			// Format 3: Direct XML data (from convertFromOpenRegisterObjects).
			// This handles the case where $archiMateData['views'][$identifier] = $xmlData.
			$this->arrayToXml(data: $object, xml: $objectNode);
		}//end if
	}//end addObjectToFolder()

	/**
	 * Get all objects from the AMEF register
	 *
	 * Queries each schema separately because OpenRegister's magic table routing
	 * requires both register AND schema in the query. Without schema, the query
	 * falls back to the generic objects table (which is empty for magic-table registers).
	 *
	 * @param \OCA\OpenRegister\Contract\ObjectServiceInterface $objectService OpenRegister ObjectService.
	 * @param int $registerId AMEF register ID.
	 * @param array $schemaIdMap Mapping of schema IDs to schema types.
	 *
	 * @return array Array of objects from all schemas in the register.
	 *
	 * @throws \RuntimeException If retrieval fails.
	 * @spec   openspec/specs/archimate-export/spec.md
	 */
	public function getObjectsFromDatabase(
		\OCA\OpenRegister\Contract\ObjectServiceInterface $objectService,
		int $registerId,
		array $schemaIdMap = [],
	): array {
		$this->logger->info(
			'Retrieving all objects from AMEF register',
			[
				'register_id' => $registerId,
				'schema_count' => count($schemaIdMap),
			]
		);

		$allObjects = [];

		// Map schema types (singular) to section names used in XML generation.
		$sectionNameMap = [
			'element' => 'element',
			'relationship' => 'relationship',
			'view' => 'view',
			'organization' => 'organization',
			'property_definition' => 'property_definition',
			'model' => 'model',
		];

		// Query each schema separately (required for magic table routing).
		foreach ($schemaIdMap as $schemaId => $schemaType) {
			$query = [
				'@self' => [
					'register' => $registerId,
					'schema' => (int)$schemaId,
				],
				'_limit' => 10000,
			];

			try {
				$schemaObjects = $objectService->searchObjects(query: $query, _rbac: false, _multitenancy: false);

				// Inject 'section' field if missing (magic table objects don't store it).
				$sectionName = $sectionNameMap[$schemaType] ?? $schemaType;
				foreach ($schemaObjects as &$obj) {
					if (is_object($obj) === true && method_exists($obj, 'jsonSerialize') === true) {
						$obj = $obj->jsonSerialize();
					}

					if (isset($obj['section']) === false) {
						$obj['section'] = $sectionName;
					}
				}

				unset($obj);

				$this->logger->info(
					'Objects retrieved for schema',
					[
						'schema_id' => $schemaId,
						'schema_type' => $schemaType,
						'count' => count($schemaObjects),
					]
				);

				$allObjects = array_merge($allObjects, $schemaObjects);
			} catch (\Exception $e) {
				$this->logger->warning(
					'Failed to retrieve objects for schema',
					[
						'schema_id' => $schemaId,
						'schema_type' => $schemaType,
						'error' => $e->getMessage(),
					]
				);
			}//end try
		}//end foreach

		// Fallback: if no schemaIdMap provided, try querying register directly.
		if (empty($schemaIdMap) === true) {
			$query = [
				'@self' => ['register' => $registerId],
				'_limit' => 10000,
			];

			try {
				$allObjects = $objectService->searchObjects(query: $query, _rbac: false, _multitenancy: false);
			} catch (\Exception $e) {
				$this->logger->error(
					'Failed to retrieve objects from AMEF register',
					[
						'register_id' => $registerId,
						'error' => $e->getMessage(),
					]
				);
				throw new \RuntimeException('Failed to retrieve objects from database: ' . $e->getMessage());
			}
		}

		$this->logger->info(
			'Objects retrieved successfully from AMEF register',
			[
				'total_retrieved_count' => count($allObjects),
				'register_id' => $registerId,
			]
		);

		return $allObjects;
	}//end getObjectsFromDatabase()

	/**
	 * Add property definitions to XML.
	 *
	 * @param \SimpleXMLElement $xml The root XML element.
	 * @param array $propertyDefinitions The property definitions.
	 *
	 * @return void
	 * @spec   openspec/specs/archimate-export/spec.md
	 */
	public function addPropertyDefinitionsToXml(\SimpleXMLElement $xml, array $propertyDefinitions): void {
		$this->addObjectsToXml(
			xml: $xml,
			objects: $propertyDefinitions,
			folderName: 'Property Definitions',
			folderId: 'folder-property-definitions',
			folderType: 'other',
			childTagName: 'propertyDefinition'
		);
	}//end addPropertyDefinitionsToXml()

	/**
	 * Complete export process: get all objects from database and render XML in one go
	 *
	 * OPTIMIZED VERSION: This method processes 8000+ objects efficiently by:
	 * 1. Single database query to get all objects
	 * 2. Single pass through objects using section property directly
	 * 3. Direct XML generation without intermediate arrays
	 * 4. No JSON serialization overhead
	 *
	 * @param \OCA\OpenRegister\Contract\ObjectServiceInterface $objectService OpenRegister ObjectService.
	 * @param int $registerId AMEF register ID.
	 * @param array $schemaIdMap Schema IDs to types mapping.
	 * @param string|null $organization Organization filter.
	 *
	 * @return string Generated XML.
	 * @spec   openspec/specs/archimate-export/spec.md
	 */
	public function exportArchiMateXml(
		\OCA\OpenRegister\Contract\ObjectServiceInterface $objectService,
		int $registerId,
		array $schemaIdMap,
		?string $organization = null,
	): string {

		$startTime = microtime(true);
		$this->logger->info(
			'Starting OPTIMIZED ArchiMate XML export process (using section property)',
			[
				'register_id' => $registerId,
				'organization_filter' => $organization,
			]
		);

		// Step 1: Get all objects from database (queries each schema separately for magic table support).
		$objects = $this->getObjectsFromDatabase(
			objectService: $objectService,
			registerId: $registerId,
			schemaIdMap: $schemaIdMap
		);
		$dbTime = microtime(true) - $startTime;

		// Step 2: Process and generate XML in single optimized pass (no schema mapping needed).
		$xml = $this->generateXmlDirectly(objects: $objects);

		// Step 3: Run Quality Assurance checks on generated XML.
		$this->runQualityAssuranceChecks(xmlString: $xml);

		$totalTime = microtime(true) - $startTime;

		$this->logger->info(
			'OPTIMIZED ArchiMate XML export completed',
			[
				'total_objects' => count($objects),
				'xml_length' => strlen($xml),
				'db_time_seconds' => round($dbTime, 3),
				'total_time_seconds' => round($totalTime, 3),
				'objects_per_second' => round(count($objects) / $totalTime, 0),
			]
		);

		return $xml;
	}//end exportArchiMateXml()

	/**
	 * Generate XML directly from objects using section-based organization
	 *
	 * ULTRA-OPTIMIZED VERSION:
	 * - Single pass to organize objects by section
	 * - Direct XML generation per section
	 * - No unnecessary loops or checks
	 *
	 * @param array $objects Raw objects from database.
	 *
	 * @return string Generated XML.
	 */
	private function generateXmlDirectly(array $objects): string {
		$this->logger->info(
			'Starting section-based XML generation from objects',
			[
				'object_count' => count($objects),
			]
		);

		// Create base XML structure with model metadata.
		$modelMetadata = $this->extractModelMetadata(objects: $objects);
		$propDefMap = $modelMetadata['propertyDefinitionMap'] ?? [];
		$xml = $this->createCleanArchiMateXml(modelMetadata: $modelMetadata);

		// Add model name and properties if available.
		if (empty($modelMetadata) === false) {
			$this->addModelMetadataToXml(xml: $xml, modelMetadata: $modelMetadata);
		}

		// Step 1: Organize objects by section in single pass.
		$objectsBySection = [];
		$unmatchedCount = 0;

		foreach ($objects as $object) {
			// Serialize object if needed.
			if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
				$object = $object->jsonSerialize();
			}

			$sectionName = $object['section'] ?? null;

			if (empty($sectionName) === false) {
				if (isset($objectsBySection[$sectionName]) === false) {
					$objectsBySection[$sectionName] = [];
				}

				$objectsBySection[$sectionName][] = $object;
			} else {
				$unmatchedCount++;
			}
		}

		// Step 2: Generate XML sections directly.
		$validSections = ['elements', 'relationships', 'organizations', 'property_definitions', 'views'];
		$sectionCounts = [];

		// Map singular section names to plural for XML generation.
		$sectionMapping = [
			'element' => 'elements',
			'relationship' => 'relationships',
			'view' => 'views',
			'organization' => 'organizations',
			'property_definition' => 'property_definitions',
		];

		foreach ($validSections as $sectionName) {
			// Check both singular and plural section names.
			$sectionObjects = [];
			foreach ($objectsBySection as $dbSection => $objects) {
				if (isset($sectionMapping[$dbSection]) === true && $sectionMapping[$dbSection] === $sectionName) {
					$sectionObjects = array_merge($sectionObjects, $objects);
				}
			}

			if (empty($sectionObjects) === false) {
				$sectionCounts[$sectionName] = count($sectionObjects);

				// Organizations are stored as a single tree object with the full hierarchy.
				// in the xml field. Write items directly as children of <organizations>.
				if ($sectionName === 'organizations') {
					$orgFolder = $this->createSectionFolder(xml: $xml, sectionName: $sectionName);
					foreach ($sectionObjects as $object) {
						if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
							$object = $object->jsonSerialize();
						}

						$xmlField = $object['xml'] ?? [];
						// The xml field contains the raw organizations data with 'item' array.
						if (isset($xmlField['item']) === true) {
							$items = $xmlField['item'];
							// Ensure items is a list (could be single assoc array for one top-level folder).
							if (isset($items[0]) === false) {
								$items = [$items];
							}

							foreach ($items as $itemData) {
								if (is_array($itemData) === true) {
									$itemNode = $orgFolder->addChild('item');
									$this->addOrganizationItemToXml(itemNode: $itemNode, itemData: $itemData);
								}
							}
						}
					}//end foreach

					$this->logger->debug(
						"Generated XML section: {$sectionName} (tree mode)",
						[
							'object_count' => count($sectionObjects),
						]
					);
					continue;
				}//end if

				// Create section folder.
				$sectionFolder = $this->createSectionFolder(xml: $xml, sectionName: $sectionName);

				// Add all objects in this section.
				foreach ($sectionObjects as $object) {
					$this->addObjectDirectlyToXmlWithProperties(
						folder: $sectionFolder,
						object: $object,
						sectionName: $sectionName,
						propertyDefinitionMap: $propDefMap
					);
				}

				$this->logger->debug(
					"Generated XML section: {$sectionName}",
					[
						'object_count' => count($sectionObjects),
					]
				);
			}//end if
		}//end foreach

		// Debug logging.
		$this->logger->info(
			'Section-based XML generation completed',
			[
				'sections_found' => array_keys($objectsBySection),
				'section_counts' => $sectionCounts,
				'unmatched_objects' => $unmatchedCount,
				'total_objects_processed' => count($objects),
				'sections_with_data' => array_keys($sectionCounts),
			]
		);

		return $this->formatXmlOutput(xmlString: $xml->asXML());
	}//end generateXmlDirectly()

	/**
	 * Create section element in XML (matching original ArchiMate structure).
	 *
	 * @param \SimpleXMLElement $xml The root XML element.
	 * @param string $sectionName The section name.
	 *
	 * @return \SimpleXMLElement|null The created section element.
	 */
	private function createSectionFolder(\SimpleXMLElement $xml, string $sectionName): ?\SimpleXMLElement {
		// Map our section names to proper ArchiMate XML elements.
		$sectionMapping = [
			'elements' => 'elements',
			'relationships' => 'relationships',
			'views' => 'views',
			'organizations' => 'organizations',
			'property_definitions' => 'propertyDefinitions',
		];

		$xmlElementName = $sectionMapping[$sectionName] ?? $sectionName;
		$sectionElement = $xml->addChild($xmlElementName);

		// Views need a <diagrams> wrapper element according to ArchiMate standard.
		if ($sectionName === 'views') {
			return $sectionElement->addChild('diagrams');
		}

		return $sectionElement;
	}//end createSectionFolder()

	/**
	 * Add object directly to XML with properties from root fields.
	 *
	 * @param \SimpleXMLElement $folder The folder XML element.
	 * @param array $object The object data.
	 * @param string $sectionName The section name.
	 * @param array $propertyDefinitionMap Property definition map.
	 *
	 * @return void
	 */
	private function addObjectDirectlyToXmlWithProperties(
		\SimpleXMLElement $folder,
		array $object,
		string $sectionName,
		array $propertyDefinitionMap,
	): void {
		$tagName = match ($sectionName) {
			'organizations' => 'item',
			'property_definitions' => 'propertyDefinition',
			'views' => 'view',
			'relationships' => 'relationship',
			'elements' => 'element',
			default => 'element'
		};

		$objectNode = $folder->addChild($tagName);
		// Prefer the 'xml' field (original ArchiMate structure preserved during import).
		// over cleanObjectDataForXml which loses array structure for name/documentation.
		if (isset($object['xml']) === true && is_array($object['xml']) === true && empty($object['xml']) === false) {
			$xmlData = $object['xml'];
			unset($xmlData['_essential_data']);
		} else {
			$xmlData = $this->cleanObjectDataForXml(object: $object, propDefMap: $propertyDefinitionMap);
		}

		if (is_array($xmlData) === true && empty($xmlData) === false) {
			if ($sectionName === 'views') {
				$this->addViewDataToXmlNode(viewNode: $objectNode, viewData: $xmlData);
			} else {
				$this->addCleanDataToXmlNode(
					node: $objectNode,
					data: $xmlData,
					sectionName: $sectionName,
					propertyDefinitionMap: $propertyDefinitionMap
				);
			}
		}
	}//end addObjectDirectlyToXmlWithProperties()

	/**
	 * Add view data to XML node with specialized handling for nodes and connections.
	 *
	 * @param \SimpleXMLElement $viewNode The view XML node.
	 * @param array $viewData The view data array.
	 *
	 * @return void
	 */
	private function addViewDataToXmlNode(\SimpleXMLElement $viewNode, array $viewData): void {
		// Add attributes first.
		if (isset($viewData['_attributes']) === true) {
			foreach ($viewData['_attributes'] as $attrKey => $attrValue) {
				if (str_starts_with($attrKey, ':') === true) {
					continue;
					// Skip duplicate attributes with colon prefix.
				}

				// Handle namespaced attributes (e.g., xsi:type).
				[$nsPrefix, $local] = $this->splitNamespacedKey(key: $attrKey);
				if ($nsPrefix !== null) {
					$nsUri = $this->getNamespaceUri(xml: $viewNode, prefix: $nsPrefix);
					if (empty($nsUri) === false) {
						$viewNode->addAttribute($nsPrefix . ':' . $local, (string)$attrValue, $nsUri);
						continue;
					}
				}

				$viewNode->addAttribute($attrKey, (string)$attrValue);
			}
		}

		// Add identifier directly if present.
		if (isset($viewData['_identifier']) === true || isset($viewData['identifier']) === true) {
			$identifier = $viewData['_identifier'] ?? $viewData['identifier'];
			$viewNode->addAttribute('identifier', (string)$identifier);
		}

		// Add xsi:type if present.
		foreach (['_xsi__type', 'xsi:type', '_xsi:type'] as $typeKey) {
			if (isset($viewData[$typeKey]) === true) {
				$xsiNs = 'http://www.w3.org/2001/XMLSchema-instance';
				$viewNode->addAttribute('xsi:type', (string)$viewData[$typeKey], $xsiNs);
				break;
			}
		}

		// XSD-required order for ViewType (Diagram): name → documentation → properties → node → connection.
		$this->addLongTextChild(parent: $viewNode, tagName: 'name', data: $viewData['name'] ?? null);
		$this->addLongTextChild(parent: $viewNode, tagName: 'documentation', data: $viewData['documentation'] ?? null);
		if (isset($viewData['properties']) === true && is_array($viewData['properties']) === true) {
			$this->addPropertiesToXml(node: $viewNode, properties: $viewData['properties']);
		}

		// Nodes.
		if (isset($viewData['node']) === true && is_array($viewData['node']) === true) {
			$nodes = $viewData['node'];
			if ($this->isList(arr: $nodes) === false) {
				$nodes = [$nodes];
			}

			foreach ($nodes as $nodeData) {
				if (is_array($nodeData) === true) {
					$nodeElement = $viewNode->addChild('node');
					$this->addNodeDataToXmlElement(nodeElement: $nodeElement, nodeData: $nodeData);
				}
			}
		}

		// Connections.
		if (isset($viewData['connection']) === true && is_array($viewData['connection']) === true) {
			$connections = $viewData['connection'];
			if ($this->isList(arr: $connections) === false) {
				$connections = [$connections];
			}

			foreach ($connections as $connectionData) {
				if (is_array($connectionData) === true) {
					$connectionElement = $viewNode->addChild('connection');
					$this->arrayToXml(data: $connectionData, xml: $connectionElement);
				}
			}
		}
	}//end addViewDataToXmlNode()

	/**
	 * Add node data to XML element with specialized handling.
	 *
	 * @param \SimpleXMLElement $nodeElement The node XML element.
	 * @param array $nodeData The node data array.
	 *
	 * @return void
	 */
	private function addNodeDataToXmlElement(\SimpleXMLElement $nodeElement, array $nodeData): void {
		// Add node attributes first - these are the positioning and identification attributes.
		$nodeAttributes = [
			'_identifier' => 'identifier',
			'_x' => 'x',
			'_y' => 'y',
			'_w' => 'w',
			'_h' => 'h',
			'_elementRef' => 'elementRef',
			'_xsi__type' => 'xsi:type',
		];

		$addedNodeAttrs = [];
		foreach ($nodeAttributes as $dataKey => $xmlAttr) {
			if (isset($nodeData[$dataKey]) === true) {
				// Handle namespaced attributes like xsi:type.
				[$nsPrefix, $local] = $this->splitNamespacedKey(key: $xmlAttr);
				if ($nsPrefix !== null) {
					$nsUri = $this->getNamespaceUri(xml: $nodeElement, prefix: $nsPrefix);
					if (empty($nsUri) === false) {
						$nodeElement->addAttribute($nsPrefix . ':' . $local, (string)$nodeData[$dataKey], $nsUri);
						$addedNodeAttrs[] = $nsPrefix . ':' . $local;
						continue;
					}
				}

				$nodeElement->addAttribute($xmlAttr, (string)$nodeData[$dataKey]);
				$addedNodeAttrs[] = $xmlAttr;
			}
		}

		// Also check regular attributes array.
		if (isset($nodeData['_attributes']) === true) {
			foreach ($nodeData['_attributes'] as $attrKey => $attrValue) {
				if (str_starts_with($attrKey, ':') === true) {
					continue;
					// Skip duplicate attributes with colon prefix.
				}

				// Skip if we already added this attribute from the direct keys.
				$knownAttrs = ['identifier', 'x', 'y', 'w', 'h', 'elementRef', 'xsi:type'];
				if (in_array($attrKey, $knownAttrs) === true
					|| in_array($attrKey, $addedNodeAttrs) === true
				) {
					continue;
				}

				// Handle namespaced attributes.
				[$nsPrefix, $local] = $this->splitNamespacedKey(key: $attrKey);
				if ($nsPrefix !== null) {
					$nsUri = $this->getNamespaceUri(xml: $nodeElement, prefix: $nsPrefix);
					if (empty($nsUri) === false) {
						$nodeElement->addAttribute($nsPrefix . ':' . $local, (string)$attrValue, $nsUri);
						continue;
					}
				}

				$nodeElement->addAttribute($attrKey, (string)$attrValue);
			}//end foreach
		}//end if

		// XSD-required order for ViewNodeType: label → style → viewRef → node (nested).
		// Label (used in Label nodes).
		if (isset($nodeData['label']) === true) {
			$labelData = $nodeData['label'];
			if (is_array($labelData) === true) {
				$labelElement = $nodeElement->addChild('label');
				$this->arrayToXml(data: $labelData, xml: $labelElement);
			} else {
				$labelElement = $nodeElement->addChild('label');
				$labelElement[0] = (string)$labelData;
			}
		}

		// Style (lineColor → fillColor → font per XSD StyleType order).
		if (isset($nodeData['style']) === true && is_array($nodeData['style']) === true) {
			$styleElement = $nodeElement->addChild('style');
			$styleData = $nodeData['style'];
			// Enforce StyleType order: lineColor → fillColor → font.
			foreach (['lineColor', 'fillColor', 'font'] as $styleKey) {
				if (isset($styleData[$styleKey]) === true && is_array($styleData[$styleKey]) === true) {
					$child = $styleElement->addChild($styleKey);
					$this->arrayToXml(data: $styleData[$styleKey], xml: $child);
				}
			}
		}

		// ViewRef handling.
		if (isset($nodeData['viewRef']) === true) {
			$viewRefData = $nodeData['viewRef'];
			if (is_array($viewRefData) === true) {
				$vrElement = $nodeElement->addChild('viewRef');
				$this->arrayToXml(data: $viewRefData, xml: $vrElement);
			}
		}

		// Nested nodes (Container/Element type).
		if (isset($nodeData['node']) === true && is_array($nodeData['node']) === true) {
			$nestedNodes = $nodeData['node'];
			if ($this->isList(arr: $nestedNodes) === false) {
				$nestedNodes = [$nestedNodes];
			}

			foreach ($nestedNodes as $nestedNodeData) {
				if (is_array($nestedNodeData) === true) {
					$nestedNodeElement = $nodeElement->addChild('node');
					$this->addNodeDataToXmlElement(nodeElement: $nestedNodeElement, nodeData: $nestedNodeData);
				}
			}
		}
	}//end addNodeDataToXmlElement()

	/**
	 * Add organization item to XML with XSD-required child order.
	 *
	 * @param \SimpleXMLElement $itemNode The item XML node.
	 * @param array $itemData The item data array.
	 *
	 * @return void
	 */
	private function addOrganizationItemToXml(\SimpleXMLElement $itemNode, array $itemData): void {
		// Add identifierRef attribute if present.
		if (isset($itemData['_identifierRef']) === true) {
			$itemNode->addAttribute('identifierRef', (string)$itemData['_identifierRef']);
		} elseif (isset($itemData['_attributes']['identifierRef']) === true) {
			$itemNode->addAttribute('identifierRef', (string)$itemData['_attributes']['identifierRef']);
		}

		// XSD order: label → documentation → item.
		// Labels first.
		if (isset($itemData['label']) === true) {
			$labels = $itemData['label'];
			if (is_array($labels) === true && $this->isList(arr: $labels) === false) {
				$labels = [$labels];
				// Single label → list.
			}

			if (is_array($labels) === true) {
				foreach ($labels as $labelData) {
					if (is_array($labelData) === true) {
						$labelElement = $itemNode->addChild('label');
						$this->arrayToXml(data: $labelData, xml: $labelElement);
					} elseif (is_string($labelData) === true) {
						$labelElement = $itemNode->addChild('label');
						$labelElement[0] = $labelData;
					}
				}
			} elseif (is_string($labels) === true) {
				$labelElement = $itemNode->addChild('label');
				$labelElement[0] = $labels;
			}
		}//end if

		// Documentation.
		$this->addLongTextChild(parent: $itemNode, tagName: 'documentation', data: $itemData['documentation'] ?? null);

		// Nested items.
		if (isset($itemData['item']) === true) {
			$items = $itemData['item'];
			if (is_array($items) === true && $this->isList(arr: $items) === false) {
				$items = [$items];
			}

			if (is_array($items) === true) {
				foreach ($items as $childItemData) {
					if (is_array($childItemData) === true) {
						$childNode = $itemNode->addChild('item');
						$this->addOrganizationItemToXml(itemNode: $childNode, itemData: $childItemData);
					}
				}
			}
		}
	}//end addOrganizationItemToXml()

	/**
	 * Format XML output with proper indentation and line breaks.
	 *
	 * @param string $xmlString The raw XML string.
	 *
	 * @return string The formatted XML string.
	 */
	private function formatXmlOutput(string $xmlString): string {
		// Use DOMDocument to format the XML with proper indentation.
		$dom = new \DOMDocument('1.0', 'UTF-8');
		$dom->preserveWhiteSpace = false;
		$dom->formatOutput = true;

		// Load the XML string.
		if ($dom->loadXML($xmlString) === true) {
			return $dom->saveXML();
		}

		// If formatting fails, return original string.
		return $xmlString;
	}//end formatXmlOutput()

	/**
	 * Clean object data for XML export.
	 *
	 * @param array $object The object data to clean.
	 * @param array $propDefMap Property definition map.
	 *
	 * @return array The cleaned object data.
	 */
	private function cleanObjectDataForXml(array $object, array $propDefMap = []): array {
		// Remove our metadata fields.
		$cleanData = $object;
		$metadataFields = ['section', 'identifier', 'model_identifier', '@self', 'extracted_at'];
		foreach ($metadataFields as $field) {
			unset($cleanData[$field]);
		}

		// Remove duplicate underscore fields that were created during parsing.
		// Keep only the clean attribute names.
		$fieldsToRemove = [];
		foreach ($cleanData as $key => $value) {
			// Remove fields that start with multiple underscores (___identifier, etc).
			if (is_string($key) === true && preg_match('/^_{2,}/', $key) === true) {
				$fieldsToRemove[] = $key;
			} elseif (is_string($key) === true
				&& str_starts_with($key, '_') === true
				&& $key !== '_attributes'
				&& $key !== '_value'
				&& $key !== '_text'
				&& $key !== '_xsi__type'
			) {
				// Remove single underscore fields that have clean equivalents.
				$cleanKey = substr($key, 1);
				if (isset($cleanData[$cleanKey]) === true) {
					$fieldsToRemove[] = $key;
				}
			}
		}

		foreach ($fieldsToRemove as $field) {
			unset($cleanData[$field]);
		}

		// Remove flattened properties that will be reconstructed separately.
		if (empty($propDefMap) === false) {
			foreach ($propDefMap as $propRef => $propName) {
				unset($cleanData[$propName]);
			}
		}

		return $cleanData;
	}//end cleanObjectDataForXml()

	/**
	 * Add clean data to XML node with proper ArchiMate structure.
	 *
	 * @param \SimpleXMLElement $node The XML node.
	 * @param array $data The data array.
	 * @param string|null $sectionName The section name.
	 * @param array $propertyDefinitionMap Property definition map.
	 *
	 * @return void
	 */
	private function addCleanDataToXmlNode(
		\SimpleXMLElement $node,
		array $data,
		?string $sectionName = null,
		array $propertyDefinitionMap = [],
	): void {
		// Extract attributes from various possible locations.
		$attributes = [];
		if (isset($data['identifier']) === true) {
			$attributes['identifier'] = (string)$data['identifier'];
		}

		if (isset($data['_attributes']) === true && is_array($data['_attributes']) === true) {
			foreach ($data['_attributes'] as $attrKey => $attrValue) {
				// Skip colon-prefixed duplicate keys.
				if (str_starts_with($attrKey, ':') === true) {
					continue;
				}

				$isPropertyDefinition = ($sectionName === 'property_definitions');
				if ($attrKey === 'xsi:type') {
					if ($isPropertyDefinition === true) {
						$attributes['type'] = (string)$attrValue;
					} else {
						$attributes['xsi:type'] = (string)$attrValue;
					}
				} elseif (in_array(
					$attrKey,
					['identifier', 'source', 'target', 'accessType', 'isDirected', 'type']
				) === true
				) {
					if ($attrKey === 'type' && $isPropertyDefinition === false) {
						$attributes['xsi:type'] = (string)$attrValue;
					} else {
						$attributes[$attrKey] = (string)$attrValue;
					}
				}
			}//end foreach
		}//end if

		foreach (['xsi:type', 'xsi_type', '_xsi:type', '_xsi__type', '_type'] as $typeKey) {
			if (isset($data[$typeKey]) === true) {
				$isPropertyDefinition = ($sectionName === 'property_definitions');
				$isTypeKey = ($typeKey === '_type');
				if ($isTypeKey === true
					&& $isPropertyDefinition === true
					&& isset($attributes['type']) === false
				) {
					$attributes['type'] = (string)$data[$typeKey];
					break;
				}

				$xsiTypes = ['xsi:type', 'xsi_type', '_xsi:type', '_xsi__type'];
				if (in_array($typeKey, $xsiTypes) === true
					&& isset($attributes['xsi:type']) === false
				) {
					$attributes['xsi:type'] = (string)$data[$typeKey];
					break;
				}
			}
		}//end foreach

		foreach (['source', 'target', 'accessType', 'isDirected', 'type'] as $attrName) {
			if (isset($data[$attrName]) === true && isset($attributes[$attrName]) === false) {
				$isPropertyDefinition = ($sectionName === 'property_definitions');
				if ($attrName === 'type') {
					if ($isPropertyDefinition === true) {
						$attributes['type'] = (string)$data[$attrName];
					} elseif (isset($attributes['xsi:type']) === false) {
						$attributes['xsi:type'] = (string)$data[$attrName];
					}
				} else {
					$attributes[$attrName] = (string)$data[$attrName];
				}
			}
		}

		foreach ($attributes as $attrName => $attrValue) {
			if ($attrName === 'xsi:type') {
				$node->addAttribute('xsi:type', $attrValue, 'http://www.w3.org/2001/XMLSchema-instance');
			} else {
				$node->addAttribute($attrName, $attrValue);
			}
		}

		// Handle child elements in XSD-required order (xs:sequence):.
		// NamedReferenceableType: name → documentation → properties.
		$this->addLongTextChild(parent: $node, tagName: 'name', data: $data['name'] ?? null);
		$this->addLongTextChild(parent: $node, tagName: 'documentation', data: $data['documentation'] ?? null);
		if (isset($data['properties']) === true && is_array($data['properties']) === true) {
			$this->addPropertiesToXml(node: $node, properties: $data['properties']);
		}

		// Add properties from root fields using propertyDefinitionMap ONLY if no properties were already processed.
		if (empty($propertyDefinitionMap) === false && isset($data['properties']) === false) {
			$this->addPropertiesFromRootFields(node: $node, object: $data, propDefMap: $propertyDefinitionMap);
		}
	}//end addCleanDataToXmlNode()

	/**
	 * Add properties to XML node using propertyDefinitionMap from model.
	 *
	 * @param \SimpleXMLElement $node XML node to add properties to.
	 * @param array $object The object with root-level properties.
	 * @param array $propDefMap Map of property name to ref.
	 *
	 * @return void
	 */
	private function addPropertiesFromRootFields(
		\SimpleXMLElement $node,
		array $object,
		array $propDefMap,
	): void {
		// Find all root-level fields that match a propertyDefinitionMap entry.
		$properties = [];
		foreach ($propDefMap as $propRef => $propName) {
			if (isset($object[$propName]) === true) {
				$properties[] = [
					'propertyDefinitionRef' => $propRef,
					'value' => $object[$propName],
				];
			}
		}

		if (empty($properties) === false) {
			$propertiesNode = $node->addChild('properties');
			foreach ($properties as $property) {
				$propertyNode = $propertiesNode->addChild('property');
				$propertyNode->addAttribute('propertyDefinitionRef', $property['propertyDefinitionRef']);
				$valueNode = $propertyNode->addChild('value');
				$valueNode[0] = (string)$property['value'];
			}
		}
	}//end addPropertiesFromRootFields()

	/**
	 * Add properties section to XML.
	 *
	 * @param \SimpleXMLElement $node The XML node.
	 * @param array $properties The properties array.
	 *
	 * @return void
	 */
	private function addPropertiesToXml(\SimpleXMLElement $node, array $properties): void {
		if (empty($properties) === true) {
			return;
		}

		$propertiesNode = $node->addChild('properties');

		// Handle the nested structure from import service: properties.property[].
		$propList = [];
		if (isset($properties['property']) === true) {
			// Structure from import: properties.property (single object or array).
			if ($this->isList(arr: $properties['property']) === true) {
				// Multiple properties as array.
				$propList = $properties['property'];
			} else {
				// Single property as object - wrap in array.
				$propList = [$properties['property']];
			}
		} elseif ($this->isList(arr: $properties) === true) {
			// Direct array of properties.
			$propList = $properties;
		} else {
			// Single property object.
			$propList = [$properties];
		}

		foreach ($propList as $property) {
			if (is_array($property) === false) {
				continue;
			}

			$propertyNode = $propertiesNode->addChild('property');

			// Look for propertyDefinitionRef in various forms (including double underscore from import service).
			$propDefRef = null;
			foreach (['propertyDefinitionRef', '_propertyDefinitionRef', '___propertyDefinitionRef'] as $refKey) {
				if (isset($property[$refKey]) === true) {
					$propDefRef = (string)$property[$refKey];
					break;
				}
			}

			// Also check in _attributes, but avoid duplicate if we already found one.
			if ($propDefRef === null && isset($property['_attributes']['propertyDefinitionRef']) === true) {
				$propDefRef = (string)$property['_attributes']['propertyDefinitionRef'];
			}

			// Skip and clean up malformed attributes that would create invalid XML.
			if (isset($property['_attributes'][':propertyDefinitionRef']) === true) {
				unset($property['_attributes'][':propertyDefinitionRef']);
			}

			// Also check for other malformed attribute patterns.
			$badAttrs = [];
			foreach ($property['_attributes'] ?? [] as $attrName => $attrValue) {
				if (str_starts_with($attrName, ':') === true) {
					$badAttrs[] = $attrName;
				}
			}

			foreach ($badAttrs as $badAttr) {
				unset($property['_attributes'][$badAttr]);
			}

			if (empty($propDefRef) === false) {
				$propertyNode->addAttribute('propertyDefinitionRef', $propDefRef);
			}

			// Handle value in various forms.
			if (isset($property['value']) === true) {
				if (is_array($property['value']) === true) {
					$valueNode = $propertyNode->addChild('value');
					if (isset($property['value']['_value']) === true) {
						$valueNode[0] = (string)$property['value']['_value'];
					} elseif (isset($property['value']['value']) === true) {
						$valueNode[0] = (string)$property['value']['value'];
					}

					// Add xml:lang if present in various forms (including double underscore from import service).
					foreach (['xml:lang', '_xml:lang', '_xml__lang', 'xml_lang'] as $longKey) {
						if (isset($property['value'][$longKey]) === true) {
							$xmlNs = 'http://www.w3.org/XML/1998/namespace';
							$valueNode->addAttribute('xml:lang', $property['value'][$longKey], $xmlNs);
							break;
						}
					}
				} else {
					// Simple string value.
					$valueNode = $propertyNode->addChild('value');
					$valueNode[0] = (string)$property['value'];
				}//end if
			}//end if
		}//end foreach
	}//end addPropertiesToXml()

	/**
	 * Add a child element with text content and optional xml:lang attribute.
	 *
	 * @param \SimpleXMLElement $parent The parent XML element.
	 * @param string $tagName The tag name for the child.
	 * @param mixed $data The text data or array with _value.
	 *
	 * @return void
	 */
	private function addLongTextChild(\SimpleXMLElement $parent, string $tagName, $data): void {
		if ($data === null) {
			return;
		}

		if (is_array($data) === true) {
			$childNode = $parent->addChild($tagName);
			if (isset($data['_value']) === true) {
				$childNode[0] = (string)$data['_value'];
			}

			foreach (['xml:lang', '_xml:lang', '_xml__lang', 'xml_lang'] as $longKey) {
				if (isset($data[$longKey]) === true) {
					$childNode->addAttribute('xml:lang', $data[$longKey], 'http://www.w3.org/XML/1998/namespace');
					break;
				}
			}
		} elseif (is_string($data) === true && $data !== '') {
			$childNode = $parent->addChild($tagName);
			$childNode[0] = $data;
		}
	}//end addLangTextChild()

	/**
	 * Extract model metadata from objects.
	 *
	 * @param array $objects The objects to extract metadata from.
	 *
	 * @return array The model metadata array.
	 */
	private function extractModelMetadata(array $objects): array {
		foreach ($objects as $object) {
			if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
				$object = $object->jsonSerialize();
			}

			if (isset($object['section']) === true && $object['section'] === 'model') {
				return (array)$object;
			}
		}

		return [];
	}//end extractModelMetadata()

	/**
	 * Add model metadata (name, documentation, properties) to XML root.
	 *
	 * @param \SimpleXMLElement $xml The root XML element.
	 * @param array $modelMetadata The model metadata.
	 *
	 * @return void
	 */
	private function addModelMetadataToXml(\SimpleXMLElement $xml, array $modelMetadata): void {
		// Prefer xml field data (preserves full array structure with xml:lang from import).
		$xmlField = $modelMetadata['xml'] ?? [];

		// Resolve name: prefer xml field (array with _value/_xml__lang), fall back to flat field.
		$nameData = $xmlField['name'] ?? $modelMetadata['name'] ?? null;
		if ($nameData !== null) {
			$nameNode = $xml->addChild('name');
			if (is_array($nameData) === true && isset($nameData['_value']) === true) {
				$nameNode[0] = (string)$nameData['_value'];
				foreach (['xml:lang', '_xml:lang', '_xml__lang', 'xml_lang'] as $longKey) {
					if (isset($nameData[$longKey]) === true) {
						$nameNode->addAttribute('xml:lang', $nameData[$longKey], 'http://www.w3.org/XML/1998/namespace');
						break;
					}
				}
			} elseif (is_string($nameData) === true) {
				$nameNode[0] = $nameData;
			}
		}

		// Resolve documentation: prefer xml field, fall back to flat field.
		$docData = $xmlField['documentation'] ?? $modelMetadata['documentation'] ?? null;
		if ($docData !== null) {
			$docNode = $xml->addChild('documentation');
			if (is_array($docData) === true && isset($docData['_value']) === true) {
				$docNode[0] = (string)$docData['_value'];
				foreach (['xml:lang', '_xml:lang', '_xml__lang', 'xml_lang'] as $longKey) {
					if (isset($docData[$longKey]) === true) {
						$docNode->addAttribute('xml:lang', $docData[$longKey], 'http://www.w3.org/XML/1998/namespace');
						break;
					}
				}
			} elseif (is_string($docData) === true) {
				$docNode[0] = $docData;
			}
		}

		// Resolve properties: prefer xml field, fall back to flat field.
		$propsData = $xmlField['properties'] ?? $modelMetadata['properties'] ?? null;
		if ($propsData !== null && is_array($propsData) === true) {
			$this->addPropertiesToXml(node: $xml, properties: $propsData);
		}
	}//end addModelMetadataToXml()

	/**
	 * Optimized method to add data to XML node.
	 *
	 * @param \SimpleXMLElement $node The XML node.
	 * @param array $data The data array.
	 *
	 * @return void
	 */
	private function addDataToXmlNode(\SimpleXMLElement $node, array $data): void {
		// Add attributes first.
		if (isset($data['_attributes']) === true && is_array($data['_attributes']) === true) {
			foreach ($data['_attributes'] as $attrName => $attrValue) {
				$node->addAttribute($attrName, (string)$attrValue);
			}
		}

		// Add text content.
		if (isset($data['_value']) === true) {
			$node[0] = (string)$data['_value'];
		}

		// Add child elements.
		foreach ($data as $key => $value) {
			if ($key === '_attributes' || $key === '_value' || is_int($key) === true) {
				continue;
			}

			if (is_array($value) === true) {
				if (isset($value[0]) === true) {
					// Array of items.
					foreach ($value as $item) {
						$child = $node->addChild($key);
						if (is_array($item) === true) {
							$this->addDataToXmlNode(node: $child, data: $item);
						} else {
							$child[0] = (string)$item;
						}
					}
				} else {
					// Single object.
					$child = $node->addChild($key);
					$this->addDataToXmlNode(node: $child, data: $value);
				}
			} else {
				// Scalar value.
				$child = $node->addChild($key);
				$child[0] = (string)$value;
			}//end if
		}//end foreach
	}//end addDataToXmlNode()

	/**
	 * Convert OpenRegister objects back to ArchiMate format
	 *
	 * @param array $objects OpenRegister objects from all schemas.
	 * @param array $schemaIdMap Mapping of schema IDs to schema types.
	 *
	 * @return array ArchiMate data structure.
	 * @spec   openspec/specs/archimate-export/spec.md
	 */
	public function convertFromOpenRegisterObjects(array $objects, array $schemaIdMap): array {
		$this->logger->info(
			'Converting from OpenRegister objects back to ArchiMate format',
			[
				'total_objects' => count($objects),
			]
		);

		// First, organize objects by schema type based on their schema ID.
		$organizedObjects = $this->organizeObjectsBySchemaType(objects: $objects, schemaIdMap: $schemaIdMap);

		$archiMateData = [
			'model_metadata' => [],
			'elements' => [],
			'relationships' => [],
			'organizations' => [],
			'views' => [],
			'property_definitions' => [],
		];

		// Process organized objects by schema type.
		foreach ($organizedObjects as $schemaType => $schemaObjects) {
			$this->logger->debug(
				'Processing objects for schema type',
				[
					'schema_type' => $schemaType,
					'object_count' => count($schemaObjects),
				]
			);

			foreach ($schemaObjects as $object) {
				$section = $this->mapSchemaTypeToSection(schemaType: $schemaType);
				$identifier = $object['identifier'] ?? '';
				$xmlData = json_decode($object['xml_data'] ?? '{}', true);

				if ($section === 'model_metadata') {
					$archiMateData['model_metadata'] = $xmlData;
					$this->logger->debug(
						'Added model metadata',
						[
							'identifier' => $identifier,
						]
					);
				} else {
					$archiMateData[$section][$identifier] = $xmlData;
					$this->logger->debug(
						'Added section object',
						[
							'section' => $section,
							'identifier' => $identifier,
							'schema_type' => $schemaType,
						]
					);
				}
			}//end foreach
		}//end foreach

		// Reconstruct the proper nested XML structure for export.
		$archiMateData = $this->reconstructNestedXmlStructure(archiMateData: $archiMateData);

		$this->logger->info(
			'Conversion completed',
			[
				'sections' => array_keys($archiMateData),
			]
		);

		return $archiMateData;
	}//end convertFromOpenRegisterObjects()

	/**
	 * Organize objects by schema type based on their schema ID
	 *
	 * @param array $objects Raw objects from database.
	 * @param array $schemaIdMap Mapping of schema IDs to schema types.
	 *
	 * @return array Objects organized by schema type.
	 */
	private function organizeObjectsBySchemaType(array $objects, array $schemaIdMap): array {
		$organizedObjects = [];

		// Organize objects by their schema.
		foreach ($objects as $object) {
			// Serialize the object if it's not already an array.
			if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
				$object = $object->jsonSerialize();
			}

			$schemaId = $object['@self']['schema'] ?? null;

			if ($schemaId !== false && isset($schemaIdMap[$schemaId]) === true) {
				$schemaType = $schemaIdMap[$schemaId];

				if (isset($organizedObjects[$schemaType]) === false) {
					$organizedObjects[$schemaType] = [];
				}

				$organizedObjects[$schemaType][] = $object;
			}
		}

		return $organizedObjects;
	}//end organizeObjectsBySchemaType()

	/**
	 * Map schema type to ArchiMate section name
	 *
	 * @param string $schemaType Schema type from AMEF config.
	 *
	 * @return string Section name for ArchiMate data structure.
	 */
	private function mapSchemaTypeToSection(string $schemaType): string {
		$mapping = [
			'model' => 'model_metadata',
			'element' => 'elements',
			'relationship' => 'relationships',
			'view' => 'views',
			'organization' => 'organizations',
			'property_definition' => 'property_definitions',
		];

		return $mapping[$schemaType] ?? $schemaType;
	}//end mapSchemaTypeToSection()

	/**
	 * Reconstruct the proper nested XML structure for export
	 *
	 * @param array $archiMateData Flattened ArchiMate data.
	 *
	 * @return array Properly nested XML structure.
	 */
	private function reconstructNestedXmlStructure(array $archiMateData): array {
		// Reconstruct views with diagrams wrapper.
		if (empty($archiMateData['views']) === false && is_array($archiMateData['views']) === true) {
			$archiMateData['views'] = [
				'diagrams' => $archiMateData['views'],
			];
		}

		// Reconstruct organizations with items wrapper.
		if (empty($archiMateData['organizations']) === false && is_array($archiMateData['organizations']) === true) {
			$items = [];
			foreach ($archiMateData['organizations'] as $org) {
				$items[] = $org;
			}

			$archiMateData['organizations'] = [
				'item' => $items,
			];
		}

		return $archiMateData;
	}//end reconstructNestedXmlStructure()

	/**
	 * Run comprehensive Quality Assurance checks on exported XML
	 *
	 * @param string $xmlString The generated XML string.
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException If any QA check fails.
	 */
	private function runQualityAssuranceChecks(string $xmlString): void {
		$this->logger->info('Running Quality Assurance checks on exported XML');

		// DEBUG: Save XML to file for inspection.
		$debugPath = '/tmp/debug_export.xml';
		file_put_contents($debugPath, $xmlString);
		$this->logger->info('DEBUG: Raw XML saved to ' . $debugPath . ' (size: ' . strlen($xmlString) . ' bytes)');

		try {
			$xml = new \SimpleXMLElement($xmlString);

			// QA Check 1: Every <element> has xsi:type and unique identifier.
			$this->validateElementsHaveTypeAndIdentifier(xml: $xml);

			// QA Check 2: Every <relationship> has xsi:type, source, target with valid references.
			$this->validateRelationshipsHaveSourceTarget(xml: $xml);

			// QA Check 3: No empty <property/> tags; all have propertyDefinitionRef and <value>.
			$this->validatePropertiesAreNotEmpty(xml: $xml);

			// QA Check 4: propid-2 exists for all elements and value === identifier.
			$this->validateObjectIdProperty(xml: $xml);

			// QA Check 5: name/documentation are trimmed and whitespace normalized.
			$this->validateTextContentNormalized(xml: $xml);

			$this->logger->info('All Quality Assurance checks passed successfully');
		} catch (\Exception $e) {
			$this->logger->error('Quality Assurance check failed: ' . $e->getMessage());
			throw new \InvalidArgumentException('Export QA validation failed: ' . $e->getMessage());
		}//end try
	}//end runQualityAssuranceChecks()

	/**
	 * Validate that every element has xsi:type and unique identifier.
	 *
	 * @param \SimpleXMLElement $xml The XML to validate.
	 *
	 * @return void
	 */
	private function validateElementsHaveTypeAndIdentifier(\SimpleXMLElement $xml): void {
		$elements = $xml->xpath('//element');
		$identifiers = [];

		foreach ($elements as $element) {
			$attributes = $element->attributes();

			// Check xsi:type exists.
			$xsiType = $element->attributes('http://www.w3.org/2001/XMLSchema-instance');
			if (isset($xsiType['type']) === false) {
				throw new \InvalidArgumentException('Element missing xsi:type: ' . (string)$attributes['identifier']);
			}

			// Check identifier exists and is unique.
			if (isset($attributes['identifier']) === false) {
				throw new \InvalidArgumentException('Element missing identifier');
			}

			$identifier = (string)$attributes['identifier'];
			if (in_array($identifier, $identifiers) === true) {
				throw new \InvalidArgumentException('Duplicate identifier found: ' . $identifier);
			}

			$identifiers[] = $identifier;
		}//end foreach

		$this->logger->debug('Validated ' . count($elements) . ' elements with unique identifiers and xsi:type');
	}//end validateElementsHaveTypeAndIdentifier()

	/**
	 * Validate that every relationship has xsi:type, source, target.
	 *
	 * @param \SimpleXMLElement $xml The XML to validate.
	 *
	 * @return void
	 */
	private function validateRelationshipsHaveSourceTarget(\SimpleXMLElement $xml): void {
		$relationships = $xml->xpath('//relationship');
		$allIdentifiers = [];

		// Collect all valid identifiers from elements and relationships.
		foreach ($xml->xpath('//*[@identifier]') as $node) {
			$allIdentifiers[] = (string)$node->attributes()['identifier'];
		}

		foreach ($relationships as $relationship) {
			$attributes = $relationship->attributes();

			// Check xsi:type exists.
			$xsiType = $relationship->attributes('http://www.w3.org/2001/XMLSchema-instance');
			if (isset($xsiType['type']) === false) {
				throw new \InvalidArgumentException('Relationship missing xsi:type: ' . (string)$attributes['identifier']);
			}

			// Check source exists and references valid identifier.
			if (isset($attributes['source']) === false) {
				throw new \InvalidArgumentException('Relationship missing source: ' . (string)$attributes['identifier']);
			}

			$source = (string)$attributes['source'];
			if (in_array($source, $allIdentifiers) === false) {
				throw new \InvalidArgumentException('Relationship source references non-existent identifier: ' . $source);
			}

			// Check target exists and references valid identifier.
			if (isset($attributes['target']) === false) {
				throw new \InvalidArgumentException('Relationship missing target: ' . (string)$attributes['identifier']);
			}

			$target = (string)$attributes['target'];
			if (in_array($target, $allIdentifiers) === false) {
				throw new \InvalidArgumentException('Relationship target references non-existent identifier: ' . $target);
			}
		}//end foreach

		$this->logger->debug('Validated ' . count($relationships) . ' relationships with valid source/target references');
	}//end validateRelationshipsHaveSourceTarget()

	/**
	 * Validate that no properties are empty.
	 *
	 * @param \SimpleXMLElement $xml The XML to validate.
	 *
	 * @return void
	 */
	private function validatePropertiesAreNotEmpty(\SimpleXMLElement $xml): void {
		$properties = $xml->xpath('//property');

		foreach ($properties as $property) {
			$attributes = $property->attributes();

			// Check propertyDefinitionRef exists.
			if (isset($attributes['propertyDefinitionRef']) === false) {
				throw new \InvalidArgumentException('Property missing propertyDefinitionRef');
			}

			// Check value element exists and has content.
			$valueElements = $property->xpath('value');
			if (empty($valueElements) === true) {
				$propRef = (string)$attributes['propertyDefinitionRef'];
				throw new \InvalidArgumentException("Property missing value element: $propRef");
			}

			$value = trim((string)$valueElements[0]);
			$propRef = (string)$attributes['propertyDefinitionRef'];
			if (empty($value) === true) {
				throw new \InvalidArgumentException("Property has empty value: $propRef");
			}
		}//end foreach

		$this->logger->debug('Validated ' . count($properties) . ' properties have propertyDefinitionRef and non-empty values');
	}//end validatePropertiesAreNotEmpty()

	/**
	 * Validate that propid-2 exists for all elements.
	 *
	 * @param \SimpleXMLElement $xml The XML to validate.
	 *
	 * @return void
	 */
	private function validateObjectIdProperty(\SimpleXMLElement $xml): void {
		$elements = $xml->xpath('//element');

		foreach ($elements as $element) {
			$identifier = (string)$element->attributes()['identifier'];

			// Find propid-2 property.
			$objectIdProps = $element->xpath('properties/property[@propertyDefinitionRef="propid-2"]');
			if (empty($objectIdProps) === true) {
				throw new \InvalidArgumentException('Element missing propid-2 property: ' . $identifier);
			}

			$valueElements = $objectIdProps[0]->xpath('value');
			if (empty($valueElements) === true) {
				throw new \InvalidArgumentException('Element propid-2 missing value: ' . $identifier);
			}

			$objectIdValue = trim((string)$valueElements[0]);
			$expectedValue = str_replace('id-', '', $identifier);
			// Remove 'id-' prefix for comparison.
			if ($objectIdValue !== $expectedValue) {
				throw new \InvalidArgumentException(
					sprintf(
						'Element propid-2 value mismatch. Expected: %s, Got: %s (Element: %s)',
						$expectedValue,
						$objectIdValue,
						$identifier
					)
				);
			}
		}//end foreach

		$this->logger->debug('Validated propid-2 property for ' . count($elements) . ' elements');
	}//end validateObjectIdProperty()

	/**
	 * Validate that name/documentation text content is normalized.
	 *
	 * @param \SimpleXMLElement $xml The XML to validate.
	 *
	 * @return void
	 */
	private function validateTextContentNormalized(\SimpleXMLElement $xml): void {
		$textElements = $xml->xpath('//name | //documentation | //value');

		foreach ($textElements as $element) {
			$content = (string)$element;
			$trimmed = trim($content);
			$normalized = preg_replace('/\s+/', ' ', $trimmed);
			// Normalize multiple whitespace to single space.
			if ($content !== $normalized) {
				$tagName = $element->getName();
				$parentId = '';
				if ($element->xpath('../@identifier') === true) {
					$parentId = ' (Parent: ' . (string)$element->xpath('../@identifier')[0] . ')';
				}

				throw new \InvalidArgumentException(
					sprintf(
						"Text content not normalized in <%s>%s. Expected: '%s', Got: '%s'",
						$tagName,
						$parentId,
						$normalized,
						$content
					)
				);
			}
		}//end foreach

		$this->logger->debug('Validated ' . count($textElements) . ' text elements are properly trimmed and normalized');
	}//end validateTextContentNormalized()

	// =======================================================.
	// Organization-specific ArchiMate export.
	// =======================================================.

	/**
	 * Export organization-enriched ArchiMate XML.
	 *
	 * Takes the base GEMMA model objects, adds the organization's applications
	 * as ApplicationComponent elements, creates SpecializationRelationships to
	 * referentiecomponenten, copies views with applications plotted inside, and
	 * adds SWC-specific organization folders.
	 *
	 * @param \OCA\OpenRegister\Contract\ObjectServiceInterface $objectService The object service.
	 * @param int $registerId AMEF register ID.
	 * @param array $schemaIdMap Schema ID to type map.
	 * @param string $orgName Organization name.
	 * @param string $orgUuid Organization UUID.
	 * @param array $gebruikData Usage objects.
	 * @param array $modulesData Module objects.
	 * @param array $deelnamesData Deelnames data.
	 * @param array $options Export options.
	 *
	 * @return string Generated XML.
	 * @spec   openspec/specs/archimate-export/spec.md
	 */
	public function exportOrganizationArchiMateXml(
		\OCA\OpenRegister\Contract\ObjectServiceInterface $objectService,
		int $registerId,
		array $schemaIdMap,
		string $orgName,
		string $orgUuid,
		array $gebruikData,
		array $modulesData,
		array $deelnamesData = [],
		array $options = [],
	): string {
		$startTime = microtime(true);
		$this->logger->info(
			'Starting organization ArchiMate export',
			[
				'organization' => $orgName,
				'gebruik_count' => count($gebruikData),
				'modules_count' => count($modulesData),
				'deelnames_count' => count($deelnamesData),
				'options' => $options,
			]
		);

		// Step 1: Get all base GEMMA objects.
		$baseObjects = $this->getObjectsFromDatabase(
			objectService: $objectService,
			registerId: $registerId,
			schemaIdMap: $schemaIdMap
		);

		// Step 2: Ensure Bron property definition.
		$sourcePropDefId = $this->ensureSourcePropertyDefinition(baseObjects: $baseObjects);

		// Step 3: Build lookup maps and generate elements per data type.
		$gebruiktAppElements = [];
		$gebruiktRelationships = [];
		if (($options['modules'] ?? true) === true) {
			[$moduleRefMap, $moduleNameMap] = $this->buildModuleLookupMaps(
				gebruikData: $gebruikData,
				modulesData: $modulesData
			);
			$gebruiktAppElements = $this->generateApplicationElements(
				moduleRefMap: $moduleRefMap,
				moduleNameMap: $moduleNameMap,
				sourcePropDefId: $sourcePropDefId
			);
			$gebruiktRelationships = $this->generateSpecializationRelationships(
				moduleRefMap: $moduleRefMap,
				sourcePropDefId: $sourcePropDefId
			);
		}

		$deelnamesAppElements = [];
		$deelnamesRelationships = [];
		if (($options['deelnames'] ?? false) === true && empty($deelnamesData) === false) {
			[$deelnameRefMap, $deelnameNameMap] = $this->buildModuleLookupMaps(
				gebruikData: $deelnamesData,
				modulesData: $modulesData
			);
			$deelnamesAppElements = $this->generateApplicationElements(
				moduleRefMap: $deelnameRefMap,
				moduleNameMap: $deelnameNameMap,
				sourcePropDefId: $sourcePropDefId,
				prefix: 'deelname'
			);
			$deelnamesRelationships = $this->generateSpecializationRelationships(
				moduleRefMap: $deelnameRefMap,
				sourcePropDefId: $sourcePropDefId,
				prefix: 'deelname'
			);
		}

		// Merge all elements and relationships for view enrichment.
		$allAppElements = array_merge($gebruiktAppElements, $deelnamesAppElements);
		$allRelationships = array_merge($gebruiktRelationships, $deelnamesRelationships);

		// Step 4: Copy and enrich views with all elements.
		$viewCopies = $this->copyAndEnrichViews(
			baseObjects: $baseObjects,
			orgName: $orgName,
			appElements: $allAppElements,
			relationships: $allRelationships,
			sourcePropDefId: $sourcePropDefId
		);

		// Step 5: Build SWC organization folders with typed structure.
		$swcFolders = $this->buildSwcOrganizationFolders(
			gebruiktAppElements: $gebruiktAppElements,
			deelnamesAppElements: $deelnamesAppElements,
			relationships: $allRelationships,
			viewCopies: $viewCopies
		);

		// Step 6: Assemble into XML.
		$xml = $this->assembleOrganizationXml(
			baseObjects: $baseObjects,
			orgName: $orgName,
			appElements: $allAppElements,
			relationships: $allRelationships,
			viewCopies: $viewCopies,
			swcFolders: $swcFolders,
			sourcePropDefId: $sourcePropDefId
		);

		$totalTime = microtime(true) - $startTime;
		$this->logger->info(
			'Organization ArchiMate export completed',
			[
				'organization' => $orgName,
				'gebruikt_elements' => count($gebruiktAppElements),
				'deelnames_elements' => count($deelnamesAppElements),
				'relationships' => count($allRelationships),
				'view_copies' => count($viewCopies),
				'total_time_seconds' => round($totalTime, 3),
			]
		);

		return $xml;
	}//end exportOrganizationArchiMateXml()

	/**
	 * Build lookup maps from gebruik and modules data.
	 *
	 * @param array $gebruikData The gebruik data.
	 * @param array $modulesData The modules data.
	 *
	 * @return array Array of [moduleRefMap, moduleNameMap].
	 */
	private function buildModuleLookupMaps(array $gebruikData, array $modulesData): array {
		// ModuleId => [refCompIdentifiers].
		$moduleRefMap = [];
		// ModuleId => name.
		$moduleNameMap = [];
		// Build name map from modules data.
		foreach ($modulesData as $module) {
			if (is_object($module) === true && method_exists($module, 'jsonSerialize') === true) {
				$module = $module->jsonSerialize();
			}

			$id = $module['id'] ?? $module['@self']['id'] ?? null;
			$name = $module['name'] ?? $module['@self']['name'] ?? null;
			if ($id !== null && $name !== null) {
				$moduleNameMap[$id] = $name;
			}
		}

		// Build ref map from gebruik data.
		foreach ($gebruikData as $gebruik) {
			if (is_object($gebruik) === true && method_exists($gebruik, 'jsonSerialize') === true) {
				$gebruik = $gebruik->jsonSerialize();
			}

			$moduleId = $gebruik['module'] ?? null;
			if ($moduleId === null) {
				continue;
			}

			// Get module name from gebruik if not already known.
			if (isset($moduleNameMap[$moduleId]) === false) {
				$moduleNameMap[$moduleId] = $gebruik['moduleName'] ?? $gebruik['@self']['name'] ?? 'Module';
			}

			// Get referentiecomponenten UUIDs.
			$refComps = $gebruik['usedForReferenceComponents'] ?? [];
			if (is_array($refComps) === false) {
				continue;
			}

			foreach ($refComps as $refComp) {
				$refCompUuid = ($refComp['id'] ?? $refComp['uuid'] ?? null);
				if (is_string($refComp) === true) {
					$refCompUuid = $refComp;
				}

				if ($refCompUuid === null) {
					continue;
				}

				// Build the ArchiMate identifier (id-{uuid}).
				$refCompIdentifier = 'id-' . $refCompUuid;

				if (isset($moduleRefMap[$moduleId]) === false) {
					$moduleRefMap[$moduleId] = [];
				}

				if (in_array($refCompIdentifier, $moduleRefMap[$moduleId]) === false) {
					$moduleRefMap[$moduleId][] = $refCompIdentifier;
				}
			}//end foreach
		}//end foreach

		$this->logger->debug(
			'Module lookup maps built',
			[
				'modules_with_refs' => count($moduleRefMap),
				'modules_with_names' => count($moduleNameMap),
			]
		);

		return [$moduleRefMap, $moduleNameMap];
	}//end buildModuleLookupMaps()

	/**
	 * Check if a Bron property definition exists in base objects, add one if not.
	 *
	 * @param array $baseObjects The base objects to check.
	 *
	 * @return string The propertyDefinition identifier for Bron.
	 */
	private function ensureSourcePropertyDefinition(array &$baseObjects): string {
		$sourceId = 'id-swc-propdef-bron';

		// Check if "Bron" already exists.
		foreach ($baseObjects as $obj) {
			if (is_object($obj) === true && method_exists($obj, 'jsonSerialize') === true) {
				$obj = $obj->jsonSerialize();
			}

			$section = $obj['section'] ?? '';
			if ($section === 'property_definition') {
				$xml = $obj['xml'] ?? [];
				$name = $xml['name']['_value'] ?? $xml['name'] ?? null;
				if ($name === 'Bron') {
					$existingId = $xml['_identifier'] ?? $obj['identifier'] ?? null;
					if (empty($existingId) === false) {
						$this->logger->debug('Found existing Bron property definition', ['id' => $existingId]);
						return $existingId;
					}
				}
			}
		}

		$this->logger->debug('Bron property definition not found, will create', ['id' => $sourceId]);
		return $sourceId;
	}//end ensureBronPropertyDefinition()

	/**
	 * Generate ApplicationComponent element arrays for each module.
	 *
	 * @param array $moduleRefMap Module to ref component ID map.
	 * @param array $moduleNameMap Module to name map.
	 * @param string $sourcePropDefId Bron property definition ID.
	 * @param string $prefix Optional prefix for IDs.
	 *
	 * @return array Array of element data arrays ready for XML generation.
	 */
	private function generateApplicationElements(
		array $moduleRefMap,
		array $moduleNameMap,
		string $sourcePropDefId,
		string $prefix = '',
	): array {
		$elements = [];
		$idPrefix = 'id-swc-app-';
		if ($prefix !== '') {
			$idPrefix = $prefix . '-app-';
		}

		foreach ($moduleRefMap as $moduleId => $refCompIds) {
			$appIdentifier = $idPrefix . $moduleId;
			$name = $moduleNameMap[$moduleId] ?? 'Module';

			$elements[] = [
				'identifier' => $appIdentifier,
				'name' => $name,
				'xsi_type' => 'ApplicationComponent',
				'bronPropDefId' => $sourcePropDefId,
				'moduleId' => $moduleId,
			];
		}

		$this->logger->debug('Generated application elements', ['count' => count($elements), 'prefix' => $prefix]);
		return $elements;
	}//end generateApplicationElements()

	/**
	 * Generate SpecializationRelationship arrays for module to refcomp mappings.
	 *
	 * @param array $moduleRefMap Module to ref component ID map.
	 * @param string $sourcePropDefId Bron property definition ID.
	 * @param string $prefix Optional prefix for IDs.
	 *
	 * @return array Array of relationship data arrays.
	 */
	private function generateSpecializationRelationships(
		array $moduleRefMap,
		string $sourcePropDefId,
		string $prefix = '',
	): array {
		$relationships = [];
		$appIdPrefix = 'id-swc-app-';
		if ($prefix !== '') {
			$appIdPrefix = $prefix . '-app-';
		}

		$relIdPrefix = 'id-swc-rel-';
		if ($prefix !== '') {
			$relIdPrefix = $prefix . '-rel-';
		}

		foreach ($moduleRefMap as $moduleId => $refCompIds) {
			$appIdentifier = $appIdPrefix . $moduleId;

			foreach ($refCompIds as $refCompIdentifier) {
				$relIdentifier = $relIdPrefix . $moduleId . '-' . str_replace('id-', '', $refCompIdentifier);

				$relationships[] = [
					'identifier' => $relIdentifier,
					'xsi_type' => 'SpecializationRelationship',
					'source' => $appIdentifier,
					'target' => $refCompIdentifier,
					'bronPropDefId' => $sourcePropDefId,
				];
			}
		}

		$this->logger->debug(
			'Generated specialization relationships',
			['count' => count($relationships), 'prefix' => $prefix]
		);
		return $relationships;
	}//end generateSpecializationRelationships()

	/**
	 * Copy qualifying views and inject application nodes.
	 *
	 * @param array $baseObjects The base objects from database.
	 * @param string $orgName The organization name.
	 * @param array $appElements Application elements.
	 * @param array $relationships Relationship data.
	 * @param string $sourcePropDefId Bron property definition ID.
	 *
	 * @return array Array of enriched view data arrays.
	 */
	private function copyAndEnrichViews(
		array $baseObjects,
		string $orgName,
		array $appElements,
		array $relationships,
		string $sourcePropDefId,
	): array {
		$viewCopies = [];

		// Build a reverse lookup: refCompIdentifier => [(appIdentifier, relIdentifier, moduleName)].
		// Derived from the actual generated elements and relationships (handles all prefixes).
		$appNameMap = [];
		foreach ($appElements as $el) {
			$appNameMap[$el['identifier']] = $el['name'];
		}

		$refCompApps = [];
		foreach ($relationships as $rel) {
			$appIdentifier = $rel['source'];
			$refCompIdentifier = $rel['target'];
			$relIdentifier = $rel['identifier'];
			$name = $appNameMap[$appIdentifier] ?? 'Module';

			if (isset($refCompApps[$refCompIdentifier]) === false) {
				$refCompApps[$refCompIdentifier] = [];
			}

			$refCompApps[$refCompIdentifier][] = [
				'appIdentifier' => $appIdentifier,
				'relIdentifier' => $relIdentifier,
				'name' => $name,
			];
		}

		// Iterate view objects.
		foreach ($baseObjects as $obj) {
			if (is_object($obj) === true && method_exists($obj, 'jsonSerialize') === true) {
				$obj = $obj->jsonSerialize();
			}

			$section = $obj['section'] ?? '';
			if ($section !== 'view') {
				continue;
			}

			$xmlData = $obj['xml'] ?? [];
			if (empty($xmlData) === true) {
				continue;
			}

			$originalIdentifier = $xmlData['_identifier'] ?? $obj['identifier'] ?? null;
			if ($originalIdentifier === null) {
				continue;
			}

			// Deep-copy the view XML.
			$viewCopy = json_decode(json_encode($xmlData), true);

			// Assign new identifier.
			$newIdentifier = 'id-swc-view-' . str_replace('id-', '', $originalIdentifier);
			$viewCopy['_identifier'] = $newIdentifier;
			if (isset($viewCopy['_attributes']['identifier']) === true) {
				$viewCopy['_attributes']['identifier'] = $newIdentifier;
			}

			// Rename view: use Titel view SWC property or fallback to original name.
			$viewName = $this->getViewSwcTitle(viewData: $viewCopy) ?? $this->getViewName(viewData: $viewCopy);
			$viewCopy['name'] = ['_value' => $viewName . ' ' . $orgName];

			// Add Bron property to view.
			$viewCopy = $this->addSourceProperty(data: $viewCopy, sourcePropDefId: $sourcePropDefId);

			// Inject application nodes and connections.
			$viewCopy = $this->injectApplicationNodesInView(viewData: $viewCopy, refCompApps: $refCompApps);

			$viewCopies[] = [
				'identifier' => $newIdentifier,
				'xml' => $viewCopy,
				'section' => 'view',
			];
		}//end foreach

		$this->logger->debug('Copied and enriched views', ['count' => count($viewCopies)]);
		return $viewCopies;
	}//end copyAndEnrichViews()

	/**
	 * Extract "Titel view SWC" property value from view XML data.
	 *
	 * @param array $viewData The view data array.
	 *
	 * @return string|null The SWC title, or null if not found.
	 */
	private function getViewSwcTitle(array $viewData): ?string {
		$properties = $viewData['properties']['property'] ?? $viewData['properties'] ?? [];
		if (is_array($properties) === false) {
			return null;
		}

		// Normalize to list.
		if (isset($properties['_propertyDefinitionRef']) === true) {
			$properties = [$properties];
		}

		foreach ($properties as $prop) {
			if (is_array($prop) === false) {
				continue;
			}

			// Check property definition name or ref.
			$value = $prop['value']['_value'] ?? $prop['value'] ?? null;
			// We look for a property whose name contains "Titel view SWC".
			// This is stored as the property's propertyDefinitionRef linking to a named definition.
			// For now, check if the property key/label matches.
			$propName = $prop['_name'] ?? $prop['name'] ?? '';
			if (is_string($propName) === true && stripos($propName, 'Titel view SWC') !== false && $value !== null) {
				if (is_string($value) === true) {
					return $value;
				}

				return null;
			}
		}

		return null;
	}//end getViewSwcTitle()

	/**
	 * Extract view name from view XML data.
	 *
	 * @param array $viewData The view data array.
	 *
	 * @return string The view name.
	 */
	private function getViewName(array $viewData): string {
		if (isset($viewData['name']['_value']) === true) {
			return $viewData['name']['_value'];
		}

		if (isset($viewData['name']) === true && is_string($viewData['name']) === true) {
			return $viewData['name'];
		}

		return 'View';
	}//end getViewName()

	/**
	 * Add Bron=Softwarecatalogus property to an XML data array.
	 *
	 * @param array $data The data array.
	 * @param string $sourcePropDefId The Bron property definition ID.
	 *
	 * @return array The updated data array.
	 */
	private function addSourceProperty(array $data, string $sourcePropDefId): array {
		$sourceProp = [
			'_propertyDefinitionRef' => $sourcePropDefId,
			'value' => ['_value' => 'Softwarecatalogus'],
		];

		if (isset($data['properties']) === false) {
			$data['properties'] = ['property' => [$sourceProp]];
		} elseif (isset($data['properties']['property']) === true) {
			if (isset($data['properties']['property']['_propertyDefinitionRef']) === true) {
				// Single property, convert to list.
				$data['properties']['property'] = [$data['properties']['property'], $sourceProp];
			} else {
				$data['properties']['property'][] = $sourceProp;
			}
		} else {
			$data['properties']['property'] = [$sourceProp];
		}

		return $data;
	}//end addBronProperty()

	/**
	 * Walk the view node tree and inject application child nodes.
	 *
	 * @param array $viewData The view data array.
	 * @param array $refCompApps The ref component apps map.
	 *
	 * @return array The updated view data.
	 */
	private function injectApplicationNodesInView(array $viewData, array $refCompApps): array {
		// Inject into top-level nodes.
		if (isset($viewData['node']) === true && is_array($viewData['node']) === true) {
			$nodes = $viewData['node'];
			if ($this->isList(arr: $nodes) === true) {
				$nodes = [$nodes];
			}

			$newConnections = [];
			$viewData['node'] = $this->processNodesForInjection(
				nodes: $nodes,
				refCompApps: $refCompApps,
				newConnections: $newConnections
			);

			// Add connections to the view.
			if (empty($newConnections) === false) {
				if (isset($viewData['connection']) === false) {
					$viewData['connection'] = [];
				} elseif ($this->isList(arr: $viewData['connection']) === true) {
					$viewData['connection'] = [$viewData['connection']];
				}

				foreach ($newConnections as $conn) {
					$viewData['connection'][] = $conn;
				}
			}
		}//end if

		return $viewData;
	}//end injectApplicationNodesInView()

	/**
	 * Recursively process nodes, injecting application child nodes.
	 *
	 * @param array $nodes The nodes to process.
	 * @param array $refCompApps The ref component apps map.
	 * @param array $newConnections Accumulator for new connections.
	 *
	 * @return array The processed nodes.
	 */
	private function processNodesForInjection(array $nodes, array $refCompApps, array &$newConnections): array {
		foreach ($nodes as &$node) {
			if (is_array($node) === false) {
				continue;
			}

			$elementRef = $node['_elementRef'] ?? $node['_attributes']['elementRef'] ?? null;

			if ($elementRef !== null && isset($refCompApps[$elementRef]) === true) {
				$apps = $refCompApps[$elementRef];
				$parentW = (int)($node['_w'] ?? $node['_attributes']['w'] ?? 120);
				$parentH = (int)($node['_h'] ?? $node['_attributes']['h'] ?? 80);
				$parentIdentifier = $node['_identifier'] ?? $node['_attributes']['identifier'] ?? null;

				// Calculate child node positions.
				$childW = max($parentW - 20, 40);
				$childH = 18;
				$gap = 2;
				$childX = 10;

				// Ensure nested nodes array.
				if (isset($node['node']) === false) {
					$node['node'] = [];
				} elseif ($this->isList(arr: $node['node']) === true) {
					$node['node'] = [$node['node']];
				}

				$existingChildCount = count($node['node']);

				foreach ($apps as $index => $app) {
					$stackIndex = $existingChildCount + $index;
					$childY = $parentH - 5 - (($stackIndex + 1) * ($childH + $gap));
					if ($childY < 20) {
						$childY = 20 + ($stackIndex * ($childH + $gap));
					}

					$childNodeId = 'id-swc-node-' . $app['appIdentifier'] . '-' . str_replace('id-', '', $elementRef);

					$childNode = [
						'_identifier' => $childNodeId,
						'_elementRef' => $app['appIdentifier'],
						'_xsi__type' => 'Element',
						'_x' => (string)$childX,
						'_y' => (string)max(20, $childY),
						'_w' => (string)$childW,
						'_h' => (string)$childH,
						'style' => [
							'fillColor' => ['_r' => '200', '_g' => '255', '_b' => '200', '_a' => '100'],
							'lineColor' => ['_r' => '0', '_g' => '150', '_b' => '0'],
							'font' => ['_name' => 'Segoe UI', '_size' => '9'],
						],
					];

					$node['node'][] = $childNode;

					// Create connection for the relationship.
					if (empty($parentIdentifier) === false) {
						$connId = 'id-swc-conn-' . str_replace('id-swc-rel-', '', $app['relIdentifier']);
						$newConnections[] = [
							'_identifier' => $connId,
							'_relationshipRef' => $app['relIdentifier'],
							'_source' => $childNodeId,
							'_target' => $parentIdentifier,
							'_xsi__type' => 'Relationship',
						];
					}
				}//end foreach
			}//end if

			// Recurse into nested nodes.
			if (isset($node['node']) === true && is_array($node['node']) === true) {
				$nestedNodes = $node['node'];
				if ($this->isList(arr: $nestedNodes) === true) {
					$nestedNodes = [$nestedNodes];
				}

				$node['node'] = $this->processNodesForInjection(
					nodes: $nestedNodes,
					refCompApps: $refCompApps,
					newConnections: $newConnections
				);
			}
		}//end foreach

		unset($node);

		return $nodes;
	}//end processNodesForInjection()

	/**
	 * Build SWC organization folder items.
	 *
	 * @param array $gebruiktAppElements Gebruikt application elements.
	 * @param array $deelnamesAppElements Deelnames application elements.
	 * @param array $relationships Relationship data.
	 * @param array $viewCopies View copy data.
	 *
	 * @return array Organization items for the SWC folders.
	 */
	private function buildSwcOrganizationFolders(
		array $gebruiktAppElements,
		array $deelnamesAppElements,
		array $relationships,
		array $viewCopies,
	): array {
		$folders = [];

		// Typed application folders — only created when data exists.
		if (empty($gebruiktAppElements) === false) {
			$items = [];
			foreach ($gebruiktAppElements as $el) {
				$items[] = ['_identifierRef' => $el['identifier']];
			}

			$folders[] = [
				'label' => ['_value' => 'Gebruikt (Softwarecatalogus)'],
				'items' => $items,
			];
		}

		if (empty($deelnamesAppElements) === false) {
			$items = [];
			foreach ($deelnamesAppElements as $el) {
				$items[] = ['_identifierRef' => $el['identifier']];
			}

			$folders[] = [
				'label' => ['_value' => 'Deelnames (Softwarecatalogus)'],
				'items' => $items,
			];
		}

		// Shared folders — always present when data exists.
		if (empty($relationships) === false) {
			$relItems = [];
			foreach ($relationships as $rel) {
				$relItems[] = ['_identifierRef' => $rel['identifier']];
			}

			$folders[] = [
				'label' => ['_value' => 'Relaties (Softwarecatalogus)'],
				'items' => $relItems,
			];
		}

		if (empty($viewCopies) === false) {
			$viewItems = [];
			foreach ($viewCopies as $vc) {
				$viewItems[] = ['_identifierRef' => $vc['identifier']];
			}

			$folders[] = [
				'label' => ['_value' => 'Views (Softwarecatalogus)'],
				'items' => $viewItems,
			];
		}

		return $folders;
	}//end buildSwcOrganizationFolders()

	/**
	 * Assemble the final organization-specific ArchiMate XML.
	 *
	 * @param array $baseObjects The base objects.
	 * @param string $orgName The organization name.
	 * @param array $appElements Application elements.
	 * @param array $relationships Relationship data.
	 * @param array $viewCopies View copy data.
	 * @param array $swcFolders SWC folder data.
	 * @param string $sourcePropDefId Bron property definition ID.
	 *
	 * @return string The assembled XML string.
	 */
	private function assembleOrganizationXml(
		array $baseObjects,
		string $orgName,
		array $appElements,
		array $relationships,
		array $viewCopies,
		array $swcFolders,
		string $sourcePropDefId,
	): string {
		// Extract model metadata.
		$modelMetadata = $this->extractModelMetadata(objects: $baseObjects);
		$propDefMap = $modelMetadata['propertyDefinitionMap'] ?? [];

		// Create base XML.
		$xml = $this->createCleanArchiMateXml(modelMetadata: $modelMetadata);

		// Override model name.
		$modelName = 'Softwarecatalogus ' . $orgName;
		// Remove existing name children and add new one.
		foreach ($xml->children() as $child) {
			if ($child->getName() === 'name') {
				$dom = dom_import_simplexml($child);
				$dom->parentNode->removeChild($dom);
				break;
			}
		}

		$nameEl = $xml->addChild('name', htmlspecialchars($modelName));
		$nameEl->addAttribute('xml:lang', 'nl', 'http://www.w3.org/XML/1998/namespace');

		// Organize base objects by section.
		$objectsBySection = [];
		foreach ($baseObjects as $object) {
			if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
				$object = $object->jsonSerialize();
			}

			$sectionName = $object['section'] ?? null;
			if (empty($sectionName) === false) {
				$objectsBySection[$sectionName][] = $object;
			}
		}

		// --- Elements section ---.
		$elementsFolder = $xml->addChild('elements');
		$sectionMapping = ['element' => 'elements'];
		foreach ($objectsBySection as $dbSection => $objects) {
			if (($sectionMapping[$dbSection] ?? null) === 'elements') {
				foreach ($objects as $obj) {
					if (is_object($obj) === true && method_exists($obj, 'jsonSerialize') === true) {
						$obj = $obj->jsonSerialize();
					}

					$this->addObjectDirectlyToXmlWithProperties(
						folder: $elementsFolder,
						object: $obj,
						sectionName: 'elements',
						propertyDefinitionMap: $propDefMap
					);
				}
			}
		}

		// Add SWC application elements.
		foreach ($appElements as $appEl) {
			$elNode = $elementsFolder->addChild('element');
			$elNode->addAttribute('identifier', $appEl['identifier']);
			$elNode->addAttribute('xsi:type', $appEl['xsi_type'], 'http://www.w3.org/2001/XMLSchema-instance');
			$nameChild = $elNode->addChild('name', htmlspecialchars($appEl['name']));
			$nameChild->addAttribute('xml:lang', 'nl', 'http://www.w3.org/XML/1998/namespace');
			// Add Bron property.
			$propsEl = $elNode->addChild('properties');
			$propEl = $propsEl->addChild('property');
			$propEl->addAttribute('propertyDefinitionRef', $appEl['bronPropDefId']);
			$propEl->addChild('value', 'Softwarecatalogus');
		}

		// --- Relationships section ---.
		$relsFolder = $xml->addChild('relationships');
		foreach ($objectsBySection as $dbSection => $objects) {
			if ($dbSection === 'relationship') {
				foreach ($objects as $obj) {
					if (is_object($obj) === true && method_exists($obj, 'jsonSerialize') === true) {
						$obj = $obj->jsonSerialize();
					}

					$this->addObjectDirectlyToXmlWithProperties(
						folder: $relsFolder,
						object: $obj,
						sectionName: 'relationships',
						propertyDefinitionMap: $propDefMap
					);
				}
			}
		}

		// Add SWC relationships.
		foreach ($relationships as $rel) {
			$relNode = $relsFolder->addChild('relationship');
			$relNode->addAttribute('identifier', $rel['identifier']);
			$relNode->addAttribute('xsi:type', $rel['xsi_type'], 'http://www.w3.org/2001/XMLSchema-instance');
			$relNode->addAttribute('source', $rel['source']);
			$relNode->addAttribute('target', $rel['target']);
			$propsEl = $relNode->addChild('properties');
			$propEl = $propsEl->addChild('property');
			$propEl->addAttribute('propertyDefinitionRef', $rel['bronPropDefId']);
			$propEl->addChild('value', 'Softwarecatalogus');
		}

		// --- Property Definitions section ---.
		$propDefsFolder = $xml->addChild('propertyDefinitions');
		foreach ($objectsBySection as $dbSection => $objects) {
			if ($dbSection === 'property_definition') {
				foreach ($objects as $obj) {
					if (is_object($obj) === true && method_exists($obj, 'jsonSerialize') === true) {
						$obj = $obj->jsonSerialize();
					}

					$this->addObjectDirectlyToXmlWithProperties(
						folder: $propDefsFolder,
						object: $obj,
						sectionName: 'property_definitions',
						propertyDefinitionMap: $propDefMap
					);
				}
			}
		}

		// Add Bron property definition if we created it.
		if ($sourcePropDefId === 'id-swc-propdef-bron') {
			$propDefNode = $propDefsFolder->addChild('propertyDefinition');
			$propDefNode->addAttribute('identifier', $sourcePropDefId);
			$propDefNode->addAttribute('type', 'string');
			$nameChild = $propDefNode->addChild('name', 'Bron');
			$nameChild->addAttribute('xml:lang', 'nl', 'http://www.w3.org/XML/1998/namespace');
		}

		// --- Organizations section ---.
		$orgsFolder = $xml->addChild('organizations');
		// Write existing organization tree.
		foreach ($objectsBySection as $dbSection => $objects) {
			if ($dbSection === 'organization') {
				foreach ($objects as $obj) {
					if (is_object($obj) === true && method_exists($obj, 'jsonSerialize') === true) {
						$obj = $obj->jsonSerialize();
					}

					$xmlField = $obj['xml'] ?? [];
					if (isset($xmlField['item']) === true) {
						$items = $xmlField['item'];
						if (isset($items[0]) === false) {
							$items = [$items];
						}

						foreach ($items as $itemData) {
							if (is_array($itemData) === true) {
								$itemNode = $orgsFolder->addChild('item');
								$this->addOrganizationItemToXml(itemNode: $itemNode, itemData: $itemData);
							}
						}
					}
				}
			}//end if
		}//end foreach

		// Add SWC folder: top-level folder named after organization, with sub-folders.
		if (empty($swcFolders) === false) {
			$orgFolder = $orgsFolder->addChild('item');
			$orgLabelEl = $orgFolder->addChild('label', htmlspecialchars($orgName));
			$orgLabelEl->addAttribute('xml:lang', 'nl', 'http://www.w3.org/XML/1998/namespace');
			foreach ($swcFolders as $folderData) {
				$subFolder = $orgFolder->addChild('item');
				$labelEl = $subFolder->addChild('label', htmlspecialchars($folderData['label']['_value']));
				$labelEl->addAttribute('xml:lang', 'nl', 'http://www.w3.org/XML/1998/namespace');
				foreach ($folderData['items'] as $identifierRefItem) {
					$childItem = $subFolder->addChild('item');
					$childItem->addAttribute('identifierRef', $identifierRefItem['_identifierRef']);
				}
			}
		}

		// --- Views section ---.
		$viewsSection = $xml->addChild('views');
		$diagramsFolder = $viewsSection->addChild('diagrams');
		// Write original views.
		foreach ($objectsBySection as $dbSection => $objects) {
			if ($dbSection === 'view') {
				foreach ($objects as $obj) {
					if (is_object($obj) === true && method_exists($obj, 'jsonSerialize') === true) {
						$obj = $obj->jsonSerialize();
					}

					$this->addObjectDirectlyToXmlWithProperties(
						folder: $diagramsFolder,
						object: $obj,
						sectionName: 'views',
						propertyDefinitionMap: $propDefMap
					);
				}
			}
		}

		// Write enriched view copies.
		foreach ($viewCopies as $vc) {
			$viewNode = $diagramsFolder->addChild('view');
			$this->addViewDataToXmlNode(viewNode: $viewNode, viewData: $vc['xml']);
		}

		return $this->formatXmlOutput(xmlString: $xml->asXML());
	}//end assembleOrganizationXml()
}//end class
