<?php
/**
 * Unit tests for SbomController's upload guard + authorization.
 *
 * Covers the no-admin-idor gate (admin, OR manage-group + module manage-ACL
 * required; refused otherwise, with the import service never invoked), the
 * oversized-upload guard, the non-JSON guard, and the happy path — all
 * BEFORE `SbomImportService::importForModuleVersie()` (and therefore the
 * parser) is ever reached on a rejected upload.
 *
 * @category  Test
 * @package   OCA\SoftwareCatalog\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/specs/sbom-import/spec.md#requirement-uploaded-sbom-files-are-bounded-in-size-and-json-only
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Controller;

use OCA\SoftwareCatalog\Controller\SbomController;
use OCA\SoftwareCatalog\Service\SbomImportService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test class for SbomController.
 */
class SbomControllerTest extends TestCase
{
    /**
     * @var SbomImportService|MockObject
     */
    private SbomImportService|MockObject $importService;

    /**
     * @var IUserSession|MockObject
     */
    private IUserSession|MockObject $userSession;

    /**
     * @var IGroupManager|MockObject
     */
    private IGroupManager|MockObject $groupManager;

    /**
     * @var array<int,string> Temp files created by a test, removed in tearDown().
     */
    private array $tempFiles = [];

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_string($path) === true && file_exists($path) === true) {
                unlink($path);
            }
        }

        $this->tempFiles = [];
    }//end tearDown()

    /**
     * Build a controller with a logged-in user of the given role, and an
     * IRequest mock reporting the given uploaded-file/param shape.
     *
     * @param bool                      $isAdmin      Whether the caller is an admin.
     * @param array<int,string>         $memberGroups Non-admin groups the caller belongs to.
     * @param array<string,mixed>|null  $uploadedFile The `getUploadedFile('sbomFile')` return value.
     * @param array<string,string>      $params       `getParam()` overrides (format, operationId).
     *
     * @return SbomController The controller under test.
     */
    private function makeController(
        bool $isAdmin,
        array $memberGroups,
        ?array $uploadedFile,
        array $params = []
    ): SbomController {
        $request = $this->createMock(IRequest::class);
        $request->method('getUploadedFile')->willReturn($uploadedFile);
        $request->method('getParam')->willReturnCallback(
            function (string $key, $default = null) use ($params) {
                return $params[$key] ?? $default;
            }
        );

        $this->importService = $this->createMock(SbomImportService::class);
        $this->userSession    = $this->createMock(IUserSession::class);
        $this->groupManager   = $this->createMock(IGroupManager::class);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('caller-uid');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isAdmin')->with('caller-uid')->willReturn($isAdmin);
        $this->groupManager->method('isInGroup')->willReturnCallback(
            static function (string $uid, string $group) use ($memberGroups) {
                return in_array($group, $memberGroups, true);
            }
        );

        return new SbomController(
            $request,
            $this->userSession,
            $this->groupManager,
            $this->importService,
            $this->createMock(LoggerInterface::class)
        );
    }//end makeController()

    /**
     * Build an uploaded-file array pointing at a real temp file with the
     * given contents.
     *
     * @param string $contents The file contents.
     * @param string $name     The reported original file name.
     *
     * @return array{tmp_name:string,name:string,size:int}
     */
    private function uploadedFile(string $contents, string $name = 'sbom.json'): array
    {
        $path = tempnam(sys_get_temp_dir(), 'sbom-test-');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return ['tmp_name' => $path, 'name' => $name, 'size' => strlen($contents)];
    }//end uploadedFile()

    /**
     * A caller who is neither admin nor in a manage group is refused (403);
     * the import service is never invoked.
     *
     * @return void
     */
    public function testImportRefusesCallerWithNoManageRole(): void
    {
        $controller = $this->makeController(isAdmin: false, memberGroups: [], uploadedFile: null);
        $this->importService->expects($this->never())->method('importForModuleVersie');

        $response = $controller->importSbom('mv-uuid-1');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }//end testImportRefusesCallerWithNoManageRole()

    /**
     * A caller in a manage group but WITHOUT manage-ACL (RBAC read) on the
     * target module is refused (403); the import service is never invoked.
     *
     * @return void
     */
    public function testImportRefusesManageGroupWithoutModuleAcl(): void
    {
        $controller = $this->makeController(
            isAdmin: false,
            memberGroups: ['aanbod-beheerder'],
            uploadedFile: null
        );
        $this->importService->method('resolveParentModuleUuid')->willReturn('module-uuid-1');
        $this->importService->method('userCanReadModule')->with('module-uuid-1')->willReturn(false);
        $this->importService->expects($this->never())->method('importForModuleVersie');

        $response = $controller->importSbom('mv-uuid-1');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }//end testImportRefusesManageGroupWithoutModuleAcl()

    /**
     * An admin caller with no file uploaded gets a 400, not a 403/500.
     *
     * @return void
     */
    public function testImportWithNoFileReturns400(): void
    {
        $controller = $this->makeController(isAdmin: true, memberGroups: [], uploadedFile: null);
        $this->importService->expects($this->never())->method('importForModuleVersie');

        $response = $controller->importSbom('mv-uuid-1');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testImportWithNoFileReturns400()

    /**
     * An oversized upload is rejected BEFORE the import service (and
     * therefore the parser) is invoked.
     *
     * @return void
     */
    public function testOversizedUploadRejectedBeforeImport(): void
    {
        $oversized = str_repeat('a', 200);
        $upload    = $this->uploadedFile($oversized);
        // Report a size over the 10 MB limit regardless of the tiny temp
        // file's actual bytes — the controller trusts the reported size.
        $upload['size'] = 10485760 + 1;

        $controller = $this->makeController(isAdmin: true, memberGroups: [], uploadedFile: $upload);
        $this->importService->expects($this->never())->method('importForModuleVersie');

        $response = $controller->importSbom('mv-uuid-1');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testOversizedUploadRejectedBeforeImport()

    /**
     * A non-JSON upload is rejected with 400 before the import service is
     * invoked.
     *
     * @return void
     */
    public function testNonJsonUploadRejected(): void
    {
        $upload = $this->uploadedFile('this is not { json');

        $controller = $this->makeController(isAdmin: true, memberGroups: [], uploadedFile: $upload);
        $this->importService->expects($this->never())->method('importForModuleVersie');

        $response = $controller->importSbom('mv-uuid-1');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testNonJsonUploadRejected()

    /**
     * An admin caller with a valid small JSON upload reaches the import
     * service and its result is returned with 200.
     *
     * @return void
     */
    public function testValidUploadReachesImportServiceAndReturns200(): void
    {
        $upload = $this->uploadedFile('{"bomFormat":"CycloneDX","specVersion":"1.6","components":[]}');

        $controller = $this->makeController(isAdmin: true, memberGroups: [], uploadedFile: $upload);
        $this->importService->expects($this->once())
            ->method('importForModuleVersie')
            ->with('mv-uuid-1', $this->isType('string'), 'cyclonedx-json', 'sbom.json')
            ->willReturn(['success' => true, 'componentCount' => 0, 'operationId' => null]);

        $response = $controller->importSbom('mv-uuid-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testValidUploadReachesImportServiceAndReturns200()

    /**
     * A manage-group caller WITH manage-ACL on the target module is
     * authorized and reaches the import service.
     *
     * @return void
     */
    public function testManageGroupWithModuleAclIsAuthorized(): void
    {
        $upload = $this->uploadedFile('{"bomFormat":"CycloneDX","specVersion":"1.6","components":[]}');

        $controller = $this->makeController(
            isAdmin: false,
            memberGroups: ['software-catalog-admins'],
            uploadedFile: $upload
        );
        $this->importService->method('resolveParentModuleUuid')->willReturn('module-uuid-1');
        $this->importService->method('userCanReadModule')->with('module-uuid-1')->willReturn(true);
        $this->importService->expects($this->once())
            ->method('importForModuleVersie')
            ->willReturn(['success' => true, 'componentCount' => 0, 'operationId' => null]);

        $response = $controller->importSbom('mv-uuid-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testManageGroupWithModuleAclIsAuthorized()

    /**
     * getSbomImportStatus() refuses an unauthenticated caller.
     *
     * @return void
     */
    public function testStatusRefusesUnauthenticated(): void
    {
        $request = $this->createMock(IRequest::class);
        $this->userSession  = $this->createMock(IUserSession::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->importService = $this->createMock(SbomImportService::class);
        $this->userSession->method('getUser')->willReturn(null);

        $controller = new SbomController(
            $request,
            $this->userSession,
            $this->groupManager,
            $this->importService,
            $this->createMock(LoggerInterface::class)
        );

        $response = $controller->getSbomImportStatus('mv-uuid-1');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testStatusRefusesUnauthenticated()

    /**
     * REGRESSION: a non-existent `moduleVersieUuid` returns 404, not 500.
     *
     * Confirmed live: `GET /apps/softwarecatalog/api/moduleversies/{uuid}/sbom`
     * for a well-formed but non-existent uuid 500'd with an uncaught
     * `OCP\AppFramework\Db\DoesNotExistException` ("Object with identifier
     * '...' not found in any magic table") — OpenRegister's real
     * `ObjectService::find()` re-throws instead of returning `null` for this
     * shape, unlike the assumption `SbomImportService::getStatus()`'s own
     * `if ($moduleVersie !== null)` guard was written under.
     *
     * @return void
     */
    public function testStatusReturns404ForNonExistentModuleVersie(): void
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturn(null);
        $this->userSession  = $this->createMock(IUserSession::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->importService = $this->createMock(SbomImportService::class);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('caller-uid');
        $this->userSession->method('getUser')->willReturn($user);

        $this->importService->method('getStatus')
            ->willThrowException(new DoesNotExistException("Object with identifier '00000000-0000-0000-0000-000000000000' not found in any magic table"));

        $controller = new SbomController(
            $request,
            $this->userSession,
            $this->groupManager,
            $this->importService,
            $this->createMock(LoggerInterface::class)
        );

        $response = $controller->getSbomImportStatus('00000000-0000-0000-0000-000000000000');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        $this->assertSame('MODULE_VERSION_NOT_FOUND', $response->getData()['error'] ?? null);
    }//end testStatusReturns404ForNonExistentModuleVersie()

    /**
     * REGRESSION: `importSbom()` also translates a `DoesNotExistException`
     * escaping `SbomImportService::importForModuleVersie()` (same underlying
     * OpenRegister `find()` behaviour as `testStatusReturns404ForNonExistentModuleVersie()`)
     * to 404, not 500.
     *
     * @return void
     */
    public function testImportReturns404ForNonExistentModuleVersie(): void
    {
        $upload = $this->uploadedFile('{"bomFormat":"CycloneDX","specVersion":"1.6","components":[]}');

        $controller = $this->makeController(isAdmin: true, memberGroups: [], uploadedFile: $upload);
        $this->importService->method('importForModuleVersie')
            ->willThrowException(new DoesNotExistException("Object with identifier '00000000-0000-0000-0000-000000000000' not found in any magic table"));

        $response = $controller->importSbom('00000000-0000-0000-0000-000000000000');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        $this->assertSame('MODULE_VERSION_NOT_FOUND', $response->getData()['error'] ?? null);
    }//end testImportReturns404ForNonExistentModuleVersie()
}//end class
