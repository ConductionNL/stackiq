<?php

/**
 * Unit tests for the decomposed OrganizationSyncService helpers.
 *
 * Covers method-decomposition task 7.1 — extract `handleSyncError()` as a
 * centralised error-handling sink replacing the ad-hoc catch blocks across
 * the sync pipeline methods.
 *
 * @category  Test
 * @package   OCA\SoftwareCatalog\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-7-1
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Service;

use OCA\SoftwareCatalog\Service\OrganizationSyncService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the private helpers extracted from OrganizationSyncService.
 *
 * @category Test
 * @package  OCA\SoftwareCatalog\Tests\Unit\Service
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-7-1
 */
class OrganizationSyncServiceDecompositionTest extends TestCase
{

    /**
     * Build a service without invoking the constructor — the helper under
     * test only reads the `logger` property, so wiring a full constructor
     * (which requires Doctrine for IDBConnection) is unnecessary.
     *
     * @param LoggerInterface $logger A real or stub logger.
     *
     * @return OrganizationSyncService
     */
    private function makeService(LoggerInterface $logger): OrganizationSyncService
    {
        $reflection = new \ReflectionClass(OrganizationSyncService::class);
        $service    = $reflection->newInstanceWithoutConstructor();

        $loggerProp = $reflection->getProperty('logger');
        $loggerProp->setAccessible(true);
        $loggerProp->setValue($service, $logger);

        return $service;

    }//end makeService()


    /**
     * handleSyncError appends a uniformly-shaped entry to stats['errors']
     * and emits a log line via the injected logger.
     *
     * @return void
     */
    public function testHandleSyncErrorAppendsToStatsAndLogs(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('OrganizationSync'),
                $this->arrayHasKey('error')
            );

        $service    = $this->makeService($logger);
        $reflection = new \ReflectionMethod($service, 'handleSyncError');
        $reflection->setAccessible(true);

        $stats = ['errors' => []];
        $reflection->invokeArgs(
            $service,
            ['OrganizationSync', 'uuid-123', new \RuntimeException('boom'), &$stats]
        );

        $this->assertCount(1, $stats['errors']);
        $this->assertSame('uuid-123: boom', $stats['errors'][0]);

    }//end testHandleSyncErrorAppendsToStatsAndLogs()


    /**
     * handleSyncError does not crash when stats has no errors[] key —
     * it still logs the failure.
     *
     * @return void
     */
    public function testHandleSyncErrorSurvivesMissingErrorsKey(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $service    = $this->makeService($logger);
        $reflection = new \ReflectionMethod($service, 'handleSyncError');
        $reflection->setAccessible(true);

        $stats = [];
        $reflection->invokeArgs(
            $service,
            ['ContactSync', 'user-7', new \LogicException('nope'), &$stats]
        );

        $this->assertArrayNotHasKey('errors', $stats);

    }//end testHandleSyncErrorSurvivesMissingErrorsKey()


}//end class
