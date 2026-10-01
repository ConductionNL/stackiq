<?php

/**
 * Stackiq CmdbImportController.
 *
 * Upload endpoint for a TOPdesk CMDB export (xlsx) and the cancel endpoint
 * of a running import (openspec/changes/cmdb-export-import/contract.md).
 *
 * AUTH: both methods carry neither `NoAdminRequired` nor `NoCSRFRequired`, so
 * Nextcloud's middleware lets only a Nextcloud admin with a valid CSRF token
 * reach them (403 / 412 before the body runs). The import writes into the
 * catalogue for a whole municipality, which is an administrative action, so
 * a member of the app's own manager groups is refused as well.
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
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * CMDB import and cancel, admin-only and CSRF-protected.
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
	 * @return JSONResponse The report (200), or an error envelope with the contract code.
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-8
	 */
	public function import(): JSONResponse {
		$validated = $this->validateRequest();
		if ($validated instanceof JSONResponse) {
			return $validated;
		}

		try {
			$report = $this->importService->import(path: $validated['path'], options: $validated['options']);
		} catch (CmdbImportException $e) {
			$this->logger->info(
				'CmdbImportController: import refused',
				['error' => $e->getErrorCode(), 'details' => $e->getDetails(), 'reason' => $e->getMessage()]
			);
			return $this->fromException(e: $e);
		} catch (\Exception $e) {
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
		$upload = $this->uploadedFile();
		if ($upload === null) {
			return $this->error(code: 'NO_FILE_UPLOADED', status: Http::STATUS_BAD_REQUEST);
		}

		$maxBytes = $this->importService->maxFileBytes();
		if ($upload['tooLarge'] === true || $upload['size'] > $maxBytes) {
			return $this->error(code: 'FILE_TOO_LARGE', status: Http::STATUS_REQUEST_ENTITY_TOO_LARGE, details: ['maxBytes' => $maxBytes]);
		}

		try {
			$this->importService->assertXlsx(path: $upload['tmpName'], fileName: $upload['name']);
		} catch (CmdbImportException $e) {
			return $this->fromException(e: $e);
		}

		$missingRecords = (string)$this->request->getParam('missingRecords', 'keep');
		if ($this->importService->supportsMissingRecords(mode: $missingRecords) === false) {
			return $this->error(code: 'MISSING_RECORDS_UNSUPPORTED', status: Http::STATUS_UNPROCESSABLE_ENTITY, details: ['accepted' => ['keep']]);
		}

		$municipalityUuid = trim((string)$this->request->getParam('municipalityUuid', ''));
		$municipalityName = trim((string)$this->request->getParam('municipalityName', ''));
		if ($municipalityUuid === '' && $municipalityName === '') {
			return $this->error(code: CmdbImportException::MUNICIPALITY_REQUIRED, status: Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return [
			'path' => $upload['tmpName'],
			'options' => [
				'municipalityUuid' => $municipalityUuid,
				'municipalityName' => $municipalityName,
				'updateExisting' => $this->booleanParam(name: 'updateExisting', default: true),
				'operationId' => $this->request->getParam('operationId'),
			],
		];
	}//end validateRequest()

	/**
	 * Ask a running CMDB import to stop between rows.
	 *
	 * @param string $operationId The operation id.
	 *
	 * @return JSONResponse `{success, cancelRequested}`, or 404 OPERATION_NOT_FOUND.
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-7
	 */
	public function cancel(string $operationId): JSONResponse {
		if ($this->importService->requestCancel(operationId: $operationId) === false) {
			return $this->error(code: 'OPERATION_NOT_FOUND', status: Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(data: ['success' => true, 'cancelRequested' => true], statusCode: Http::STATUS_OK);
	}//end cancel()

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
		$megabytes = (string)intdiv($this->importService->maxFileBytes(), 1048576);
		$expected = implode(', ', array_map('strval', ($details['expected'] ?? [])));

		return match ($code) {
			'NO_FILE_UPLOADED' => $this->l10n->t('No file was uploaded.'),
			'NOT_XLSX' => $this->l10n->t('The file is not an Excel workbook (.xlsx).'),
			'FILE_TOO_LARGE' => $this->l10n->t('The file is larger than the maximum of %s MB.', [$megabytes]),
			'MISSING_RECORDS_UNSUPPORTED' => $this->l10n->t('Only keeping records that are missing from the export is supported.'),
			'MUNICIPALITY_REQUIRED' => $this->l10n->t('Choose a municipality or enter the name of a new one.'),
			'MUNICIPALITY_INVALID' => $this->l10n->t('The chosen organisation is not a municipality.'),
			'NO_SOURCE_SHEET' => $this->l10n->t('The workbook has neither of the sheets %s.', [$expected]),
			'MISSING_COLUMN' => $this->l10n->t('Sheet "%1$s" has no column "%2$s".', [(string)($details['sheet'] ?? ''), (string)($details['column'] ?? '')]),
			'TOO_MANY_ROWS' => $this->l10n->t('Sheet "%1$s" has more than %2$s rows.', [(string)($details['sheet'] ?? ''), (string)($details['limit'] ?? '')]),
			'MAPPING_UNAVAILABLE' => $this->l10n->t('The import mapping cannot run: OpenRegister is missing or a mapping file is invalid.'),
			'READER_UNAVAILABLE' => $this->l10n->t('The Excel reader is not available: OpenRegister is missing or incomplete.'),
			'NOT_CONFIGURED' => $this->l10n->t('Stackiq is not configured: the register or its schemas cannot be found.'),
			'OPERATION_NOT_FOUND' => $this->l10n->t('No running CMDB import has this id.'),
			default => $this->l10n->t('The import failed. The details are in the Nextcloud log.'),
		};
	}//end message()

	/**
	 * A boolean form field (`true`/`false`, `1`/`0`).
	 *
	 * @param string $name The field.
	 * @param bool $default The value when absent.
	 *
	 * @return bool
	 */
	private function booleanParam(string $name, bool $default): bool {
		$value = $this->request->getParam($name);
		if ($value === null || $value === '') {
			return $default;
		}

		if (is_bool($value) === true) {
			return $value;
		}

		return in_array(strtolower((string)$value), ['false', '0', 'no', 'off'], true) === false;
	}//end booleanParam()

	/**
	 * The uploaded export, or null when none was sent.
	 *
	 * @return array{tmpName: string, name: string, size: int, tooLarge: bool}|null
	 */
	private function uploadedFile(): ?array {
		$file = $this->request->getUploadedFile(self::FILE_FIELD);
		if (is_array($file) === false || $file === []) {
			return null;
		}

		$error = (int)($file['error'] ?? UPLOAD_ERR_OK);
		if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
			return ['tmpName' => '', 'name' => (string)($file['name'] ?? ''), 'size' => 0, 'tooLarge' => true];
		}

		$tmpName = (string)($file['tmp_name'] ?? '');
		if ($error !== UPLOAD_ERR_OK || $tmpName === '') {
			return null;
		}

		$size = (int)($file['size'] ?? 0);
		if ($size === 0 && is_file($tmpName) === true) {
			$size = (int)filesize($tmpName);
		}

		return ['tmpName' => $tmpName, 'name' => (string)($file['name'] ?? ''), 'size' => $size, 'tooLarge' => false];
	}//end uploadedFile()
}//end class
