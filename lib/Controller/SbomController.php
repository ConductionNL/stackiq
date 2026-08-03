<?php
/**
 * Softwarecatalog SbomController.
 *
 * Upload endpoint for importing a Software Bill of Materials (CycloneDX
 * 1.5/1.6 JSON, optionally SPDX 2.x JSON) against a specific `moduleVersie`.
 * Mirrors `SettingsController::importArchiMate`'s upload → parse → object
 * creation → status ergonomics, scoped down to a single-schema,
 * single-parent-object import.
 *
 * AUTH (ADR-005): `#[NoAdminRequired]` route annotation PLUS an explicit
 * authorization guard in the method body — admin group membership, OR
 * membership of one of the app's manage-tier groups AND manage-ACL
 * visibility (RBAC read) of the target moduleVersie's parent `module`. The
 * route annotation alone is NOT treated as sufficient authorization
 * (no-admin-idor gate): without the body guard, any authenticated user could
 * import/replace another module's component set (IDOR / OWASP A01:2021).
 *
 * The upload is bounded in size and validated as JSON BEFORE the parser
 * (`SbomParserService`) is ever invoked — an oversized or non-JSON upload
 * never reaches parsing and never changes the previous component set.
 *
 * @category  Controller
 * @package   OCA\SoftwareCatalog\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/specs/sbom-import/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Controller;

use OCA\SoftwareCatalog\AppInfo\Application;
use OCA\SoftwareCatalog\Exception\UnsupportedSbomFormatException;
use OCA\SoftwareCatalog\Service\SbomImportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * SBOM upload + status endpoints, scoped to a single `moduleVersie`.
 *
 * @spec openspec/specs/sbom-import/spec.md
 */
class SbomController extends Controller
{
    /**
     * Maximum accepted upload size in bytes (design: default 10 MB).
     */
    private const MAX_UPLOAD_BYTES = 10485760;

    /**
     * Groups (beyond admin) allowed to import an SBOM, subject to the
     * per-object manage-ACL check on the target module (design: "admin
     * group membership OR manage-ACL on the target moduleVersie's parent
     * module").
     *
     * @var array<int,string>
     */
    private const MANAGE_GROUPS = ['software-catalog-admins', 'aanbod-beheerder', 'functioneel-beheerder'];

    /**
     * Constructor.
     *
     * @param IRequest          $request       The request.
     * @param IUserSession      $userSession   The user session (auth guard).
     * @param IGroupManager     $groupManager  Group membership (role/admin guard).
     * @param SbomImportService $importService The SBOM import service.
     * @param LoggerInterface   $logger        Logger.
     */
    public function __construct(
        IRequest $request,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly SbomImportService $importService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Import an SBOM for a `moduleVersie`. Multipart upload only.
     *
     * `DoesNotExistException` is caught here (not just the explicit
     * `RuntimeException` "moduleVersie not found" guard already inside
     * `SbomImportService::importForModuleVersie()`): OpenRegister's real
     * `ObjectService::find()` does not reliably return `null` for a missing
     * object the way the unit-test stub does — for a well-formed but
     * non-existent uuid its cross-table fallback lookup re-throws
     * `OCP\AppFramework\Db\DoesNotExistException` instead. That exception can
     * originate from `authorizeManage()` (via
     * `SbomImportService::resolveParentModuleUuid()`, for a non-admin/
     * manage-group caller) or from `importForModuleVersie()` itself, so the
     * whole method body is covered by one outer try/catch rather than
     * threading a guard through each call site individually. Uncaught, this
     * escaped as a 500 (confirmed live for `GET .../sbom` on
     * `getSbomImportStatus()` — the same defect class applies here).
     *
     * @param string $moduleVersieUuid The target moduleVersie's uuid.
     *
     * @return JSONResponse The import result summary, or a 400/401/403/404/422/500.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @spec            openspec/specs/sbom-import/spec.md#requirement-uploaded-sbom-files-are-bounded-in-size-and-json-only
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function importSbom(string $moduleVersieUuid): JSONResponse
    {
        try {
            $guard = $this->authorizeManage(moduleVersieUuid: $moduleVersieUuid);
            if ($guard instanceof JSONResponse) {
                return $guard;
            }

            $validated = $this->validateUpload(moduleVersieUuid: $moduleVersieUuid);
            if ($validated instanceof JSONResponse) {
                return $validated;
            }

            $result = $this->importService->importForModuleVersie(
                moduleVersieUuid: $moduleVersieUuid,
                rawJson: $validated['contents'],
                format: $validated['format'],
                fileName: $validated['fileName']
            );
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                data: ['message' => 'moduleVersie not found: '.$moduleVersieUuid, 'error' => 'MODULE_VERSION_NOT_FOUND'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        } catch (UnsupportedSbomFormatException $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage(), 'error' => 'UNSUPPORTED_SBOM_FORMAT'],
                statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
            );
        } catch (\RuntimeException $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage(), 'error' => 'MODULE_VERSION_NOT_FOUND'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        } catch (\Exception $e) {
            $this->logger->error(
                'SbomController: import failed',
                ['moduleVersieUuid' => $moduleVersieUuid, 'error' => $e->getMessage()]
            );
            return new JSONResponse(
                data: ['message' => 'Import failed: '.$e->getMessage(), 'error' => 'IMPORT_FAILED'],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try

        return new JSONResponse(data: $result);
    }//end importSbom()

    /**
     * Validate the multipart upload (present, bounded size, readable, valid
     * JSON, known `format` param) BEFORE the parser is ever invoked — an
     * oversized or non-JSON upload never reaches parsing and never changes
     * the previous component set.
     *
     * @param string $moduleVersieUuid The target moduleVersie's uuid (for the size-rejection log line).
     *
     * @return array{contents:string,format:string,fileName:string}|JSONResponse
     *         The validated upload, or a 400 JSONResponse on the first failed check.
     *
     * @spec openspec/specs/sbom-import/spec.md#requirement-uploaded-sbom-files-are-bounded-in-size-and-json-only
     */
    private function validateUpload(string $moduleVersieUuid): array|JSONResponse
    {
        $upload = $this->parseUploadedFile();
        if ($upload === null) {
            return new JSONResponse(
                data: ['message' => 'No SBOM file uploaded', 'error' => 'NO_FILE_UPLOADED'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        if ($upload['fileSize'] > self::MAX_UPLOAD_BYTES) {
            $this->logger->warning(
                'SbomController: upload rejected (too large)',
                ['moduleVersieUuid' => $moduleVersieUuid, 'fileSize' => $upload['fileSize']]
            );
            return new JSONResponse(
                data: [
                    'message' => sprintf('File exceeds the maximum allowed size of %d bytes', self::MAX_UPLOAD_BYTES),
                    'error'   => 'FILE_TOO_LARGE',
                ],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        $contents = false;
        if (is_readable($upload['tmpName']) === true) {
            $contents = file_get_contents($upload['tmpName']);
        }

        if ($contents === false) {
            return new JSONResponse(
                data: ['message' => 'Uploaded file could not be read', 'error' => 'FILE_UNREADABLE'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        // JSON-only gate BEFORE the parser is invoked (semantic bomFormat/
        // specVersion validation happens inside SbomParserService).
        // json_validate() (PHP 8.3+) replaces the older
        // `json_decode($contents); json_last_error()` side-effect idiom: it
        // populates the same global error state — so json_last_error_msg()
        // below is unchanged — without materialising a decoded value that is
        // immediately discarded, and without the peak-memory cost of decoding
        // an SBOM that is about to be re-parsed by SbomParserService anyway.
        if (json_validate($contents) === false) {
            return new JSONResponse(
                data: [
                    'message' => 'Uploaded file is not valid JSON: '.json_last_error_msg(),
                    'error'   => 'INVALID_JSON',
                ],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        $format = (string) $this->request->getParam('format', SbomImportService::SUPPORTED_FORMATS[0]);
        if (in_array($format, SbomImportService::SUPPORTED_FORMATS, true) === false) {
            return new JSONResponse(
                data: ['message' => 'Unknown SBOM format: '.$format, 'error' => 'UNKNOWN_FORMAT'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        return [
            'contents' => $contents,
            'format'   => $format,
            'fileName' => $upload['fileName'],
        ];
    }//end validateUpload()

    /**
     * Read SBOM import status/provenance for a `moduleVersie`, optionally
     * including a `progress-tracking` snapshot when `operationId` is given.
     *
     * Confirmed live (500 on a non-existent `moduleVersieUuid`):
     * `SbomImportService::getStatus()` calls OpenRegister's real
     * `ObjectService::find()`, which — despite its `?ObjectEntity` return
     * type suggesting `null` on a miss (and despite the local
     * `if ($moduleVersie !== null)` guard already inside `getStatus()`) —
     * can re-throw `OCP\AppFramework\Db\DoesNotExistException` from its
     * cross-table fallback lookup for a well-formed but unresolvable uuid,
     * rather than returning `null`. Uncaught, that propagated straight
     * through this controller method as a 500. Caught here and translated
     * to a proper 404 — the endpoint is a plain "read status for this uuid"
     * lookup, so a missing `moduleVersie` is an ordinary not-found, not a
     * server error.
     *
     * @param string $moduleVersieUuid The target moduleVersie's uuid.
     *
     * @return JSONResponse `{sbomLastImportedAt, sbomFormat, sbomFileName, progress}`, or a 404.
     *
     * @NoAdminRequired
     * @spec            openspec/specs/sbom-import/spec.md#requirement-large-imports-run-in-bounded-batches-with-progress-reporting
     * @spec            openspec/specs/sbom-import/spec.md#requirement-moduleversie-records-sbom-import-provenance
     */
    #[NoAdminRequired]
    public function getSbomImportStatus(string $moduleVersieUuid): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(data: ['message' => 'Not logged in'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $operationId = $this->request->getParam('operationId');
        if (is_string($operationId) === false) {
            $operationId = null;
        }

        try {
            return new JSONResponse(
                data: $this->importService->getStatus(
                    moduleVersieUuid: $moduleVersieUuid,
                    operationId: $operationId
                )
            );
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                data: ['message' => 'moduleVersie not found: '.$moduleVersieUuid, 'error' => 'MODULE_VERSION_NOT_FOUND'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }
    }//end getSbomImportStatus()

    /**
     * Manage-ACL authorization guard (IDOR guard). Returns a JSONResponse to
     * short-circuit on failure, or null when the caller may import.
     *
     * Authorized when the caller is an admin, OR is a member of one of
     * `self::MANAGE_GROUPS` AND can resolve the target moduleVersie's parent
     * `module` under normal RBAC (manage-ACL proxy: readable-under-RBAC AND
     * an editor-tier group, per the module/kwetsbaarheid schema's own role
     * vocabulary).
     *
     * @param string $moduleVersieUuid The target moduleVersie's uuid.
     *
     * @return JSONResponse|null Error response, or null when authorized.
     *
     * @spec openspec/specs/sbom-import/spec.md#requirement-uploaded-sbom-files-are-bounded-in-size-and-json-only
     */
    private function authorizeManage(string $moduleVersieUuid): ?JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['message' => 'Not logged in'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        if ($this->groupManager->isAdmin($user->getUID()) === true) {
            return null;
        }

        $inManageGroup = false;
        foreach (self::MANAGE_GROUPS as $group) {
            if ($this->groupManager->isInGroup($user->getUID(), $group) === true) {
                $inManageGroup = true;
                break;
            }
        }

        if ($inManageGroup === false) {
            $this->logger->warning(
                'SbomController: import refused (not admin, no manage group)',
                ['moduleVersieUuid' => $moduleVersieUuid, 'uid' => $user->getUID()]
            );
            return new JSONResponse(
                data: ['message' => 'Admin privileges or a manage role are required to import an SBOM'],
                statusCode: Http::STATUS_FORBIDDEN
            );
        }

        $moduleUuid = $this->importService->resolveParentModuleUuid($moduleVersieUuid);
        if ($moduleUuid === null || $this->importService->userCanReadModule($moduleUuid) === false) {
            $this->logger->warning(
                'SbomController: import refused (no manage-ACL on target module)',
                ['moduleVersieUuid' => $moduleVersieUuid, 'uid' => $user->getUID()]
            );
            return new JSONResponse(
                data: ['message' => 'You do not have manage access to this application'],
                statusCode: Http::STATUS_FORBIDDEN
            );
        }

        return null;
    }//end authorizeManage()

    /**
     * Parse the uploaded SBOM file from the multipart request.
     *
     * Inspects both the NC request wrapper and the `$_FILES` superglobal as
     * a fallback, mirroring `SettingsController::parseArchiMateFileUpload`.
     *
     * @return array{tmpName:string,fileName:string,fileSize:int}|null Upload
     *         info, or null when no file was uploaded.
     *
     * @SuppressWarnings(PHPMD.Superglobals)
     */
    private function parseUploadedFile(): ?array
    {
        $uploadedFiles = $this->request->getUploadedFile('sbomFile');
        $filesArray    = $_FILES['sbomFile'] ?? null;

        if (empty($uploadedFiles) === true && empty($filesArray) === true) {
            return null;
        }

        $fileData = $filesArray;
        if ($uploadedFiles !== null) {
            $fileData = $uploadedFiles;
        }

        $tmpName = $fileData['tmp_name'] ?? '';

        $fileSize = $fileData['size'] ?? null;
        if ($fileSize === null) {
            $fileSize = 0;
            if (is_string($tmpName) === true && $tmpName !== '') {
                $fileSize = (int) filesize($tmpName);
            }
        }

        return [
            'tmpName'  => $tmpName,
            'fileName' => $fileData['name'] ?? 'sbom.json',
            'fileSize' => (int) $fileSize,
        ];
    }//end parseUploadedFile()
}//end class
