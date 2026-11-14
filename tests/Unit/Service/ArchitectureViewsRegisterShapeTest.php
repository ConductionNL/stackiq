<?php

/**
 * Register-shape tests for architecture-views-editor.
 *
 * The fragment `register.d/architecture-views.json`, merged onto the shipped
 * register with SettingsService's own merge, gives `view` its tags, status,
 * origin and basedOn, gives `element` and `relation` an origin, adds the
 * `view-version` schema to the `vng-gemma` register, and declares a view
 * lifecycle whose every value is a member of the status enum.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/architecture-views-editor/specs/architecture-views-editor/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The merged register carries the architecture views shape.
 */
final class ArchitectureViewsRegisterShapeTest extends TestCase {

	private const SETTINGS = __DIR__ . '/../../../lib/Settings';

	/**
	 * The shipped register with every fragment merged on in name order, as loadSettings() merges them.
	 *
	 * @return array<string, mixed>
	 */
	private static function merged(): array {
		$merge = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$register = self::read(self::SETTINGS . '/softwarecatalogus_register.json');
		$fragments = glob(self::SETTINGS . '/register.d/*.json');
		sort($fragments);
		foreach ($fragments as $fragment) {
			$register = $merge->invoke(null, $register, self::read($fragment));
		}

		return $register;
	}//end merged()

	/**
	 * A JSON file, decoded.
	 *
	 * @param string $path The file.
	 *
	 * @return array<string, mixed>
	 */
	private static function read(string $path): array {
		return json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
	}//end read()

	/**
	 * `view` carries tags, status, origin and basedOn, none of them required; element and relation an origin.
	 *
	 * @return void
	 */
	public function testViewElementAndRelationGainTheirFields(): void {
		$schemas = self::merged()['components']['schemas'];

		$view = $schemas['view'];
		foreach (['tags', 'status', 'origin', 'basedOn'] as $field) {
			$this->assertArrayHasKey($field, $view['properties'], $field);
			$this->assertNotContains($field, $view['required']);
		}

		// The imported GEMMA properties are still there after the merge.
		foreach (['identifier', 'name', 'nodes', 'connections', 'xml'] as $field) {
			$this->assertArrayHasKey($field, $view['properties'], $field);
		}

		$this->assertSame(['draft', 'in review', 'published', 'retired'], $view['properties']['status']['enum']);
		$this->assertSame('draft', $view['properties']['status']['default']);
		$this->assertSame(['imported', 'drawn'], $view['properties']['origin']['enum']);
		$this->assertTrue($view['properties']['tags']['facetable']);

		foreach (['element', 'relation'] as $slug) {
			$this->assertSame(['imported', 'drawn'], $schemas[$slug]['properties']['origin']['enum'], $slug);
			$this->assertNotContains('origin', $schemas[$slug]['required'], $slug);
		}
	}//end testViewElementAndRelationGainTheirFields()

	/**
	 * `vng-gemma` lists `view-version` with magic mapping, and the snapshot is not public.
	 *
	 * @return void
	 */
	public function testTheGemmaRegisterHoldsViewVersions(): void {
		$merged = self::merged()['components'];
		$register = $merged['registers']['vng-gemma'];

		$this->assertContains('view-version', $register['schemas']);
		foreach (['element', 'model', 'property-definition', 'relation', 'view'] as $slug) {
			$this->assertContains($slug, $register['schemas'], $slug);
		}

		$this->assertTrue($register['configuration']['schemas']['view-version']['magicMapping']);
		$version = $merged['schemas']['view-version'];
		$this->assertSame(['view', 'versionNumber', 'nodes', 'connections'], $version['required']);
		$this->assertNotContains('public', array_map(static fn (mixed $rule): mixed => (is_array($rule) === true ? ($rule['group'] ?? null) : $rule), $version['authorization']['read']));
	}//end testTheGemmaRegisterHoldsViewVersions()

	/**
	 * Every `from` and `to` of the view lifecycle is a member of the status enum, and the initial state too.
	 *
	 * @return void
	 */
	public function testTheLifecycleNamesOnlyStatusValues(): void {
		$view = self::merged()['components']['schemas']['view'];
		$lifecycle = $view['configuration']['x-openregister-lifecycle'];
		$statuses = $view['properties']['status']['enum'];

		$this->assertSame('status', $lifecycle['field']);
		$this->assertContains($lifecycle['initial'], $statuses);
		$this->assertSame(['submit', 'publish', 'rework', 'retire', 'reopen'], array_keys($lifecycle['transitions']));
		foreach ($lifecycle['transitions'] as $name => $transition) {
			$this->assertContains($transition['to'], $statuses, $name);
			foreach ($transition['from'] as $from) {
				$this->assertContains($from, $statuses, $name);
			}
		}
	}//end testTheLifecycleNamesOnlyStatusValues()

	/**
	 * The three changed schemas carry versions above the shipped register's, so the import does not skip them.
	 *
	 * @return void
	 */
	public function testTheChangedSchemasCarryBumpedVersions(): void {
		$base = self::read(self::SETTINGS . '/softwarecatalogus_register.json')['components']['schemas'];
		$merged = self::merged()['components']['schemas'];

		foreach (['view', 'element', 'relation'] as $slug) {
			$this->assertTrue(version_compare($merged[$slug]['version'], $base[$slug]['version'], '>'), $slug);
		}
	}//end testTheChangedSchemasCarryBumpedVersions()
}//end class
