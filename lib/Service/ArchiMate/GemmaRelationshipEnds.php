<?php

/**
 * GEMMA relationship ends
 *
 * One relationship the GEMMA standard linker reads, with the identifiers of
 * its source and target.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service\ArchiMate
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/archimate-import/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\ArchiMate;

/**
 * A relationship and its two ends, as GemmaStandardLinker collects what it links.
 *
 * @spec openspec/specs/archimate-import/spec.md
 */
final class GemmaRelationshipEnds {

	/**
	 * The relationship.
	 *
	 * @var array<string, mixed>
	 */
	public array $relationship = [];

	/**
	 * The source and target identifiers.
	 *
	 * @var array{0: string, 1: string}
	 */
	public array $ends = ['', ''];
}//end class
