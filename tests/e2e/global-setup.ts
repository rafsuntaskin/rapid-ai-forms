import { chromium, type FullConfig } from '@playwright/test';
import { execSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import path from 'node:path';
import { wpCli } from './helpers';

/**
 * One-time setup: make sure the plugin is active with the stub provider, seed
 * fixtures, and save an authenticated admin storage state the specs reuse.
 */
async function globalSetup( config: FullConfig ) {
	const baseURL = config.projects[ 0 ].use.baseURL ?? 'http://localhost:8888';
	const root = path.resolve( __dirname, '..', '..' );

	// The plugin folder (and thus the WP-CLI slug) is `wp-ai-forms`; wp-env
	// auto-activates it from .wp-env.json, so this is just belt-and-suspenders.
	wpCli( 'plugin activate wp-ai-forms' );

	// Pretty permalinks so rest_url() is /wp-json/… — with the default plain
	// permalinks it's ?rest_route=…, and the admin SPA's query-string REST
	// calls (e.g. submissions?form_id=6) collide on the second `?` → 404.
	wpCli( "rewrite structure '/%postname%/' --hard" );
	wpCli( 'rewrite flush --hard' );

	// Seed the contact form + page and select the stub provider (writes
	// tests/e2e/.fixtures.json). seed.php does the provider selection in PHP.
	execSync(
		'npx wp-env run cli wp eval-file wp-content/plugins/wp-ai-forms/tests/e2e/seed.php',
		{ cwd: root, stdio: 'inherit' }
	);

	// Authenticate once and persist storage state (wp-env default admin).
	mkdirSync( path.resolve( __dirname, '.auth' ), { recursive: true } );
	const browser = await chromium.launch();
	const page = await browser.newPage();
	await page.goto( `${ baseURL }/wp-login.php`, { waitUntil: 'domcontentloaded' } );
	await page.locator( 'input[name="log"]' ).waitFor( { state: 'visible' } );

	// Set the field values directly. fill()/typing are flaky here — WP's
	// show/hide-password wrapper re-renders #user_pass and swallows input — but
	// a native form submits whatever `input.value` holds, so this is stable.
	await page.evaluate( () => {
		( document.querySelector( 'input[name="log"]' ) as HTMLInputElement ).value = 'admin';
		( document.querySelector( 'input[name="pwd"]' ) as HTMLInputElement ).value = 'password';
	} );

	// Resolve on navigation *commit* — the post-login dashboard blocks its
	// `load` event on slow external widgets (wp.org feed, gravatars).
	await Promise.all( [
		page.waitForURL( /wp-admin\//, { waitUntil: 'commit', timeout: 30_000 } ),
		page.click( '#wp-submit' ),
	] );
	await page.context().storageState( { path: path.resolve( __dirname, '.auth', 'admin.json' ) } );
	await browser.close();
}

export default globalSetup;
