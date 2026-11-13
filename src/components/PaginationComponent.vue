<!--
  - SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div v-if="totalPages > 1 || totalItems > minItemsToShow" class="viewPagination">
		<!-- Page info first -->
		<div class="viewPaginationInfo">
			<span class="viewPageInfo">
				{{
					t('stackiq', 'Page {current} of {total}', {
						current: currentPage,
						total: totalPages,
					})
				}}
			</span>
		</div>

		<!-- Page navigation in middle -->
		<div v-if="totalPages > 1" class="viewPaginationNav">
			<!-- First page button -->
			<NcButton :disabled="currentPage === 1" @click="changePage(1)">
				{{ t('stackiq', 'First') }}
			</NcButton>

			<!-- Previous page button -->
			<NcButton
				:disabled="currentPage === 1"
				@click="changePage(currentPage - 1)">
				{{ t('stackiq', 'Previous') }}
			</NcButton>

			<!-- Page number buttons -->
			<div class="viewPaginationNumbers">
				<template v-for="page in visiblePages">
					<span
						v-if="page === '...'"
						:key="'ellipsis-' + page"
						class="viewPaginationEllipsis"
						>...</span
					>
					<NcButton
						v-else
						:key="page"
						:variant="page === currentPage ? 'primary' : 'secondary'"
						:disabled="page === currentPage"
						@click="changePage(page)">
						{{ page }}
					</NcButton>
				</template>
			</div>

			<!-- Next page button -->
			<NcButton
				:disabled="currentPage === totalPages"
				@click="changePage(currentPage + 1)">
				{{ t('stackiq', 'Next') }}
			</NcButton>

			<!-- Last page button -->
			<NcButton
				:disabled="currentPage === totalPages"
				@click="changePage(totalPages)">
				{{ t('stackiq', 'Last') }}
			</NcButton>
		</div>

		<!-- Page size selector last -->
		<div class="viewPaginationPageSize">
			<label for="pageSize">{{ t('stackiq', 'Items per page:') }}</label>
			<NcSelect
				id="pageSize"
				class="pagination-page-size-select"
				:modelValue="currentPageSizeOption"
				:options="pageSizeOptions"
				:clearable="false"
				:inputLabel="t('stackiq', 'Items per page')"
				:labelOutside="true"
				@option:selected="changePageSize" />
		</div>
	</div>
</template>

<script>
import { NcButton, NcSelect } from '@nextcloud/vue'

/**
 * Reusable pagination component for OpenRegister views
 *
 * @package
 * @author Ruben Linde <ruben@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license EUPL-1.2
 * @version 1.0.0
 */
/**
 * The page numbers from one page to another, inclusive.
 *
 * @param {number} from The first page.
 * @param {number} to The last page.
 * @return {Array<number>} The pages.
 */
function pageRange(from, to) {
	const pages = []
	for (let i = from; i <= to; i++) {
		pages.push(i)
	}
	return pages
}

/**
 * The pages after the first one in a collapsed pagination, around the
 * current page, with ellipses for the pages left out.
 *
 * @param {number} current The current page.
 * @param {number} total The number of pages, more than 7.
 * @return {Array<number|string>} The pages and ellipses after page 1.
 */
function pageWindow(current, total) {
	if (current <= 4) {
		// Current page is near the beginning
		return [...pageRange(2, 5), '...', total]
	}
	if (current >= total - 3) {
		// Current page is near the end
		return ['...', ...pageRange(total - 4, total)]
	}
	// Current page is in the middle
	return ['...', ...pageRange(current - 1, current + 1), '...', total]
}

export default {
	name: 'PaginationComponent',
	components: {
		NcButton,
		NcSelect,
	},

	props: {
		/**
		 * Current page number
		 *
		 * @type {number}
		 * @default 1
		 */
		currentPage: {
			type: Number,
			default: 1,
		},

		/**
		 * Total number of pages
		 *
		 * @type {number}
		 * @default 1
		 */
		totalPages: {
			type: Number,
			default: 1,
		},

		/**
		 * Total number of items
		 *
		 * @type {number}
		 * @default 0
		 */
		totalItems: {
			type: Number,
			default: 0,
		},

		/**
		 * Current page size/limit
		 *
		 * @type {number}
		 * @default 20
		 */
		currentPageSize: {
			type: Number,
			default: 20,
		},

		/**
		 * Available page size options
		 *
		 * @type {Array<object>}
		 * @default Standard options array
		 */
		pageSizeOptions: {
			type: Array,
			default: () => [
				{ value: 10, label: '10' },
				{ value: 20, label: '20' },
				{ value: 50, label: '50' },
				{ value: 100, label: '100' },
				{ value: 250, label: '250' },
				{ value: 500, label: '500' },
				{ value: 1000, label: '1000' },
			],
		},

		/**
		 * Minimum items needed to show pagination
		 *
		 * @type {number}
		 * @default 10
		 */
		minItemsToShow: {
			type: Number,
			default: 10,
		},
	},

	computed: {
		/**
		 * Get current page size option object
		 *
		 * @return {object} Current page size option object
		 * @spec openspec/specs/fe-shell-navigation/spec.md
		 */
		currentPageSizeOption() {
			return (
				this.pageSizeOptions.find(
					(option) => option.value === this.currentPageSize,
				) || this.pageSizeOptions[1]
			)
		},

		/**
		 * Calculate visible page numbers for pagination
		 *
		 * @return {Array} Array of page numbers and ellipsis
		 * @spec openspec/specs/fe-shell-navigation/spec.md
		 */
		visiblePages() {
			const total = this.totalPages
			if (total <= 7) {
				// Show all pages if 7 or fewer
				return pageRange(1, total)
			}
			// Always show first page
			return [1, ...pageWindow(this.currentPage, total)]
		},
	},

	methods: {
		/**
		 * Change to a specific page
		 *
		 * @param {number} page - The page number to change to
		 * @return {void}
		 * @spec openspec/specs/fe-shell-navigation/spec.md
		 */
		changePage(page) {
			if (page !== this.currentPage && page >= 1 && page <= this.totalPages) {
				/**
				 * Emitted when page changes
				 *
				 * @event page-changed
				 * @type {number} The new page number
				 */
				this.$emit('page-changed', page)
			}
		},

		/**
		 * Change page size
		 *
		 * @param {object} option - Selected page size option
		 * @return {void}
		 * @spec openspec/specs/fe-shell-navigation/spec.md
		 */
		changePageSize(option) {
			if (option.value !== this.currentPageSize) {
				/**
				 * Emitted when page size changes
				 *
				 * @event page-size-changed
				 * @type {number} The new page size
				 */
				this.$emit('page-size-changed', option.value)
			}
		},
	},
}
</script>

<style scoped>
/* Pagination Styles */
.viewPagination {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-top: 24px;
	padding: 16px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	gap: 16px;
	flex-wrap: wrap;
}

.viewPaginationInfo {
	display: flex;
	align-items: center;
	color: var(--color-text-lighter);
	font-size: 14px;
}

.viewPaginationNav {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.viewPaginationNumbers {
	display: flex;
	align-items: center;
	gap: 4px;
}

.viewPaginationEllipsis {
	padding: 6px 8px;
	color: var(--color-text-lighter);
}

.viewPaginationPageSize {
	display: flex;
	align-items: center;
	gap: 8px;
}

.viewPaginationPageSize label {
	font-size: 14px;
	color: var(--color-text-lighter);
	white-space: nowrap;
}

.pagination-page-size-select {
	min-width: 80px;
}

/* Responsive adjustments */
@media (max-width: 768px) {
	.viewPagination {
		flex-direction: column;
		align-items: stretch;
		gap: 12px;
	}

	.viewPaginationNav {
		justify-content: center;
		flex-wrap: wrap;
	}

	.viewPaginationNumbers {
		justify-content: center;
		flex-wrap: wrap;
	}
}
</style>
