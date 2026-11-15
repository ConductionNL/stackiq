<?php

/**
 * The domain facet reads the element property the register declares (#1141).
 *
 * FacetService read `domain` from each reference component element, while the
 * register's `element` schema declares the property as `domein` and the GEMMA
 * import stores it under that lower-cased name. The key never matched, so the
 * domain facet was empty for every module and service.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/gemma-faceted-search/tasks.md#task-8
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Settings;

use OCA\Stackiq\Service\FacetService;
use PHPUnit\Framework\TestCase;

/**
 * Anchors the facet's element key to the shipped register.
 */
class ElementDomainKeyDeclaredTest extends TestCase {

	/**
	 * The key FacetService reads is a property of the register's element schema.
	 *
	 * @return void
	 */
	public function testTheDomainFacetReadsADeclaredElementProperty(): void {
		$path = __DIR__ . '/../../../lib/Settings/softwarecatalogus_register.json';
		$register = json_decode(json: (string) file_get_contents(filename: $path), associative: true);
		$this->assertIsArray(actual: $register);

		$properties = $register['components']['schemas']['element']['properties'] ?? null;
		$this->assertIsArray(actual: $properties, message: 'the register must ship an element schema');

		$this->assertArrayHasKey(
			key: FacetService::ELEMENT_DOMAIN_PROPERTY,
			array: $properties,
			message: 'the domain facet must read a property the element schema declares'
		);
	}//end testTheDomainFacetReadsADeclaredElementProperty()
}//end class
