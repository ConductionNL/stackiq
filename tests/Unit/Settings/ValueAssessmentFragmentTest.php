<?php

/**
 * The value assessment on a usage: scores, the declared suggested TIME class and the demo seeds.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/application-value-assessment/spec.md#requirement-req-ava-001-an-organisation-scores-each-application-it-uses-on-value-fit-and-risk
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Settings;

use OCA\Stackiq\Service\PortfolioReportDerivation;
use OCA\Stackiq\Service\SettingsService;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Merges register.d/value-assessment.json into the monolith with SettingsService's own merge.
 *
 * @coversNothing
 */
class ValueAssessmentFragmentTest extends TestCase {

	private const SCORES = ['businessValue', 'technicalFit', 'riskScore'];

	/**
	 * The usage schema after the fragment is merged in.
	 *
	 * @return array<string, mixed> The schema.
	 */
	private function usageSchema(): array {
		$dir      = __DIR__ . '/../../../lib/Settings';
		$base     = json_decode((string) file_get_contents($dir . '/softwarecatalogus_register.json'), true);
		$fragment = json_decode((string) file_get_contents($dir . '/register.d/value-assessment.json'), true);
		$this->assertIsArray($fragment, 'register.d/value-assessment.json must exist and parse');

		$merge  = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$merged = $merge->invoke(null, $base, $fragment);

		return $merged['components']['schemas']['usage'];
	}//end usageSchema()

	/**
	 * The declared expression of the suggested class.
	 *
	 * @return mixed The expression.
	 */
	private function expression(): mixed {
		$calc = $this->usageSchema()['configuration']['x-openregister-calculations']['suggestedTimeClassification'];
		return $calc['expression'];
	}//end expression()

	/**
	 * Evaluate the operators the expression uses, with OpenRegister's semantics
	 * (CalculationEvaluator: gte is false on null, eq is loose, a bare scalar is a literal).
	 *
	 * @param mixed                $node The expression node.
	 * @param array<string, mixed> $data The usage data.
	 *
	 * @return mixed The value.
	 */
	private function evaluate(mixed $node, array $data): mixed {
		if (is_array($node) === false) {
			return $node;
		}

		$op   = (string) array_key_first($node);
		$args = $node[$op];
		switch ($op) {
			case 'prop':
				return ($data[$args] ?? null);
			case 'lit':
				return $args;
			case 'if':
				if ($this->evaluate($args[0], $data) === true) {
					return $this->evaluate($args[1], $data);
				}
				return $this->evaluate($args[2], $data);
			case 'or':
				foreach ($args as $arg) {
					if ($this->evaluate($arg, $data) === true) {
						return true;
					}
				}
				return false;
			case 'eq':
				return $this->evaluate($args[0], $data) == $this->evaluate($args[1], $data);
			case 'gte':
				$a = $this->evaluate($args[0], $data);
				$b = $this->evaluate($args[1], $data);
				return $a !== null && $b !== null && $a >= $b;
		}

		$this->fail('The expression uses an operator this test does not model: ' . $op);
	}//end evaluate()

	/**
	 * The three scores run from 1 to 5, the date is a date, and none is required.
	 *
	 * @return void
	 */
	public function testTheScoresRunFromOneToFiveAndAreOptional(): void {
		$schema = $this->usageSchema();
		foreach (self::SCORES as $field) {
			$property = $schema['properties'][$field];
			$this->assertSame('integer', $property['type'], $field);
			$this->assertSame(1, $property['minimum'], $field);
			$this->assertSame(5, $property['maximum'], $field);
			$this->assertNotEmpty($property['title'], $field);
		}

		$this->assertSame('date', $schema['properties']['scoredOn']['format']);
		$required = ($schema['required'] ?? []);
		$this->assertSame([], array_values(array_intersect([...self::SCORES, 'scoredOn', 'suggestedTimeClassification'], $required)));
	}//end testTheScoresRunFromOneToFiveAndAreOptional()

	/**
	 * The suggestion carries the TIME enum, is not on the form, and is a materialised calculation.
	 *
	 * @return void
	 */
	public function testTheSuggestionIsADeclaredMaterialisedCalculation(): void {
		$schema     = $this->usageSchema();
		$suggestion = $schema['properties']['suggestedTimeClassification'];

		$this->assertSame($schema['properties']['timeClassification']['enum'], $suggestion['enum']);
		$this->assertTrue($suggestion['hideOnForm']);

		$calc = $schema['configuration']['x-openregister-calculations']['suggestedTimeClassification'];
		$this->assertSame('string', $calc['type']);
		$this->assertTrue($calc['materialise']);
		$this->assertArrayNotHasKey('dependsOn', $calc);

		// The merge keeps the usage lifecycle next to the calculation.
		$this->assertArrayHasKey('x-openregister-lifecycle', $schema['configuration']);
	}//end testTheSuggestionIsADeclaredMaterialisedCalculation()

	/**
	 * The four mappings of the spec, and no suggestion while a score is missing.
	 *
	 * @return void
	 */
	public function testTheExpressionGivesTheFourClasses(): void {
		$cases = [
			[5, 2, 'Migrate'],
			[4, 4, 'Invest'],
			[2, 5, 'Tolerate'],
			[1, 2, 'Eliminate'],
			[3, 3, 'Invest'],
			[5, null, null],
			[null, 4, null],
		];
		foreach ($cases as [$value, $fit, $expected]) {
			$data = array_filter(['businessValue' => $value, 'technicalFit' => $fit], static fn ($v) => $v !== null);
			$this->assertSame($expected, $this->evaluate($this->expression(), $data), json_encode($data));
		}
	}//end testTheExpressionGivesTheFourClasses()

	/**
	 * The report's own rule agrees with the declared expression on every combination.
	 *
	 * @return void
	 */
	public function testTheReportRuleMatchesTheDeclaredExpression(): void {
		$derivation = new PortfolioReportDerivation();
		foreach ([null, 1, 2, 3, 4, 5] as $value) {
			foreach ([null, 1, 2, 3, 4, 5] as $fit) {
				$data = array_filter(['businessValue' => $value, 'technicalFit' => $fit], static fn ($v) => $v !== null);
				$this->assertSame(
					$this->evaluate($this->expression(), $data),
					$derivation->suggestTimeClassification(businessValue: $value, technicalFit: $fit),
					json_encode($data)
				);
			}
		}
	}//end testTheReportRuleMatchesTheDeclaredExpression()

	/**
	 * The schema version moves above the version before this change.
	 *
	 * @return void
	 */
	public function testTheSchemaVersionMovesUp(): void {
		$this->assertTrue(version_compare($this->usageSchema()['version'], '1.5.2', '>'));
	}//end testTheSchemaVersionMovesUp()

	/**
	 * The scored demo usages pass the merged schema and land in all four suggested classes,
	 * with one recorded class that differs from its suggestion.
	 *
	 * @return void
	 */
	public function testTheSeedsAreValidAndCoverTheFourClasses(): void {
		$schema = $this->usageSchema();
		$mock   = json_decode((string) file_get_contents(__DIR__ . '/../../../lib/Settings/stackiq_mock_register.json'), true);
		$seeds  = array_values(
			array_filter(
				$mock['components']['objects'],
				static fn (array $o) => ($o['@self']['schema'] ?? '') === 'usage' && isset($o['businessValue']) === true
			)
		);
		$this->assertGreaterThanOrEqual(4, count($seeds));

		$fields = [...self::SCORES, 'scoredOn', 'suggestedTimeClassification', 'timeClassification'];
		$shape  = ['type' => 'object', 'properties' => array_intersect_key($schema['properties'], array_flip($fields))];
		foreach ($shape['properties'] as $name => $property) {
			$shape['properties'][$name] = array_intersect_key($property, array_flip(['type', 'enum', 'minimum', 'maximum', 'format']));
		}

		$validator  = new Validator();
		$suggested  = [];
		$mismatches = 0;
		foreach ($seeds as $seed) {
			$payload = array_intersect_key($seed, array_flip($fields));
			$result  = $validator->validate(json_decode((string) json_encode($payload)), (string) json_encode($shape));
			$this->assertTrue($result->isValid(), $seed['@self']['slug']);

			$expected = $this->evaluate($this->expression(), $payload);
			$this->assertSame($expected, $seed['suggestedTimeClassification'], $seed['@self']['slug']);
			$suggested[$expected] = true;
			if ($expected !== ($seed['timeClassification'] ?? null)) {
				$mismatches++;
			}
		}

		ksort($suggested);
		$this->assertSame(['Eliminate', 'Invest', 'Migrate', 'Tolerate'], array_keys($suggested));
		$this->assertGreaterThanOrEqual(1, $mismatches);
	}//end testTheSeedsAreValidAndCoverTheFourClasses()
}//end class
