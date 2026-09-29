<?php

/**
 * The aiSystem schema as the import sees it: the register.d fragment merged
 * into the monolith with SettingsService's own merge.
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
 * @spec openspec/specs/ai-system-inventory/spec.md#requirement-req-ais-001-an-organisation-registers-the-ai-systems-it-uses-next-to-their-applications
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
 * aiSystem schema and the stackiq register from the result.
 *
 * @coversNothing
 */
class AiSystemFragmentTest extends TestCase {

	/**
	 * The register configuration after every fragment is merged in.
	 *
	 * @return array<string, mixed> The merged configuration.
	 */
	private function merged(): array {
		$dir    = __DIR__ . '/../../../lib/Settings';
		$merged = json_decode((string) file_get_contents($dir . '/softwarecatalogus_register.json'), true);
		$merge  = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');

		$files = glob($dir . '/register.d/*.json');
		sort($files);
		foreach ($files as $file) {
			$fragment = json_decode((string) file_get_contents($file), true);
			$merged   = $merge->invoke(null, $merged, $fragment);
		}

		return $merged;
	}//end merged()

	/**
	 * The aiSystem schema.
	 *
	 * @return array<string, mixed> The schema.
	 */
	private function schema(): array {
		$schemas = $this->merged()['components']['schemas'];
		$this->assertArrayHasKey('aiSystem', $schemas);

		return $schemas['aiSystem'];
	}//end schema()

	/**
	 * The stackiq register lists the new schema, and keeps the ones it had.
	 *
	 * @return void
	 */
	public function testTheStackiqRegisterListsAiSystem(): void {
		$list = $this->merged()['components']['registers']['stackiq']['schemas'];

		$this->assertContains('aiSystem', $list);
		$this->assertContains('module', $list);
		$this->assertContains('usage', $list);
		$this->assertSame(count($list), count(array_unique($list)));
	}//end testTheStackiqRegisterListsAiSystem()

	/**
	 * The record holds the fields of REQ-AIS-001 and REQ-AIS-002.
	 *
	 * @return void
	 */
	public function testTheRecordHoldsTheInventoryAndActFields(): void {
		$props = $this->schema()['properties'];

		$this->assertSame(['AI agent', 'AI model', 'AI feature'], $props['kind']['enum']);
		$this->assertSame(
			['prohibited', 'high risk', 'limited risk', 'minimal risk', 'not yet assessed'],
			$props['aiActRiskCategory']['enum']
		);
		$this->assertSame('not yet assessed', $props['aiActRiskCategory']['default']);
		$this->assertSame(['provider', 'deployer'], $props['aiActRole']['enum']);
		$this->assertSame('#/components/schemas/module', $props['module']['$ref']);
		$this->assertSame('#/components/schemas/organization', $props['provider']['$ref']);
		$this->assertSame('uri', $props['algorithmRegisterUrl']['format']);
		$this->assertSame('date', $props['assessedOn']['format']);
		$this->assertSame('string', $props['friaDocumentRef']['type']);
		$this->assertTrue($props['kind']['facetable']);
		$this->assertTrue($props['aiActRiskCategory']['facetable']);
		$this->assertSame(['name'], $this->schema()['required']);

		foreach (['kind', 'aiActRiskCategory', 'aiActRole', 'status'] as $field) {
			$this->assertSame($props[$field]['enum'], array_keys($props[$field]['x-enum-labels']), $field);
		}
	}//end testTheRecordHoldsTheInventoryAndActFields()

	/**
	 * Evidence files carry the four tags the act asks of a deployer.
	 *
	 * @return void
	 */
	public function testEvidenceFilesCarryTheFourTags(): void {
		$config = $this->schema()['configuration'];

		$this->assertTrue($config['allowFiles']);
		$this->assertSame(['FRIA', 'Technical documentation', 'Human oversight', 'Logging'], $config['allowedTags']);
	}//end testEvidenceFilesCarryTheFourTags()

	/**
	 * The lifecycle names exactly the status values, so every transition can match a row.
	 *
	 * @return void
	 */
	public function testTheLifecycleMatchesTheStatusValues(): void {
		$schema    = $this->schema();
		$lifecycle = $schema['configuration']['x-openregister-lifecycle'];
		$values    = $schema['properties']['status']['enum'];

		$this->assertSame('status', $lifecycle['field']);
		$this->assertContains($lifecycle['initial'], $values);
		foreach ($lifecycle['final'] as $final) {
			$this->assertContains($final, $values);
		}

		foreach ($lifecycle['transitions'] as $name => $transition) {
			$this->assertContains($transition['to'], $values, $name);
			foreach ($transition['from'] as $from) {
				$this->assertContains($from, $values, $name);
			}
		}
	}//end testTheLifecycleMatchesTheStatusValues()

	/**
	 * An organisation reads and edits only its own AI systems; a supplier reads those it provides.
	 *
	 * @return void
	 */
	public function testReadsAreScopedToTheOrganisation(): void {
		$read = $this->schema()['authorization']['read'];

		$this->assertContains(['group' => 'gebruik-beheerder', 'match' => ['_organisation' => '$organisation']], $read);
		$this->assertContains(['group' => 'aanbod-beheerder', 'match' => ['provider' => '$organisation']], $read);
		foreach ($read as $rule) {
			$this->assertIsArray($rule, 'no read rule may grant a whole group every AI system');
		}
	}//end testReadsAreScopedToTheOrganisation()
}//end class
