<?php

/**
 * The maintenanceWindow schema and the roadmap statement as the app imports
 * them, and the schema lookup the owner resolution depends on.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/maintenance-and-supplier-roadmap/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Settings;

use OCA\Stackiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Asserts the schema, its lifecycle and notification rules, the roadmap field
 * and the schema id lookup.
 */
class MaintenanceRoadmapFragmentTest extends TestCase {

	/**
	 * The merged register.
	 *
	 * @return array<string, mixed>
	 */
	private function register(): array {
		$dir      = __DIR__ . '/../../../lib/Settings';
		$base     = json_decode((string) file_get_contents($dir . '/softwarecatalogus_register.json'), true);
		$fragment = json_decode((string) file_get_contents($dir . '/register.d/maintenance-and-roadmap.json'), true);

		$merge = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		return $merge->invoke(null, $base, $fragment);
	}//end register()

	/**
	 * The maintenanceWindow schema.
	 *
	 * @return array<string, mixed>
	 */
	private function window(): array {
		return $this->register()['components']['schemas']['maintenanceWindow'];
	}//end window()

	/**
	 * The window names its product, a version of that product, a time window, an impact and a status.
	 *
	 * @return void
	 */
	public function testTheWindowCarriesProductTimeImpactAndStatus(): void {
		$window = $this->window();
		$props  = $window['properties'];

		$this->assertSame(['module', 'title', 'startsAt', 'endsAt'], $window['required']);
		$this->assertSame('#/components/schemas/module', $props['module']['$ref']);
		$this->assertSame('#/components/schemas/moduleVersion', $props['moduleVersion']['$ref']);
		$this->assertSame(['module' => '@object.module'], $props['moduleVersion']['x-relation-filter']);
		$this->assertSame('date-time', $props['startsAt']['format']);
		$this->assertSame('date-time', $props['endsAt']['format']);
		$this->assertSame(['no impact', 'degraded', 'unavailable'], $props['impact']['enum']);
		$this->assertSame(['planned', 'in progress', 'completed', 'cancelled'], $props['status']['enum']);
		$this->assertSame($props['status']['enum'], array_keys($props['status']['x-enum-labels']));
		foreach ($props as $key => $prop) {
			$this->assertNotEmpty($prop['title'] ?? '', $key);
			$this->assertNotEmpty($prop['description'] ?? '', $key);
		}

		$this->assertContains('maintenanceWindow', $this->register()['components']['registers']['stackiq']['schemas']);
	}//end testTheWindowCarriesProductTimeImpactAndStatus()

	/**
	 * Start, complete and cancel move between the status values the enum holds.
	 *
	 * @return void
	 */
	public function testTheLifecycleNamesTheEnumValues(): void {
		$window    = $this->window();
		$lifecycle = $window['configuration']['x-openregister-lifecycle'];
		$enum      = $window['properties']['status']['enum'];

		$this->assertSame('planned', $lifecycle['initial']);
		$this->assertSame(['start', 'complete', 'cancel'], array_keys($lifecycle['transitions']));
		foreach ($lifecycle['transitions'] as $name => $transition) {
			$this->assertContains($transition['to'], $enum, $name);
			foreach ($transition['from'] as $from) {
				$this->assertContains($from, $enum, $name);
			}
		}
	}//end testTheLifecycleNamesTheEnumValues()

	/**
	 * The announcement fires when the owners are recorded, the reminder the day before a planned start, both to the recorded owners.
	 *
	 * @return void
	 */
	public function testTheRulesReachTheRecordedOwners(): void {
		$window = $this->window();
		$rules  = $window['x-openregister-notifications'];

		$this->assertSame(['maintenance-announced', 'maintenance-starts-tomorrow'], array_keys($rules));
		foreach ($rules as $name => $rule) {
			$this->assertSame([['kind' => 'relation', 'relation' => 'notifyUserIds']], $rule['recipients'], $name);
			$this->assertSame(['nc-notification'], $rule['channels'], $name);
			$this->assertStringContainsString('{{title}}', $rule['subject']['en'], $name);
			$this->assertStringContainsString('{{title}}', $rule['subject']['nl'], $name);
		}

		$this->assertSame(
			['type' => 'updated', 'condition' => ['field' => 'recipientsResolvedAt', 'operator' => 'changed']],
			$rules['maintenance-announced']['trigger']
		);
		$this->assertSame('string', $window['properties']['recipientsResolvedAt']['type'], 'a changed condition compares scalars');
		$this->assertSame('array', $window['properties']['notifyUserIds']['type']);
		$this->assertTrue($window['properties']['notifyUserIds']['hideOnForm']);

		$reminder = $rules['maintenance-starts-tomorrow']['trigger'];
		$this->assertSame('scheduled', $reminder['type']);
		$this->assertSame(['operator' => 'withinNext', 'value' => 'P1D'], $reminder['filter']['startsAt']);
		$this->assertSame(['operator' => 'equals', 'value' => 'planned'], $reminder['filter']['status']);
	}//end testTheRulesReachTheRecordedOwners()

	/**
	 * Only the supplier and the catalogue admins announce and change maintenance.
	 *
	 * @return void
	 */
	public function testOnlyTheSupplierAnnouncesMaintenance(): void {
		$auth = $this->window()['authorization'];

		$this->assertSame(['software-catalog-admins', 'aanbod-beheerder'], $auth['create']);
		$this->assertSame(['public'], $auth['read']);
		foreach (['update', 'delete'] as $action) {
			$this->assertSame(
				['software-catalog-admins', ['group' => 'aanbod-beheerder', 'match' => ['_organisation' => '$organisation']]],
				$auth[$action],
				$action
			);
		}
	}//end testOnlyTheSupplierAnnouncesMaintenance()

	/**
	 * A product carries its supplier's roadmap statement, and the module version moves up so the import takes it.
	 *
	 * @return void
	 */
	public function testAProductCarriesARoadmapStatement(): void {
		$module = $this->register()['components']['schemas']['module'];

		$this->assertSame('markdown', $module['properties']['roadmapStatement']['format']);
		$this->assertSame('Roadmap', $module['properties']['roadmapStatement']['title']);
		$this->assertTrue(version_compare($module['version'], '0.3.3', '>'));
		$this->assertArrayHasKey('provider', $module['properties']);
	}//end testAProductCarriesARoadmapStatement()

	/**
	 * The app resolves the maintenanceWindow schema id from the stored config, which the owner resolution relies on.
	 *
	 * @return void
	 */
	public function testTheSchemaIdResolves(): void {
		$store  = ['voorzieningen_config' => json_encode(['register' => '7', 'maintenanceWindow_schema' => '42'])];
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default=''): string => ($store[$key] ?? $default)
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \Exception('not resolvable in a unit context'));

		$settings = new SettingsService(
			config: $config,
			request: $this->createMock(IRequest::class),
			container: $container,
			appManager: $this->createMock(IAppManager::class),
			logger: $this->createMock(LoggerInterface::class),
			groupManager: $this->createMock(IGroupManager::class),
			l10n: $this->createMock(IL10N::class)
		);

		$this->assertSame(42, $settings->getSchemaIdForObjectType('maintenanceWindow'));
		$this->assertSame(7, $settings->getRegisterIdForObjectType('maintenanceWindow'));
	}//end testTheSchemaIdResolves()
}//end class
