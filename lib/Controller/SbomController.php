<?php

/**
 * Stackiq SbomController.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @package   OCA\Stackiq\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/sbom-import/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Controller;

use OCA\Stackiq\AppInfo\AppIdentity;
use OCA\Stackiq\Exception\UnsupportedSbomFormatException;
use OCA\Stackiq\Service\SbomImportService;
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
class SbomController extends Controller {
	/**
	 * Maximum accepted upload size in bytes (design: default 10 MB).
	 */
	private const MAX_UPLOAD_BYTES = 10485760;

	/**
	 * Import failures answered with the exception's own message: error code
	 * and status per exception class, the first match winning.
	 *
	 * @var array<class-string,array{0:string,1:int}>
	 */
	private const MESSAGE_FAILURES = [
		UnsupportedSbomFormatException::class => ['UNSUPPORTED_SBOM_FORMAT', Http::STATUS_UNPROCESSABLE_ENTITY],
		\RuntimeException::class => ['MODULE_VERSION_NOT_FOUND', Http::STATUS_NOT_FOUND],
	];

	/**
	 * Groups (beyond admin) allowed to import an SBOM, subject to the
	 * per-object manage-ACL check on the target module (design: "admin
	 * group membership OR manage-ACL on the target moduleVersie's parent
	 * module").
	 *
	 * `software-catalog-admins` is FROZEN across the stackiq -> stackiq
	 * rename: it is a Nextcloud group id, and membership is stored against that
	 * literal in `oc_group_user`. Renaming it makes every membership check miss
	 * without raising anything, so the app would silently drop the permissions
	 * of everyone currently in the group.
	 *
	 * @var array<int,string>
	 */
	private const MANAGE_GROUPS = ['software-catalog-admins', 'aanbod-beheerder', 'functioneel-beheerder'];

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param IUserSession $userSession The user session (auth guard).
	 * @param IGroupManager $groupManager Group membership (role/admin guard).
	 * @param SbomImportService $importService The SBOM import service.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly SbomImportService $importService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
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
	public function importSbom(string $moduleVersieUuid): JSONResponse {
		try {
			$result = $this->authorizedImport(moduleVersieUuid: $moduleVersieUuid);
		} catch (\Exception $e) {
			return $this->importFailureResponse(error: $e, moduleVersieUuid: $moduleVersieUuid);
		}

		if ($result instanceof JSONResponse) {
			return $result;
		}

		return new JSONResponse(data: $result);
	}//end importSbom()

	/**
	 * Check the caller's manage access and the upload, then import.
	 *
	 * @param string $moduleVersieUuid The target moduleVersie's uuid.
	 *
	 * @return array|JSONResponse The import result summary, or the refusal.
	 */
	private function authorizedImport(string $moduleVersieUuid): array|JSONResponse {
		$validated = $this->authorizeManage(moduleVersieUuid: $moduleVersieUuid)
			?? $this->validateUpload(moduleVersieUuid: $moduleVersieUuid);
		if ($validated instanceof JSONResponse) {
			return $validated;
		}

		return $this->importService->importForModuleVersie(
			moduleVersieUuid: $moduleVersieUuid,
			rawJson: $validated['contents'],
			format: $validated['format'],
			fileName: $validated['fileName']
		);
	}//end authorizedImport()

	/**
	 * The response for a failed import: 404 for an unknown moduleVersie, 422
	 * for an unsupported format, else a logged 500.
	 *
	 * @param \Exception $error            The failure.
	 * @param string     $moduleVersieUuid The target moduleVersie's uuid.
	 *
	 * @return JSONResponse
	 */
	private function importFailureResponse(\Exception $error, string $moduleVersieUuid): JSONResponse {
		if ($error instanceof DoesNotExistException) {
			return $this->notFound(moduleVersieUuid: $moduleVersieUuid);
		}

		foreach (self::MESSAGE_FAILURES as $class => [$code, $status]) {
			if ($error instanceof $class) {
				return new JSONResponse(data: ['message' => $error->getMessage(), 'error' => $code], statusCode: $status);
			}
		}

		$this->logger->error('SbomController: import failed', ['moduleVersieUuid' => $moduleVersieUuid, 'error' => $error->getMessage()]);
		$data = ['message' => 'Import failed: ' . $error->getMessage(), 'error' => 'IMPORT_FAILED'];
		return new JSONResponse(data: $data, statusCode: Http::STATUS_INTERNAL_SERVER_ERROR);
	}//end importFailureResponse()

	/**
	 * The 404 for an unknown (or unreadable) moduleVersie.
	 *
	 * @param string $moduleVersieUuid The target moduleVersie's uuid.
	 *
	 * @return JSONResponse
	 */
	private function notFound(string $moduleVersieUuid): JSONResponse {
		return new JSONResponse(
			data: ['message' => 'moduleVersie not found: ' . $moduleVersieUuid, 'error' => 'MODULE_VERSION_NOT_FOUND'],
			statusCode: Http::STATUS_NOT_FOUND
		);
	}//end notFound()

	/**
	 * Validate the multipart upload (present, bounded size, readable, valid
	 * JSON, known `format` param) BEFORE the parser is ever invoked — an
	 * oversized or non-JSON upload never reaches parsing and never changes
	 * the previous component set.
	 *
	 * @param string $moduleVersieUuid The target moduleVersie's uuid (for the size-rejection log line).
	 *
	 * @return array{contents:string,format:string,fileName:string}|JSONResponse
	 *                                                                           The validated upload, or a 400 JSONResponse on the first failed check.
	 *
	 * @spec openspec/specs/sbom-import/spec.md#requirement-uploaded-sbom-files-are-bounded-in-size-and-json-only
	 */
	private function validateUpload(string $moduleVersieUuid): array|JSONResponse {
		$upload = $this->parseUploadedFile();
		if ($upload === null) {
			return $this->badRequest(message: 'No SBOM file uploaded', error: 'NO_FILE_UPLOADED');
		}

		$contents = $this->uploadContents(upload: $upload, moduleVersieUuid: $moduleVersieUuid);
		if ($contents instanceof JSONResponse) {
			return $contents;
		}

		return $this->withRequestedFormat(contents: $contents, fileName: $upload['fileName']);
	}//end validateUpload()

	/**
	 * The validated upload with the requested `format` param, which defaults
	 * to the first supported format.
	 *
	 * @param string $contents The upload's contents.
	 * @param string $fileName The upload's file name.
	 *
	 * @return array{contents:string,format:string,fileName:string}|JSONResponse
	 *                                                                           The validated upload, or a 400 for an unknown format.
	 */
	private function withRequestedFormat(string $contents, string $fileName): array|JSONResponse {
		$format = (string)$this->request->getParam('format', SbomImportService::SUPPORTED_FORMATS[0]);
		if (in_array($format, SbomImportService::SUPPORTED_FORMATS, true) === false) {
			return $this->badRequest(message: 'Unknown SBOM format: ' . $format, error: 'UNKNOWN_FORMAT');
		}

		return [
			'contents' => $contents,
			'format' => $format,
			'fileName' => $fileName,
		];
	}//end withRequestedFormat()

	/**
	 * The upload's contents once it is within the size limit, readable and valid JSON.
	 *
	 * @param array{tmpName:string,fileName:string,fileSize:int} $upload           The upload.
	 * @param string                                             $moduleVersieUuid The target moduleVersie's uuid (for the size-rejection log line).
	 *
	 * @return string|JSONResponse The contents, or a 400 JSONResponse on the first failed check.
	 */
	private function uploadContents(array $upload, string $moduleVersieUuid): string|JSONResponse {
		if ($upload['fileSize'] > self::MAX_UPLOAD_BYTES) {
			return $this->tooLargeResponse(fileSize: $upload['fileSize'], moduleVersieUuid: $moduleVersieUuid);
		}

		$contents = $this->readUpload(tmpName: $upload['tmpName']);
		if ($contents === false) {
			return $this->badRequest(message: 'Uploaded file could not be read', error: 'FILE_UNREADABLE');
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
			return $this->badRequest(message: 'Uploaded file is not valid JSON: ' . json_last_error_msg(), error: 'INVALID_JSON');
		}

		return $contents;
	}//end uploadContents()

	/**
	 * The uploaded temporary file's contents, false when it cannot be read.
	 *
	 * @param string $tmpName The temporary file path.
	 *
	 * @return string|false
	 */
	private function readUpload(string $tmpName): string|false {
		if (is_readable($tmpName) === true) {
			return file_get_contents($tmpName);
		}

		return false;
	}//end readUpload()

	/**
	 * Log and refuse an upload over the size limit.
	 *
	 * @param int    $fileSize         The upload size in bytes.
	 * @param string $moduleVersieUuid The target moduleVersie's uuid.
	 *
	 * @return JSONResponse
	 */
	private function tooLargeResponse(int $fileSize, string $moduleVersieUuid): JSONResponse {
		$this->logger->warning(
			'SbomController: upload rejected (too large)',
			['moduleVersieUuid' => $moduleVersieUuid, 'fileSize' => $fileSize]
		);
		return $this->badRequest(
			message: sprintf('File exceeds the maximum allowed size of %d bytes', self::MAX_UPLOAD_BYTES),
			error: 'FILE_TOO_LARGE'
		);
	}//end tooLargeResponse()

	/**
	 * A 400 response with a message and an error code.
	 *
	 * @param string $message The message.
	 * @param string $error   The error code.
	 *
	 * @return JSONResponse
	 */
	private function badRequest(string $message, string $error): JSONResponse {
		return new JSONResponse(
			data: ['message' => $message, 'error' => $error],
			statusCode: Http::STATUS_BAD_REQUEST
		);
	}//end badRequest()

	/**
	 * Read SBOM import status/provenance for a `moduleVersie`, optionally
	 * including a `progress-tracking` snapshot when `operationId` is given.
	 *
	 * Confirmed live (500 on a non-existent `moduleVersieUuid`):
	 * `SbomImportService::getStatus()` calls OpenRegister's real
	 * `ObjectService::find()`, which — despite its `?ObjectEntity` return
	 * type suggesting `null` on a miss (and despite the local
	 * `if ($moduleVersion !== null)` guard already inside `getStatus()`) —
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
	public function getSbomImportStatus(string $moduleVersieUuid): JSONResponse {
		// The read guard, authorizeRead(), answers the 401 for a caller without a session.
		// The guard goes INSIDE the try, for the reason importSbom()'s docblock
		// already records: authorizeRead() reaches
		// SbomImportService::resolveParentModuleUuid(), and OpenRegister's real
		// ObjectService::find() RE-THROWS DoesNotExistException for a
		// well-formed but non-existent uuid rather than returning null. Guarding
		// outside the try would therefore turn this endpoint's clean 404 into a
		// 500 for exactly the callers the guard was added for — non-admins
		// passing an unknown id.
		try {
			return $this->authorizeRead(moduleVersieUuid: $moduleVersieUuid)
				?? $this->statusResponse(moduleVersieUuid: $moduleVersieUuid);
		} catch (DoesNotExistException $e) {
			return $this->notFound(moduleVersieUuid: $moduleVersieUuid);
		}
	}//end getSbomImportStatus()

	/**
	 * The import status for a moduleVersie, with the `operationId` progress
	 * snapshot when that param is a string.
	 *
	 * @param string $moduleVersieUuid The target moduleVersie's uuid.
	 *
	 * @return JSONResponse
	 */
	private function statusResponse(string $moduleVersieUuid): JSONResponse {
		$operationId = $this->request->getParam('operationId');
		if (is_string($operationId) === false) {
			$operationId = null;
		}

		return new JSONResponse(
			data: $this->importService->getStatus(
				moduleVersieUuid: $moduleVersieUuid,
				operationId: $operationId
			)
		);
	}//end statusResponse()

	/**
	 * Read-ACL authorization guard (IDOR guard) for the import-status read.
	 *
	 * `getSbomImportStatus()` took a caller-supplied `moduleVersieUuid` all the
	 * way to `SbomImportService::getStatus()`, whose lookup runs with
	 * `_rbac: false, _multitenancy: false` — so the object reference was never
	 * scoped to the caller and any authenticated user could read any
	 * moduleVersie's import provenance by substituting a uuid.
	 *
	 * This is `authorizeManage()`'s read tier: the same two building blocks
	 * (`resolveParentModuleUuid` + `userCanReadModule`) minus the editor-group
	 * membership requirement, because reading a status is not managing an
	 * import. `userCanReadModule()` resolves with `_rbac: true,
	 * _multitenancy: true`, and — checked rather than assumed — the `module`
	 * schema carries a real `authorization.read` ACL (group tiers plus an
	 * `_organisation` match), so this is a genuine scope rather than the
	 * default-open case an authorization-less schema would give.
	 *
	 * Refuses with 404, not 403, deliberately: the sibling not-found path
	 * already answers 404 for an unknown uuid, and matching it keeps the
	 * endpoint from becoming an existence oracle for other tenants' ids.
	 *
	 * @param string $moduleVersieUuid The target moduleVersie's uuid.
	 *
	 * @return JSONResponse|null Error response, or null when authorized.
	 *
	 * @spec openspec/specs/sbom-import/spec.md#requirement-moduleversie-records-sbom-import-provenance
	 */
	private function authorizeRead(string $moduleVersieUuid): ?JSONResponse {
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null) {
			return new JSONResponse(data: ['message' => 'Not logged in'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		if ($this->groupManager->isAdmin($uid) === true || $this->canReadParentModule(moduleVersieUuid: $moduleVersieUuid) === true) {
			return null;
		}

		$this->logger->warning(
			'SbomController: status read refused (no read access to the parent module)',
			['moduleVersieUuid' => $moduleVersieUuid, 'uid' => $uid]
		);
		return $this->notFound(moduleVersieUuid: $moduleVersieUuid);
	}//end authorizeRead()

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
	private function authorizeManage(string $moduleVersieUuid): ?JSONResponse {
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null) {
			return new JSONResponse(data: ['message' => 'Not logged in'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		if ($this->groupManager->isAdmin($uid) === true) {
			return null;
		}

		return $this->nonAdminManageRefusal(moduleVersieUuid: $moduleVersieUuid, uid: $uid);
	}//end authorizeManage()

	/**
	 * The refusal for a non-admin import: 403 without a manage group, 403
	 * without read access to the parent module, else null.
	 *
	 * @param string $moduleVersieUuid The target moduleVersie's uuid.
	 * @param string $uid              The caller's uid.
	 *
	 * @return JSONResponse|null
	 */
	private function nonAdminManageRefusal(string $moduleVersieUuid, string $uid): ?JSONResponse {
		$context = ['moduleVersieUuid' => $moduleVersieUuid, 'uid' => $uid];
		if ($this->isInManageGroup(uid: $uid) === false) {
			$this->logger->warning('SbomController: import refused (not admin, no manage group)', $context);
			$message = 'Admin privileges or a manage role are required to import an SBOM';
			return new JSONResponse(data: ['message' => $message], statusCode: Http::STATUS_FORBIDDEN);
		}

		if ($this->canReadParentModule(moduleVersieUuid: $moduleVersieUuid) === false) {
			$this->logger->warning('SbomController: import refused (no manage-ACL on target module)', $context);
			$message = 'You do not have manage access to this application';
			return new JSONResponse(data: ['message' => $message], statusCode: Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end nonAdminManageRefusal()

	/**
	 * Whether the user is a member of one of the manage groups.
	 *
	 * @param string $uid The caller's uid.
	 *
	 * @return bool
	 */
	private function isInManageGroup(string $uid): bool {
		foreach (self::MANAGE_GROUPS as $group) {
			if ($this->groupManager->isInGroup($uid, $group) === true) {
				return true;
			}
		}

		return false;
	}//end isInManageGroup()

	/**
	 * Whether the caller can read the moduleVersie's parent module under normal RBAC.
	 *
	 * @param string $moduleVersieUuid The target moduleVersie's uuid.
	 *
	 * @return bool False when the parent module is unknown or not readable.
	 */
	private function canReadParentModule(string $moduleVersieUuid): bool {
		$moduleUuid = $this->importService->resolveParentModuleUuid($moduleVersieUuid);
		return $moduleUuid !== null && $this->importService->userCanReadModule($moduleUuid) === true;
	}//end canReadParentModule()

	/**
	 * Parse the uploaded SBOM file from the multipart request.
	 *
	 * Inspects both the NC request wrapper and the `$_FILES` superglobal as
	 * a fallback, mirroring `SettingsController::parseArchiMateFileUpload`.
	 *
	 * @return array{tmpName:string,fileName:string,fileSize:int}|null Upload
	 *                                                                 info, or null when no file was uploaded.
	 *
	 * @SuppressWarnings(PHPMD.Superglobals)
	 */
	private function parseUploadedFile(): ?array {
		$uploadedFiles = $this->request->getUploadedFile('sbomFile');
		$filesArray = $_FILES['sbomFile'] ?? null;

		if (empty($uploadedFiles) === true && empty($filesArray) === true) {
			return null;
		}

		$fileData = $uploadedFiles ?? $filesArray;
		$tmpName = $fileData['tmp_name'] ?? '';

		return [
			'tmpName' => $tmpName,
			'fileName' => $fileData['name'] ?? 'sbom.json',
			'fileSize' => (int)($fileData['size'] ?? $this->fileSizeOnDisk(tmpName: $tmpName)),
		];
	}//end parseUploadedFile()

	/**
	 * The size of the uploaded temporary file, 0 when there is none.
	 *
	 * @param mixed $tmpName The temporary file path.
	 *
	 * @return int
	 */
	private function fileSizeOnDisk(mixed $tmpName): int {
		if (is_string($tmpName) === true && $tmpName !== '') {
			return (int)filesize($tmpName);
		}

		return 0;
	}//end fileSizeOnDisk()
}//end class
