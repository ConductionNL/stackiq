<?php
/**
 * SPDX-FileCopyrightText: 2026 Conduction b.v. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Service;

use OCA\SoftwareCatalog\Service\AangebodenGebruikService;
use OCA\SoftwareCatalog\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * Unit tests for the W30 AangebodenGebruikService decomposition helper
 * (resolveVoorzieningenSchemaConfig).
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-7
 */
class AangebodenGebruikServiceDecompositionTest extends TestCase
{
    private function makeService(SettingsService $settings, LoggerInterface $logger): AangebodenGebruikService
    {
        $reflection = new ReflectionClass(AangebodenGebruikService::class);
        /** @var AangebodenGebruikService $svc */
        $svc = $reflection->newInstanceWithoutConstructor();

        $settingsProp = $reflection->getProperty('settingsService');
        $settingsProp->setAccessible(true);
        $settingsProp->setValue($svc, $settings);

        $loggerProp = $reflection->getProperty('logger');
        $loggerProp->setAccessible(true);
        $loggerProp->setValue($svc, $logger);

        return $svc;
    }

    private function call(AangebodenGebruikService $svc, string $key, string $label): array
    {
        $ref = new ReflectionMethod($svc, 'resolveVoorzieningenSchemaConfig');
        $ref->setAccessible(true);
        return $ref->invokeArgs($svc, [$key, $label]);
    }

    public function testResolveReturnsTupleWhenConfigComplete(): void
    {
        $settings = $this->createMock(SettingsService::class);
        $settings->method('getVoorzieningenConfig')->willReturn([
            'register'         => 'reg-1',
            'gebruik_schema'   => 'sch-gebruik',
            'koppeling_schema' => 'sch-koppeling',
        ]);

        $svc    = $this->makeService($settings, $this->createMock(LoggerInterface::class));
        $result = $this->call($svc, 'gebruik_schema', 'voorzieningen');

        $this->assertSame(['register_id' => 'reg-1', 'schemas' => ['sch-gebruik']], $result);
    }

    public function testResolvePicksKoppelingSchemaWhenRequested(): void
    {
        $settings = $this->createMock(SettingsService::class);
        $settings->method('getVoorzieningenConfig')->willReturn([
            'register'         => 'reg-1',
            'gebruik_schema'   => 'sch-gebruik',
            'koppeling_schema' => 'sch-koppeling',
        ]);

        $svc    = $this->makeService($settings, $this->createMock(LoggerInterface::class));
        $result = $this->call($svc, 'koppeling_schema', 'koppelingen');

        $this->assertSame(['register_id' => 'reg-1', 'schemas' => ['sch-koppeling']], $result);
    }

    public function testResolveThrowsWhenSchemaMissing(): void
    {
        $settings = $this->createMock(SettingsService::class);
        $settings->method('getVoorzieningenConfig')->willReturn(['register' => 'reg-1']);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('error');

        $svc = $this->makeService($settings, $logger);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Voorzieningen configuration not found');
        $this->call($svc, 'gebruik_schema', 'voorzieningen');
    }

    public function testResolveThrowsWhenSettingsRaises(): void
    {
        $settings = $this->createMock(SettingsService::class);
        $settings->method('getVoorzieningenConfig')->willThrowException(new \RuntimeException('boom'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('warning');
        $logger->expects($this->atLeastOnce())->method('error');

        $svc = $this->makeService($settings, $logger);
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Koppelingen configuration not found');
        $this->call($svc, 'koppeling_schema', 'koppelingen');
    }
}
