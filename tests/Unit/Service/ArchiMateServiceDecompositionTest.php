<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction b.v. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Service;

use OCA\SoftwareCatalog\Service\ArchiMateService;
use OCA\SoftwareCatalog\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Unit tests for the W30 ArchiMateService decomposition helper
 * (resolveOrgRegisterAndSchema).
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-4
 */
class ArchiMateServiceDecompositionTest extends TestCase {
	private function makeService(SettingsService $settings): ArchiMateService {
		$reflection = new ReflectionClass(ArchiMateService::class);
		/** @var ArchiMateService $svc */
		$svc = $reflection->newInstanceWithoutConstructor();
		$prop = $reflection->getProperty('settingsService');
		$prop->setAccessible(true);
		$prop->setValue($svc, $settings);
		return $svc;
	}

	private function call(ArchiMateService $svc, string $method, array $args) {
		$ref = new ReflectionMethod($svc, $method);
		$ref->setAccessible(true);
		return $ref->invokeArgs($svc, $args);
	}

	public function testResolveOrgRegisterAndSchemaPrefersVoorzieningenKeys(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->expects($this->never())->method('getVoorzieningenRegisterId');
		$settings->expects($this->never())->method('getSchemaIdForObjectType');

		$svc = $this->makeService($settings);
		$config = ['register' => 'reg-1', 'organisatie_schema' => 'sch-org'];
		$result = $this->call($svc, 'resolveOrgRegisterAndSchema', [$config]);

		$this->assertSame(['reg-1', 'sch-org'], $result);
	}

	public function testResolveOrgRegisterAndSchemaFallsBackWhenRegisterEmpty(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getVoorzieningenRegisterId')->willReturn(99);
		$settings->method('getSchemaIdForObjectType')->willReturn(88);

		$svc = $this->makeService($settings);
		$config = ['register' => '', 'organisatie_schema' => 'sch-org'];
		$result = $this->call($svc, 'resolveOrgRegisterAndSchema', [$config]);

		$this->assertSame([99, 88], $result);
	}

	public function testResolveOrgRegisterAndSchemaFallsBackWhenSchemaMissing(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getVoorzieningenRegisterId')->willReturn(99);
		$settings->method('getSchemaIdForObjectType')->willReturn(88);

		$svc = $this->makeService($settings);
		$config = ['register' => 'reg-1']; // organisatie_schema missing
		$result = $this->call($svc, 'resolveOrgRegisterAndSchema', [$config]);

		$this->assertSame([99, 88], $result);
	}

	public function testResolveOrgRegisterAndSchemaReturnsBothNullWhenNothingResolves(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getVoorzieningenRegisterId')->willReturn(null);
		$settings->method('getSchemaIdForObjectType')->willReturn(null);

		$svc = $this->makeService($settings);
		$result = $this->call($svc, 'resolveOrgRegisterAndSchema', [[]]);

		$this->assertSame([null, null], $result);
	}
}
