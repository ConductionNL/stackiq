<?php

/**
 * Unit tests for the decomposed GebruikController helpers.
 *
 * Covers method-decomposition task 9.3 — extract `resolveUserRoles()` and
 * `applyAanbodScopeToOptions()` from `getGebruiken()`.
 *
 * @category  Test
 * @package   OCA\SoftwareCatalog\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-9-3
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Controller;

use OCA\SoftwareCatalog\Controller\GebruikController;
use OCA\SoftwareCatalog\Service\GebruikService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the private helpers extracted from GebruikController.
 *
 * @category Test
 * @package  OCA\SoftwareCatalog\Tests\Unit\Controller
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-9-3
 */
class GebruikControllerDecompositionTest extends TestCase
{

    /**
     * Build the controller without invoking the constructor — only the
     * gebruikService property is needed for the aanbod-scope path.
     *
     * @param GebruikService|null $gebruikService Optional service stub.
     *
     * @return GebruikController
     */
    private function makeController(?GebruikService $gebruikService=null): GebruikController
    {
        $reflection = new \ReflectionClass(GebruikController::class);
        $controller = $reflection->newInstanceWithoutConstructor();

        if ($gebruikService !== null) {
            $prop = $reflection->getProperty('gebruikService');
            $prop->setAccessible(true);
            $prop->setValue($controller, $gebruikService);
        }

        return $controller;

    }//end makeController()


    /**
     * Admin role bypasses aanbod scoping — options are returned unchanged
     * and getApplicationIds() is never called.
     *
     * @return void
     */
    public function testAdminBypassesAanbodScope(): void
    {
        $gebruikService = $this->createMock(GebruikService::class);
        $gebruikService->expects($this->never())->method('getApplicationIds');

        $controller = $this->makeController($gebruikService);
        $reflection = new \ReflectionMethod($controller, 'applyAanbodScopeToOptions');
        $reflection->setAccessible(true);

        $roles  = [
            'isAdmin'     => true,
            'isBeheerder' => false,
            'isAanbod'    => true,
            'orgUuid'     => 'org-1',
        ];
        $result = $reflection->invoke($controller, $roles, ['x' => 1]);

        $this->assertSame(['x' => 1], $result);

    }//end testAdminBypassesAanbodScope()


    /**
     * Pure aanbod-beheerder with no applicaties → caller should render the
     * empty result (helper returns null).
     *
     * @return void
     */
    public function testAanbodWithoutApplicatiesReturnsNull(): void
    {
        $gebruikService = $this->createMock(GebruikService::class);
        $gebruikService->method('getApplicationIds')->willReturn([]);

        $controller = $this->makeController($gebruikService);
        $reflection = new \ReflectionMethod($controller, 'applyAanbodScopeToOptions');
        $reflection->setAccessible(true);

        $roles  = [
            'isAdmin'     => false,
            'isBeheerder' => false,
            'isAanbod'    => true,
            'orgUuid'     => 'org-1',
        ];
        $result = $reflection->invoke($controller, $roles, []);

        $this->assertNull($result);

    }//end testAanbodWithoutApplicatiesReturnsNull()


    /**
     * Pure aanbod-beheerder with applicaties + no module filter → the
     * module filter is auto-applied from the user's applicaties.
     *
     * @return void
     */
    public function testAanbodWithApplicatiesInjectsModuleFilter(): void
    {
        $gebruikService = $this->createMock(GebruikService::class);
        $gebruikService->method('getApplicationIds')->willReturn(['app-a', 'app-b']);

        $controller = $this->makeController($gebruikService);
        $reflection = new \ReflectionMethod($controller, 'applyAanbodScopeToOptions');
        $reflection->setAccessible(true);

        $roles  = [
            'isAdmin'     => false,
            'isBeheerder' => false,
            'isAanbod'    => true,
            'orgUuid'     => 'org-1',
        ];
        $result = $reflection->invoke($controller, $roles, []);

        $this->assertIsArray($result);
        $this->assertSame(['app-a', 'app-b'], $result['module']);

    }//end testAanbodWithApplicatiesInjectsModuleFilter()


    /**
     * Pure aanbod-beheerder requesting a module they don't own → null.
     *
     * @return void
     */
    public function testAanbodWithDisallowedModuleReturnsNull(): void
    {
        $gebruikService = $this->createMock(GebruikService::class);
        $gebruikService->method('getApplicationIds')->willReturn(['app-a']);

        $controller = $this->makeController($gebruikService);
        $reflection = new \ReflectionMethod($controller, 'applyAanbodScopeToOptions');
        $reflection->setAccessible(true);

        $roles  = [
            'isAdmin'     => false,
            'isBeheerder' => false,
            'isAanbod'    => true,
            'orgUuid'     => 'org-1',
        ];
        $result = $reflection->invoke($controller, $roles, ['module' => 'forbidden']);

        $this->assertNull($result);

    }//end testAanbodWithDisallowedModuleReturnsNull()


}//end class
