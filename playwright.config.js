// @ts-check
const { defineConfig, devices } = require( '@playwright/test' );

/**
 * Config Playwright per gli E2E di DB Cookie Manager.
 *
 * baseURL punta all'ambiente "development" di wp-env (porta 8888), che è quello
 * su cui opera di default `wp-env run cli` (il setup installa lì WooCommerce e
 * il prodotto). Development e tests sono due WordPress separati: testare sullo
 * stesso ambiente configurato dal setup evita il mismatch prodotto-non-trovato.
 */
module.exports = defineConfig( {
	testDir: './tests/e2e',
	fullyParallel: false, // i test condividono lo stato consenso: sequenziali.
	forbidOnly: !! process.env.CI,
	retries: process.env.CI ? 1 : 0,
	workers: 1,
	reporter: process.env.CI ? [ [ 'list' ], [ 'html', { open: 'never' } ] ] : 'list',

	use: {
		baseURL: process.env.WP_BASE_URL || 'http://localhost:8888',
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
