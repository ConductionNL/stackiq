/**
 * Get the current theme from Nextcloud
 *
 * @return {string} The current theme name
 * @spec openspec/specs/fe-stores/spec.md
 */
export function getTheme() {
	// Try to get theme from Nextcloud's OCA.Theming
	if (typeof OCA !== 'undefined' && OCA.Theming && OCA.Theming.name) {
		return OCA.Theming.name
	}

	// Fallback to checking for dark theme class
	if (document.documentElement.classList.contains('theme--dark')) {
		return 'dark'
	}

	// Default theme
	return 'light'
}

/**
 * Check if the current theme is dark
 *
 * @return {boolean} True if dark theme is active
 * @spec openspec/specs/fe-stores/spec.md
 */
export function isDarkTheme() {
	return getTheme() === 'dark'
}

/**
 * The Nextcloud primary colour variables getThemeVariables reads, in order.
 *
 * @type {Array<string>}
 */
const THEME_VARIABLES = [
	'--color-primary',
	'--color-primary-text',
	'--color-primary-element',
	'--color-primary-light',
	'--color-primary-light-text',
	'--color-primary-light-element',
	'--color-primary-element-text',
	'--color-primary-element-hover',
	'--color-primary-element-active',
	'--color-primary-element-disabled',
	'--color-primary-element-light',
	'--color-primary-element-light-text',
	'--color-primary-element-light-border',
	'--color-primary-element-light-hover',
	'--color-primary-element-light-active',
	'--color-primary-element-light-disabled',
	'--color-primary-element-light-focus',
	'--color-primary-element-light-focus-shadow',
	'--color-primary-element-light-focus-border',
	'--color-primary-element-light-focus-text',
	'--color-primary-element-light-focus-hover',
	'--color-primary-element-light-focus-active',
	'--color-primary-element-light-focus-disabled',
	'--color-primary-element-light-focus-border-hover',
	'--color-primary-element-light-focus-border-active',
	'--color-primary-element-light-focus-border-disabled',
	'--color-primary-element-light-focus-text-hover',
	'--color-primary-element-light-focus-text-active',
	'--color-primary-element-light-focus-text-disabled',
	'--color-primary-element-light-focus-shadow-hover',
	'--color-primary-element-light-focus-shadow-active',
	'--color-primary-element-light-focus-shadow-disabled',
	'--color-primary-element-light-focus-hover-border',
	'--color-primary-element-light-focus-hover-text',
	'--color-primary-element-light-focus-hover-shadow',
	'--color-primary-element-light-focus-active-border',
	'--color-primary-element-light-focus-active-text',
	'--color-primary-element-light-focus-active-shadow',
	'--color-primary-element-light-focus-disabled-border',
	'--color-primary-element-light-focus-disabled-text',
	'--color-primary-element-light-focus-disabled-shadow',
]

/**
 * Get theme-specific CSS variables
 *
 * @return {object} Theme CSS variables
 * @spec openspec/specs/fe-stores/spec.md
 */
export function getThemeVariables() {
	const computedStyle = getComputedStyle(document.documentElement)
	return Object.fromEntries(
		THEME_VARIABLES.map((name) => [name, computedStyle.getPropertyValue(name)]),
	)
}
