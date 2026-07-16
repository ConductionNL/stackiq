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
 * Get theme-specific CSS variables
 *
 * @return {object} Theme CSS variables
  * @spec openspec/specs/fe-stores/spec.md
 */
export function getThemeVariables() {
	const root = document.documentElement
	const computedStyle = getComputedStyle(root)

	return {
		'--color-primary': computedStyle.getPropertyValue('--color-primary'),
		'--color-primary-text': computedStyle.getPropertyValue('--color-primary-text'),
		'--color-primary-element': computedStyle.getPropertyValue('--color-primary-element'),
		'--color-primary-light': computedStyle.getPropertyValue('--color-primary-light'),
		'--color-primary-light-text': computedStyle.getPropertyValue('--color-primary-light-text'),
		'--color-primary-light-element': computedStyle.getPropertyValue('--color-primary-light-element'),
		'--color-primary-element-text': computedStyle.getPropertyValue('--color-primary-element-text'),
		'--color-primary-element-hover': computedStyle.getPropertyValue('--color-primary-element-hover'),
		'--color-primary-element-active': computedStyle.getPropertyValue('--color-primary-element-active'),
		'--color-primary-element-disabled': computedStyle.getPropertyValue('--color-primary-element-disabled'),
		'--color-primary-element-light': computedStyle.getPropertyValue('--color-primary-element-light'),
		'--color-primary-element-light-text': computedStyle.getPropertyValue('--color-primary-element-light-text'),
		'--color-primary-element-light-border': computedStyle.getPropertyValue('--color-primary-element-light-border'),
		'--color-primary-element-light-hover': computedStyle.getPropertyValue('--color-primary-element-light-hover'),
		'--color-primary-element-light-active': computedStyle.getPropertyValue('--color-primary-element-light-active'),
		'--color-primary-element-light-disabled': computedStyle.getPropertyValue('--color-primary-element-light-disabled'),
		'--color-primary-element-light-focus': computedStyle.getPropertyValue('--color-primary-element-light-focus'),
		'--color-primary-element-light-focus-shadow': computedStyle.getPropertyValue('--color-primary-element-light-focus-shadow'),
		'--color-primary-element-light-focus-border': computedStyle.getPropertyValue('--color-primary-element-light-focus-border'),
		'--color-primary-element-light-focus-text': computedStyle.getPropertyValue('--color-primary-element-light-focus-text'),
		'--color-primary-element-light-focus-hover': computedStyle.getPropertyValue('--color-primary-element-light-focus-hover'),
		'--color-primary-element-light-focus-active': computedStyle.getPropertyValue('--color-primary-element-light-focus-active'),
		'--color-primary-element-light-focus-disabled': computedStyle.getPropertyValue('--color-primary-element-light-focus-disabled'),
		'--color-primary-element-light-focus-border-hover': computedStyle.getPropertyValue('--color-primary-element-light-focus-border-hover'),
		'--color-primary-element-light-focus-border-active': computedStyle.getPropertyValue('--color-primary-element-light-focus-border-active'),
		'--color-primary-element-light-focus-border-disabled': computedStyle.getPropertyValue('--color-primary-element-light-focus-border-disabled'),
		'--color-primary-element-light-focus-text-hover': computedStyle.getPropertyValue('--color-primary-element-light-focus-text-hover'),
		'--color-primary-element-light-focus-text-active': computedStyle.getPropertyValue('--color-primary-element-light-focus-text-active'),
		'--color-primary-element-light-focus-text-disabled': computedStyle.getPropertyValue('--color-primary-element-light-focus-text-disabled'),
		'--color-primary-element-light-focus-shadow-hover': computedStyle.getPropertyValue('--color-primary-element-light-focus-shadow-hover'),
		'--color-primary-element-light-focus-shadow-active': computedStyle.getPropertyValue('--color-primary-element-light-focus-shadow-active'),
		'--color-primary-element-light-focus-shadow-disabled': computedStyle.getPropertyValue('--color-primary-element-light-focus-shadow-disabled'),
		'--color-primary-element-light-focus-hover-border': computedStyle.getPropertyValue('--color-primary-element-light-focus-hover-border'),
		'--color-primary-element-light-focus-hover-text': computedStyle.getPropertyValue('--color-primary-element-light-focus-hover-text'),
		'--color-primary-element-light-focus-hover-shadow': computedStyle.getPropertyValue('--color-primary-element-light-focus-hover-shadow'),
		'--color-primary-element-light-focus-active-border': computedStyle.getPropertyValue('--color-primary-element-light-focus-active-border'),
		'--color-primary-element-light-focus-active-text': computedStyle.getPropertyValue('--color-primary-element-light-focus-active-text'),
		'--color-primary-element-light-focus-active-shadow': computedStyle.getPropertyValue('--color-primary-element-light-focus-active-shadow'),
		'--color-primary-element-light-focus-disabled-border': computedStyle.getPropertyValue('--color-primary-element-light-focus-disabled-border'),
		'--color-primary-element-light-focus-disabled-text': computedStyle.getPropertyValue('--color-primary-element-light-focus-disabled-text'),
		'--color-primary-element-light-focus-disabled-shadow': computedStyle.getPropertyValue('--color-primary-element-light-focus-disabled-shadow'),
	}
}
