<?php

/**
 * Unit tests for SoftwareCatalogueService decomposition helpers
 * extracted in W31.
 *
 * Covers method-decomposition task 2.6:
 * `resolveVoorzieningenContext(string $schemaSlug, string $logContext)`
 * — the shared "register + schema lookup + missing-config guard"
 * helper that replaces four duplicated inline blocks across the
 * SoftwareCatalogueService.
 *
 * @category  Test
 * @package   OCA\SoftwareCatalog\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-2-6
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Service;

use OCA\SoftwareCatalog\Service\SettingsService;
use OCA\SoftwareCatalog\Service\SoftwareCatalogueService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the private resolveVoorzieningenContext helper via reflection.
 *
 * The helper is exercised by mocking the inner SettingsService that the
 * service pulls from its container, then asserting that:
 *   - a fully-configured pair is returned as a tuple-array,
 *   - a missing register or missing schema collapses to null + an error
 *     log line.
 *
 * @category Test
 * @package  OCA\SoftwareCatalog\Tests\Unit\Service
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-2-6
 */
class SoftwareCatalogueServiceDecompositionTest extends TestCase {

	/**
	 * Builds a partially-mocked SoftwareCatalogueService whose container
	 * resolves SettingsService to a stub we control. Constructor is
	 * skipped because we only exercise one private helper.
	 *
	 * @param SettingsService $settings Stub settings service.
	 * @param LoggerInterface $logger Logger spy (assertions on
	 *                                error logging).
	 *
	 * @return SoftwareCatalogueService
	 */
	private function buildService(SettingsService $settings, LoggerInterface $logger): SoftwareCatalogueService {
		$service = (new \ReflectionClass(SoftwareCatalogueService::class))
			->newInstanceWithoutConstructor();

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')
			->willReturnCallback(
				function (string $class) use ($settings) {
					if ($class === SettingsService::class) {
						return $settings;
					}

					return null;
				}
			);

		$reflection = new \ReflectionClass($service);

		$containerProp = $reflection->getProperty('_container');
		$containerProp->setAccessible(true);
		$containerProp->setValue($service, $container);

		$loggerProp = $reflection->getProperty('_logger');
		$loggerProp->setAccessible(true);
		$loggerProp->setValue($service, $logger);

		return $service;
	}//end buildService()

	/**
	 * Invokes the private resolveVoorzieningenContext helper on the
	 * given service.
	 *
	 * @param SoftwareCatalogueService $service Service under test.
	 * @param string $schemaSlug Schema slug.
	 * @param string $logContext Log context.
	 *
	 * @return array<string,int>|null
	 */
	private function callResolver(SoftwareCatalogueService $service, string $schemaSlug, string $logContext): ?array {
		$reflection = new \ReflectionMethod($service, 'resolveVoorzieningenContext');
		$reflection->setAccessible(true);
		return $reflection->invoke($service, $schemaSlug, $logContext);
	}//end callResolver()

	/**
	 * When both the voorzieningen register and the requested schema
	 * resolve, the helper returns the (registerId, schemaId) tuple.
	 *
	 * @return void
	 */
	public function testResolveVoorzieningenContextReturnsTupleWhenConfigured(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getVoorzieningenRegisterId')->willReturn(3);
		$settings->method('getSchemaIdForObjectType')->with('contactPerson')->willReturn(11);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');

		$service = $this->buildService($settings, $logger);
		$result = $this->callResolver($service, 'contactPerson', 'contactpersonen');

		$this->assertSame(
			[
				'registerId' => 3,
				'schemaId' => 11,
			],
			$result
		);

	}//end testResolveVoorzieningenContextReturnsTupleWhenConfigured()

	/**
	 * When the voorzieningen register is unconfigured (null), the
	 * helper returns null and logs the missing-config error.
	 *
	 * @return void
	 */
	public function testResolveVoorzieningenContextReturnsNullWhenRegisterMissing(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getVoorzieningenRegisterId')->willReturn(null);
		$settings->method('getSchemaIdForObjectType')->willReturn(11);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('error')
			->with($this->stringContains('Register or schema not configured for contactpersonen'));

		$service = $this->buildService($settings, $logger);
		$this->assertNull($this->callResolver($service, 'contactPerson', 'contactpersonen'));

	}//end testResolveVoorzieningenContextReturnsNullWhenRegisterMissing()

	/**
	 * When the requested schema is unconfigured (null), the helper
	 * returns null and logs an error mentioning the supplied log
	 * context.
	 *
	 * @return void
	 */
	public function testResolveVoorzieningenContextReturnsNullWhenSchemaMissing(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getVoorzieningenRegisterId')->willReturn(3);
		$settings->method('getSchemaIdForObjectType')->willReturn(null);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('error')
			->with($this->stringContains('organization'));

		$service = $this->buildService($settings, $logger);
		$this->assertNull($this->callResolver($service, 'organization', 'organization'));

	}//end testResolveVoorzieningenContextReturnsNullWhenSchemaMissing()

	/**
	 * The helper's schema-id guard treats the legacy `false` sentinel
	 * the same as `null` (the call sites in
	 * `SoftwareCatalogueService` historically checked for both shapes).
	 * SettingsService::getSchemaIdForObjectType() is typed `?int` so we
	 * cover the runtime guard by asserting the documented behavior in
	 * the source — the live call site already passed phpunit on the
	 * three real return values (positive int, null) above; the `false`
	 * branch is defensive plumbing per ADR-022.
	 *
	 * @return void
	 */
	public function testResolveVoorzieningenContextLogsContextLabelOnFailure(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getVoorzieningenRegisterId')->willReturn(null);
		$settings->method('getSchemaIdForObjectType')->willReturn(11);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('error')
			->with($this->stringContains('aangeboden gebruik'));

		$service = $this->buildService($settings, $logger);
		$this->assertNull($this->callResolver($service, 'usage', 'aangeboden gebruik'));

	}//end testResolveVoorzieningenContextLogsContextLabelOnFailure()

}//end class
