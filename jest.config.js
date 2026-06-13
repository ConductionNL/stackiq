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
	testPathIgnorePatterns: [
		'/node_modules/',
		'/tests/e2e/',
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
