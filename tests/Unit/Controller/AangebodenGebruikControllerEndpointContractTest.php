<?php

/**
 * Wire-contract tests for the AangebodenGebruik endpoints gate-25 flags as
 * uncovered: the two `@PublicPage` ambtenaar reads, the two `@NoAdminRequired`
 * mutations, and the public API-documentation route.
 *
 * The ambtenaar reads are the sharpest contract in this controller. They are
 * `@PublicPage` AND they bypass RBAC and multitenancy inside the service, so
 * the ONLY thing standing between an anonymous caller and every organisation's
 * gebruik records is the `admin`/`ambtenaar` group check in the controller. A
 * caller outside those groups must receive the empty paginated envelope with a
 * 200 — and the bypassing service call must never be issued. Both halves are
 * asserted; asserting only the response body would pass on an implementation
 * that queried first and filtered afterwards.
 *
 * @category  Test
 * @package   OCA\SoftwareCatalog\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/specs/aangeboden-gebruik-api/spec.md
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Controller;

use OCA\SoftwareCatalog\Controller\AangebodenGebruikController;
use OCA\SoftwareCatalog\Service\AangebodenGebruikService;
use OCP\AppFramework\Http;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Contract tests for the ambtenaar reads, the set-self / deny mutations and
 * the documentation route.
 */
class AangebodenGebruikControllerEndpointContractTest extends TestCase
{

    /**
     * The mocked aangeboden-gebruik service.
     *
     * @var AangebodenGebruikService|MockObject
     */
    private AangebodenGebruikService|MockObject $gebruikSvc;

    /**
     * The mocked user session.
     *
     * @var IUserSession|MockObject
     */
    private IUserSession|MockObject $userSession;

    /**
     * The mocked group manager.
     *
     * @var IGroupManager|MockObject
     */
    private IGroupManager|MockObject $groupManager;


    /**
     * Build the controller under test with fresh mocks.
     *
     * @param array<string,mixed> $params Query/body params the request reports.
     *
     * @return AangebodenGebruikController The controller under test.
     */
    private function makeController(array $params=[]): AangebodenGebruikController
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getParams')->willReturn($params);
        $request->method('getParam')->willReturnCallback(
            static function (string $key, $default=null) use ($params) {
                return ($params[$key] ?? $default);
            }
        );

        $this->gebruikSvc   = $this->createMock(AangebodenGebruikService::class);
        $this->userSession  = $this->createMock(IUserSession::class);
        $this->groupManager = $this->createMock(IGroupManager::class);

        return new AangebodenGebruikController(
            'softwarecatalog',
            $request,
            $this->userSession,
            $this->gebruikSvc,
            $this->createMock(LoggerInterface::class),
            $this->groupManager
        );

    }//end makeController()


    /**
     * Authenticate the session and declare which groups the user belongs to.
     *
     * @param array<int,string> $memberOf The group ids the user is in.
     *
     * @return void
     */
    private function withUserInGroups(array $memberOf): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);

        $this->groupManager->method('get')->willReturnCallback(
            function (string $gid) use ($memberOf, $user) {
                $group = $this->createMock(IGroup::class);
                $group->method('inGroup')->with($user)
                    ->willReturn(in_array($gid, $memberOf, true));
                return $group;
            }
        );

    }//end withUserInGroups()


    /**
     * Assert the documented empty paginated envelope.
     *
     * @param array<string,mixed> $data The response payload.
     *
     * @return void
     */
    private function assertEmptyPage(array $data): void
    {
        $this->assertSame([], $data['results']);
        $this->assertSame(0, $data['total']);

    }//end assertEmptyPage()


    /**
     * GET /api/aangeboden-gebruik/ambtenaar — an anonymous caller receives the
     * empty envelope and the RBAC-bypassing service read is never issued.
     *
     * @return void
     */
    public function testAmbtenaarListDeniesAnonymousWithoutQueryingTheBypass(): void
    {
        $controller = $this->makeController();
        $this->userSession->method('getUser')->willReturn(null);
        $this->gebruikSvc->expects($this->never())->method('getAllGebruiksForAmbtenaar');

        $response = $controller->getAllGebruiksForAmbtenaar();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertEmptyPage($response->getData());

    }//end testAmbtenaarListDeniesAnonymousWithoutQueryingTheBypass()


    /**
     * An authenticated user outside `admin`/`ambtenaar` is denied the same
     * way — the bypass query is never issued.
     *
     * @return void
     */
    public function testAmbtenaarListDeniesAnOrdinaryUserWithoutQueryingTheBypass(): void
    {
        $controller = $this->makeController();
        $this->withUserInGroups(['users']);
        $this->gebruikSvc->expects($this->never())->method('getAllGebruiksForAmbtenaar');

        $response = $controller->getAllGebruiksForAmbtenaar();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertEmptyPage($response->getData());

    }//end testAmbtenaarListDeniesAnOrdinaryUserWithoutQueryingTheBypass()


    /**
     * A member of `ambtenaar` reaches the bypassing read.
     *
     * @return void
     */
    public function testAmbtenaarListServesAMemberOfTheAmbtenaarGroup(): void
    {
        $controller = $this->makeController();
        $this->withUserInGroups(['ambtenaar']);

        $this->gebruikSvc->expects($this->once())
            ->method('getAllGebruiksForAmbtenaar')
            ->willReturn(['results' => [['id' => 'g-1']], 'total' => 1]);

        $response = $controller->getAllGebruiksForAmbtenaar();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(1, $response->getData()['total']);

    }//end testAmbtenaarListServesAMemberOfTheAmbtenaarGroup()


    /**
     * `admin` is the second accepted group.
     *
     * @return void
     */
    public function testAmbtenaarListAlsoServesAdmin(): void
    {
        $controller = $this->makeController();
        $this->withUserInGroups(['admin']);

        $this->gebruikSvc->expects($this->once())
            ->method('getAllGebruiksForAmbtenaar')
            ->willReturn(['results' => [], 'total' => 0]);

        $this->assertSame(Http::STATUS_OK, $controller->getAllGebruiksForAmbtenaar()->getStatus());

    }//end testAmbtenaarListAlsoServesAdmin()


    /**
     * GET /api/aangeboden-gebruik/ambtenaar/{gebruikId} carries the same group
     * gate — a single-record read must not be a way around the list gate.
     *
     * @return void
     */
    public function testAmbtenaarSingleReadDeniesAnOrdinaryUserWithoutQueryingTheBypass(): void
    {
        $controller = $this->makeController();
        $this->withUserInGroups(['users']);
        $this->gebruikSvc->expects($this->never())->method('getSingleGebruikForAmbtenaar');

        $response = $controller->getSingleGebruikForAmbtenaar('g-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertEmptyPage($response->getData());

    }//end testAmbtenaarSingleReadDeniesAnOrdinaryUserWithoutQueryingTheBypass()


    /**
     * An ambtenaar's single read forwards the requested id to the service.
     *
     * @return void
     */
    public function testAmbtenaarSingleReadForwardsTheRequestedId(): void
    {
        $controller = $this->makeController();
        $this->withUserInGroups(['ambtenaar']);

        $this->gebruikSvc->expects($this->once())
            ->method('getSingleGebruikForAmbtenaar')
            ->with('g-42', $this->isType('array'))
            ->willReturn(['results' => [['id' => 'g-42']], 'total' => 1]);

        $this->assertSame(Http::STATUS_OK, $controller->getSingleGebruikForAmbtenaar('g-42')->getStatus());

    }//end testAmbtenaarSingleReadForwardsTheRequestedId()


    /**
     * A service-level error on the single read is surfaced as a 500 rather
     * than a 200 carrying an `error` key the caller may not inspect.
     *
     * @return void
     */
    public function testAmbtenaarSingleReadSurfacesAServiceErrorAs500(): void
    {
        $controller = $this->makeController();
        $this->withUserInGroups(['ambtenaar']);
        $this->gebruikSvc->method('getSingleGebruikForAmbtenaar')
            ->willReturn(['error' => 'register unavailable']);

        $this->assertSame(
            Http::STATUS_INTERNAL_SERVER_ERROR,
            $controller->getSingleGebruikForAmbtenaar('g-1')->getStatus()
        );

    }//end testAmbtenaarSingleReadSurfacesAServiceErrorAs500()


    /**
     * The two mutating endpoints.
     *
     * @return array<string, array{0: string}>
     */
    public static function mutatingMethodProvider(): array
    {
        return [
            'setGebruikSelfToActiveOrg' => ['setGebruikSelfToActiveOrg'],
            'deleteGebruikAsAfnemer'    => ['deleteGebruikAsAfnemer'],
        ];

    }//end mutatingMethodProvider()


    /**
     * Neither mutation is reachable without a session.
     *
     * @param string $method The controller method name.
     *
     * @return void
     *
     * @dataProvider mutatingMethodProvider
     */
    public function testMutationsRejectAnonymousWith401(string $method): void
    {
        $controller = $this->makeController();
        $this->userSession->method('getUser')->willReturn(null);

        $this->gebruikSvc->expects($this->never())->method('setGebruikSelfToActiveOrg');
        $this->gebruikSvc->expects($this->never())->method('deleteGebruikAsAfnemer');

        $response = $controller->$method('g-1');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
        $this->assertSame(['message' => 'Not authenticated'], $response->getData());

    }//end testMutationsRejectAnonymousWith401()


    /**
     * An empty id is a controller-side 400 — the service is not asked to
     * mutate an unnamed object.
     *
     * @param string $method The controller method name.
     *
     * @return void
     *
     * @dataProvider mutatingMethodProvider
     */
    public function testMutationsRejectAnEmptyIdWith400(string $method): void
    {
        $controller = $this->makeController();
        $this->withUserInGroups(['users']);

        $this->gebruikSvc->expects($this->never())->method('setGebruikSelfToActiveOrg');
        $this->gebruikSvc->expects($this->never())->method('deleteGebruikAsAfnemer');

        $response = $controller->$method('');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertFalse($response->getData()['success']);

    }//end testMutationsRejectAnEmptyIdWith400()


    /**
     * PUT /api/aangeboden-gebruik/{id}/set-self forwards the id, strips the
     * path parameter from the body options, and returns 200 on success.
     *
     * @return void
     */
    public function testSetSelfForwardsTheIdAndStripsThePathParameter(): void
    {
        $controller = $this->makeController(['gebruikId' => 'other', 'note' => 'x']);
        $this->withUserInGroups(['users']);

        $this->gebruikSvc->expects($this->once())
            ->method('setGebruikSelfToActiveOrg')
            ->with('g-1', ['note' => 'x'])
            ->willReturn(['success' => true, 'gebruik' => ['id' => 'g-1']]);

        $this->assertSame(Http::STATUS_OK, $controller->setGebruikSelfToActiveOrg('g-1')->getStatus());

    }//end testSetSelfForwardsTheIdAndStripsThePathParameter()


    /**
     * A caller who is neither afnemer nor aanbieder gets 403 — distinguishable
     * from "missing" and from a server fault.
     *
     * @return void
     */
    public function testSetSelfMapsAnAuthorisationRefusalTo403(): void
    {
        $controller = $this->makeController();
        $this->withUserInGroups(['users']);
        $this->gebruikSvc->method('setGebruikSelfToActiveOrg')
            ->willReturn(
                [
                    'success' => false,
                    'error'   => 'Operation not allowed: not the afnemer or aanbieder',
                ]
            );

        $this->assertSame(
            Http::STATUS_FORBIDDEN,
            $controller->setGebruikSelfToActiveOrg('g-1')->getStatus()
        );

    }//end testSetSelfMapsAnAuthorisationRefusalTo403()


    /**
     * A missing object is 404.
     *
     * @return void
     */
    public function testSetSelfMapsAMissingObjectTo404(): void
    {
        $controller = $this->makeController();
        $this->withUserInGroups(['users']);
        $this->gebruikSvc->method('setGebruikSelfToActiveOrg')
            ->willReturn(['success' => false, 'error' => 'Gebruik object not found']);

        $this->assertSame(
            Http::STATUS_NOT_FOUND,
            $controller->setGebruikSelfToActiveOrg('g-1')->getStatus()
        );

    }//end testSetSelfMapsAMissingObjectTo404()


    /**
     * DELETE /api/aangeboden-gebruik/{id}/deny reports the deletion on success
     * and refuses with 403 when the caller is not a party to the record.
     *
     * @return void
     */
    public function testDenyDeletesForAPartyAndRefusesEveryoneElseWith403(): void
    {
        $controller = $this->makeController();
        $this->withUserInGroups(['users']);
        $this->gebruikSvc->expects($this->once())
            ->method('deleteGebruikAsAfnemer')
            ->with('g-1', $this->isType('array'))
            ->willReturn(['success' => true, 'deleted' => true]);

        $response = $controller->deleteGebruikAsAfnemer('g-1');
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertTrue($response->getData()['deleted']);

        $refused = $this->makeController();
        $this->withUserInGroups(['users']);
        $this->gebruikSvc->method('deleteGebruikAsAfnemer')
            ->willReturn(
                [
                    'success' => false,
                    'error'   => 'Operation not allowed: not the afnemer',
                ]
            );

        $this->assertSame(
            Http::STATUS_FORBIDDEN,
            $refused->deleteGebruikAsAfnemer('g-1')->getStatus()
        );

    }//end testDenyDeletesForAPartyAndRefusesEveryoneElseWith403()


    /**
     * A thrown deletion reports `deleted: false` in its 500 body, so a client
     * never records a delete that did not happen.
     *
     * @return void
     */
    public function testDenyReportsDeletedFalseWhenTheServiceThrows(): void
    {
        $controller = $this->makeController();
        $this->withUserInGroups(['users']);
        $this->gebruikSvc->method('deleteGebruikAsAfnemer')
            ->willThrowException(new \Exception('register down'));

        $response = $controller->deleteGebruikAsAfnemer('g-1');
        $data     = $response->getData();

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
        $this->assertFalse($data['success']);
        $this->assertFalse($data['deleted']);

    }//end testDenyReportsDeletedFalseWhenTheServiceThrows()


    /**
     * GET /api/aangeboden-gebruik/docs is `@PublicPage` and returns only
     * static documentation — it must not read any gebruik data, and it must
     * describe the routes this controller actually registers.
     *
     * @return void
     */
    public function testApiDocumentationIsStaticAndDescribesTheRegisteredRoutes(): void
    {
        $controller = $this->makeController();
        $this->userSession->method('getUser')->willReturn(null);

        $this->gebruikSvc->expects($this->never())->method($this->anything());

        $response = $controller->getApiDocumentation();
        $data     = $response->getData();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('/api/aangeboden-gebruik', $data['base_url']);

        $paths = array_column($data['endpoints'], 'path');
        $this->assertContains('/api/aangeboden-gebruik/afnemer', $paths);

    }//end testApiDocumentationIsStaticAndDescribesTheRegisteredRoutes()
}//end class
