module.exports = {
	transform: {
		'^.+\\.vue$': '@vue/vue2-jest',
		'^.+\\.js$': 'babel-jest',
		'^.+\\.ts$': 'ts-jest',
		'.+\\.(css|styl|less|sass|scss|png|jpg|ttf|woff|woff2)$': 'jest-transform-stub',
	},
	moduleFileExtensions: ['js', 'json', 'vue', 'ts'],
	// Playwright end-to-end specs under tests/e2e/ run via the Playwright
	// runner (npm run test:e2e), not jest. Excluding them keeps the jest
	// unit suite from trying (and failing) to compile @playwright/test specs.
	// Vitest specs under tests/vitest/ import from 'vitest' and run via the
	// vitest runner (npm run test:unit); jest cannot execute them (vitest is
	// ESM-only and cannot be require()d), so they are excluded here as well.
	testPathIgnorePatterns: [
		'/node_modules/',
		'/tests/e2e/',
		'/tests/vitest/',
	],
	testEnvironment: 'jest-environment-jsdom',
	moduleNameMapper: {
		'^@/(.*)$': '<rootDir>/src/$1',
	},
	coveragePathIgnorePatterns: [
		'index.js',
		'index.ts',
	],
	coverageDirectory: '<rootDir>/coverage-frontend/',
}
