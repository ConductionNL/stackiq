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
 * @spec openspec/changes/insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-002-the-applications-and-services-pages-shall-export-the-rows-the-facets-the-quick-filter-and-the-search-leave
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Stackiq\Kernel\Contracts\FacetServiceInterface;
use OCA\Stackiq\Service\Export\SimpleXlsxWriter;
use OCA\Stackiq\Service\Settings\CatalogueLocator;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Builds the CSV or XLSX export of a faceted catalogue list.
 *
 * @spec openspec/changes/insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-002-the-applications-and-services-pages-shall-export-the-rows-the-facets-the-quick-filter-and-the-search-leave
 */
class CatalogExportService {

	/**
	 * The schemas this export serves: the two faceted lists.
	 *
	 * @var array<int, string>
	 */
	public const SCHEMAS = ['module', 'catalogService'];

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
	 * @param FacetServiceInterface $facets       The GEMMA facet matching the list uses.
	 * @param CatalogueLocator      $catalogue    The catalogue register and schema ids.
	 * @param ContainerInterface    $container    Reaches OpenRegister's ObjectService and SchemaMapper.
	 * @param IUserSession          $userSession  The caller.
	 * @param IGroupManager         $groupManager The caller's groups, for a schema's `export` grant.
	 * @param IL10N                 $l10n         The cut-off row in the caller's language.
	 * @param LoggerInterface       $logger       Logs a schema it could not read.
	 *
	 * @spec openspec/changes/insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-002-the-applications-and-services-pages-shall-export-the-rows-the-facets-the-quick-filter-and-the-search-leave
	 */
	public function __construct(
		private readonly FacetServiceInterface $facets,
		private readonly CatalogueLocator $catalogue,
		private readonly ContainerInterface $container,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
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
	 * @spec openspec/changes/insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-002-the-applications-and-services-pages-shall-export-the-rows-the-facets-the-quick-filter-and-the-search-leave
	 */
	public function assertSchema(string $schema): void {
		if (in_array($schema, self::SCHEMAS, true) === false) {
			throw new InvalidArgumentException(sprintf('Only %s can be exported here.', implode(' and ', self::SCHEMAS)));
		}
	}//end assertSchema()

	/**
	 * Whether the caller may export the schema: when its `authorization` names
	 * `export`, the caller must be in one of those groups (an administrator
	 * always is); otherwise the `read` grant applies, which the RBAC read
	 * enforces row by row. A schema that cannot be read refuses.
	 *
	 * @param string $schema The schema slug, already checked.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-002-the-applications-and-services-pages-shall-export-the-rows-the-facets-the-quick-filter-and-the-search-leave
	 */
	public function mayExport(string $schema): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		try {
			$schemaId = $this->catalogue->getSchemaIdForObjectType(objectType: $schema);
			if ($schemaId === null) {
				return false;
			}

			$entity        = $this->container->get('OCA\OpenRegister\Db\SchemaMapper')->find($schemaId, _rbac: false, _multitenancy: false);
			$authorization = (array)($entity->getAuthorization() ?? []);
		} catch (Throwable $exception) {
			$this->logger->warning('[CatalogExportService] The schema could not be read: {msg}', ['msg' => $exception->getMessage(), 'schema' => $schema]);
			return false;
		}

		if (array_key_exists('export', $authorization) === false) {
			return true;
		}

		$uid = $user->getUID();
		if ($this->groupManager->isAdmin($uid) === true) {
			return true;
		}

		foreach ((array)$authorization['export'] as $group) {
			if (is_string($group) === true && $this->groupManager->isInGroup($uid, $group) === true) {
				return true;
			}
		}

		return false;
	}//end mayExport()

	/**
	 * The export file of the list as filtered.
	 *
	 * @param string                $schema      `module` or `catalogService`, already checked.
	 * @param array<string, array>  $facets      The selected facet values per GEMMA dimension.
	 * @param string|null           $search      The search box.
	 * @param array<string, mixed>  $quickFilter The active quick filter's field values.
	 * @param array<int, array{key: string, label: string}> $columns The page's columns.
	 * @param string                $format      `csv` or `xlsx`.
	 *
	 * @return array{body: string, contentType: string, filename: string}
	 *
	 * @throws InvalidArgumentException On an unknown format.
	 * @throws RuntimeException When OpenRegister is not available.
	 *
	 * @spec openspec/changes/insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-002-the-applications-and-services-pages-shall-export-the-rows-the-facets-the-quick-filter-and-the-search-leave
	 */
	public function export(string $schema, array $facets, ?string $search, array $quickFilter, array $columns, string $format): array {
		if (in_array($format, self::FORMATS, true) === false) {
			throw new InvalidArgumentException('The format is csv or xlsx.');
		}

		if ($columns === []) {
			$columns = array_map(static fn (string $key): array => ['key' => $key, 'label' => $key], self::DEFAULT_COLUMNS);
		}

		$matched = $this->facets->getFacets(schema: $schema, filters: $facets, search: $search);
		$ids     = array_values(array_filter(($matched['_meta']['matchedObjectIds'] ?? []), 'is_string'));
		$objects = $this->readObjects(schema: $schema, ids: $ids, quickFilter: $quickFilter);

		$rows = [array_column($columns, 'label')];
		foreach ($objects as $object) {
			$rows[] = array_map(fn (array $column): string => self::cell(value: $this->valueAt(object: $object, key: $column['key'])), $columns);
		}

		if (($matched['_meta']['truncated'] ?? false) === true) {
			$rows[] = [$this->l10n->t(self::CUT_ROW, [count($objects)])];
		}

		$name = $schema . '-export-' . gmdate('Y-m-d');
		if ($format === 'xlsx') {
			return [
				'body' => (new SimpleXlsxWriter())->write(sheetName: $schema, rows: $rows),
				'contentType' => SimpleXlsxWriter::CONTENT_TYPE,
				'filename' => $name . '.xlsx',
			];
		}

		return ['body' => self::csv(rows: $rows), 'contentType' => 'text/csv; charset=utf-8', 'filename' => $name . '.csv'];
	}//end export()

	/**
	 * The matched objects, narrowed by the quick filter, read with the caller's rights.
	 *
	 * @param string                $schema      The schema slug.
	 * @param array<int, string>    $ids         The ids FacetService matched.
	 * @param array<string, mixed>  $quickFilter The quick filter's field values.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @throws RuntimeException When OpenRegister is not available.
	 */
	private function readObjects(string $schema, array $ids, array $quickFilter): array {
		if ($ids === []) {
			return [];
		}

		$registerId = $this->catalogue->getRegisterIdForObjectType(objectType: $schema);
		$schemaId   = $this->catalogue->getSchemaIdForObjectType(objectType: $schema);
		if ($registerId === null || $schemaId === null) {
			return [];
		}

		$objectService = $this->container->get(ObjectService::class);
		if ($objectService instanceof ObjectServiceInterface === false) {
			throw new RuntimeException('OpenRegister ObjectService not available');
		}

		$query = array_merge(
			self::quickFilterQuery(quickFilter: $quickFilter),
			[
				'@self' => ['register' => $registerId, 'schema' => $schemaId],
				'id' => $ids,
				'_limit' => count($ids),
				'_page' => 1,
			]
		);
		$result = $objectService->searchObjectsPaginated($query);

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
	 * The quick filter as query fields: plain field names with a scalar or a list of scalars.
	 *
	 * @param array<string, mixed> $quickFilter The quick filter.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-002-the-applications-and-services-pages-shall-export-the-rows-the-facets-the-quick-filter-and-the-search-leave
	 */
	public static function quickFilterQuery(array $quickFilter): array {
		$query = [];
		foreach ($quickFilter as $field => $value) {
			if (is_string($field) === false || preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $field) !== 1) {
				continue;
			}

			if (is_scalar($value) === true || (is_array($value) === true && array_filter($value, 'is_scalar') === $value && $value !== [])) {
				$query[$field] = $value;
			}
		}

		return $query;
	}//end quickFilterQuery()

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
	 * @spec openspec/changes/insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-002-the-applications-and-services-pages-shall-export-the-rows-the-facets-the-quick-filter-and-the-search-leave
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
