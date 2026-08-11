<?php

/**
 * Wire-contract tests for the four ContactpersonenController endpoints gate-25
 * flags as uncovered: password change, available groups, single user info and
 * bulk user info.
 *
 * All four are `@NoAdminRequired`, and three of them expose account data or
 * account control, so the authorisation ladder IS the contract:
 *
 *   * `POST /api/contactpersonen/change-password` — an admin may change any
 *     account; a non-admin may change ONLY their own, and only by proving the
 *     current password. Both refusals must land before `setPassword()`.
 *   * `GET /api/contactpersonen/{id}/user-info` and
 *     `POST /api/contactpersonen/bulk-user-info` — admin or an organisation
 *     admin (`gebruik-beheerder` / `aanbod-beheerder`) only; everyone else is
 *     403 without the lookup happening.
 *
 * @category  Test
 * @package   OCA\SoftwareCatalog\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/specs/contactpersonen-api/spec.md
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Controller;

use OCA\SoftwareCatalog\Controller\ContactpersonenController;
use OCA\SoftwareCatalog\Service\ContactpersoonService;
use OCA\SoftwareCatalog\Service\SettingsService;
use OCA\SoftwareCatalog\Service\SoftwareCatalogue\ContactPersonHandler;
use OCP\AppFramework\Http;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Contract tests for contactpersonen#changePassword, #getAvailableGroups,
 * #getUserInfo and #getBulkUserInfo.
 */
class ContactpersonenControllerUserAdminContractTest extends TestCase
{

    /**
     * The mocked user manager.
     *
     * @var IUserManager|MockObject
     */
    private IUserManager|MockObject $userManager;

    /**
     * The mocked group manager.
     *
     * @var IGroupManager|MockObject
     */
    private IGroupManager|MockObject $groupManager;

    /**
     * The mocked user session.
     *
     * @var IUserSession|MockObject
     */
    private IUserSession|MockObject $userSession;

    /**
     * The mocked contactpersoon service.
     *
     * @var ContactpersoonService|MockObject
     */
    private ContactpersoonService|MockObject $contactSvc;

    /**
     * The mocked DI container (used to reach OpenRegister's ObjectService).
     *
     * @var ContainerInterface|MockObject
     */
    private ContainerInterface|MockObject $container;


    /**
     * Build the controller under test with fresh mocks.
     *
     * @return ContactpersonenController The controller under test.
     */
    private function makeController(): ContactpersonenController
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getParams')->willReturn([]);

        $this->userManager  = $this->createMock(IUserManager::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->userSession  = $this->createMock(IUserSession::class);
        $this->contactSvc   = $this->createMock(ContactpersoonService::class);
        $this->container    = $this->createMock(ContainerInterface::class);

        return new ContactpersonenController(
            'softwarecatalog',
            $request,
            $this->createMock(SettingsService::class),
            $this->createMock(ContactPersonHandler::class),
            $this->contactSvc,
            $this->userManager,
            $this->groupManager,
            $this->userSession,
            $this->container,
            $this->createMock(ISecureRandom::class),
            $this->createMock(LoggerInterface::class)
        );

    }//end makeController()


    /**
     * Authenticate the session.
     *
     * @param string $uid       The uid to report.
     * @param bool   $isAdmin   Whether the group manager reports them as admin.
     * @param bool   $isOrgAdmin Whether they are in an organisation-admin group.
     *
     * @return void
     */
    private function withUser(string $uid='alice', bool $isAdmin=false, bool $isOrgAdmin=false): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isAdmin')->willReturn($isAdmin);
        $this->groupManager->method('isInGroup')->willReturn($isOrgAdmin);

    }//end withUser()


    /**
     * The four endpoints, each rejecting an anonymous caller identically.
     *
     * @return array<string, array{0: string, 1: array<int,mixed>}>
     */
    public static function anonymousEndpointProvider(): array
    {
        return [
            'changePassword'     => ['changePassword', ['alice', 'a-long-password']],
            'getAvailableGroups' => ['getAvailableGroups', []],
            'getUserInfo'        => ['getUserInfo', ['cp-1']],
            'getBulkUserInfo'    => ['getBulkUserInfo', []],
        ];

    }//end anonymousEndpointProvider()


    /**
     * An anonymous caller is refused 401 and neither the user manager nor the
     * contactpersoon service is consulted.
     *
     * @param string           $method The controller method name.
     * @param array<int,mixed> $args   Positional arguments for the call.
     *
     * @return void
     *
     * @dataProvider anonymousEndpointProvider
     */
    public function testAnonymousCallerIsRejectedWith401(string $method, array $args): void
    {
        $controller = $this->makeController();
        $this->userSession->method('getUser')->willReturn(null);

        $this->userManager->expects($this->never())->method('get');
        $this->userManager->expects($this->never())->method('checkPassword');
        $this->contactSvc->expects($this->never())->method($this->anything());
        $this->container->expects($this->never())->method('get');

        $response = $controller->$method(...$args);

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
        $this->assertSame(['message' => 'Not authenticated'], $response->getData());

    }//end testAnonymousCallerIsRejectedWith401()


    /**
     * A non-admin changing SOMEONE ELSE'S password is refused 403, and no
     * password is ever set.
     *
     * @return void
     */
    public function testANonAdminCannotChangeAnotherUsersPassword(): void
    {
        $controller = $this->makeController();
        $this->withUser('alice', false);

        $this->userManager->expects($this->never())->method('get');

        $response = $controller->changePassword('bob', 'a-long-password', 'whatever');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
        $this->assertSame(['message' => 'Insufficient permissions'], $response->getData());

    }//end testANonAdminCannotChangeAnotherUsersPassword()


    /**
     * A self-service reset without the current password is refused 400 — the
     * confirmation is not optional for a non-admin.
     *
     * @return void
     */
    public function testASelfServiceResetRequiresTheCurrentPassword(): void
    {
        $controller = $this->makeController();
        $this->withUser('alice', false);

        $this->userManager->expects($this->never())->method('get');

        $response = $controller->changePassword('alice', 'a-long-password', '');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertFalse($response->getData()['success']);

    }//end testASelfServiceResetRequiresTheCurrentPassword()


    /**
     * A wrong current password is refused 403 and nothing is written.
     *
     * @return void
     */
    public function testAWrongCurrentPasswordIsRefusedWith403(): void
    {
        $controller = $this->makeController();
        $this->withUser('alice', false);

        $this->userManager->method('checkPassword')->willReturn(false);
        $this->userManager->expects($this->never())->method('get');

        $response = $controller->changePassword('alice', 'a-long-password', 'wrong');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
        $this->assertStringContainsString('incorrect', $response->getData()['message']);

    }//end testAWrongCurrentPasswordIsRefusedWith403()


    /**
     * A correct self-service reset sets the new password.
     *
     * @return void
     */
    public function testACorrectSelfServiceResetSetsTheNewPassword(): void
    {
        $controller = $this->makeController();
        $this->withUser('alice', false);

        $target = $this->createMock(IUser::class);
        $target->expects($this->once())->method('setPassword')
            ->with('a-long-password')->willReturn(true);

        $this->userManager->method('checkPassword')->willReturn($this->createMock(IUser::class));
        $this->userManager->method('get')->with('alice')->willReturn($target);

        $response = $controller->changePassword('alice', 'a-long-password', 'right');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertTrue($response->getData()['success']);

    }//end testACorrectSelfServiceResetSetsTheNewPassword()


    /**
     * An admin changes another account's password WITHOUT supplying that
     * user's current password — the documented admin path.
     *
     * @return void
     */
    public function testAnAdminChangesAnotherAccountWithoutTheCurrentPassword(): void
    {
        $controller = $this->makeController();
        $this->withUser('root', true);

        $target = $this->createMock(IUser::class);
        $target->expects($this->once())->method('setPassword')->willReturn(true);
        $this->userManager->method('get')->with('bob')->willReturn($target);
        $this->userManager->expects($this->never())->method('checkPassword');

        $response = $controller->changePassword('bob', 'a-long-password');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());

    }//end testAnAdminChangesAnotherAccountWithoutTheCurrentPassword()


    /**
     * A password shorter than the 10-character floor is refused 400. Nextcloud
     * silently fails short passwords, so the endpoint rejects them explicitly.
     *
     * @return void
     */
    public function testAShortPasswordIsRefusedWith400(): void
    {
        $controller = $this->makeController();
        $this->withUser('root', true);

        $target = $this->createMock(IUser::class);
        $target->expects($this->never())->method('setPassword');
        $this->userManager->method('get')->willReturn($target);

        $response = $controller->changePassword('bob', 'short');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertStringContainsString('10 characters', $response->getData()['message']);

    }//end testAShortPasswordIsRefusedWith400()


    /**
     * A password the server policy rejects is reported as a 400 failure, not
     * as a success — `setPassword()` returning false must not be discarded.
     *
     * @return void
     */
    public function testAPolicyRejectedPasswordIsReportedAsAFailure(): void
    {
        $controller = $this->makeController();
        $this->withUser('root', true);

        $target = $this->createMock(IUser::class);
        $target->method('setPassword')->willReturn(false);
        $this->userManager->method('get')->willReturn($target);

        $response = $controller->changePassword('bob', 'a-long-password');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertFalse($response->getData()['success']);

    }//end testAPolicyRejectedPasswordIsReportedAsAFailure()


    /**
     * An unknown target account is a 404.
     *
     * @return void
     */
    public function testAnUnknownTargetAccountIs404(): void
    {
        $controller = $this->makeController();
        $this->withUser('root', true);
        $this->userManager->method('get')->willReturn(null);

        $this->assertSame(
            Http::STATUS_NOT_FOUND,
            $controller->changePassword('ghost', 'a-long-password')->getStatus()
        );

    }//end testAnUnknownTargetAccountIs404()


    /**
     * GET /api/contactpersonen/available-groups lists only the catalog groups
     * that actually EXIST on the instance — offering a group that cannot be
     * assigned would produce a silent failure downstream.
     *
     * @return void
     */
    public function testAvailableGroupsListsOnlyTheGroupsThatExist(): void
    {
        $controller = $this->makeController();
        $this->withUser();

        $this->groupManager->method('get')->willReturnCallback(
            function (string $gid) {
                if ($gid === 'gebruik-raadpleger') {
                    return null;
                }

                return $this->createMock(IGroup::class);
            }
        );

        $data = $controller->getAvailableGroups()->getData();
        $ids  = array_column($data['groups'], 'id');

        $this->assertTrue($data['success']);
        $this->assertContains('gebruik-beheerder', $ids);
        $this->assertContains('aanbod-beheerder', $ids);
        $this->assertNotContains('gebruik-raadpleger', $ids);

    }//end testAvailableGroupsListsOnlyTheGroupsThatExist()


    /**
     * GET /api/contactpersonen/{id}/user-info refuses a caller who is neither
     * an admin nor an organisation admin, without performing the lookup.
     *
     * @return void
     */
    public function testUserInfoRefusesAnOrdinaryUserWithoutLookingAnythingUp(): void
    {
        $controller = $this->makeController();
        $this->withUser('alice', false, false);

        $this->container->expects($this->never())->method('get');

        $response = $controller->getUserInfo('cp-1');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
        $this->assertSame(['message' => 'Insufficient permissions'], $response->getData());

    }//end testUserInfoRefusesAnOrdinaryUserWithoutLookingAnythingUp()


    /**
     * An organisation admin (`gebruik-beheerder` / `aanbod-beheerder`) passes
     * the gate — the endpoint is not admin-only.
     *
     * @return void
     */
    public function testUserInfoAdmitsAnOrganisationAdmin(): void
    {
        $controller = $this->makeController();
        $this->withUser('alice', false, true);

        // Past the gate the OpenRegister lookup happens. Stand in for it with
        // an object store that reports "no such contactpersoon", so the
        // response distinguishes "you may not ask" (403) from "there is
        // nothing to show" (404).
        $objectService = new class {

            /**
             * Stand-in for OpenRegister's ObjectService::find().
             *
             * @param string $id       The object id.
             * @param string $register The register slug.
             * @param string $schema   The schema slug.
             *
             * @return null Always "not found" for this test.
             */
            public function find(string $id, string $register, string $schema)
            {
                return null;

            }//end find()
        };

        $this->container->expects($this->once())->method('get')->willReturn($objectService);

        $response = $controller->getUserInfo('cp-1');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        $this->assertFalse($response->getData()['success']);

    }//end testUserInfoAdmitsAnOrganisationAdmin()


    /**
     * POST /api/contactpersonen/bulk-user-info carries the SAME authorisation
     * gate as the single read — a bulk route must not be a way around it.
     *
     * @return void
     */
    public function testBulkUserInfoRefusesAnOrdinaryUserWithoutQuerying(): void
    {
        $controller = $this->makeController();
        $this->withUser('alice', false, false);

        $this->contactSvc->expects($this->never())->method('getBulkUserInfo');

        $response = $controller->getBulkUserInfo();

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
        $this->assertSame(['message' => 'Insufficient permissions'], $response->getData());

    }//end testBulkUserInfoRefusesAnOrdinaryUserWithoutQuerying()


    /**
     * An authorised caller supplying no ids gets a 400 — the endpoint does not
     * silently fall back to "all contactpersonen".
     *
     * @return void
     */
    public function testBulkUserInfoRefusesAnEmptyIdListWith400(): void
    {
        $controller = $this->makeController();
        $this->withUser('root', true);

        $this->contactSvc->expects($this->never())->method('getBulkUserInfo');

        $response = $controller->getBulkUserInfo();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertFalse($response->getData()['success']);

    }//end testBulkUserInfoRefusesAnEmptyIdListWith400()
}//end class
