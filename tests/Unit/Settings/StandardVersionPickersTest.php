<?php

/**
 * The standard version pickers read the GEMMA register; the pages show and offer them.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Settings
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/catalogue-connection-pages/spec.md#requirement-a-connection-s-standard-is-picked-from-the-gemma-standard-versions-req-clr-012
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Settings;

use OCA\Stackiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Reads the register as imported and the page manifests.
 *
 * @coversNothing
 */
final class StandardVersionPickersTest extends TestCase {

	/**
	 * The merged schemas.
	 *
	 * @return array<string, mixed>
	 */
	private function schemas(): array {
		$dir = __DIR__ . '/../../../lib/Settings';
		$merged = json_decode((string)file_get_contents($dir . '/softwarecatalogus_register.json'), true);
		$merge = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$keep = new ReflectionMethod(SettingsService::class, 'keepHighestSchemaVersions');
		$files = glob($dir . '/register.d/*.json');
		sort($files);
		foreach ($files as $file) {
			$before = $merged;
			$merged = $keep->invoke(null, $before, $merge->invoke(null, $merged, json_decode((string)file_get_contents($file), true)));
		}

		return $merged['components']['schemas'];
	}//end schemas()

	/**
	 * A page's config from a manifest file.
	 *
	 * @param string $file The manifest file under src/.
	 * @param string $page The page id.
	 *
	 * @return array<string, mixed>
	 */
	private function page(string $file, string $page): array {
		$manifest = json_decode((string)file_get_contents(__DIR__ . '/../../../src/' . $file), true);
		foreach ($manifest['pages'] as $candidate) {
			if ($candidate['id'] === $page) {
				return $candidate['config'];
			}
		}

		self::fail($page . ' not found in ' . $file);
	}//end page()

	public function testTheConnectionPickerListsTheGemmaStandardVersions(): void {
		$items = $this->schemas()['connection']['properties']['standardVersions']['items'];

		$this->assertSame('vng-gemma', $items['x-external-register']);
		$this->assertSame('name', $items['x-label-field']);
		$this->assertSame('gemmaType=standaardversie', $items['objectConfiguration']['queryParams']);
		$this->assertSame('#/components/schemas/element', $items['$ref']);
	}

	public function testTheCompliancePickerListsTheGemmaStandardVersions(): void {
		$field = $this->schemas()['compliancy']['properties']['standardVersion'];

		$this->assertSame('vng-gemma', $field['x-external-register']);
		$this->assertSame('gemmaType=standaardversie', $field['objectConfiguration']['queryParams']);
	}

	public function testTheConnectionPageShowsThePickedStandardVersion(): void {
		$data = array_values(array_filter($this->page(file: 'manifest.d/connections.json', page: 'KoppelingDetail')['widgets'], static fn (array $w): bool => $w['id'] === 'kp-data'))[0];

		$this->assertContains('standardVersions', $data['content']['include']);
	}

	public function testTheApplicationPageOffersToDeclareAStandardForThisApplication(): void {
		$list = array_values(array_filter($this->page(file: 'manifest.json', page: 'ModuleDetail')['widgets'], static fn (array $w): bool => $w['id'] === 'md-compliance'))[0]['content'];

		$this->assertArrayNotHasKey('allowCreate', $list);
		$this->assertSame('Declare a standard', $list['addLabel']);
		$this->assertSame(['module' => '@objectId'], $list['filter']);
		$this->assertContains('standardVersion', $list['formIncludeFields']);
	}
}
