<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction b.v. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Db\Organisation;
use OCA\Stackiq\Service\Settings\CatalogueLocator;
use OCA\Stackiq\Service\SettingsService;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * Covers the thin SettingsService members that forward to the CatalogueLocator
 * and IAppConfig, the step-result accumulator that records a failure or logs a
 * success, and the organisation-type classifier that maps a name to a type.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */
#[CoversClass(SettingsService::class)]
class SettingsServiceDelegationTest extends TestCase {
	/**
	 * Build the service without its constructor and set only the given
	 * properties.
	 *
	 * @param array<string, mixed> $props Property name → value.
	 *
	 * @return SettingsService
	 */
	private function makeService(array $props): SettingsService {
		$reflection = new ReflectionClass(SettingsService::class);
		$service    = $reflection->newInstanceWithoutConstructor();
		foreach ($props as $name => $value) {
			$property = $reflection->getProperty($name);
			$property->setAccessible(true);
			$property->setValue($service, $value);
		}
		return $service;
	}

	/**
	 * Invoke a private method by name.
	 *
	 * @param SettingsService $service The service.
	 * @param string          $method  The method name.
	 * @param array           $args    Arguments (by value).
	 *
	 * @return mixed
	 */
	private function call(SettingsService $service, string $method, array $args) {
		$ref = new ReflectionMethod($service, $method);
		$ref->setAccessible(true);
		return $ref->invokeArgs($service, $args);
	}

	public function testOpenRegisterQueriesForwardToTheCatalogueLocator(): void {
		$catalogue = $this->createMock(CatalogueLocator::class);
		$catalogue->method('isOpenRegisterInstalled')->with('9.9.9')->willReturn(true);
		$catalogue->method('getVoorzieningenRegisterId')->willReturn(42);

		$service = $this->makeService(['catalogue' => $catalogue]);

		$this->assertTrue($service->isOpenRegisterInstalled('9.9.9'));
		$this->assertSame(42, $service->getVoorzieningenRegisterId());
	}

	public function testSetAmefConfigAndClearCacheForwardToTheCatalogueLocator(): void {
		$catalogue = $this->createMock(CatalogueLocator::class);
		$catalogue->expects($this->once())->method('setAmefConfig')->with(['element' => 7]);
		$catalogue->expects($this->once())->method('clearConfigurationCache');

		$service = $this->makeService(['catalogue' => $catalogue]);

		$service->setAmefConfig(['element' => 7]);
		$service->clearConfigurationCache();
	}

	public function testSetCatalogLocationWritesTheAppConfigString(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->once())->method('setValueString')
			->with('stackiq', 'catalog_location', 'https://catalog.example.com');

		$service = $this->makeService(['config' => $config, 'appName' => 'stackiq']);

		$service->setCatalogLocation('https://catalog.example.com');
	}

	public function testAddStepResultRecordsAFailureWithItsMessage(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');
		$logger->expects($this->never())->method('info');

		$service = $this->makeService(['logger' => $logger]);
		$results = ['errors' => []];
		$this->call($service, 'addStepResult', [&$results, ['success' => false, 'message' => 'no register'], 'Register check']);

		$this->assertSame(['Register check failed: no register'], $results['errors']);
	}

	public function testAddStepResultLogsASuccessWithoutRecordingAnError(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info');
		$logger->expects($this->never())->method('warning');

		$service = $this->makeService(['logger' => $logger]);
		$results = ['errors' => []];
		$this->call($service, 'addStepResult', [&$results, ['success' => true], 'Register check']);

		$this->assertSame([], $results['errors']);
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function organisationNames(): array {
		return [
			'municipality' => ['Gemeente Amsterdam', 'Municipality'],
			'province'     => ['Provincie Utrecht', 'Provincie'],
			'ministry'     => ['Ministerie van BZK', 'Ministerie'],
			'supplier'     => ['Acme Software B.V.', 'Supplier'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('organisationNames')]
	public function testDetermineOrganisationTypeClassifiesByName(string $name, string $expected): void {
		$organisation = new class($name) extends Organisation {
			public function __construct(private string $orgName) {
			}

			public function getName(): string {
				return $this->orgName;
			}
		};

		$service = $this->makeService([]);
		$this->assertSame($expected, $this->call($service, 'determineOrganisationType', [$organisation]));
	}
}
