// @ts-check
const { defineConfig, devices } = require( '@playwright/test' );

/**
 * Config Playwright per gli E2E di DB Cookie Manager.
 *
 * baseURL punta all'istanza "tests" di wp-env (porta 8889 in .wp-env.json).
 * I test girano solo su Chromium in CI: il blocco/consenso non è
 * browser-specifico e Chromium tiene i tempi bassi.
 */
module.exports = defineConfig( {
	testDir: './tests/e2e',
	fullyParallel: false, // i test condividono lo stato consenso: sequenziali.
	forbidOnly: !! process.env.CI,
	retries: process.env.CI ? 1 : 0,
	workers: 1,
	reporter: process.env.CI ? [ [ 'list' ], [ 'html', { open: 'never' } ] ] : 'list',

	use: {
		baseURL: process.env.WP_BASE_URL || 'http://localhost:8889',
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
	},

	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
		},
	],
} );
