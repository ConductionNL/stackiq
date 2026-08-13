<template>
	<div
		class="published-icon"
		:class="{ published: isPublished }"
		:title="tooltip || defaultTooltip">
		<span v-if="isPublished" class="published-icon-svg">✓</span>
		<span v-else class="unpublished-icon-svg">✗</span>
	</div>
</template>

<script>
export default {
	name: 'PublishedIcon',
	props: {
		isPublished: {
			type: Boolean,
			default: false,
		},
		tooltip: {
			type: String,
			default: '',
		},
	},
	computed: {
		/**
		 * @spec openspec/specs/fe-shell-navigation/spec.md
		 */
		defaultTooltip() {
			return this.isPublished ? 'Published' : 'Not Published'
		},
	},
}
</script>

<style scoped>
.published-icon {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 16px;
	height: 16px;
	border-radius: 50%;
}

.published-icon-svg {
	color: var(--color-success, #46ba61);
	font-weight: bold;
	font-size: 14px;
}

.unpublished-icon-svg {
	color: var(--color-warning, #f39c12);
	font-weight: bold;
	font-size: 14px;
}

.published-icon:hover {
	cursor: help;
}
</style>
