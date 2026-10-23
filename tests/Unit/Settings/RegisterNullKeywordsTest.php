<?php

/**
 * Guard: no register schema may carry a null JSON-Schema validation keyword.
 *
 * OpenRegister's validator (opis/json-schema) rejects a null where a keyword
 * wants a number ("minimum must contain a valid number"), so a schema with
 * hardValidation refuses every object. The register file once carried
 * `"minimum": null` and similar on ~420 properties and the E2E seed failed.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Scans the register file and its register.d fragments for null keywords.
 */
class RegisterNullKeywordsTest extends TestCase {
	private const KEYWORDS = [
		'minimum',
		'maximum',
		'exclusiveMinimum',
		'exclusiveMaximum',
		'multipleOf',
		'minLength',
		'maxLength',
		'minItems',
		'maxItems',
		'minProperties',
		'maxProperties',
	];

	/**
	 * Collect the paths of null validation keywords below a node.
	 *
	 * @param mixed  $node The decoded JSON node.
	 * @param string $path The path to the node.
	 *
	 * @return array<int,string> The offending paths.
	 */
	private function findNullKeywords(mixed $node, string $path): array {
		$found = [];
		if (is_array($node) === false) {
			return $found;
		}

		foreach ($node as $key => $value) {
			$child = $path.'/'.$key;
			if ($value === null && in_array($key, self::KEYWORDS, true) === true) {
				$found[] = $child;
				continue;
			}

			$found = array_merge($found, $this->findNullKeywords($value, $child));
		}

		return $found;
	}//end findNullKeywords()

	/**
	 * No schema in the register or its fragments carries a null keyword.
	 *
	 * @return void
	 */
	public function testNoSchemaCarriesNullValidationKeywords(): void {
		$dir   = __DIR__.'/../../../lib/Settings';
		$files = array_merge([$dir.'/softwarecatalogus_register.json'], glob($dir.'/register.d/*.json') ?: []);

		$found = [];
		foreach ($files as $file) {
			$this->assertFileExists($file);
			$decoded = json_decode((string)file_get_contents($file), true);
			$this->assertIsArray($decoded, basename($file).' must be valid JSON');
			$schemas = $decoded['components']['schemas'] ?? [];
			$found   = array_merge($found, $this->findNullKeywords($schemas, basename($file)));
		}

		$this->assertSame([], array_slice($found, 0, 10), 'Null validation keywords make OpenRegister refuse the schema (showing first 10)');
	}//end testNoSchemaCarriesNullValidationKeywords()
}//end class
