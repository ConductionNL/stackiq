<?php

/**
 * Stackiq CmdbImportController.
 *
 * Upload endpoint for a TOPdesk CMDB export (xlsx) and the cancel endpoint
 * of a running import (openspec/changes/cmdb-export-import/contract.md).
 *
 * AUTH (ADR-005): both methods are `#[AuthorizedAdminSetting(StackiqAdmin::class)]`
 * and carry neither `NoAdminRequired` nor `NoCSRFRequired`, so Nextcloud's
 * middleware lets only a Nextcloud admin, or a member of a group an admin
 * delegated the stackiq admin settings to, reach them, and only with a valid
 * CSRF token (403 / 412 before the body runs). The import writes into the
 * catalogue for a whole municipality, which is an administrative action, so
 * a member of the app's own manager groups is refused unless the stackiq
 * settings were delegated to that group.
 *
 * The upload is checked before it is parsed, in the order of design D10:
 * present, size, xlsx, `missingRecords`, municipality. Every expected service
 * exception is translated here to the status contract.md gives it; anything
 * else is 500 `IMPORT_FAILED` with a generic message and the detail in the log.
 *
 * @category  Controller
 * @package   OCA\Stackiq\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/cmdb-export-import/tasks.md#task-8
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Controller;

use OCA\Stackiq\AppInfo\Application;
use OCA\Stackiq\Exception\CmdbImportException;
use OCA\Stackiq\Service\CmdbExportImportService;
use OCA\Stackiq\Settings\StackiqAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * CMDB import and cancel, for (delegated) stackiq admins and CSRF-protected.
 *
 * @spec openspec/changes/cmdb-export-import/tasks.md#task-8
 */
class CmdbImportController extends Controller {
	/**
	 * The multipart field of the export.
	 */
	public const FILE_FIELD = 'cmdbFile';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param CmdbExportImportService $importService The import service.
	 * @param IL10N $l10n Translations of the error messages.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-8
	 */
	public function __construct(
		IRequest $request,
		private readonly CmdbExportImportService $importService,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Import a TOPdesk CMDB export for one municipality.
	 *
	 * Multipart fields: `cmdbFile`, `municipalityUuid` or `municipalityName`,
	 * `updateExisting` (default true), `missingRecords` (only `keep`) and
	 * `operationId` (pattern `cmdb-` plus 8 to 64 letters, digits or hyphens).
	 *
	 * @AuthorizedAdminSetting(settings=OCA\Stackiq\Settings\StackiqAdmin)
	 *
	 * @return JSONResponse The report (200), or an error envelope with the contract code.
	 *
	 * @auth admin-only importing a CMDB export rewrites the catalogue of a whole municipality, so only a (delegated) stackiq admin runs it.
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-8
	 */
	#[AuthorizedAdminSetting(settings: StackiqAdmin::class)]
	public function import(): JSONResponse {
		try {
			// The upload checks run inside the same boundary, so an unexpected
			// error from the ZIP check is IMPORT_FAILED too, not a bare 500.
			$validated = $this->validateRequest();
			if ($validated instanceof JSONResponse) {
				return $validated;
			}

			$report = $this->importService->import(path: $validated['path'], options: $validated['options']);
		} catch (CmdbImportException $e) {
			$this->logger->info(
				'CmdbImportController: import refused',
				['error' => $e->getErrorCode(), 'details' => $e->getDetails(), 'reason' => $e->getMessage()]
			);
			return $this->fromException(e: $e);
		} catch (\Throwable $e) {
			$this->logger->error('CmdbImportController: import failed', ['exception' => $e]);
			return $this->error(code: 'IMPORT_FAILED', status: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(data: $report, statusCode: Http::STATUS_OK);
	}//end import()

	/**
	 * Check the request in the order of design D10, before anything is parsed.
	 *
	 * Present, size, xlsx, `missingRecords`, municipality.
	 *
	 * @return array{path: string, options: array<string, mixed>}|JSONResponse The import input, or the first error.
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-8
	 */
	private function validateRequest(): array|JSONResponse {
		$maxBytes = $this->importService->maxFileBytes();
		$upload = $this->uploadedFile();
		if ($upload === null) {
			return $this->missingUpload(maxBytes: $maxBytes);
		}

		if ($upload['serverError'] !== null) {
			// The upload reached PHP but could not be stored: a server problem, not the admin's.
			$this->logger->error('CmdbImportController: the upload could not be stored', ['uploadError' => $upload['serverError']]);
			return $this->error(code: 'UPLOAD_FAILED', status: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if ($upload['tooLarge'] === true) {
			// PHP's upload_max_filesize fired; it can be lower than the profile's maximum.
			$limit = min($maxBytes, ($this->iniBytes(name: 'upload_max_filesize') ?? $maxBytes));
			return $this->error(code: 'FILE_TOO_LARGE', status: Http::STATUS_REQUEST_ENTITY_TOO_LARGE, details: ['maxBytes' => $limit]);
		}

		if ($upload['size'] > $maxBytes) {
			return $this->error(code: 'FILE_TOO_LARGE', status: Http::STATUS_REQUEST_ENTITY_TOO_LARGE, details: ['maxBytes' => $maxBytes]);
		}

		try {
			$this->importService->assertXlsx(path: $upload['tmpName'], fileName: $upload['name']);
		} catch (CmdbImportException $e) {
			return $this->fromException(e: $e);
		}

		return $this->readOptions(path: $upload['tmpName']);
	}//end validateRequest()

	/**
	 * The answer when no upload arrived: 413 when the body was over post_max_size, else 400.
	 *
	 * PHP drops the whole body, files and fields alike, when it is larger than
	 * post_max_size, so such a request looks like one without a file.
	 *
	 * @param int $maxBytes The profile's maximum.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-8
	 */
	private function missingUpload(int $maxBytes): JSONResponse {
		$postLimit = $this->iniBytes(name: 'post_max_size');
		$length = (int)$this->request->getHeader('Content-Length');
		if ($postLimit !== null && $postLimit > 0 && $length > $postLimit) {
			return $this->error(
				code: 'FILE_TOO_LARGE',
				status: Http::STATUS_REQUEST_ENTITY_TOO_LARGE,
				details: ['maxBytes' => min($maxBytes, $postLimit)]
			);
		}

		return $this->error(code: 'NO_FILE_UPLOADED', status: Http::STATUS_BAD_REQUEST);
	}//end missingUpload()

	/**
	 * A PHP size setting such as `10M`, in bytes.
	 *
	 * Protected so a test can stand in for php.ini, whose size settings cannot
	 * be changed at run time.
	 *
	 * @param string $name The ini setting.
	 *
	 * @return int|null Null when it is unset or not a size; 0 means no limit.
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-8
	 */
	protected function iniBytes(string $name): ?int {
		$value = trim((string)ini_get($name));
		if (preg_match('/^(\d+)\s*([KMG]?)$/i', $value, $matches) !== 1) {
			return null;
		}

		$exponent = ['' => 0, 'K' => 1, 'M' => 2, 'G' => 3][strtoupper($matches[2])];
		return ((int)$matches[1] * (1024 ** $exponent));
	}//end iniBytes()

	/**
	 * Read and check the form fields, after the upload itself was checked.
	 *
	 * A field sent as an array (`municipalityName[]=x`) is refused instead of
	 * being cast to the string "Array".
	 *
	 * @param string $path The checked upload.
	 *
	 * @return array{path: string, options: array<string, mixed>}|JSONResponse The import input, or the first error.
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-8
	 */
	private function readOptions(string $path): array|JSONResponse {
		$missingRecords = $this->stringParam(name: 'missingRecords', default: 'keep');
		if ($missingRecords === null) {
			return $this->invalidField(field: 'missingRecords');
		}

		if ($this->importService->supportsMissingRecords(mode: $missingRecords) === false) {
			return $this->error(code: 'MISSING_RECORDS_UNSUPPORTED', status: Http::STATUS_UNPROCESSABLE_ENTITY, details: ['accepted' => ['keep']]);
		}

		// An unrecognised value is refused rather than read as true: true is the mode that overwrites.
		$updateExisting = $this->booleanParam(name: 'updateExisting', default: true);
		if ($updateExisting === null) {
			return $this->invalidField(field: 'updateExisting', accepted: ['true', 'false']);
		}

		$municipalityUuid = $this->stringParam(name: 'municipalityUuid', default: '');
		$municipalityName = $this->stringParam(name: 'municipalityName', default: '');
		if ($municipalityUuid === null) {
			return $this->invalidField(field: 'municipalityUuid');
		}

		if ($municipalityName === null) {
			return $this->invalidField(field: 'municipalityName');
		}

		$municipalityUuid = trim($municipalityUuid);
		$municipalityName = trim($municipalityName);
		if ($municipalityUuid === '' && $municipalityName === '') {
			return $this->error(code: CmdbImportException::MUNICIPALITY_REQUIRED, status: Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return [
			'path' => $path,
			'options' => [
				'municipalityUuid' => $municipalityUuid,
				'municipalityName' => $municipalityName,
				'updateExisting' => $updateExisting,
				'operationId' => $this->request->getParam('operationId'),
			],
		];
	}//end readOptions()


	/**
	 * Ask a running CMDB import to stop between rows.
	 *
	 * @param string $operationId The operation id.
	 *
	 * @AuthorizedAdminSetting(settings=OCA\Stackiq\Settings\StackiqAdmin)
	 *
	 * @return JSONResponse `{success, cancelRequested}`, or 404 OPERATION_NOT_FOUND.
	 *
	 * @auth admin-only cancelling an import is part of running it, so only a (delegated) stackiq admin may do it (CSRF checked).
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-7
	 */
	#[AuthorizedAdminSetting(settings: StackiqAdmin::class)]
	public function cancel(string $operationId): JSONResponse {
		if ($this->importService->requestCancel(operationId: $operationId) === false) {
			return $this->error(code: 'OPERATION_NOT_FOUND', status: Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(data: ['success' => true, 'cancelRequested' => true], statusCode: Http::STATUS_OK);
	}//end cancel()

	/**
	 * The 400 FIELD_INVALID response for a malformed form field.
	 *
	 * @param string $field The field.
	 * @param array<int, string> $accepted The values the field accepts, when it has a fixed set.
	 *
	 * @return JSONResponse
	 */
	private function invalidField(string $field, array $accepted = []): JSONResponse {
		$details = ['field' => $field];
		if ($accepted !== []) {
			$details['accepted'] = $accepted;
		}

		return $this->error(code: 'FIELD_INVALID', status: Http::STATUS_BAD_REQUEST, details: $details);
	}//end invalidField()

	/**
	 * Translate a CmdbImportException into its contract response.
	 *
	 * @param CmdbImportException $e The exception.
	 *
	 * @return JSONResponse
	 */
	private function fromException(CmdbImportException $e): JSONResponse {
		return $this->error(code: $e->getErrorCode(), status: $e->getHttpStatus(), details: $e->getDetails());
	}//end fromException()

	/**
	 * The error envelope of contract.md.
	 *
	 * @param string $code The machine error code.
	 * @param int $status The HTTP status.
	 * @param array<string, mixed> $details Details, e.g. sheet and column.
	 *
	 * @return JSONResponse
	 */
	private function error(string $code, int $status, array $details = []): JSONResponse {
		return new JSONResponse(
			data: [
				'success' => false,
				'error' => $code,
				'message' => $this->message(code: $code, details: $details),
				'details' => (object)$details,
			],
			statusCode: $status
		);
	}//end error()

	/**
	 * The translated message of an error code.
	 *
	 * @param string $code The machine error code.
	 * @param array<string, mixed> $details The details.
	 *
	 * @return string
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) One branch per contract error code.
	 */
	private function message(string $code, array $details): string {
		$megabytes = (string)round((int)($details['maxBytes'] ?? $this->importService->maxFileBytes()) / 1048576, 1);
		$expected = implode(', ', array_map('strval', ($details['expected'] ?? [])));
		$accepted = implode(', ', array_map('strval', ($details['accepted'] ?? [])));

		return match ($code) {
			'NO_FILE_UPLOADED' => $this->l10n->t('No file was uploaded.'),
			'NOT_XLSX' => $this->l10n->t('The file is not an Excel workbook (.xlsx).'),
			'FILE_TOO_LARGE' => $this->l10n->t('The file is larger than the maximum of %s MB.', [$megabytes]),
			'MISSING_RECORDS_UNSUPPORTED' => $this->l10n->t('Only keeping records that are missing from the export is supported.'),
			'FIELD_INVALID' => $this->fieldMessage(field: (string)($details['field'] ?? ''), accepted: $accepted),
			'MUNICIPALITY_REQUIRED' => $this->l10n->t('Choose a municipality or enter the name of a new one.'),
			'MUNICIPALITY_INVALID' => $this->l10n->t('The chosen organisation is not a municipality.'),
			'NO_SOURCE_SHEET' => $this->l10n->t('The workbook has neither of the sheets %s.', [$expected]),
			'MISSING_COLUMN' => $this->l10n->t('Sheet "%1$s" has no column "%2$s".', [(string)($details['sheet'] ?? ''), (string)($details['column'] ?? '')]),
			'TOO_MANY_ROWS' => $this->l10n->t('Sheet "%1$s" has more than %2$s rows.', [(string)($details['sheet'] ?? ''), (string)($details['limit'] ?? '')]),
			'MAPPING_UNAVAILABLE' => $this->l10n->t('The import mapping cannot run: OpenRegister is missing or a mapping file is invalid.'),
			'READER_UNAVAILABLE' => $this->l10n->t('The Excel reader is not available: OpenRegister is missing or incomplete.'),
			'NOT_CONFIGURED' => $this->l10n->t('Stackiq is not configured: the register or its schemas cannot be found.'),
			'OPERATION_NOT_FOUND' => $this->l10n->t('No running CMDB import has this id.'),
			'UPLOAD_FAILED' => $this->l10n->t('The server could not store the uploaded file. The details are in the Nextcloud log.'),
			default => $this->l10n->t('The import failed. The details are in the Nextcloud log.'),
		};
	}//end message()

	/**
	 * The message of FIELD_INVALID, naming the accepted values when the field has a fixed set.
	 *
	 * @param string $field The field.
	 * @param string $accepted The accepted values, comma-separated, or ''.
	 *
	 * @return string
	 */
	private function fieldMessage(string $field, string $accepted): string {
		if ($accepted === '') {
			return $this->l10n->t('Field "%s" has an invalid value.', [$field]);
		}

		return $this->l10n->t('Field "%1$s" must be one of: %2$s.', [$field, $accepted]);
	}//end fieldMessage()

	/**
	 * A boolean form field: `true`/`false` or `1`/`0`, trimmed and in any case.
	 *
	 * @param string $name The field.
	 * @param bool $default The value when absent or empty.
	 *
	 * @return bool|null Null for any other value, which the caller refuses.
	 */
	private function booleanParam(string $name, bool $default): ?bool {
		$value = $this->request->getParam($name);
		if ($value === null || $value === '') {
			return $default;
		}

		if (is_bool($value) === true) {
			return $value;
		}

		if (is_string($value) === false && is_int($value) === false) {
			return null;
		}

		return match (strtolower(trim((string)$value))) {
			'true', '1' => true,
			'false', '0' => false,
			default => null,
		};
	}//end booleanParam()

	/**
	 * A text form field.
	 *
	 * @param string $name The field.
	 * @param string $default The value when absent.
	 *
	 * @return string|null Null when the field is not a single value, such as `name[]=x`.
	 */
	private function stringParam(string $name, string $default): ?string {
		$value = $this->request->getParam($name, $default);
		if (is_string($value) === true) {
			return $value;
		}

		if (is_int($value) === true || is_float($value) === true) {
			return (string)$value;
		}

		return null;
	}//end stringParam()

	/**
	 * The uploaded export, or null when none was sent.
	 *
	 * `serverError` is the PHP upload error when the file reached the server
	 * but could not be stored (no tmp dir, disk full, an extension stopped it).
	 *
	 * @return array{tmpName: string, name: string, size: int, tooLarge: bool, serverError: int|null}|null
	 */
	private function uploadedFile(): ?array {
		$file = $this->request->getUploadedFile(self::FILE_FIELD);
		if (is_array($file) === false || $file === []) {
			return null;
		}

		// `cmdbFile[]` gives arrays for every key; that is no usable upload.
		$error = $file['error'] ?? UPLOAD_ERR_OK;
		if (is_int($error) === false) {
			return null;
		}

		if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
			return ['tmpName' => '', 'name' => '', 'size' => 0, 'tooLarge' => true, 'serverError' => null];
		}

		if (in_array($error, [UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION], true) === true) {
			return ['tmpName' => '', 'name' => '', 'size' => 0, 'tooLarge' => false, 'serverError' => $error];
		}

		$tmpName = $file['tmp_name'] ?? '';
		if ($error !== UPLOAD_ERR_OK || is_string($tmpName) === false || $tmpName === '') {
			return null;
		}

		$size = (int)($file['size'] ?? 0);
		if ($size === 0 && is_file($tmpName) === true) {
			$size = (int)filesize($tmpName);
		}

		$name = $file['name'] ?? '';
		if (is_string($name) === false) {
			$name = '';
		}

		return ['tmpName' => $tmpName, 'name' => $name, 'size' => $size, 'tooLarge' => false, 'serverError' => null];
	}//end uploadedFile()
}//end class
