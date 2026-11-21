<?php

/**
 * The application form offers the GEMMA reference components, picked from the
 * register that holds them, and the application page shows them.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/reference-component-placement/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Settings;

use OCA\Stackiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Merges every register.d fragment the way loadSettings() does and reads the
 * module's referenceComponents field and the ModuleDetail page.
 *
 * @coversNothing
 */
class ReferenceComponentPlacementTest extends TestCase {

	/**
	 * The register configuration, with or without the register.d fragments.
	 *
	 * @param bool $withFragments Whether to merge the fragments in.
	 *
	 * @return array<string, mixed> The configuration.
	 */
	private function register(bool $withFragments = true): array {
		$dir    = __DIR__ . '/../../../lib/Settings';
		$merged = json_decode((string) file_get_contents($dir . '/softwarecatalogus_register.json'), true);
		$this->assertIsArray(actual: $merged);
		if ($withFragments === false) {
			return $merged;
		}

		$merge = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$files = glob($dir . '/register.d/*.json');
		sort($files);
		foreach ($files as $file) {
			$fragment = json_decode((string) file_get_contents($file), true);
			$merged   = $merge->invoke(null, $merged, $fragment);
		}

		return $merged;
	}//end register()

	/**
	 * The field is on the form, and its picker reads the GEMMA elements from
	 * the register that declares them, with their names as labels.
	 *
	 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/reference-component-placement/spec.md#requirement-a-supplier-picks-the-reference-components-an-application-fulfils-on-its-form-req-clr-007
	 *
	 * @return void
	 */
	public function testTheApplicationFormOffersTheReferenceComponentsFromTheGemmaRegister(): void {
		$register = $this->register();
		$field    = $register['components']['schemas']['module']['properties']['referenceComponents'];

		$this->assertFalse(condition: $field['hideOnForm'], message: 'the application form must offer the reference components');
		$this->assertSame(expected: '#/components/schemas/element', actual: $field['items']['$ref']);
		$this->assertSame(
			expected: 'vng-gemma',
			actual: $field['items']['x-external-register'],
			message: 'the picker must read the register that holds the GEMMA elements'
		);
		$this->assertSame(expected: 'name', actual: $field['items']['x-label-field']);
		$this->assertSame(
			expected: 'gemmaType=referentiecomponent&_extend=aanbevolenStandaarden,verplichteStandaarden',
			actual: $field['items']['objectConfiguration']['queryParams'],
			message: 'the picker must still list reference components only'
		);

		$registers = array_column($register['components']['registers'], 'schemas', 'slug');
		$this->assertContains(
			needle: 'element',
			haystack: $registers['vng-gemma'],
			message: 'the vng-gemma register must declare the element schema'
		);
		$this->assertArrayHasKey(key: 'name', array: $register['components']['schemas']['element']['properties']);

	}//end testTheApplicationFormOffersTheReferenceComponentsFromTheGemmaRegister()

	/**
	 * Without the fragment the field was hidden: the test above is about the fragment.
	 *
	 * @return void
	 */
	public function testTheBaseRegisterAloneHidesTheField(): void {
		$field = $this->register(withFragments: false)['components']['schemas']['module']['properties']['referenceComponents'];

		$this->assertTrue(condition: $field['hideOnForm']);
		$this->assertArrayNotHasKey(key: 'x-external-register', array: $field['items']);

	}//end testTheBaseRegisterAloneHidesTheField()

	/**
	 * The application page shows the picked reference components.
	 *
	 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/reference-component-placement/spec.md#scenario-a-supplier-places-an-application-on-a-reference-component
	 *
	 * @return void
	 */
	public function testTheApplicationPageShowsThePickedComponents(): void {
		$manifest = json_decode((string) file_get_contents(__DIR__ . '/../../../src/manifest.json'), true);
		$this->assertIsArray(actual: $manifest);

		$pages  = array_column($manifest['pages'], null, 'id');
		$widget = null;
		foreach ($pages['ModuleDetail']['config']['widgets'] as $candidate) {
			if (($candidate['id'] ?? null) === 'md-data') {
				$widget = $candidate;
			}
		}

		$this->assertNotNull(actual: $widget, message: 'ModuleDetail must carry its md-data widget');
		$this->assertContains(needle: 'referenceComponents', haystack: $widget['content']['include']);

	}//end testTheApplicationPageShowsThePickedComponents()
}//end class
