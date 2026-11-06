<?php

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Settings;

use OCA\Stackiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * REQ-CDQ-001, REQ-CDQ-003: the usage schema declares the rules the Data quality page counts.
 */
final class DataQualityFragmentTest extends TestCase {
	/**
	 * The register with every fragment merged the way the loader merges them.
	 *
	 * @return array<string,mixed>
	 */
	private function merged(): array {
		$dir = __DIR__ . '/../../../lib/Settings';
		$merged = json_decode((string)file_get_contents($dir . '/softwarecatalogus_register.json'), true);
		$merge = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$keep = new ReflectionMethod(SettingsService::class, 'keepHighestSchemaVersions');

		$files = glob($dir . '/register.d/*.json');
		sort($files);
		foreach ($files as $file) {
			$before = $merged;
			$merged = $merge->invoke(null, $merged, json_decode((string)file_get_contents($file), true));
			$merged = $keep->invoke(null, $before, $merged);
		}

		return $merged;
	}//end merged()

	public function testEveryUsageRuleNamesAFieldTheSchemaHas(): void {
		$usage = $this->merged()['components']['schemas']['usage'];
		$quality = $usage['configuration']['x-openregister-quality'];

		self::assertNotEmpty($quality['rules']);
		foreach ($quality['rules'] as $rule) {
			self::assertContains($rule['type'], ['required', 'format', 'freshness']);
			self::assertArrayHasKey($rule['field'], $usage['properties'], 'rule on a field usage does not declare: ' . $rule['field']);
		}

		self::assertArrayHasKey($quality['field'], $usage['properties']);
		self::assertArrayHasKey($quality['statusField'], $usage['properties']);
		self::assertContains('freshness', array_column($quality['rules'], 'type'));
	}//end testEveryUsageRuleNamesAFieldTheSchemaHas()

	public function testTheMergedVersionsRiseSoTheImportRuns(): void {
		$schemas = $this->merged()['components']['schemas'];

		self::assertTrue(version_compare($schemas['usage']['version'], '1.5.9', '>='));
		self::assertTrue(version_compare($schemas['module']['version'], '0.3.11', '>='));
		self::assertArrayHasKey('qualityScore', $schemas['module']['properties']);
	}//end testTheMergedVersionsRiseSoTheImportRuns()
}//end class
