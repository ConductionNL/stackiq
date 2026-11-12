<?php

/**
 * XML attribute run for the ArchiMate export
 *
 * The element one array-to-XML conversion writes attributes onto, and the
 * attribute names it has written so far.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service\ArchiMate
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/archimate-export/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\ArchiMate;

/**
 * Writes the text of one element and tracks the attributes written onto it
 * so duplicates are skipped.
 *
 * @spec openspec/specs/archimate-export/spec.md
 */
final class XmlAttributeRun {
	/**
	 * Attribute names written onto the element, in write order.
	 *
	 * @var string[]
	 */
	private array $written = [];

	/**
	 * Constructor.
	 *
	 * @param \SimpleXMLElement $xml The element the attributes are written onto.
	 */
	public function __construct(
		public readonly \SimpleXMLElement $xml,
	) {
	}//end __construct()

	/**
	 * Set the text content of the element.
	 *
	 * @param string $text The text content.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/archimate-export/spec.md
	 */
	public function setText(string $text): void {
		$element    = $this->xml;
		$element[0] = $text;
	}//end setText()

	/**
	 * Whether an attribute of this name was already written (loose comparison).
	 *
	 * @param string|int $name The attribute name.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/archimate-export/spec.md
	 */
	public function has(string|int $name): bool {
		return in_array($name, $this->written) === true;
	}//end has()

	/**
	 * Record an attribute name as written.
	 *
	 * @param string $name The attribute name.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/archimate-export/spec.md
	 */
	public function record(string $name): void {
		$this->written[] = $name;
	}//end record()
}//end class
