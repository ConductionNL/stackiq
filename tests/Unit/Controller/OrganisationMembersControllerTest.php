<?php
/**
 * Unit tests for OrganisationMembersController's beheerder-only authorization
 * guard (self-service colleague access, VNG Softwarecatalogus #65).
 *
 * Covers the no-admin-idor gate: grant()/revoke() MUST refuse (403) a caller
 * who is not in the `beheerder` NC group, and MUST refuse (403) a `beheerder`
 * of a DIFFERENT organisation — in both cases MUST NOT reach OpenRegister's
 * OrganisationService::joinOrganisation()/leaveOrganisation(). A grant to a
 * non-existent Nextcloud user MUST be refused (404) without ever reaching
 * joinOrganisation(). An authorized beheerder's grant/revoke MUST succeed
 * and delegate the actual mutation to OpenRegister — no parallel membership
 * store is written.
 *
 * @category  Tests
 * @package   OCA\SoftwareCatalog\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/specs/multi-org-membership/spec.md#requirement-granting-or-revoking-organisation-access-must-be-restricted-to-a-beheerder-of-that-organisation-req-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Controller;

use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\SoftwareCatalog\Controller\OrganisationMembersController;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test class for OrganisationMembersController's beheerder-of-this-org guard.
 */
class OrganisationMembersControllerTest extends TestCase
{

    /**
     * @var IUserSession|MockObject
     */
    private IUserSession|MockObject $userSession;

    /**
     * @var IGroupManager|MockObject
     */
    private IGroupManager|MockObject $groupManager;

    /**
     * @var IUserManager|MockObject
     */
    private IUserManager|MockObject $userManager;

    /**
     * @var ContainerInterface|MockObject
     */
    private ContainerInterface|MockObject $container;

    /**
     * @var OrganisationService|MockObject
     */
    private OrganisationService|MockObject $organisationService;

    /**
     * Build the controller with the current mocks and a logged-in user.
     *
     * @param bool   $isBeheerder    Whether the caller is in the `beheerder` NC group.
     * @param array  $callerOrgUuids Organisation UUIDs the caller belongs to (per OpenRegister).
     * @param string $callerUid      The caller's Nextcloud user id.
     *
     * @return OrganisationMembersController The controller under test.
     */
    private function makeController(
        bool $isBeheerder,
        array $callerOrgUuids,
        string $callerUid = 'caller-uid'
    ): OrganisationMembersController {
        $request = $this->createMock(IRequest::class);

        $this->userSession   = $this->createMock(IUserSession::class);
        $this->groupManager  = $this->createMock(IGroupManager::class);
        $this->userManager   = $this->createMock(IUserManager::class);
        $this->container     = $this->createMock(ContainerInterface::class);
        $this->organisationService = $this->createMock(OrganisationService::class);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($callerUid);
        $this->userSession->method('getUser')->willReturn($user);

        $this->groupManager->method('isInGroup')->with($callerUid, 'beheerder')->willReturn($isBeheerder);

        $callerOrgs = array_map(
            function (string $uuid): Organisation {
                $org = new Organisation();
                $org->setUuid($uuid);
                return $org;
            },
            $callerOrgUuids
        );
        $this->organisationService->method('getUserOrganisations')->willReturn($callerOrgs);

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\OrganisationService')
            ->willReturn($this->organisationService);

        return new OrganisationMembersController(
            $request,
            $this->userSession,
            $this->groupManager,
            $this->userManager,
            $this->container,
            $this->createMock(LoggerInterface::class)
        );
    }//end makeController()

    /**
     * A caller who is not in the `beheerder` group is refused (403) on
     * grant(), and OpenRegister's joinOrganisation() is never invoked.
     *
     * @return void
     */
    public function testGrantRefusesNonBeheerder(): void
    {
        $controller = $this->makeController(isBeheerder: false, callerOrgUuids: ['org-a']);
        $this->organisationService->expects($this->never())->method('joinOrganisation');

        $response = $controller->grant(uuid: 'org-a', userId: 'colleague-uid');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }//end testGrantRefusesNonBeheerder()

    /**
     * A caller who is not in the `beheerder` group is refused (403) on
     * revoke(), and OpenRegister's leaveOrganisation() is never invoked.
     *
     * @return void
     */
    public function testRevokeRefusesNonBeheerder(): void
    {
        $controller = $this->makeController(isBeheerder: false, callerOrgUuids: ['org-a']);
        $this->organisationService->expects($this->never())->method('leaveOrganisation');

        $response = $controller->revoke(uuid: 'org-a', userId: 'colleague-uid');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }//end testRevokeRefusesNonBeheerder()

    /**
     * A caller who is a `beheerder` but only of a DIFFERENT organisation is
     * refused (403) on grant() — the org-membership check, not just the
     * group check, MUST gate the mutation.
     *
     * @return void
     */
    public function testGrantRefusesBeheerderOfDifferentOrganisation(): void
    {
        $controller = $this->makeController(isBeheerder: true, callerOrgUuids: ['org-b']);
        $this->organisationService->expects($this->never())->method('joinOrganisation');

        $response = $controller->grant(uuid: 'org-a', userId: 'colleague-uid');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }//end testGrantRefusesBeheerderOfDifferentOrganisation()

    /**
     * A caller who is a `beheerder` but only of a DIFFERENT organisation is
     * refused (403) on revoke() too.
     *
     * @return void
     */
    public function testRevokeRefusesBeheerderOfDifferentOrganisation(): void
    {
        $controller = $this->makeController(isBeheerder: true, callerOrgUuids: ['org-b']);
        $this->organisationService->expects($this->never())->method('leaveOrganisation');

        $response = $controller->revoke(uuid: 'org-a', userId: 'colleague-uid');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }//end testRevokeRefusesBeheerderOfDifferentOrganisation()

    /**
     * A grant to a user id that does not resolve via IUserManager::get() is
     * refused (404), and joinOrganisation() is never invoked — existing-
     * user-only (REQ-005).
     *
     * @return void
     */
    public function testGrantRefusesNonExistentUser(): void
    {
        $controller = $this->makeController(isBeheerder: true, callerOrgUuids: ['org-a']);
        $this->userManager->method('get')->with('no-such-user')->willReturn(null);
        $this->organisationService->expects($this->never())->method('joinOrganisation');

        $response = $controller->grant(uuid: 'org-a', userId: 'no-such-user');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }//end testGrantRefusesNonExistentUser()

    /**
     * An authorized beheerder's grant reaches OpenRegister's
     * joinOrganisation() and succeeds with 200 — the actual membership
     * mutation is delegated, not reimplemented.
     *
     * @return void
     */
    public function testGrantAuthorizesBeheerderAndDelegatesToOpenRegister(): void
    {
        $controller = $this->makeController(isBeheerder: true, callerOrgUuids: ['org-a']);

        $existingUser = $this->createMock(IUser::class);
        $this->userManager->method('get')->with('colleague-uid')->willReturn($existingUser);

        $this->organisationService->expects($this->once())
            ->method('joinOrganisation')
            ->with('org-a', 'colleague-uid')
            ->willReturn(true);

        $response = $controller->grant(uuid: 'org-a', userId: 'colleague-uid');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testGrantAuthorizesBeheerderAndDelegatesToOpenRegister()

    /**
     * An authorized beheerder's revoke reaches OpenRegister's
     * leaveOrganisation() and succeeds with 200.
     *
     * @return void
     */
    public function testRevokeAuthorizesBeheerderAndDelegatesToOpenRegister(): void
    {
        $controller = $this->makeController(isBeheerder: true, callerOrgUuids: ['org-a']);

        $this->organisationService->expects($this->once())
            ->method('leaveOrganisation')
            ->with('org-a', 'colleague-uid')
            ->willReturn(true);

        $response = $controller->revoke(uuid: 'org-a', userId: 'colleague-uid');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testRevokeAuthorizesBeheerderAndDelegatesToOpenRegister()

    /**
     * When OpenRegister's joinOrganisation() throws (e.g. organisation not
     * found), the controller surfaces a 400 rather than a 5xx or a silent
     * success.
     *
     * @return void
     */
    public function testGrantSurfacesOpenRegisterExceptionAs400(): void
    {
        $controller = $this->makeController(isBeheerder: true, callerOrgUuids: ['org-a']);

        $existingUser = $this->createMock(IUser::class);
        $this->userManager->method('get')->with('colleague-uid')->willReturn($existingUser);

        $this->organisationService->method('joinOrganisation')
            ->willThrowException(new \Exception('Organisation not found'));

        $response = $controller->grant(uuid: 'org-a', userId: 'colleague-uid');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testGrantSurfacesOpenRegisterExceptionAs400()
}//end class
