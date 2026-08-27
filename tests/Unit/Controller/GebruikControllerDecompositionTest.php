<?php

/**
 * Unit tests for the decomposed GebruikController helpers.
 *
 * Covers method-decomposition task 9.3 — extract `resolveUserRoles()` and
 * `applyAanbodScopeToOptions()` from `getGebruiken()` — and vendor-
 * visibility-rbac REQ-003/REQ-002/REQ-001, which extend
 * `applyAanbodScopeToOptions()` to scope `gebruik-beheerder` reads and to
 * add the `ambtenaar` bypass that was missing from `resolveUserRoles()`.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-9-3
 * @spec openspec/specs/vendor-visibility-rbac/spec.md#requirement-gebruik-beheerder-reads-of-gebruik-objects-must-be-scoped-to-the-caller-s-own-organisation-req-003
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\Stackiq\Controller\GebruikController;
use OCA\Stackiq\Service\GebruikService;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the private helpers extracted from GebruikController.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Controller
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-9-3
 */
class GebruikControllerDecompositionTest extends TestCase {

	/**
	 * Build the controller without invoking the constructor — only the
	 * gebruikService property is needed for the aanbod-scope path.
	 *
	 * @param GebruikService|null $gebruikService Optional service stub.
	 *
	 * @return GebruikController
	 */
	private function makeController(?GebruikService $gebruikService = null): GebruikController {
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
	 * Build a controller with groupManager + config wired, for
	 * resolveUserRoles() reflection tests.
	 *
	 * @param array<int, string> $groupNames The caller's NC group ids.
	 * @param string $orgUuid The caller's active organisation.
	 *
	 * @return GebruikController
	 */
	private function makeControllerWithGroups(array $groupNames, string $orgUuid = ''): GebruikController {
		$reflection = new \ReflectionClass(GebruikController::class);
		$controller = $reflection->newInstanceWithoutConstructor();

		$groups = array_map(
			function (string $name) {
				$group = $this->createMock(IGroup::class);
				$group->method('getGID')->willReturn($name);
				return $group;
			},
			$groupNames
		);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroups')->willReturn($groups);

		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturn($orgUuid);

		$groupManagerProp = $reflection->getProperty('groupManager');
		$groupManagerProp->setAccessible(true);
		$groupManagerProp->setValue($controller, $groupManager);

		$configProp = $reflection->getProperty('config');
		$configProp->setAccessible(true);
		$configProp->setValue($controller, $config);

		return $controller;
	}//end makeControllerWithGroups()

	/**
	 * Admin role bypasses aanbod scoping — options are returned unchanged
	 * and getApplicationIds() is never called.
	 *
	 * @return void
	 */
	public function testAdminBypassesAanbodScope(): void {
		$gebruikService = $this->createMock(GebruikService::class);
		$gebruikService->expects($this->never())->method('getApplicationIds');

		$controller = $this->makeController($gebruikService);
		$reflection = new \ReflectionMethod($controller, 'applyAanbodScopeToOptions');
		$reflection->setAccessible(true);

		$roles = [
			'isAdmin' => true,
			'isBeheerder' => false,
			'isAanbod' => true,
			'orgUuid' => 'org-1',
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
	public function testAanbodWithoutApplicatiesReturnsNull(): void {
		$gebruikService = $this->createMock(GebruikService::class);
		$gebruikService->method('getApplicationIds')->willReturn([]);

		$controller = $this->makeController($gebruikService);
		$reflection = new \ReflectionMethod($controller, 'applyAanbodScopeToOptions');
		$reflection->setAccessible(true);

		$roles = [
			'isAdmin' => false,
			'isBeheerder' => false,
			'isAanbod' => true,
			'orgUuid' => 'org-1',
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
	public function testAanbodWithApplicatiesInjectsModuleFilter(): void {
		$gebruikService = $this->createMock(GebruikService::class);
		$gebruikService->method('getApplicationIds')->willReturn(['app-a', 'app-b']);

		$controller = $this->makeController($gebruikService);
		$reflection = new \ReflectionMethod($controller, 'applyAanbodScopeToOptions');
		$reflection->setAccessible(true);

		$roles = [
			'isAdmin' => false,
			'isBeheerder' => false,
			'isAanbod' => true,
			'orgUuid' => 'org-1',
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
	public function testAanbodWithDisallowedModuleReturnsNull(): void {
		$gebruikService = $this->createMock(GebruikService::class);
		$gebruikService->method('getApplicationIds')->willReturn(['app-a']);

		$controller = $this->makeController($gebruikService);
		$reflection = new \ReflectionMethod($controller, 'applyAanbodScopeToOptions');
		$reflection->setAccessible(true);

		$roles = [
			'isAdmin' => false,
			'isBeheerder' => false,
			'isAanbod' => true,
			'orgUuid' => 'org-1',
		];
		$result = $reflection->invoke($controller, $roles, ['module' => 'forbidden']);

		$this->assertNull($result);

	}//end testAanbodWithDisallowedModuleReturnsNull()

	/**
	 * REQ-003 (vendor-visibility-rbac) regression: a `gebruik-beheerder` with
	 * no other options-supplied filter gets scoped to their own
	 * organisation's `afnemer` relationship — this is the fix for
	 * discovery.md finding 2 (previously: no scoping at all, full
	 * cross-organisation read).
	 *
	 * @return void
	 */
	public function testGebruikBeheerderIsScopedToOwnOrganisationAfnemer(): void {
		$gebruikService = $this->createMock(GebruikService::class);
		$gebruikService->expects($this->never())->method('getApplicationIds');

		$controller = $this->makeController($gebruikService);
		$reflection = new \ReflectionMethod($controller, 'applyAanbodScopeToOptions');
		$reflection->setAccessible(true);

		$roles = [
			'isAdmin' => false,
			'isBeheerder' => true,
			'isAanbod' => false,
			'isAmbtenaar' => false,
			'orgUuid' => 'municipality-a',
		];
		$result = $reflection->invoke($controller, $roles, []);

		$this->assertIsArray($result);
		$this->assertSame('municipality-a', $result['consumer']);

	}//end testGebruikBeheerderIsScopedToOwnOrganisationAfnemer()

	/**
	 * REQ-003 negative test: a `gebruik-beheerder` requesting another
	 * organisation's `afnemer` filter is denied (null → empty result), not
	 * silently widened. Deny-before-grant (REQ-001): getApplicationIds is
	 * never called on this path either.
	 *
	 * @return void
	 */
	public function testGebruikBeheerderCrossOrganisationAfnemerFilterIsDenied(): void {
		$gebruikService = $this->createMock(GebruikService::class);
		$gebruikService->expects($this->never())->method('getApplicationIds');

		$controller = $this->makeController($gebruikService);
		$reflection = new \ReflectionMethod($controller, 'applyAanbodScopeToOptions');
		$reflection->setAccessible(true);

		$roles = [
			'isAdmin' => false,
			'isBeheerder' => true,
			'isAanbod' => false,
			'isAmbtenaar' => false,
			'orgUuid' => 'municipality-a',
		];
		$result = $reflection->invoke($controller, $roles, ['consumer' => 'municipality-b']);

		$this->assertNull($result);

	}//end testGebruikBeheerderCrossOrganisationAfnemerFilterIsDenied()

	/**
	 * REQ-003 regression: a `gebruik-beheerder` explicitly filtering by
	 * their OWN organisation's afnemer value is unaffected.
	 *
	 * @return void
	 */
	public function testGebruikBeheerderOwnAfnemerFilterIsPreserved(): void {
		$controller = $this->makeController($this->createMock(GebruikService::class));
		$reflection = new \ReflectionMethod($controller, 'applyAanbodScopeToOptions');
		$reflection->setAccessible(true);

		$roles = [
			'isAdmin' => false,
			'isBeheerder' => true,
			'isAanbod' => false,
			'isAmbtenaar' => false,
			'orgUuid' => 'municipality-a',
		];
		$result = $reflection->invoke($controller, $roles, ['consumer' => 'municipality-a']);

		$this->assertSame(['consumer' => 'municipality-a'], $result);

	}//end testGebruikBeheerderOwnAfnemerFilterIsPreserved()

	/**
	 * REQ-003 regression scenario ("ambtenaar retains the existing
	 * unrestricted read"): `ambtenaar` bypasses scoping exactly like admin,
	 * even without gebruik-beheerder/aanbod-beheerder/admin membership.
	 *
	 * @return void
	 */
	public function testAmbtenaarBypassesAllScoping(): void {
		$gebruikService = $this->createMock(GebruikService::class);
		$gebruikService->expects($this->never())->method('getApplicationIds');

		$controller = $this->makeController($gebruikService);
		$reflection = new \ReflectionMethod($controller, 'applyAanbodScopeToOptions');
		$reflection->setAccessible(true);

		$roles = [
			'isAdmin' => false,
			'isBeheerder' => false,
			'isAanbod' => false,
			'isAmbtenaar' => true,
			'orgUuid' => 'org-1',
		];
		$result = $reflection->invoke($controller, $roles, ['x' => 1]);

		$this->assertSame(['x' => 1], $result);

	}//end testAmbtenaarBypassesAllScoping()

	/**
	 * REQ-003: a caller who is BOTH aanbod-beheerder and gebruik-beheerder
	 * (not admin/ambtenaar) is scoped as gebruik-beheerder — the pre-fix
	 * code treated any isBeheerder===true as "skip scoping entirely",
	 * which is exactly the leaked path. This asserts scoping is applied,
	 * not bypassed, when both flags are set.
	 *
	 * @return void
	 */
	public function testDualRoleWithoutAdminOrAmbtenaarIsStillScoped(): void {
		$gebruikService = $this->createMock(GebruikService::class);
		$gebruikService->expects($this->never())->method('getApplicationIds');

		$controller = $this->makeController($gebruikService);
		$reflection = new \ReflectionMethod($controller, 'applyAanbodScopeToOptions');
		$reflection->setAccessible(true);

		$roles = [
			'isAdmin' => false,
			'isBeheerder' => true,
			'isAanbod' => true,
			'isAmbtenaar' => false,
			'orgUuid' => 'org-1',
		];
		$result = $reflection->invoke($controller, $roles, []);

		$this->assertIsArray($result);
		$this->assertSame('org-1', $result['consumer']);

	}//end testDualRoleWithoutAdminOrAmbtenaarIsStillScoped()

	/**
	 * resolveUserRoles(): a user in ONLY the `ambtenaar` group (not admin,
	 * not gebruik-beheerder, not aanbod-beheerder) resolves `isAmbtenaar`
	 * true and `hasAccess` true. Before this change, resolveUserRoles() did
	 * not check the `ambtenaar` group at all, so a pure-ambtenaar caller
	 * failed `hasAccess` and got the empty envelope — a functional
	 * regression relative to every other "sees everything" path in this
	 * codebase (getAllGebruiksForAmbtenaar et al.), fixed as part of
	 * REQ-003.
	 *
	 * @return void
	 */
	public function testResolveUserRolesRecognisesAmbtenaarGroup(): void {
		$controller = $this->makeControllerWithGroups(['ambtenaar'], 'org-1');
		$reflection = new \ReflectionMethod($controller, 'resolveUserRoles');
		$reflection->setAccessible(true);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('user-1');
		$result = $reflection->invoke($controller, $user);

		$this->assertTrue($result['isAmbtenaar']);
		$this->assertFalse($result['isAdmin']);
		$this->assertFalse($result['isBeheerder']);
		$this->assertFalse($result['isAanbod']);
		$this->assertTrue($result['hasAccess']);

	}//end testResolveUserRolesRecognisesAmbtenaarGroup()

	/**
	 * resolveUserRoles(): a user with none of admin/gebruik-beheerder/
	 * aanbod-beheerder/ambtenaar has hasAccess === false (fail closed —
	 * unchanged baseline behaviour).
	 *
	 * @return void
	 */
	public function testResolveUserRolesDeniesUnrelatedGroup(): void {
		$controller = $this->makeControllerWithGroups(['some-other-group'], 'org-1');
		$reflection = new \ReflectionMethod($controller, 'resolveUserRoles');
		$reflection->setAccessible(true);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('user-1');
		$result = $reflection->invoke($controller, $user);

		$this->assertFalse($result['hasAccess']);

	}//end testResolveUserRolesDeniesUnrelatedGroup()

}//end class
