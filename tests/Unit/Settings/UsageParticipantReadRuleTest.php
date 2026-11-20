<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Settings;

use OCA\Stackiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * REQ-DLG-010: a participant of a usage reads it, and only reads it, in the merged register.
 *
 * @spec openspec/changes/shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-a-participant-reads-the-usages-shared-with-it-and-their-connections-req-dlg-010
 */
final class UsageParticipantReadRuleTest extends TestCase {
	/**
	 * The register as it is imported: the monolith with every fragment merged in name order.
	 *
	 * @return array<string, mixed> The merged register.
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
			$merged = $keep->invoke(null, $before, $merge->invoke(null, $merged, json_decode((string)file_get_contents($file), true)));
		}

		return $merged;
	}//end merged()

	/**
	 * The participant read entries: one per role, matching the caller's organisation in `participants`.
	 *
	 * @return void
	 */
	public function testAParticipantManagerAndReaderMayReadTheUsage(): void {
		$read = $this->merged()['components']['schemas']['usage']['authorization']['read'];
		$participantRules = array_values(array_filter(
			$read,
			static fn (mixed $rule): bool => is_array($rule) && isset($rule['match']['participants'])
		));

		$this->assertSame(expected: ['gebruik-beheerder', 'gebruik-raadpleger'], actual: array_column($participantRules, 'group'));
		foreach ($participantRules as $rule) {
			$this->assertSame(expected: ['$contains' => '$organisation'], actual: $rule['match']['participants']);
			$this->assertTrue(condition: $rule['crossOrganisation'], message: 'a participant is another organisation than the owner');
		}
	}//end testAParticipantManagerAndReaderMayReadTheUsage()

	/**
	 * The owner's and the public read rules survive the restated list.
	 *
	 * @return void
	 */
	public function testTheEarlierReadRulesAreKept(): void {
		$read = $this->merged()['components']['schemas']['usage']['authorization']['read'];
		$encoded = array_map(static fn (mixed $rule): string => (string)json_encode($rule), $read);

		$this->assertContains(needle: '{"group":"gebruik-beheerder","match":{"_organisation":"$organisation"}}', haystack: $encoded);
		$this->assertContains(
			needle: '{"group":"gebruik-beheerder","match":{"consumer":"$organisation"},"crossOrganisation":true}',
			haystack: $encoded
		);
		$this->assertContains(
			needle: '{"group":"aanbod-beheerder","match":{"provider":"$organisation"},"crossOrganisation":true}',
			haystack: $encoded
		);
		$this->assertContains(needle: '{"group":"public","match":{"publicationDate":{"$lte":"$now"}},"crossOrganisation":true}', haystack: $encoded);
	}//end testTheEarlierReadRulesAreKept()

	/**
	 * Being a participant grants no write: update and delete carry no participant rule.
	 *
	 * @return void
	 */
	public function testAParticipantDoesNotChangeOrDeleteTheUsage(): void {
		$authorization = $this->merged()['components']['schemas']['usage']['authorization'];
		foreach (['update', 'delete'] as $action) {
			$this->assertStringNotContainsString(
				needle: 'participants',
				haystack: (string)json_encode($authorization[$action] ?? []),
				message: $action
			);
		}
	}//end testAParticipantDoesNotChangeOrDeleteTheUsage()

	/**
	 * The usage schema's version moves past every earlier fragment's, so an installed instance imports the rules.
	 *
	 * @return void
	 */
	public function testTheVersionMovesSoTheImportApplies(): void {
		$this->assertSame(expected: '1.6.3', actual: $this->merged()['components']['schemas']['usage']['version']);
	}//end testTheVersionMovesSoTheImportApplies()
}//end class
