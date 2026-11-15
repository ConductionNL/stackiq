<?php

/**
 * Golden decision table for GebruikController::applyAanbodScopeToOptions().
 *
 * Every combination of caller role flags, request filters and vendor
 * applicatie ids is run through the scope decision and compared with the
 * recorded outcome: the options that are allowed through, or a refusal
 * (null), plus the lookups the decision made.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/vendor-visibility-rbac/spec.md#requirement-gebruik-beheerder-reads-of-gebruik-objects-must-be-scoped-to-the-caller-s-own-organisation-req-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\Stackiq\Controller\GebruikController;
use OCA\Stackiq\Service\GebruikService;
use PHPUnit\Framework\TestCase;

/**
 * Pins the allowed and refused outcomes of the gebruik read scope.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Controller
 */
class GebruikControllerScopeGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../fixtures/gebruik/aanbod-scope.json';

	/**
	 * The role flag sets under test.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function roleSets(): array {
		$sets = [];
		foreach ([false, true] as $admin) {
			foreach ([null, false, true] as $ambtenaar) {
				foreach ([false, true] as $aanbod) {
					foreach ([false, true] as $beheerder) {
						$roles = ['isAdmin' => $admin, 'isBeheerder' => $beheerder, 'isAanbod' => $aanbod, 'orgUuid' => 'org-1'];
						if ($ambtenaar !== null) {
							$roles['isAmbtenaar'] = $ambtenaar;
						}

						$sets[json_encode($roles)] = $roles;
					}
				}
			}
		}

		return $sets;
	}//end roleSets()

	/**
	 * The request filters under test.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function optionSets(): array {
		return [
			'none'             => ['_limit' => 10],
			'module-owned'     => ['module' => 'app-1'],
			'module-loose'     => ['module' => '7'],
			'module-foreign'   => ['module' => 'app-9'],
			'consumer-own'     => ['consumer' => 'org-1'],
			'consumer-foreign' => ['consumer' => 'org-2', 'module' => 'app-2'],
		];
	}//end optionSets()

	/**
	 * Run one decision and capture its outcome.
	 *
	 * @param array<string, mixed>   $roles   Role flags.
	 * @param array<string, mixed>   $options Request options.
	 * @param array<int, string|int> $ids     Applicatie ids the vendor offers.
	 *
	 * @return array<string, mixed>
	 */
	private function decide(array $roles, array $options, array $ids): array {
		$lookups = [];
		$service = $this->createMock(GebruikService::class);
		$service->method('getApplicationIds')->willReturnCallback(
			function (array $options) use (&$lookups, $ids): array {
				$lookups[] = $options;
				return $ids;
			}
		);

		$reflection = new \ReflectionClass(GebruikController::class);
		$controller = $reflection->newInstanceWithoutConstructor();
		$prop       = $reflection->getProperty('gebruikService');
		$prop->setAccessible(true);
		$prop->setValue($controller, $service);

		$method = new \ReflectionMethod($controller, 'applyAanbodScopeToOptions');
		$method->setAccessible(true);
		$result = $method->invoke($controller, $roles, $options);

		return ['result' => $result, 'refused' => $result === null, 'lookups' => $lookups];
	}//end decide()

	/**
	 * Build every case of the decision table.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function table(): array {
		$idSets = ['no-ids' => [], 'ids' => ['app-1', 'app-2', 7]];
		$table  = [];
		foreach (self::roleSets() as $roleKey => $roles) {
			foreach (self::optionSets() as $optionKey => $options) {
				foreach ($idSets as $idKey => $ids) {
					$table[$roleKey . ' ' . $optionKey . ' ' . $idKey] = $this->decide(roles: $roles, options: $options, ids: $ids);
				}
			}
		}

		return $table;
	}//end table()

	/**
	 * The full decision table matches the recorded outcomes.
	 *
	 * @return void
	 */
	public function testDecisionTableMatchesGolden(): void {
		$table = $this->table();
		if (getenv('GOLDEN_WRITE') === '1') {
			$lines = [];
			foreach ($table as $case => $row) {
				$lines[] = json_encode($case) . ': ' . json_encode($row, JSON_UNESCAPED_SLASHES);
			}

			file_put_contents(self::FIXTURE, "{\n" . implode(",\n", $lines) . "\n}\n");
		}

		$golden = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame($golden, json_decode((string) json_encode($table), true));

	}//end testDecisionTableMatchesGolden()

	/**
	 * The table holds both allowed and refused outcomes.
	 *
	 * @return void
	 */
	public function testTableCoversAllowedAndRefused(): void {
		$outcomes = array_count_values(
			array_map(
				fn (array $row): string => ($row['refused'] === true ? 'refused' : 'allowed'),
				$this->table()
			)
		);

		$this->assertGreaterThan(0, ($outcomes['refused'] ?? 0));
		$this->assertGreaterThan(0, ($outcomes['allowed'] ?? 0));

	}//end testTableCoversAllowedAndRefused()
}//end class
