<?php

/**
 * McpFragmentTest
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Mcp
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Mcp;

use OCA\Stackiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The derived MCP fragment annotates the 14 live catalogue schemas, adds no
 * schema to the register, names only real properties as filters, and carries
 * write verbs on vulnerability alone.
 *
 * @covers \OCA\Stackiq\Service\SettingsService
 *
 * @spec openspec/changes/mcp-full-action-surface/tasks.md#1.1
 * @spec openspec/changes/mcp-full-action-surface/tasks.md#6.5
 */
final class McpFragmentTest extends TestCase {

	/**
	 * The schemas the fragment annotates, by their current slugs.
	 *
	 * @var array<int, string>
	 */
	private const ANNOTATED = [
		'module',
		'moduleVersion',
		'catalogService',
		'organization',
		'contactPerson',
		'connection',
		'compliancy',
		'usage',
		'catalogContract',
		'suite',
		'vulnerability',
		'software-review',
		'bioMeasure',
		'sbomComponent',
	];

	/**
	 * The slugs the superseded fragment used; none may enter the register.
	 *
	 * @var array<int, string>
	 */
	private const RETIRED = ['moduleVersie', 'dienst', 'organisatie', 'contactpersoon', 'koppeling', 'gebruik', 'kwetsbaarheid', 'beoordeeling'];

	/**
	 * The schemas that must stay unannotated.
	 *
	 * @var array<int, string>
	 */
	private const EXCLUDED = ['element', 'view', 'model', 'property-definition', 'relation', 'sector'];

	/**
	 * Read a JSON file of the app.
	 *
	 * @param string $path The path below the app root.
	 *
	 * @return array<mixed>
	 */
	private static function json(string $path): array {
		return json_decode((string) file_get_contents(dirname(__DIR__, 3).'/'.$path), true, 512, JSON_THROW_ON_ERROR);
	}//end json()

	/**
	 * The register with every register.d fragment merged in the order loadSettings() uses.
	 *
	 * @param bool $withThisFragment False to leave this change's fragment out.
	 *
	 * @return array<mixed>
	 */
	private function mergedRegister(bool $withThisFragment = true): array {
		$merged = self::json('lib/Settings/softwarecatalogus_register.json');
		$merge  = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$merge->setAccessible(true);

		$fragments = glob(dirname(__DIR__, 3).'/lib/Settings/register.d/*.json');
		sort($fragments);
		foreach ($fragments as $fragment) {
			if ($withThisFragment === false && basename($fragment) === 'mcp-full-action-surface.json') {
				continue;
			}

			$merged = $merge->invoke(null, $merged, json_decode((string) file_get_contents($fragment), true));
		}

		return $merged;
	}//end mergedRegister()

	/**
	 * The fragment annotates exactly the 14 schemas, each of which exists in the monolith.
	 *
	 * @return void
	 */
	public function testTheFragmentAnnotatesExactlyTheLiveSchemas(): void {
		$fragment = self::json('lib/Settings/register.d/mcp-full-action-surface.json')['components']['schemas'];
		$monolith = self::json('lib/Settings/softwarecatalogus_register.json')['components']['schemas'];

		$this->assertSame(self::ANNOTATED, array_keys($fragment));
		foreach (array_keys($fragment) as $slug) {
			$this->assertArrayHasKey($slug, $monolith, $slug.' exists in the monolith');
			$this->assertSame(['configuration'], array_keys($fragment[$slug]), $slug.' carries an annotation only');
			$this->assertSame(['x-openregister-mcp'], array_keys($fragment[$slug]['configuration']));
		}
	}//end testTheFragmentAnnotatesExactlyTheLiveSchemas()

	/**
	 * Merging this fragment leaves the schema key set unchanged: the register
	 * merged with and without it holds the same schemas.
	 *
	 * @return void
	 */
	public function testTheMergeAddsNoSchema(): void {
		$without = array_keys($this->mergedRegister(withThisFragment: false)['components']['schemas']);
		$merged  = array_keys($this->mergedRegister()['components']['schemas']);
		sort($without);
		sort($merged);

		$this->assertSame($without, $merged);
		$this->assertSame([], array_values(array_intersect(self::RETIRED, $merged)));
	}//end testTheMergeAddsNoSchema()

	/**
	 * Every search filter names a property of the merged schema, and the
	 * verbs, scopes and hints come from the closed vocabularies.
	 *
	 * @return void
	 */
	public function testEveryAnnotationIsValid(): void {
		$schemas = $this->mergedRegister()['components']['schemas'];
		foreach (self::ANNOTATED as $slug) {
			$mcp = $schemas[$slug]['configuration']['x-openregister-mcp'];
			$this->assertTrue($mcp['enabled'], $slug);
			foreach ($mcp['tools'] as $verb => $config) {
				$this->assertContains($verb, ['search', 'get', 'create', 'update', 'delete'], $slug);
				$this->assertSame([], array_diff(array_keys($config), ['description', 'scope', 'filters', 'readOnlyHint', 'destructiveHint', 'idempotentHint']), "{$slug}.{$verb} keys");
				$this->assertNotSame('', trim((string) $config['description']), "{$slug}.{$verb} has a description");
				$expectedScope = match ($verb) {
					'search', 'get' => 'read',
					default => $verb,
				};
				$this->assertSame($expectedScope, $config['scope'], "{$slug}.{$verb} scope");
				$this->assertSame($expectedScope === 'read', $config['readOnlyHint'], "{$slug}.{$verb} readOnlyHint");
				$this->assertFalse($config['destructiveHint'], "{$slug}.{$verb} destructiveHint");
				if ($verb !== 'search') {
					$this->assertArrayNotHasKey('filters', $config, "{$slug}.{$verb} filters");
				}
			}

			$this->assertNotEmpty($mcp['tools']['search']['filters'], $slug);
			foreach ($mcp['tools']['search']['filters'] as $filter) {
				$this->assertArrayHasKey($filter, $schemas[$slug]['properties'], "{$slug} filter {$filter}");
			}
		}//end foreach
	}//end testEveryAnnotationIsValid()

	/**
	 * Only vulnerability carries write verbs, and only create and update.
	 *
	 * @return void
	 */
	public function testVulnerabilityIsTheOnlySchemaWithWriteVerbs(): void {
		$schemas = $this->mergedRegister()['components']['schemas'];
		foreach ($schemas as $slug => $schema) {
			$tools = array_keys($schema['configuration']['x-openregister-mcp']['tools'] ?? []);
			$expected = match (true) {
				$slug === 'vulnerability' => ['search', 'get', 'create', 'update'],
				in_array($slug, self::ANNOTATED, true) => ['search', 'get'],
				default => [],
			};
			$this->assertSame($expected, $tools, (string) $slug);
		}

		foreach (self::EXCLUDED as $slug) {
			$this->assertArrayNotHasKey('x-openregister-mcp', $schemas[$slug]['configuration'] ?? [], $slug);
		}
	}//end testVulnerabilityIsTheOnlySchemaWithWriteVerbs()

	/**
	 * The superseded fragment was never created.
	 *
	 * @return void
	 */
	public function testTheSupersededFragmentIsAbsent(): void {
		$dir = dirname(__DIR__, 3).'/lib/Settings/register.d/';

		$this->assertFileExists($dir.'mcp-full-action-surface.json');
		$this->assertFileDoesNotExist($dir.'stackiq-mcp-adoption.json');
	}//end testTheSupersededFragmentIsAbsent()
}//end class
