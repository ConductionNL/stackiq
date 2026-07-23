<?php

/**
 * Unit tests for PortfolioReportController.
 *
 * Covers the deny-before-query organisation-access gate
 * (`isAuthorisedForOrganisation()`) and the JSON/CSV response branching of
 * `index()`.
 *
 * @category  Test
 * @package   OCA\SoftwareCatalog\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md#requirement-report-and-csv-export-are-scoped-to-the-requesters-authorised-organisations
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Controller;

use OCA\SoftwareCatalog\Controller\PortfolioReportController;
use OCA\SoftwareCatalog\Service\PortfolioReportService;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PortfolioReportController.
 *
 * @spec openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md
 */
class PortfolioReportControllerTest extends TestCase
{
    /**
     * Build a controller with mocked collaborators.
     *
     * @param array<int,string>          $groupNames  The caller's NC group ids.
     * @param string                     $orgUuid     The caller's active organisation.
     * @param IUser|null                 $user        The authenticated user (null → unauthenticated).
     * @param array<string,mixed>        $params      Request params (`organisation`, `format`).
     * @param PortfolioReportService|null $service    Optional service stub.
     *
     * @return PortfolioReportController
     */
    private function makeController(
        array $groupNames,
        string $orgUuid,
        ?IUser $user,
        array $params,
        ?PortfolioReportService $service=null
    ): PortfolioReportController {
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

        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($user);

        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(
            static function (string $key, $default=null) use ($params) {
                return $params[$key] ?? $default;
            }
        );

        return new PortfolioReportController(
            appName: 'softwarecatalog',
            request: $request,
            userSession: $userSession,
            groupManager: $groupManager,
            config: $config,
            reportService: $service ?? $this->createMock(PortfolioReportService::class)
        );
    }//end makeController()

    /**
     * Unauthenticated caller gets 401, never reaches the service.
     *
     * @return void
     */
    public function testUnauthenticatedIsRejected(): void
    {
        $service = $this->createMock(PortfolioReportService::class);
        $service->expects($this->never())->method('buildReport');

        $controller = $this->makeController([], '', null, ['organisation' => 'org-a'], $service);
        $response   = $controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(401, $response->getStatus());
    }//end testUnauthenticatedIsRejected()

    /**
     * Missing `organisation` param is a 400, never reaches the service.
     *
     * @return void
     */
    public function testMissingOrganisationIsBadRequest(): void
    {
        $service = $this->createMock(PortfolioReportService::class);
        $service->expects($this->never())->method('buildReport');

        $user       = $this->createMock(IUser::class);
        $controller = $this->makeController(['admin'], 'org-a', $user, [], $service);
        $response   = $controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(400, $response->getStatus());
    }//end testMissingOrganisationIsBadRequest()

    /**
     * A non-admin/ambtenaar user requesting ANOTHER organisation's report is
     * denied (403) BEFORE the report service is ever invoked — fail closed.
     *
     * @return void
     */
    public function testCrossOrganisationRequestIsDeniedBeforeQuery(): void
    {
        $service = $this->createMock(PortfolioReportService::class);
        $service->expects($this->never())->method('buildReport');
        $service->expects($this->never())->method('buildCsv');

        $user       = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('user-1');
        $controller = $this->makeController(
            ['gebruik-beheerder'],
            'municipality-a',
            $user,
            ['organisation' => 'municipality-b'],
            $service
        );
        $response   = $controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(403, $response->getStatus());
    }//end testCrossOrganisationRequestIsDeniedBeforeQuery()

    /**
     * A user requesting their OWN organisation's report gets a 200 JSON
     * report.
     *
     * @return void
     */
    public function testOwnOrganisationRequestReturnsReport(): void
    {
        $service = $this->createMock(PortfolioReportService::class);
        $service->expects($this->once())
            ->method('buildReport')
            ->with('municipality-a')
            ->willReturn(['organisation' => 'municipality-a']);
        $service->expects($this->never())->method('buildCsv');

        $user       = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('user-1');
        $controller = $this->makeController(
            ['gebruik-beheerder'],
            'municipality-a',
            $user,
            ['organisation' => 'municipality-a'],
            $service
        );
        $response   = $controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(200, $response->getStatus());
    }//end testOwnOrganisationRequestReturnsReport()

    /**
     * `admin` may request any organisation's report — the existing
     * unrestricted-read bypass.
     *
     * @return void
     */
    public function testAdminMayRequestAnyOrganisation(): void
    {
        $service = $this->createMock(PortfolioReportService::class);
        $service->expects($this->once())->method('buildReport')->willReturn([]);

        $user       = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin-1');
        $controller = $this->makeController(['admin'], 'org-x', $user, ['organisation' => 'org-y'], $service);
        $response   = $controller->index();

        $this->assertSame(200, $response->getStatus());
    }//end testAdminMayRequestAnyOrganisation()

    /**
     * `?format=csv` on an authorised request returns a CSV download
     * response, not JSON.
     *
     * @return void
     */
    public function testCsvFormatReturnsDownloadResponse(): void
    {
        $service = $this->createMock(PortfolioReportService::class);
        $service->expects($this->once())
            ->method('buildCsv')
            ->with('municipality-a')
            ->willReturn("organisation,module\nmunicipality-a,Example\n");
        $service->expects($this->never())->method('buildReport');

        $user       = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('user-1');
        $controller = $this->makeController(
            ['gebruik-beheerder'],
            'municipality-a',
            $user,
            ['organisation' => 'municipality-a', 'format' => 'csv'],
            $service
        );
        $response   = $controller->index();

        $this->assertInstanceOf(DataDownloadResponse::class, $response);
    }//end testCsvFormatReturnsDownloadResponse()

    /**
     * CSV export for an unauthorised organisation is denied and the CSV
     * builder is never invoked.
     *
     * @return void
     */
    public function testCsvFormatDeniedForUnauthorisedOrganisation(): void
    {
        $service = $this->createMock(PortfolioReportService::class);
        $service->expects($this->never())->method('buildCsv');

        $user       = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('user-1');
        $controller = $this->makeController(
            ['gebruik-beheerder'],
            'municipality-a',
            $user,
            ['organisation' => 'municipality-b', 'format' => 'csv'],
            $service
        );
        $response   = $controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(403, $response->getStatus());
    }//end testCsvFormatDeniedForUnauthorisedOrganisation()

    /**
     * `ambtenaar` bypasses organisation scoping exactly like admin.
     *
     * @return void
     */
    public function testAmbtenaarMayRequestAnyOrganisation(): void
    {
        $service = $this->createMock(PortfolioReportService::class);
        $service->expects($this->once())->method('buildReport')->willReturn([]);

        $user       = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('user-1');
        $controller = $this->makeController(['ambtenaar'], 'org-x', $user, ['organisation' => 'org-y'], $service);
        $response   = $controller->index();

        $this->assertSame(200, $response->getStatus());
    }//end testAmbtenaarMayRequestAnyOrganisation()

    /**
     * A caller with no active organisation configured is denied, never
     * silently matched.
     *
     * @return void
     */
    public function testNoActiveOrganisationIsDenied(): void
    {
        $service = $this->createMock(PortfolioReportService::class);
        $service->expects($this->never())->method('buildReport');

        $user       = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('user-1');
        $controller = $this->makeController(['gebruik-beheerder'], '', $user, ['organisation' => 'org-y'], $service);
        $response   = $controller->index();

        $this->assertSame(403, $response->getStatus());
    }//end testNoActiveOrganisationIsDenied()
}//end class
