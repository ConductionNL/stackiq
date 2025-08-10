<?php

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Service;

use Psr\Log\LoggerInterface;

/**
 * Class ArchiMateImportService
 *
 * @category Service
 * @package  OCA\SoftwareCatalog\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @copyright Copyright (c) Conduction
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version  1.0.0
 * @link     https://github.com/ConductionNL/SoftwareCatalog
 *
 * Provides generic XML → array conversion helpers for the AMEF import flow.
 */
class ArchiMateImportService
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Convert a SimpleXMLElement into a normalized associative array.
     *
     * Conventions:
     * - All attributes added as top-level keys with leading underscore `_`.
     * - Namespaced attributes use `prefix__name` in the underscored form and are
     *   also available in the legacy bag form under `_attributes['prefix:name']`.
     * - A legacy `_attributes` bag is maintained for backward compatibility.
     * - Leaf node text is available as `_value`; when children exist alongside
     *   text, it is available as `_text`.
     * - Repeated child nodes are represented as arrays.
     */
    public function xmlToArray(\SimpleXMLElement $xml): array
    {
        // Initialize result and legacy attribute bag for compatibility
        $result = [];
        $attrBag = [];

        // Extract non-namespaced attributes → both underscored keys and attr bag
        foreach ($xml->attributes() as $attrName => $attrValue) {
            $underscoredKey = '_' . str_replace(':', '__', (string) $attrName);
            $scalar = (string) $attrValue;
            $result[$underscoredKey] = $scalar;
            $attrBag[(string) $attrName] = $scalar;
        }

        // Extract namespaced attributes → both underscored keys and attr bag
        foreach ($xml->getNameSpaces(true) as $prefix => $_) {
            $attributes = $xml->attributes($prefix, true);
            foreach ($attributes as $attrName => $attrValue) {
                $underscoredKey = '_' . $prefix . '__' . str_replace(':', '__', (string) $attrName);
                $scalar = (string) $attrValue;
                $result[$underscoredKey] = $scalar;
                $attrBag[$prefix . ':' . (string) $attrName] = $scalar;
            }
        }

        // Preserve legacy _attributes bag if any attributes were found
        if (!empty($attrBag)) {
            $result['_attributes'] = $attrBag;
        }

        // Extract children
        $children = $xml->children();
        if (count($children) === 0) {
            // Leaf node: always return array shape for compatibility
            $text = trim((string) $xml);
            if ($text !== '') {
                $result['_value'] = $text;
            }
            return $result;
        }

        // Process child elements (merge by local-name)
        foreach ($children as $child) {
            $childName = $child->getName();
            $childValue = $this->xmlToArray($child);

            if (!array_key_exists($childName, $result)) {
                $result[$childName] = $childValue;
            } else {
                // Ensure multiple children become an array
                if (!is_array($result[$childName]) || $this->isAssoc($result[$childName])) {
                    $result[$childName] = [$result[$childName]];
                }
                $result[$childName][] = $childValue;
            }
        }

        // Preserve text content when children exist
        $text = trim((string) $xml);
        if ($text !== '') {
            $result['_text'] = $text;
        }

        return $result;
    }


    private function isAssoc(mixed $value): bool
    {
        if (!is_array($value)) {
            return false;
        }
        return array_keys($value) !== range(0, count($value) - 1);
    }
}


