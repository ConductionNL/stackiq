<?php

/**
 * Unit tests for SettingsService decomposition helpers extracted in W31.
 *
 * Covers method-decomposition task 1.5: `buildObjectTypeStatusEntry()` —
 * the shared object-type status helper extracted out of
 * `getConfigurationStatus()`.
 *
 * @category  Test
 * @package   OCA\SoftwareCatalog\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-1-5
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Service;

use OCA\SoftwareCatalog\Service\SettingsService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the private decomposition helpers extracted from
 * SettingsService in W31.
 *
 * The helpers under test are pure functions of the public lookup
 * methods (`getSchemaIdForObjectType`, `getRegisterIdForObjectType`);
 * we exercise them via reflection on a partial-mock service so the
 * test does not need a live OpenRegister container.
 *
 * @category Test
 * @package  OCA\SoftwareCatalog\Tests\Unit\Service
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-1-5
 */
class SettingsServiceDecompositionTest extends TestCase {

	/**
	 * When both schema and register lookups resolve to positive ints,
	 * the status entry reports `configured: true` and echoes the IDs.
	 *
	 * @return void
	 */
	public function testBuildObjectTypeStatusEntryReportsConfiguredWhenBothPresent(): void {
		$service = $this->getMockBuilder(SettingsService::class)
			->disableOriginalConstructor()
			->onlyMethods(['getSchemaIdForObjectType', 'getRegisterIdForObjectType'])
			->getMock();

		$service->method('getSchemaIdForObjectType')->with('organization')->willReturn(42);
		$service->method('getRegisterIdForObjectType')->with('organization')->willReturn(7);

		$reflection = new \ReflectionMethod($service, 'buildObjectTypeStatusEntry');
		$reflection->setAccessible(true);
		$entry = $reflection->invoke($service, 'organization');

		$this->assertSame(
			[
				'configured' => true,
				'schemaId' => 42,
				'registerId' => 7,
			],
			$entry
		);

	}//end testBuildObjectTypeStatusEntryReportsConfiguredWhenBothPresent()

	/**
	 * When either lookup returns null, the status entry reports
	 * `configured: false` and propagates the nulls so the caller can
	 * surface "missing schema" vs "missing register" diagnostics.
	 *
	 * @return void
	 */
	public function testBuildObjectTypeStatusEntryReportsUnconfiguredWhenSchemaMissing(): void {
		$service = $this->getMockBuilder(SettingsService::class)
			->disableOriginalConstructor()
			->onlyMethods(['getSchemaIdForObjectType', 'getRegisterIdForObjectType'])
			->getMock();

		$service->method('getSchemaIdForObjectType')->willReturn(null);
		$service->method('getRegisterIdForObjectType')->willReturn(7);

		$reflection = new \ReflectionMethod($service, 'buildObjectTypeStatusEntry');
		$reflection->setAccessible(true);
		$entry = $reflection->invoke($service, 'contactpersoon');

		$this->assertFalse($entry['configured']);
		$this->assertNull($entry['schemaId']);
		$this->assertSame(7, $entry['registerId']);

	}//end testBuildObjectTypeStatusEntryReportsUnconfiguredWhenSchemaMissing()

	/**
	 * getConfigurationStatus uses the new helper to build the
	 * organization + contact entries (regression: legacy behavior
	 * preserved after the extraction).
	 *
	 * @return void
	 */
	public function testGetConfigurationStatusDelegatesToHelperForBothObjectTypes(): void {
		$service = $this->getMockBuilder(SettingsService::class)
			->disableOriginalConstructor()
			->onlyMethods(['getSchemaIdForObjectType', 'getRegisterIdForObjectType', 'getRegisterVerificationStatus'])
			->getMock();

		$service->method('getSchemaIdForObjectType')->willReturnMap(
			[
				['organization', 11],
				['contactpersoon', 12],
			]
		);
		$service->method('getRegisterIdForObjectType')->willReturnMap(
			[
				['organization', 21],
				['contactpersoon', 22],
			]
		);
		$service->method('getRegisterVerificationStatus')->willReturn(
			[
				'ok' => true,
				'checked' => false,
				'missingSchemas' => [],
				'unresolvedObjectTypes' => [],
				'message' => null,
			]
		);

		$status = $service->getConfigurationStatus();

		$this->assertArrayHasKey('organization', $status);
		$this->assertArrayHasKey('contact', $status);
		$this->assertArrayHasKey('registerVerification', $status);
		$this->assertTrue($status['organization']['configured']);
		$this->assertSame(11, $status['organization']['schemaId']);
		$this->assertSame(21, $status['organization']['registerId']);
		$this->assertTrue($status['contact']['configured']);
		$this->assertSame(12, $status['contact']['schemaId']);
		$this->assertSame(22, $status['contact']['registerId']);
		$this->assertTrue($status['registerVerification']['ok']);

	}//end testGetConfigurationStatusDelegatesToHelperForBothObjectTypes()

}//end class
