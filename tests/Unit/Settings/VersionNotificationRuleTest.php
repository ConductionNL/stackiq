<?php

/**
 * The module-version-published rule, checked against the register as the import sees it (REQ-CLR-003).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/softwarecatalog-notifications/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Settings;

use OCA\OpenRegister\Notifications\Service\Notification\RecipientResolverInterface;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\UsageOwnerRecipientResolver;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * A published version alerts the owners of every usage of its application.
 *
 * @coversNothing
 */
final class VersionNotificationRuleTest extends TestCase {

	/**
	 * The merged moduleVersion schema, merged as SettingsService::loadSettings does.
	 *
	 * @return array<string, mixed>
	 */
	private static function schema(): array {
		$dir = __DIR__ . '/../../../lib/Settings';
		$register = json_decode((string) file_get_contents($dir . '/softwarecatalogus_register.json'), true);
		$fragments = glob($dir . '/register.d/*.json');
		sort($fragments);
		$merge = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$keep  = new ReflectionMethod(SettingsService::class, 'keepHighestSchemaVersions');
		foreach ($fragments as $fragment) {
			$register = $keep->invoke(null, $register, $merge->invoke(null, $register, json_decode((string) file_get_contents($fragment), true)));
		}

		return $register['components']['schemas']['moduleVersion'];
	}//end schema()

	/**
	 * The rule.
	 *
	 * @return array<string, mixed>
	 */
	private static function rule(): array {
		return self::schema()['x-openregister-notifications']['module-version-published'];
	}//end rule()

	public function testTheOwnersOfTheUsagesAreRecipients(): void {
		$resolvers = [];
		foreach (self::rule()['recipients'] as $entry) {
			if (($entry['kind'] ?? '') === 'expression') {
				$resolvers[] = $entry['resolver'] ?? '';
			}
		}

		$this->assertSame([UsageOwnerRecipientResolver::class], $resolvers);
		$this->assertTrue(is_subclass_of(UsageOwnerRecipientResolver::class, RecipientResolverInterface::class));
	}

	public function testTheResolverReadsTheApplicationFromAFieldThisSchemaHas(): void {
		$property = self::schema()['properties']['module'] ?? null;

		$this->assertIsArray($property);
		$this->assertContains('module', UsageOwnerRecipientResolver::APPLICATION_FIELDS);
		$ref = $property['$ref'] ?? ($property['items']['$ref'] ?? null);
		$this->assertSame('#/components/schemas/module', $ref);
	}

	public function testTheRuleStillFiresOnCreate(): void {
		$rule = self::rule();

		$this->assertTrue($rule['enabled']);
		$this->assertSame(['type' => 'created'], $rule['trigger']);
	}

	public function testEverySubjectPlaceholderNamesAPropertyOfTheSchema(): void {
		$properties = array_keys(self::schema()['properties']);
		foreach (self::rule()['subject'] as $language => $subject) {
			preg_match_all('/\{\{\s*([\w.]+)\s*\}\}/', $subject, $matches);
			$this->assertNotSame([], $matches[1], "the $language subject names a field");
			foreach ($matches[1] as $placeholder) {
				$this->assertContains(explode('.', $placeholder)[0], $properties, "$language subject placeholder {$placeholder}");
			}
		}
	}
}
