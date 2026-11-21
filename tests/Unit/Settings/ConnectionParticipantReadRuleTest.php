<?php

/**
 * The connection schema lets a participant of a shared usage read the connections it lists.
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
 * @spec openspec/changes/shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-the-connections-list-says-with-whom-a-connection-is-shared-req-dlg-011
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Settings;

use OCA\Stackiq\Service\ConnectionShareSync;
use OCA\Stackiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Reads the register as it is imported (monolith plus fragments).
 *
 * @coversNothing
 */
final class ConnectionParticipantReadRuleTest extends TestCase {
	/**
	 * The merged connection schema.
	 *
	 * @return array<string, mixed>
	 */
	private function connection(): array {
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

		return $merged['components']['schemas']['connection'];
	}//end connection()

	/**
	 * Aparticipant manager and reader may read the connection.
	 *
	 * @return void
	 */
	public function testAParticipantManagerAndReaderMayReadTheConnection(): void {
		$read = $this->connection()['authorization']['read'];
		$rules = array_values(
			array_filter($read, static fn (mixed $rule): bool => is_array($rule) && isset($rule['match'][ConnectionShareSync::FIELD]))
		);

		$this->assertSame(expected: ['gebruik-beheerder', 'gebruik-raadpleger'], actual: array_column($rules, 'group'));
		foreach ($rules as $rule) {
			$this->assertSame(expected: ['$contains' => '$organisation'], actual: $rule['match'][ConnectionShareSync::FIELD]);
			$this->assertTrue(condition: $rule['crossOrganisation']);
		}
	}

	/**
	 * The earlier read rules are kept and writes get no participant rule.
	 *
	 * @return void
	 */
	public function testTheEarlierReadRulesAreKeptAndWritesGetNoParticipantRule(): void {
		$authorization = $this->connection()['authorization'];
		$encoded = array_map(static fn (mixed $rule): string => (string)json_encode($rule), $authorization['read']);

		$this->assertContains(needle: '{"group":"gebruik-beheerder","match":{"_organisation":"$organisation"}}', haystack: $encoded);
		$this->assertContains(needle: '{"group":"public","match":{"publicationDate":{"$lte":"$now"}},"crossOrganisation":true}', haystack: $encoded);
		$this->assertContains(
			needle: '{"group":"aanbod-beheerder","match":{"provider":"$organisation"},"crossOrganisation":true}',
			haystack: $encoded
		);
		foreach (['update', 'delete'] as $action) {
			$this->assertStringNotContainsString(
				needle: ConnectionShareSync::FIELD,
				haystack: (string)json_encode($authorization[$action]),
				message: $action
			);
		}
	}

	/**
	 * The field is declared and the version moves so the import applies.
	 *
	 * @return void
	 */
	public function testTheFieldIsDeclaredAndTheVersionMovesSoTheImportApplies(): void {
		$connection = $this->connection();

		$this->assertTrue(condition: version_compare($connection['version'], '0.3.7', '>='));
		$this->assertSame(expected: 'array', actual: $connection['properties'][ConnectionShareSync::FIELD]['type']);
		$this->assertTrue(condition: $connection['properties'][ConnectionShareSync::FIELD]['readOnly']);
	}
}
