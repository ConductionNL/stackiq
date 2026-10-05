<?php

/**
 * The maintenance window's notification fields, merged the way the app merges its register.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/maintenance-and-supplier-roadmap/spec.md#requirement-req-msr-003-the-owners-of-every-usage-are-notified
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Settings;

use OCA\Stackiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Only the catalogue's administrators write the fields the maintenance-announced rule reads.
 */
class MaintenanceRecipientRulesTest extends TestCase {

	/**
	 * The base register with every fragment merged in file name order, as SettingsService::loadSettings does.
	 *
	 * @return array<string, mixed>
	 */
	private function register(): array {
		$deep   = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$keep   = new ReflectionMethod(SettingsService::class, 'keepHighestSchemaVersions');
		$merged = json_decode((string) file_get_contents(__DIR__ . '/../../../lib/Settings/softwarecatalogus_register.json'), true);
		$files  = glob(__DIR__ . '/../../../lib/Settings/register.d/*.json');
		sort($files);
		foreach ($files as $file) {
			$fragment = json_decode((string) file_get_contents($file), true);
			$merged   = $keep->invoke(null, $merged, $deep->invoke(null, $merged, $fragment));
		}

		return $merged;
	}//end register()

	/**
	 * A supplier cannot write the owners to notify or the moment that sends the notice.
	 *
	 * @return void
	 */
	public function testOnlyAdministratorsWriteTheNotificationFields(): void {
		$window = $this->register()['components']['schemas']['maintenanceWindow'];

		foreach (['notifyUserIds', 'recipientsResolvedAt'] as $field) {
			$this->assertArrayHasKey('type', $window['properties'][$field], $field . ' is a real property, not a rule on nothing');
			$this->assertSame(['update' => ['software-catalog-admins']], $window['properties'][$field]['authorization'] ?? null, $field);
		}

		$this->assertSame(
			'recipientsResolvedAt',
			$window['x-openregister-notifications']['maintenance-announced']['trigger']['condition']['field'],
			'the protected field is the one the notice fires on'
		);
		$this->assertSame('notifyUserIds', $window['x-openregister-notifications']['maintenance-announced']['recipients'][0]['relation']);
		$this->assertTrue(version_compare($window['version'], '0.1.1', '>='), 'the schema version is raised so the rule is applied on upgrade');
	}//end testOnlyAdministratorsWriteTheNotificationFields()

	/**
	 * The window's own object rules are unchanged: a supplier still creates and edits its windows.
	 *
	 * @return void
	 */
	public function testTheObjectRulesAreUnchanged(): void {
		$base   = json_decode((string) file_get_contents(__DIR__ . '/../../../lib/Settings/softwarecatalogus_register.json'), true);
		$merged = $this->register();

		$this->assertSame(
			$base['components']['schemas']['maintenanceWindow']['authorization'],
			$merged['components']['schemas']['maintenanceWindow']['authorization']
		);
	}//end testTheObjectRulesAreUnchanged()
}//end class
