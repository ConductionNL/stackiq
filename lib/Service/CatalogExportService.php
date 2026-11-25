<?php

/**
 * Stackiq catalogue export service
 *
 * Exports the Applications (`module`) or Services (`catalogService`) list as
 * the user filtered it: the ids FacetService matched for the GEMMA facets and
 * the search, narrowed by the active quick filter, read from OpenRegister with
 * the caller's rights, written as CSV or XLSX in the page's columns. When the
 * facet set stopped at its paging ceiling, the file's last row says so.
 *
 * @category Service
 * @package  OCA\Stackiq\Service
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
 * @spec openspec/changes/archive/2026-11-25-insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-002-the-applications-and-services-pages-shall-export-the-rows-the-facets-the-quick-filter-and-the-search-leave
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Stackiq\Kernel\Contracts\CatalogExportServiceInterface;
use OCA\Stackiq\Kernel\Contracts\FacetServiceInterface;
use OCA\Stackiq\Service\Export\CatalogExportGate;
use OCA\Stackiq\Service\Export\CatalogExportRequest;
use OCA\Stackiq\Service\Export\SimpleXlsxWriter;
use OCA\Stackiq\Service\Settings\CatalogueLocator;
use OCP\IL10N;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Builds the CSV or XLSX export of a faceted catalogue list.
 *
 * @spec openspec/changes/archive/2026-11-25-insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-002-the-applications-and-services-pages-shall-export-the-rows-the-facets-the-quick-filter-and-the-search-leave
 */
class CatalogExportService implements CatalogExportServiceInterface {

	/**
	 * The formats it writes.
	 *
	 * @var array<int, string>
	 */
	public const FORMATS = ['csv', 'xlsx'];

	/**
	 * The last row when the facet set stopped at its ceiling; %d is the number of rows written.
	 */
	public const CUT_ROW = 'This export stops at %d rows: the catalogue holds more than the list can show. Narrow the filters to export the rest.';

	/**
	 * The columns when the page sends none.
	 *
	 * @var array<int, string>
	 */
	private const DEFAULT_COLUMNS = ['name'];

	/**
	 * Constructor.
	 *
	 * @param FacetServiceInterface $facets    The GEMMA facet matching the list uses.
	 * @param CatalogueLocator      $catalogue The catalogue register and schema ids.
	 * @param ContainerInterface    $container Reaches OpenRegister's ObjectService.
	 * @param IL10N                 $l10n      The cut-off row in the caller's language.
	 * @param CatalogExportGate     $gate      Whether the caller may export a schema.
	 *
	 * @spec openspec/changes/archive/2026-11-25-insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-002-the-applications-and-services-pages-shall-export-the-rows-the-facets-the-quick-filter-and-the-search-leave
	 */
	public function __construct(
		private readonly FacetServiceInterface $facets,
		private readonly CatalogueLocator $catalogue,
		private readonly ContainerInterface $container,
		private readonly IL10N $l10n,
		private readonly CatalogExportGate $gate,
	) {
	}//end __construct()

	/**
	 * Refuse a schema this export does not serve.
	 *
	 * @param string $schema The schema slug from the URL.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When it is not `module` or `catalogService`.
	 *
	 * @spec openspec/changes/archive/2026-11-25-insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-002-the-applications-and-services-pages-shall-export-the-rows-the-facets-the-quick-filter-and-the-search-leave
	 */
	public function assertSchema(string $schema): void {
		if (in_array($schema, self::SCHEMAS, true) === false) {
			throw new InvalidArgumentException(sprintf('Only %s can be exported here.', implode(' and ', self::SCHEMAS)));
		}
	}//end assertSchema()

	/**
	 * Whether the caller may export the schema (see CatalogExportGate).
	 *
	 * @param string $schema The schema slug, already checked.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-11-25-insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-002-the-applications-and-services-pages-shall-export-the-rows-the-facets-the-quick-filter-and-the-search-leave
	 */
	public function mayExport(string $schema): bool {
		return $this->gate->mayExport(schema: $schema);
	}//end mayExport()

	/**
	 * The export file of the list as filtered.
	 *
	 * @param string               $schema  `module` or `catalogService`, already checked.
	 * @param CatalogExportRequest $request The facets, search, quick filter, columns and format.
	 *
	 * @return array{body: string, contentType: string, filename: string}
	 *
	 * @throws InvalidArgumentException On an unknown format.
	 * @throws RuntimeException When OpenRegister is not available.
	 *
	 * @spec openspec/changes/archive/2026-11-25-insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-002-the-applications-and-services-pages-shall-export-the-rows-the-facets-the-quick-filter-and-the-search-leave
	 */
	public function export(string $schema, CatalogExportRequest $request): array {
		if (in_array($request->format, self::FORMATS, true) === false) {
			throw new InvalidArgumentException('The format is csv or xlsx.');
		}

		$matched = $this->facets->getFacets(schema: $schema, filters: $request->facets, search: $request->search);
		$ids     = array_values(array_filter(($matched['_meta']['matchedObjectIds'] ?? []), 'is_string'));
		// The `@self` placeholder keeps the query's key order; readObjects() fills it in.
		$query = array_merge(
			self::quickFilterQuery(quickFilter: $request->quickFilter),
			['@self' => null, 'id' => $ids, '_limit' => count($ids), '_page' => 1]
		);
		$objects = [];
		if ($ids !== []) {
			$objects = $this->readObjects(schema: $schema, query: $query);
		}

		$rows = $this->rows(objects: $objects, columns: self::columnsOrDefault(columns: $request->columns));
		if (($matched['_meta']['truncated'] ?? false) === true) {
			$rows[] = [$this->l10n->t(self::CUT_ROW, [count($objects)])];
		}

		if ($request->format === 'xlsx') {
			return self::xlsxFile(schema: $schema, rows: $rows);
		}

		return self::csvFile(schema: $schema, rows: $rows);
	}//end export()

	/**
	 * The page's columns, or the default ones when it sent none.
	 *
	 * @param array<int, array{key: string, label: string}> $columns The page's columns.
	 *
	 * @return array<int, array{key: string, label: string}>
	 */
	private static function columnsOrDefault(array $columns): array {
		if ($columns !== []) {
			return $columns;
		}

		return array_map(static fn (string $key): array => ['key' => $key, 'label' => $key], self::DEFAULT_COLUMNS);
	}//end columnsOrDefault()

	/**
	 * The header row and one row of cells per object.
	 *
	 * @param array<int, array<string, mixed>>              $objects The objects.
	 * @param array<int, array{key: string, label: string}> $columns The columns.
	 *
	 * @return array<int, array<int, string>>
	 */
	private function rows(array $objects, array $columns): array {
		$rows = [array_column($columns, 'label')];
		foreach ($objects as $object) {
			$rows[] = array_map(fn (array $column): string => self::cell(value: $this->valueAt(object: $object, key: $column['key'])), $columns);
		}

		return $rows;
	}//end rows()

	/**
	 * The rows as an XLSX file, one sheet named after the schema.
	 *
	 * @param string                         $schema The schema slug.
	 * @param array<int, array<int, string>> $rows   The rows.
	 *
	 * @return array{body: string, contentType: string, filename: string}
	 */
	private static function xlsxFile(string $schema, array $rows): array {
		return [
			'body' => (new SimpleXlsxWriter())->write(sheetName: $schema, rows: $rows),
			'contentType' => SimpleXlsxWriter::CONTENT_TYPE,
			'filename' => self::fileName(schema: $schema) . '.xlsx',
		];
	}//end xlsxFile()

	/**
	 * The rows as a CSV file.
	 *
	 * @param string                         $schema The schema slug.
	 * @param array<int, array<int, string>> $rows   The rows.
	 *
	 * @return array{body: string, contentType: string, filename: string}
	 */
	private static function csvFile(string $schema, array $rows): array {
		return ['body' => self::csv(rows: $rows), 'contentType' => 'text/csv; charset=utf-8', 'filename' => self::fileName(schema: $schema) . '.csv'];
	}//end csvFile()

	/**
	 * The export's file name without its extension.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return string
	 */
	private static function fileName(string $schema): string {
		return $schema . '-export-' . gmdate('Y-m-d');
	}//end fileName()

	/**
	 * The matched objects, narrowed by the quick filter, read with the caller's rights.
	 *
	 * @param string               $schema The schema slug.
	 * @param array<string, mixed> $query  The quick filter fields, the ids and the paging; `@self` is filled in here.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @throws RuntimeException When OpenRegister is not available.
	 */
	private function readObjects(string $schema, array $query): array {
		$registerId = $this->catalogue->getRegisterIdForObjectType(objectType: $schema);
		$schemaId   = $this->catalogue->getSchemaIdForObjectType(objectType: $schema);
		if ($registerId === null || $schemaId === null) {
			return [];
		}

		$query['@self'] = ['register' => $registerId, 'schema' => $schemaId];
		$result         = $this->objectService()->searchObjectsPaginated($query);

		$objects = [];
		foreach (($result['results'] ?? []) as $object) {
			if (is_array($object) === false) {
				$object = (array)$object->jsonSerialize();
			}

			$objects[] = $object;
		}

		return $objects;
	}//end readObjects()

	/**
	 * OpenRegister's object service.
	 *
	 * @return ObjectServiceInterface
	 *
	 * @throws RuntimeException When OpenRegister is not available.
	 */
	private function objectService(): ObjectServiceInterface {
		$objectService = $this->container->get(ObjectService::class);
		if ($objectService instanceof ObjectServiceInterface === false) {
			throw new RuntimeException('OpenRegister ObjectService not available');
		}

		return $objectService;
	}//end objectService()

	/**
	 * The quick filter as query fields: plain field names with a scalar or a list of scalars.
	 *
	 * @param array<string, mixed> $quickFilter The quick filter.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/archive/2026-11-25-insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-002-the-applications-and-services-pages-shall-export-the-rows-the-facets-the-quick-filter-and-the-search-leave
	 */
	public static function quickFilterQuery(array $quickFilter): array {
		$query = [];
		foreach ($quickFilter as $field => $value) {
			if (is_string($field) === false || preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $field) !== 1) {
				continue;
			}

			if (self::isFilterValue(value: $value) === true) {
				$query[$field] = $value;
			}
		}

		return $query;
	}//end quickFilterQuery()

	/**
	 * Whether a quick filter value is a scalar or a non-empty list of scalars.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private static function isFilterValue(mixed $value): bool {
		if (is_scalar($value) === true) {
			return true;
		}

		return is_array($value) === true && $value !== [] && array_filter($value, 'is_scalar') === $value;
	}//end isFilterValue()

	/**
	 * The value at a column key, a dotted path into the object.
	 *
	 * @param array<string, mixed> $object The object.
	 * @param string               $key    The column key.
	 *
	 * @return mixed
	 */
	private function valueAt(array $object, string $key): mixed {
		$value = $object;
		foreach (explode('.', $key) as $part) {
			if (is_array($value) === false || array_key_exists($part, $value) === false) {
				return null;
			}

			$value = $value[$part];
		}

		return $value;
	}//end valueAt()

	/**
	 * A value as the text of one cell: a list joined, a related object by its name or title.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-11-25-insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-002-the-applications-and-services-pages-shall-export-the-rows-the-facets-the-quick-filter-and-the-search-leave
	 */
	public static function cell(mixed $value): string {
		if ($value === null) {
			return '';
		}

		if (is_bool($value) === true) {
			return var_export($value, true);
		}

		if (is_array($value) === true) {
			if (array_is_list($value) === false) {
				return (string)($value['name'] ?? $value['title'] ?? $value['id'] ?? json_encode($value));
			}

			return implode(', ', array_map(static fn (mixed $item): string => self::cell(value: $item), $value));
		}

		return (string)$value;
	}//end cell()

	/**
	 * The rows as CSV, every cell guarded against spreadsheet formulas.
	 *
	 * @param array<int, array<int, string>> $rows The rows.
	 *
	 * @return string
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) csvSafeCell() is the portfolio report's pure cell guard, shared so every CSV escapes alike.
	 */
	private static function csv(array $rows): string {
		$handle = fopen('php://temp', 'r+');
		foreach ($rows as $row) {
			fputcsv($handle, array_map(static fn (string $cell): string => PortfolioReportService::csvSafeCell(value: $cell), $row), escape: '');
		}

		rewind($handle);
		$csv = (string)stream_get_contents($handle);
		fclose($handle);

		return $csv;
	}//end csv()
}//end class
