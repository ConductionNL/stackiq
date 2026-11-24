<?php

/**
 * Legacy catalogue import controller
 *
 * The endpoint that previews an organisation's export from the previous Softwarecatalogus.
 *
 * @category  Controller
 * @package   OCA\Stackiq\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Controller;

use OCA\Stackiq\Exception\CmdbImportException;
use OCA\Stackiq\Kernel\AppIdentity;
use OCA\Stackiq\Service\LegacyCatalogueMigrationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Preview and apply of an export from the previous Softwarecatalogus, CSRF-protected.
 *
 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-2
 */
class LegacyCatalogueImportController extends Controller {

	/**
	 * The multipart field of the export.
	 */
	public const FILE_FIELD = 'file';

	/**
	 * Constructor.
	 *
	 * @param IRequest                        $request The request.
	 * @param LegacyCatalogueMigrationService $service The migration service.
	 * @param LoggerInterface                 $logger  Logs a failed preview without cell values.
	 *
	 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-2
	 */
	public function __construct(
		IRequest $request,
		private readonly LegacyCatalogueMigrationService $service,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Preview an export: per row the match or create decision for supplier, product and version. Writes nothing.
	 *
	 * Multipart field: `file` (xlsx). The right to create usages in the active organisation is checked before the
	 * file is read.
	 *
	 * @return JSONResponse The preview (200), or `{error, details}` with the contract code.
	 *
	 * @NoAdminRequired
	 *
	 * @auth member-of-active-organisation the service refuses (403) a caller without an active organisation or without the right to create usages.
	 *
	 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-2
	 */
	public function preview(): JSONResponse {
		try {
			$this->service->assertMayImport();
			$upload = $this->upload();
			if ($upload instanceof JSONResponse) {
				return $upload;
			}

			$preview = $this->service->preview(path: $upload['tmp_name'], fileName: $upload['name']);
		} catch (CmdbImportException $e) {
			return self::error(code: $e->getErrorCode(), status: $e->getHttpStatus(), details: $e->getDetails());
		} catch (Throwable $e) {
			$this->logger->error('LegacyCatalogueImportController: preview failed', ['exception' => get_class($e), 'line' => $e->getLine()]);
			return self::error(code: 'PREVIEW_FAILED', status: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(data: $preview, statusCode: Http::STATUS_OK);
	}//end preview()

	/**
	 * Bring over an export: create or update the organisation's usages and count created, updated and skipped rows.
	 *
	 * Multipart fields: `file` (the xlsx the preview read) and `leftOut` (a JSON array of the previous catalogue ids
	 * the user left out, optional). The right to create usages in the active organisation is checked before the file
	 * is read.
	 *
	 * @return JSONResponse The result (200), or `{error, details}` with the contract code.
	 *
	 * @NoAdminRequired
	 *
	 * @auth member-of-active-organisation the service refuses (403) a caller without an active organisation or without
	 *       the right to create usages, and writes with the caller's own rights.
	 *
	 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-3
	 */
	public function apply(): JSONResponse {
		try {
			$this->service->assertMayImport();
			$leftOut = $this->leftOut();
			if ($leftOut instanceof JSONResponse) {
				return $leftOut;
			}

			$upload = $this->upload();
			if ($upload instanceof JSONResponse) {
				return $upload;
			}

			$result = $this->service->apply(path: $upload['tmp_name'], fileName: $upload['name'], leftOut: $leftOut);
		} catch (CmdbImportException $e) {
			return self::error(code: $e->getErrorCode(), status: $e->getHttpStatus(), details: $e->getDetails());
		} catch (Throwable $e) {
			$this->logger->error('LegacyCatalogueImportController: apply failed', ['exception' => get_class($e), 'line' => $e->getLine()]);
			return self::error(code: 'APPLY_FAILED', status: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(data: $result, statusCode: Http::STATUS_OK);
	}//end apply()

	/**
	 * The ids the user left out, from the `leftOut` field: a JSON array of strings, empty when the field is absent.
	 *
	 * @return array<int, string>|JSONResponse The ids, or 400 INVALID_LEFT_OUT.
	 *
	 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-3
	 */
	private function leftOut(): array|JSONResponse {
		$raw = $this->request->getParam('leftOut', '');
		if ($raw === '' || $raw === null) {
			return [];
		}

		$ids = $raw;
		if (is_string($raw) === true) {
			$ids = json_decode($raw, true);
		}

		if (is_array($ids) === false || array_filter($ids, static fn (mixed $id): bool => is_string($id) === false) !== []) {
			return self::error(code: 'INVALID_LEFT_OUT', status: Http::STATUS_BAD_REQUEST);
		}

		return array_values($ids);
	}//end leftOut()

	/**
	 * The stored upload, or the error for a missing, failed or too large one.
	 *
	 * @return array{tmp_name: string, name: string}|JSONResponse
	 *
	 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-2
	 */
	private function upload(): array|JSONResponse {
		$file = $this->request->getUploadedFile(self::FILE_FIELD);
		if (is_array($file) === false || is_string($file['tmp_name'] ?? null) === false || ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
			$maxBytes = $this->service->maxFileBytes();
			if (in_array(($file['error'] ?? null), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) === true) {
				return self::error(code: 'FILE_TOO_LARGE', status: Http::STATUS_REQUEST_ENTITY_TOO_LARGE, details: ['maxBytes' => $maxBytes]);
			}

			return self::error(code: 'FILE_REQUIRED', status: Http::STATUS_BAD_REQUEST);
		}

		$maxBytes = $this->service->maxFileBytes();
		if ((int)($file['size'] ?? 0) > $maxBytes) {
			return self::error(code: 'FILE_TOO_LARGE', status: Http::STATUS_REQUEST_ENTITY_TOO_LARGE, details: ['maxBytes' => $maxBytes]);
		}

		return ['tmp_name' => $file['tmp_name'], 'name' => (string)($file['name'] ?? '')];
	}//end upload()

	/**
	 * An error envelope.
	 *
	 * @param string $code The contract code.
	 * @param int $status The HTTP status.
	 * @param array<string, mixed> $details The contract details.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-2
	 */
	private static function error(string $code, int $status, array $details = []): JSONResponse {
		return new JSONResponse(data: ['error' => $code, 'details' => $details], statusCode: $status);
	}//end error()
}//end class
