<?php

/**
 * Regression + negative tests for AangebodenGebruikService's already-correct
 * cross-organisation relationship scoping (vendor-visibility-rbac REQ-001,
 * REQ-002, REQ-005).
 *
 * discovery.md's audit found these paths already implement the correct
 * "resolve caller relationship, deny BEFORE the RBAC-bypass query" ordering.
 * This test locks that behaviour in with regression coverage so it cannot
 * silently regress, per tasks.md Task 3/Task 4 and design.md's Trade-offs
 * section ("Lock in already-correct behaviour").
 *
 * @category  Test
 * @package   OCA\SoftwareCatalog\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/specs/vendor-visibility-rbac/spec.md#requirement-every-rbac-bypassing-gebruik-koppeling-contract-read-must-evaluate-its-deny-check-before-issuing-the-bypass-query-req-001
 * @spec openspec/specs/vendor-visibility-rbac/spec.md#requirement-aanbod-beheerder-vendor-reads-of-gebruik-koppeling-objects-must-be-scoped-to-the-vendor-s-own-offered-products-req-002
 * @spec openspec/specs/vendor-visibility-rbac/spec.md#requirement-deelname-and-afnemer-relationship-reads-remain-unaffected-req-005
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\SoftwareCatalog\Service\AangebodenGebruikService;
use OCA\SoftwareCatalog\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for AangebodenGebruikService's afnemer/koppelingen access-control
 * paths.
 */
class AangebodenGebruikServiceTest extends TestCase
{

    /** @var IAppManager|MockObject */
    private IAppManager|MockObject $appManager;

    /** @var ContainerInterface|MockObject */
    private ContainerInterface|MockObject $container;

    /** @var ObjectService|MockObject */
    private ObjectService|MockObject $objectService;

    /** @var OrganisationService|MockObject */
    private OrganisationService|MockObject $organisationService;

    /** @var SettingsService|MockObject */
    private SettingsService|MockObject $settingsService;

    /** @var IUserSession|MockObject */
    private IUserSession|MockObject $userSession;

    private AangebodenGebruikService $service;


    /**
     * Wire up an AangebodenGebruikService with all collaborators mocked.
     *
     * @param string|null $activeOrgUuid The active organisation's uuid, or
     *                                   null when there is none / caller is
     *                                   anonymous.
     *
     * @return void
     */
    private function setUpService(?string $activeOrgUuid): void
    {
        $this->appManager          = $this->createMock(IAppManager::class);
        $this->container           = $this->createMock(ContainerInterface::class);
        $this->objectService       = $this->createMock(ObjectService::class);
        $this->organisationService = $this->createMock(OrganisationService::class);
        $this->settingsService     = $this->createMock(SettingsService::class);
        $this->userSession         = $this->createMock(IUserSession::class);

        $this->appManager->method('getInstalledApps')->willReturn(['openregister']);

        $this->container->method('get')->willReturnMap(
            [
                ['OCA\OpenRegister\Service\ObjectService', $this->objectService],
                ['OCA\OpenRegister\Service\OrganisationService', $this->organisationService],
            ]
        );

        $this->settingsService->method('getVoorzieningenConfig')->willReturn(
            [
                'register'          => 'reg-1',
                'gebruik_schema'    => 'schema-gebruik',
                'koppeling_schema'  => 'schema-koppeling',
                'organisatie_schema' => 'schema-organisatie',
            ]
        );

        if ($activeOrgUuid === null) {
            $this->userSession->method('getUser')->willReturn(null);
        } else {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn('caller-uid');
            $this->userSession->method('getUser')->willReturn($user);

            $org = new Organisation();
            $org->setUuid($activeOrgUuid);
            $this->organisationService->method('getActiveOrganisation')->willReturn($org);
        }

        $this->service = new AangebodenGebruikService(
            $this->createMock(\OCP\IAppConfig::class),
            $this->appManager,
            $this->container,
            $this->createMock(LoggerInterface::class),
            $this->settingsService,
            $this->userSession
        );

    }//end setUpService()


    /**
     * TC-2-shaped: with no current organisation (anonymous / no active org),
     * getGebruiksWhereAfnemer() returns the documented empty envelope and
     * NEVER issues the RBAC-disabled search — deny before grant (REQ-001).
     *
     * @return void
     */
    public function testGetGebruiksWhereAfnemerWithNoCurrentOrgNeverSearches(): void
    {
        $this->setUpService(activeOrgUuid: null);

        $this->objectService->expects($this->never())->method('searchObjectsPaginated');

        $result = $this->service->getGebruiksWhereAfnemer();

        $this->assertSame([], $result['results']);
        $this->assertSame(0, $result['total']);
        $this->assertSame('No current organization available', $result['message']);

    }//end testGetGebruiksWhereAfnemerWithNoCurrentOrgNeverSearches()


    /**
     * TC-11-shaped regression: with a current organisation, the search
     * query is field-scoped to `afnemer => currentOrg` — never an unscoped
     * cross-organisation query.
     *
     * @return void
     */
    public function testGetGebruiksWhereAfnemerScopesQueryToCurrentOrg(): void
    {
        $this->setUpService(activeOrgUuid: 'org-a');

        $capturedQuery = null;
        $this->objectService->expects($this->once())
            ->method('searchObjectsPaginated')
            ->willReturnCallback(
                function (array $query) use (&$capturedQuery) {
                    $capturedQuery = $query;
                    return ['results' => [], 'total' => 0];
                }
            );

        $result = $this->service->getGebruiksWhereAfnemer();

        $this->assertIsArray($capturedQuery);
        $this->assertSame('org-a', $capturedQuery['afnemer']);
        $this->assertSame(0, $result['total']);

    }//end testGetGebruiksWhereAfnemerScopesQueryToCurrentOrg()


    /**
     * TC-4-shaped negative test: a non-ambtenaar caller whose organisation
     * does NOT own the target uuid is denied the empty envelope, and the
     * RBAC-disabled paginated search is NEVER issued — the ownership check
     * (a single `find()` call) runs, then short-circuits BEFORE
     * `searchObjectsPaginated()` (deny before grant, REQ-001).
     *
     * @return void
     */
    public function testGetKoppelingenGebruikByUuidDeniesNonOwner(): void
    {
        $this->setUpService(activeOrgUuid: 'vendor-v');

        $targetEntity = $this->createMock(ObjectEntity::class);
        $targetEntity->method('getObject')->willReturn(['@self' => ['organisation' => 'municipality-g']]);

        $this->objectService->expects($this->once())
            ->method('find')
            ->willReturn($targetEntity);

        $this->objectService->expects($this->never())->method('searchObjectsPaginated');

        $result = $this->service->getKoppelingenGebruikByUuid(uuid: 'uuid-owned-by-g', isAmbtenaar: false);

        $this->assertSame([], $result['results']);
        $this->assertSame(0, $result['total']);

    }//end testGetKoppelingenGebruikByUuidDeniesNonOwner()


    /**
     * REQ-002 regression: a non-ambtenaar caller whose organisation DOES own
     * the target uuid is granted access and the search proceeds.
     *
     * @return void
     */
    public function testGetKoppelingenGebruikByUuidAllowsOwner(): void
    {
        $this->setUpService(activeOrgUuid: 'vendor-v');

        $targetEntity = $this->createMock(ObjectEntity::class);
        $targetEntity->method('getObject')->willReturn(['@self' => ['organisation' => 'vendor-v', 'schema' => 'schema-suite']]);

        $this->objectService->method('find')->willReturn($targetEntity);
        $this->objectService->method('buildSearchQuery')->willReturn(['@self' => []]);

        $this->objectService->expects($this->once())
            ->method('searchObjectsPaginated')
            ->willReturn(['results' => [], 'total' => 0]);

        $result = $this->service->getKoppelingenGebruikByUuid(uuid: 'uuid-owned-by-vendor-v', isAmbtenaar: false);

        $this->assertSame(0, $result['total']);

    }//end testGetKoppelingenGebruikByUuidAllowsOwner()


    /**
     * REQ-003/REQ-002 pattern regression: `ambtenaar` bypasses the ownership
     * check entirely (existing, unchanged behaviour) — no `find()`
     * ownership-lookup call is required to grant access.
     *
     * @return void
     */
    public function testGetKoppelingenGebruikByUuidAmbtenaarBypassesOwnershipCheck(): void
    {
        $this->setUpService(activeOrgUuid: null);

        $this->objectService->method('buildSearchQuery')->willReturn(['@self' => []]);
        // The ambtenaar branch still performs an "is this an organisation
        // uuid" probe via find(), but never needs it to succeed for access
        // to be granted.
        $this->objectService->method('find')->willReturn(null);

        $this->objectService->expects($this->once())
            ->method('searchObjectsPaginated')
            ->willReturn(['results' => [], 'total' => 0]);

        $result = $this->service->getKoppelingenGebruikByUuid(uuid: 'any-uuid', isAmbtenaar: true);

        $this->assertSame(0, $result['total']);

    }//end testGetKoppelingenGebruikByUuidAmbtenaarBypassesOwnershipCheck()


}//end class
