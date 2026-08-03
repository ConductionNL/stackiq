<?php
/**
 * SPDX-FileCopyrightText: 2026 Conduction b.v. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Controller;

use OCA\SoftwareCatalog\Controller\SettingsController;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * Unit tests for the W30 SettingsController decomposition helpers
 * (buildConfigReadErrorResponse / buildConfigWriteErrorResponse).
 *
 * These target the two intent-named entry points the controller actually
 * calls, rather than the shared private core — the read/write distinction
 * (whether the redacted request params reach the log) is the behaviour under
 * test, and it now lives in the choice of entry point.
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-3
 */
class SettingsControllerDecompositionTest extends TestCase
{
    private function makeController(LoggerInterface $logger): SettingsController
    {
        $reflection = new ReflectionClass(SettingsController::class);
        /** @var SettingsController $controller */
        $controller = $reflection->newInstanceWithoutConstructor();

        $loggerProp = $reflection->getProperty('logger');
        $loggerProp->setAccessible(true);
        $loggerProp->setValue($controller, $logger);

        return $controller;
    }

    private function call(SettingsController $controller, string $method, string $label, \Exception $e)
    {
        $ref = new ReflectionMethod($controller, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($controller, [$label, $e]);
    }

    public function testErrorResponseReturnsStatus500AndCanonicalShape(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with(
            'Failed to get sync config',
            $this->callback(fn ($ctx) => isset($ctx['exception']) && !isset($ctx['requestData']))
        );

        $controller = $this->makeController($logger);
        $response = $this->call(
            $controller,
            'buildConfigReadErrorResponse',
            'get sync config',
            new \RuntimeException('boom')
        );

        $this->assertSame(500, $response->getStatus());
        $data = $response->getData();
        $this->assertFalse($data['success']);
        $this->assertSame('Failed to get sync config: boom', $data['message']);
    }

    public function testErrorResponseIncludesRedactedParamsForMutatingEndpoints(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with(
            'Failed to update general config',
            $this->callback(fn ($ctx) => array_key_exists('requestData', $ctx))
        );

        $reflection = new ReflectionClass(SettingsController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $loggerProp = $reflection->getProperty('logger');
        $loggerProp->setAccessible(true);
        $loggerProp->setValue($controller, $logger);

        // Stub a minimal request that returns empty params to keep
        // getRedactedParams() happy.
        $request = $this->createMock(\OCP\IRequest::class);
        $request->method('getParams')->willReturn([]);
        $reqProp = $reflection->getProperty('request');
        $reqProp->setAccessible(true);
        $reqProp->setValue($controller, $request);

        $ref = new ReflectionMethod($controller, 'buildConfigWriteErrorResponse');
        $ref->setAccessible(true);
        $response = $ref->invokeArgs($controller, ['update general config', new \LogicException('nope')]);

        $this->assertSame(500, $response->getStatus());
        $this->assertSame(
            'Failed to update general config: nope',
            $response->getData()['message']
        );
    }
}
