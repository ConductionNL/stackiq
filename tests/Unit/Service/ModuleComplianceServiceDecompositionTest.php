<?php

/**
 * Unit tests for the decomposed ModuleComplianceService helpers.
 *
 * Covers method-decomposition task 8.2 — extract
 * `normaliseCurrentStandaarden()` + `syncStandaarden()` from
 * `handleModuleComplianceUpdate()`.
 *
 * @category  Test
 * @package   OCA\SoftwareCatalog\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-8-2
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Service;

use OCA\SoftwareCatalog\Service\ModuleComplianceService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the private helpers extracted from ModuleComplianceService.
 *
 * @category Test
 * @package  OCA\SoftwareCatalog\Tests\Unit\Service
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-8-2
 */
class ModuleComplianceServiceDecompositionTest extends TestCase
{

    /**
     * Build a service without invoking the constructor — the helper under
     * test does not touch any collaborator.
     *
     * @return ModuleComplianceService
     */
    private function makeService(): ModuleComplianceService
    {
        $reflection = new \ReflectionClass(ModuleComplianceService::class);
        return $reflection->newInstanceWithoutConstructor();

    }//end makeService()


    /**
     * normaliseCurrentStandaarden returns the array unchanged when it is
     * already an array.
     *
     * @return void
     */
    public function testNormaliseReturnsArrayUnchanged(): void
    {
        $service    = $this->makeService();
        $reflection = new \ReflectionMethod($service, 'normaliseCurrentStandaarden');
        $reflection->setAccessible(true);

        $this->assertSame(
            ['a', 'b'],
            $reflection->invoke($service, ['a', 'b'])
        );
        $this->assertSame([], $reflection->invoke($service, []));

    }//end testNormaliseReturnsArrayUnchanged()


    /**
     * normaliseCurrentStandaarden coerces non-array values to an empty
     * array, matching the legacy guard clause it replaces.
     *
     * @return void
     */
    public function testNormaliseCoercesNonArrayToEmpty(): void
    {
        $service    = $this->makeService();
        $reflection = new \ReflectionMethod($service, 'normaliseCurrentStandaarden');
        $reflection->setAccessible(true);

        $this->assertSame([], $reflection->invoke($service, null));
        $this->assertSame([], $reflection->invoke($service, 'not-an-array'));
        $this->assertSame([], $reflection->invoke($service, 42));

    }//end testNormaliseCoercesNonArrayToEmpty()


    /**
     * The extracted helpers exist with the documented visibility.
     *
     * @return void
     */
    public function testExtractedHelpersExistAndArePrivate(): void
    {
        $reflection = new \ReflectionClass(ModuleComplianceService::class);

        foreach (['normaliseCurrentStandaarden', 'syncStandaarden'] as $method) {
            $this->assertTrue(
                $reflection->hasMethod($method),
                sprintf('Expected helper %s() on ModuleComplianceService', $method)
            );
            $this->assertTrue(
                $reflection->getMethod($method)->isPrivate(),
                sprintf('Helper %s() must remain private', $method)
            );
        }

    }//end testExtractedHelpersExistAndArePrivate()


}//end class
