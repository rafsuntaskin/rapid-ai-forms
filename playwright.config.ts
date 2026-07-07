import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright config for the Rapid AI Forms plugin e2e suite.
 *
 * Targets the local @wordpress/env DEV site (http://localhost:8888). Start it
 * first with `npx wp-env start` (the mu-plugin mapping in .wp-env.json registers
 * the deterministic stub AI provider the specs rely on).
 *
 * globalSetup logs in as admin once and seeds fixtures; specs reuse the saved
 * admin storage state.
 */
const BASE_URL = process.env.WP_BASE_URL ?? 'http://localhost:8888';

export default defineConfig( {
	testDir: './tests/e2e/specs',
	globalSetup: './tests/e2e/global-setup.ts',
	outputDir: './tests/e2e/.artifacts',
	timeout: 60_000,
	expect: { timeout: 10_000 },
	fullyParallel: false,
	workers: 1,
	reporter: process.env.CI ? 'github' : [ [ 'list' ], [ 'html', { open: 'never', outputFolder: './tests/e2e/.report' } ] ],
	use: {
		baseURL: BASE_URL,
		storageState: './tests/e2e/.auth/admin.json',
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
