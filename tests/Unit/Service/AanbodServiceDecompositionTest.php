<?php

/**
 * Unit tests for the decomposed AanbodService helpers.
 *
 * Covers method-decomposition task 8.3 — extract the polymorphic party
 * id resolver shared by acceptAanbod() and denyAanbod().
 *
 * @category  Test
 * @package   OCA\SoftwareCatalog\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-8-3
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Service;

use OCA\SoftwareCatalog\Service\AanbodService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

/**
 * Tests for the private resolvePartyId helper extracted from
 * AanbodService::acceptAanbod / ::denyAanbod.
 *
 * @category Test
 * @package  OCA\SoftwareCatalog\Tests\Unit\Service
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-8-3
 */
class AanbodServiceDecompositionTest extends TestCase
{

    /**
     * Build the service via reflection (constructor signature varies by
     * branch; reflection lets the test target the helper without coupling
     * to every constructor arg).
     *
     * @return AanbodService
     */
    private function makeService(): AanbodService
    {
        $reflection = new ReflectionClass(AanbodService::class);
        $service    = $reflection->newInstanceWithoutConstructor();

        // Inject just the logger (only collaborator the helper touches).
        $loggerProperty = $reflection->getProperty('logger');
        $loggerProperty->setAccessible(true);
        $loggerProperty->setValue($service, new NullLogger());

        return $service;

    }//end makeService()


    /**
     * resolvePartyId returns the `id` field when given a tuple.
     *
     * @return void
     */
    public function testResolvePartyIdFromTuple(): void
    {
        $service    = $this->makeService();
        $reflection = new \ReflectionMethod($service, 'resolvePartyId');
        $reflection->setAccessible(true);

        $this->assertSame('org-uuid-1', $reflection->invoke($service, ['id' => 'org-uuid-1', 'name' => 'X']));

    }//end testResolvePartyIdFromTuple()


    /**
     * resolvePartyId returns the raw value when given a non-empty string.
     *
     * @return void
     */
    public function testResolvePartyIdFromString(): void
    {
        $service    = $this->makeService();
        $reflection = new \ReflectionMethod($service, 'resolvePartyId');
        $reflection->setAccessible(true);

        $this->assertSame('org-uuid-2', $reflection->invoke($service, 'org-uuid-2'));

    }//end testResolvePartyIdFromString()


    /**
     * resolvePartyId returns null for the supported null / empty / wrong
     * shapes (empty string, null, array without an id key, integer).
     *
     * @return void
     */
    public function testResolvePartyIdNullForUnsupportedShapes(): void
    {
        $service    = $this->makeService();
        $reflection = new \ReflectionMethod($service, 'resolvePartyId');
        $reflection->setAccessible(true);

        $this->assertNull($reflection->invoke($service, null));
        $this->assertNull($reflection->invoke($service, ''));
        $this->assertNull($reflection->invoke($service, ['name' => 'no-id']));
        $this->assertNull($reflection->invoke($service, 42));

    }//end testResolvePartyIdNullForUnsupportedShapes()


}//end class
