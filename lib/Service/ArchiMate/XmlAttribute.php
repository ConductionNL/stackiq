<?php

/**
 * XML attribute for the ArchiMate export
 *
 * One attribute to write onto an exported XML element: its name and its raw
 * value, cast to a string only when the attribute is written.
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
 * An attribute name (possibly `prefix:local`) with its raw value.
 *
 * @spec openspec/specs/archimate-export/spec.md
 */
final class XmlAttribute {
	/**
	 * Constructor.
	 *
	 * @param string $name  The attribute name, `prefix:local` when namespaced.
	 * @param mixed  $value The raw attribute value.
	 */
	public function __construct(
		public readonly string $name,
		public readonly mixed $value,
	) {
	}//end __construct()
}//end class
