<?php

/**
 * View Query Builder for SoftwareCatalog
 *
 * Extracts filter/sorting logic from ViewService to reduce CyclomaticComplexity,
 * NPathComplexity, and ExcessiveMethodLength on ViewService methods.
 *
 * @category  Service
 * @package   OCA\SoftwareCatalog\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-6
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Service;

/**
 * Builds and applies filters and sorting to view query option arrays.
 *
 * Each method returns an updated copy of the $options array with the
 * appropriate filter appended, keeping ViewService methods short and
 * below PHPMD ExcessiveMethodLength / CyclomaticComplexity thresholds.
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-6
 */
class ViewQueryBuilder
{
    /**
     * Apply a date range filter to the query options.
     *
     * @param array<string,mixed> $baseQuery The base query options array.
     * @param string|null         $dateFrom  ISO-8601 start date (inclusive) or null.
     * @param string|null         $dateTo    ISO-8601 end date (inclusive) or null.
     *
     * @return array<string,mixed> Updated query options with date filter applied.
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-6
     */
    public function applyDateFilter(array $baseQuery, ?string $dateFrom, ?string $dateTo): array
    {
        if ($dateFrom !== null && $dateFrom !== '') {
            $baseQuery['_modified_after'] = $dateFrom;
        }

        if ($dateTo !== null && $dateTo !== '') {
            $baseQuery['_modified_before'] = $dateTo;
        }

        return $baseQuery;

    }//end applyDateFilter()

    /**
     * Apply a status filter to the query options.
     *
     * @param array<string,mixed> $baseQuery The base query options array.
     * @param string|null         $status    Status value to filter on, or null to skip.
     *
     * @return array<string,mixed> Updated query options with status filter applied.
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-6
     */
    public function applyStatusFilter(array $baseQuery, ?string $status): array
    {
        if ($status !== null && $status !== '') {
            $baseQuery['status'] = $status;
        }

        return $baseQuery;

    }//end applyStatusFilter()

    /**
     * Apply a free-text search filter to the query options.
     *
     * @param array<string,mixed> $baseQuery The base query options array.
     * @param string|null         $search    Free-text search term, or null to skip.
     *
     * @return array<string,mixed> Updated query options with search filter applied.
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-6
     */
    public function applySearchFilter(array $baseQuery, ?string $search): array
    {
        if ($search !== null && $search !== '') {
            $baseQuery['_search'] = $search;
        }

        return $baseQuery;

    }//end applySearchFilter()

    /**
     * Apply sorting parameters to the query options.
     *
     * @param array<string,mixed> $baseQuery The base query options array.
     * @param string|null         $sortField Field name to sort by, or null for default.
     * @param string              $sortOrder 'asc' or 'desc' (default: 'asc').
     *
     * @return array<string,mixed> Updated query options with sorting applied.
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-6
     */
    public function applySorting(array $baseQuery, ?string $sortField, string $sortOrder='asc'): array
    {
        if ($sortField !== null && $sortField !== '') {
            $direction = 'ASC';
            if (strtolower($sortOrder) === 'desc') {
                $direction = 'DESC';
            }

            $baseQuery['_order'] = [$sortField => $direction];
        }

        return $baseQuery;

    }//end applySorting()

    /**
     * Build a complete query options array by applying all standard filters.
     *
     * Convenience wrapper that chains all four filter methods in one call,
     * reducing the number of lines in ViewService methods.
     *
     * @param array<string,mixed> $baseQuery  The base query options.
     * @param array<string,mixed> $filterOpts Named filter options:
     *                                        - dateFrom (string|null)
     *                                        - dateTo   (string|null)
     *                                        - status   (string|null)
     *                                        - search   (string|null)
     *                                        - sortField (string|null)
     *                                        - sortOrder (string)
     *
     * @return array<string,mixed> Fully filtered and sorted query options.
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-6
     */
    public function buildQuery(array $baseQuery, array $filterOpts): array
    {
        $query = $this->applyDateFilter(
            baseQuery: $baseQuery,
            dateFrom: $filterOpts['dateFrom'] ?? null,
            dateTo: $filterOpts['dateTo'] ?? null
        );

        $query = $this->applyStatusFilter(baseQuery: $query, status: $filterOpts['status'] ?? null);
        $query = $this->applySearchFilter(baseQuery: $query, search: $filterOpts['search'] ?? null);
        $query = $this->applySorting(
            baseQuery: $query,
            sortField: $filterOpts['sortField'] ?? null,
            sortOrder: $filterOpts['sortOrder'] ?? 'asc'
        );

        return $query;

    }//end buildQuery()
}//end class
