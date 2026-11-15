<?php

/**
 * Stackiq catalogue export controller
 *
 * `GET /api/catalog/{schema}/export`: the Applications or Services list as the
 * user filtered it, as CSV or XLSX. The GEMMA facets come as `_gf_<dimension>`
 * keys (the list's own route keys) or as plain dimension names, the active
 * quick filter as `quickFilter`, the search as `search`, the page's columns as
 * `columns`, the format as `format`.
 *
 * @category  Controller
 * @package   OCA\Stackiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-002-the-applications-and-services-pages-shall-export-the-rows-the-facets-the-quick-filter-and-the-search-leave
 */

declare(strict_types=1);

namespace OCA\Stackiq\Controller;

use InvalidArgumentException;
use OCA\Stackiq\Service\CatalogExportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Serves the faceted catalogue export.
 *
 * @category Controller
 * @package  OCA\Stackiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-002-the-applications-and-services-pages-shall-export-the-rows-the-facets-the-quick-filter-and-the-search-leave
 */
class CatalogExportController extends Controller {

	/**
	 * The GEMMA dimensions a facet selection names.
	 *
	 * @var array<int, string>
	 */
	public const DIMENSIONS = ['referenceComponent', 'standard', 'applicationService', 'domain'];

	/**
	 * The prefix of a facet key in the list's route query.
	 */
	public const FACET_PREFIX = '_gf_';

	/**
	 * Constructor.
	 *
	 * @param string               $appName The app name.
	 * @param IRequest             $request The request.
	 * @param CatalogExportService $exports Builds the file.
	 * @param LoggerInterface      $logger  Logs a failed export.
	 *
	 * @spec openspec/changes/insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-002-the-applications-and-services-pages-shall-export-the-rows-the-facets-the-quick-filter-and-the-search-leave
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CatalogExportService $exports,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Export the faceted list as filtered.
	 *
	 * The schema is checked first (400), then the schema's `export` grant
	 * (403); the rows are read with the caller's rights.
	 *
	 * @param string $schema `module` or `catalogService`.
	 *
	 * @return DataDownloadResponse|JSONResponse The file, or 400, 403 or 500.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-002-the-applications-and-services-pages-shall-export-the-rows-the-facets-the-quick-filter-and-the-search-leave
	 */
	public function export(string $schema): DataDownloadResponse|JSONResponse {
		try {
			$this->exports->assertSchema(schema: $schema);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(
				['message' => $exception->getMessage(), 'supportedSchemas' => CatalogExportService::SCHEMAS],
				400
			);
		}

		if ($this->exports->mayExport(schema: $schema) === false) {
			return new JSONResponse(['message' => 'You may not export this list.'], 403);
		}

		try {
			$file = $this->exports->export(
				schema: $schema,
				facets: $this->facets(),
				search: $this->text(name: 'search'),
				quickFilter: $this->quickFilter(),
				columns: $this->columns(),
				format: ($this->text(name: 'format') ?? 'csv')
			);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(['message' => $exception->getMessage()], 400);
		} catch (Throwable $exception) {
			$this->logger->error('[CatalogExportController] The export failed: {msg}', ['msg' => $exception->getMessage(), 'schema' => $schema]);
			return new JSONResponse(['message' => 'The export could not be made.'], 500);
		}

		return new DataDownloadResponse($file['body'], $file['filename'], $file['contentType']);
	}//end export()

	/**
	 * The selected facet values per dimension, from `_gf_<dimension>` or `<dimension>`.
	 *
	 * @return array<string, array<int, string>>
	 */
	private function facets(): array {
		$facets = [];
		foreach (self::DIMENSIONS as $dimension) {
			$value = ($this->request->getParam(self::FACET_PREFIX . $dimension) ?? $this->request->getParam($dimension));
			if (is_array($value) === false) {
				$value = [$value];
			}

			$values = array_values(array_filter($value, static fn (mixed $item): bool => is_string($item) && $item !== ''));
			if ($values !== []) {
				$facets[$dimension] = $values;
			}
		}

		return $facets;
	}//end facets()

	/**
	 * The active quick filter: an object, or its JSON text.
	 *
	 * @return array<string, mixed>
	 */
	private function quickFilter(): array {
		$value = $this->request->getParam('quickFilter');
		if (is_string($value) === true) {
			$value = json_decode($value, true);
		}

		if (is_array($value) === false) {
			return [];
		}

		return $value;
	}//end quickFilter()

	/**
	 * The page's columns: a list of `{key, label}`, or of keys, or its JSON text.
	 *
	 * @return array<int, array{key: string, label: string}>
	 */
	private function columns(): array {
		$value = $this->request->getParam('columns');
		if (is_string($value) === true) {
			$value = json_decode($value, true);
		}

		if (is_array($value) === false) {
			return [];
		}

		$columns = [];
		foreach ($value as $column) {
			$key   = $column;
			$label = $column;
			if (is_array($column) === true) {
				$key   = ($column['key'] ?? null);
				$label = ($column['label'] ?? $key);
			}

			if (is_string($key) === true && preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $key) === 1) {
				$columns[] = ['key' => $key, 'label' => (string)$label];
			}
		}

		return $columns;
	}//end columns()

	/**
	 * A text parameter, or null.
	 *
	 * @param string $name The parameter.
	 *
	 * @return string|null
	 */
	private function text(string $name): ?string {
		$value = $this->request->getParam($name);
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return trim($value);
	}//end text()
}//end class
