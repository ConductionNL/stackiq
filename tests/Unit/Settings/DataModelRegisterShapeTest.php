<?php

/**
 * Register-shape tests for architecture-data-model-and-ggm.
 *
 * The fragment `register.d/architecture-data-model-and-ggm.json`, merged onto the
 * shipped register the way SettingsService::loadSettings() merges it, gives `usage`
 * a facetable list of data entities, gives `element` an attribute list with keys
 * and `relation` a source and target cardinality, and seeds two own data entities,
 * the relation between them and a usage that holds data for one of them.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/architecture-data-model-and-ggm/specs/data-model-and-ggm/spec.md#requirement-req-dmg-003-an-application-in-use-shall-record-the-data-entities-it-holds-data-for
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
 * The merged register carries the data model shape.
 */
final class DataModelRegisterShapeTest extends TestCase {

	private const SETTINGS = __DIR__ . '/../../../lib/Settings';

	private const FRAGMENT = self::SETTINGS . '/register.d/architecture-data-model-and-ggm.json';

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
	 * The shipped register with every fragment merged in name order, keeping the highest schema versions.
	 *
	 * @param string|null $skip A fragment file name to leave out.
	 *
	 * @return array<string, mixed>
	 */
	private static function merged(?string $skip=null): array {
		$merge = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$keep = new ReflectionMethod(SettingsService::class, 'keepHighestSchemaVersions');
		$register = self::read(self::SETTINGS . '/softwarecatalogus_register.json');
		$fragments = glob(self::SETTINGS . '/register.d/*.json');
		sort($fragments);
		foreach ($fragments as $fragment) {
			if (basename($fragment) === $skip) {
				continue;
			}

			$before = $register;
			$register = $merge->invoke(null, $register, self::read($fragment));
			$register = $keep->invoke(null, $before, $register);
		}

		return $register;
	}//end merged()

	/**
	 * The seeded objects of one schema, keyed by slug.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function seeded(string $schema): array {
		$found = [];
		foreach (self::merged()['components']['objects'] as $object) {
			if (($object['@self']['schema'] ?? '') === $schema) {
				$found[$object['@self']['slug']] = $object;
			}
		}

		return $found;
	}//end seeded()

	/**
	 * `usage.dataEntities` is a facetable list of related elements, offered filtered on BusinessObject.
	 *
	 * @return void
	 */
	public function testUsageDataEntitiesRelatesToBusinessObjectElements(): void {
		$usage = self::merged()['components']['schemas']['usage'];
		$field = $usage['properties']['dataEntities'];

		$this->assertSame('array', $field['type']);
		$this->assertTrue($field['facetable']);
		$this->assertSame('#/components/schemas/element', $field['items']['$ref']);
		$this->assertSame('related-object', $field['items']['objectConfiguration']['handling']);
		$this->assertSame('type=BusinessObject', $field['items']['objectConfiguration']['queryParams']);
		$this->assertNotContains('dataEntities', $usage['required'] ?? []);

		// The existing usage fields survive the merge.
		foreach (['module', 'consumer', 'usedForReferenceComponents', 'amefElements'] as $kept) {
			$this->assertArrayHasKey($kept, $usage['properties'], $kept);
		}
	}//end testUsageDataEntitiesRelatesToBusinessObjectElements()

	/**
	 * `element.attributes` holds name, data type, key flag and description; relation has both cardinalities.
	 *
	 * @return void
	 */
	public function testElementAttributesAndRelationCardinalities(): void {
		$schemas = self::merged()['components']['schemas'];

		$attribute = $schemas['element']['properties']['attributes'];
		$this->assertSame('array', $attribute['type']);
		$this->assertSame(['name', 'dataType', 'isKey', 'description'], array_keys($attribute['items']['properties']));
		$this->assertSame(['name'], $attribute['items']['required']);
		$this->assertSame(['text', 'number', 'date', 'boolean', 'reference'], $attribute['items']['properties']['dataType']['enum']);
		$this->assertSame('boolean', $attribute['items']['properties']['isKey']['type']);
		$this->assertNotContains('attributes', $schemas['element']['required']);

		foreach (['sourceCardinality', 'targetCardinality'] as $field) {
			$this->assertSame(['0..1', '1', '0..*', '1..*'], $schemas['relation']['properties'][$field]['enum'], $field);
			$this->assertNotContains($field, $schemas['relation']['required'], $field);
		}

		// The origin from architecture-views-editor is still on both (a later fragment may add values).
		foreach (['element', 'relation'] as $slug) {
			$origins = $schemas[$slug]['properties']['origin']['enum'];
			$this->assertSame(['imported', 'drawn'], array_values(array_intersect($origins, ['imported', 'drawn'])), $slug);
		}
	}//end testElementAttributesAndRelationCardinalities()

	/**
	 * The three schemas carry versions above what the register reaches without this fragment.
	 *
	 * @return void
	 */
	public function testTheFragmentBumpsUsageElementAndRelation(): void {
		$with = self::merged()['components']['schemas'];
		$without = self::merged(skip: basename(self::FRAGMENT))['components']['schemas'];
		$fragment = self::read(self::FRAGMENT)['components']['schemas'];

		foreach (['usage', 'element', 'relation'] as $slug) {
			// A later fragment may raise the version further; the merged version then
			// still sits above this fragment's.
			$raisedHere  = version_compare($with[$slug]['version'], $without[$slug]['version'], '>');
			$raisedLater = version_compare($with[$slug]['version'], $fragment[$slug]['version'], '>');
			$this->assertTrue($raisedHere || $raisedLater, $slug . ' version must rise');
			$this->assertTrue(version_compare($with[$slug]['version'], $fragment[$slug]['version'], '>='), $slug);
		}
	}//end testTheFragmentBumpsUsageElementAndRelation()

	/**
	 * The seed holds both own entities, the relation between them by identifier, and the usage link.
	 *
	 * @return void
	 */
	public function testTheSeedHoldsTwoOwnEntitiesTheirRelationAndTheUsageLink(): void {
		// Other fragments seed vng-gemma elements and relations too; this test reads this fragment's own.
		$own      = array_flip(array_map(static fn (array $o): string => $o['@self']['slug'], self::read(self::FRAGMENT)['components']['objects']));
		$elements = array_intersect_key(self::seeded('element'), $own);
		$this->assertSame(['seed-el-melding', 'seed-el-melder'], array_keys($elements));
		foreach ($elements as $slug => $element) {
			$this->assertSame('vng-gemma', $element['@self']['register'], $slug);
			$this->assertSame('BusinessObject', $element['type'], $slug);
			$this->assertSame('drawn', $element['origin'], $slug);
			$this->assertSame('id-' . $slug, $element['identifier'], $slug);
			$this->assertIsArray($element['properties'], $slug);
		}

		$this->assertSame('Melding openbare ruimte', $elements['seed-el-melding']['name']);
		$this->assertSame(
			[['meldingnummer', 'text', true], ['datum melding', 'date', false], ['locatie', 'text', false]],
			array_map(static fn (array $a): array => [$a['name'], $a['dataType'], $a['isKey']], $elements['seed-el-melding']['attributes'])
		);
		$this->assertSame(
			[['e-mailadres', 'text', true], ['naam', 'text', false]],
			array_map(static fn (array $a): array => [$a['name'], $a['dataType'], $a['isKey']], $elements['seed-el-melder']['attributes'])
		);

		$relations = array_intersect_key(self::seeded('relation'), $own);
		$this->assertSame(['seed-rel-melding-melder'], array_keys($relations));
		$relation = $relations['seed-rel-melding-melder'];
		$this->assertSame(
			['Association', 'gedaan door', 'id-seed-el-melding', 'id-seed-el-melder', '0..*', '1', 'drawn'],
			[$relation['type'], $relation['name'], $relation['source'], $relation['target'], $relation['sourceCardinality'], $relation['targetCardinality'], $relation['origin']]
		);

		$usage = self::seeded('usage')['gebruik-topdesk-gem-leiden-deelnemers'];
		$this->assertSame(['@ref:element:seed-el-melding'], $usage['dataEntities']);
	}//end testTheSeedHoldsTwoOwnEntitiesTheirRelationAndTheUsageLink()
}//end class
