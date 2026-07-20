import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { readFixtures, wpCli, type Fixtures } from '../helpers';

/**
 * Scenario 1 (plugin loads clean) and scenario 10 (preview route is admin-only)
 * from docs/SMOKE-TEST.md.
 */

let fx: Fixtures;
test.beforeAll( () => {
	fx = readFixtures();
} );

test.describe( 'Plugin health', () => {
	test( 'admin loads clean — no JS errors, global present, version matches', async ( { page } ) => {
		const pageErrors: string[] = [];
		const consoleErrors: string[] = [];
		page.on( 'pageerror', ( e ) => pageErrors.push( e.message ) );
		page.on( 'console', ( msg ) => {
			if ( msg.type() !== 'error' ) return;
			const text = msg.text();
			// Ignore resource-load noise unrelated to the app (e.g. favicon 404).
			if ( /favicon|ResizeObserver|Failed to load resource/i.test( text ) ) return;
			consoleErrors.push( text );
		} );

		await page.goto( '/wp-admin/admin.php?page=rapid-ai-forms' );
		await expect( page.getByRole( 'heading', { name: 'Forms', exact: true } ) ).toBeVisible();

		expect( pageErrors ).toEqual( [] );
		expect( consoleErrors ).toEqual( [] );

		// No PHP notice/warning/fatal leaked into the page.
		const body = ( await page.locator( 'body' ).innerText() ).toLowerCase();
		expect( body ).not.toContain( 'fatal error' );
		expect( body ).not.toContain( 'notice:' );
		expect( body ).not.toContain( 'deprecated:' );

		// The localized admin global is present.
		const hasGlobal = await page.evaluate(
			() => typeof ( window as unknown as Record< string, unknown > ).RAPID_AI_FORMS_ADMIN === 'object'
		);
		expect( hasGlobal ).toBe( true );

		// Installed version equals the plugin header (host source of truth).
		const header = readFileSync(
			path.resolve( __dirname, '..', '..', '..', 'rapid-ai-forms.php' ),
			'utf8'
		);
		const expected = header.match( /^\s*\*\s*Version:\s*(.+)$/m )?.[ 1 ].trim();
		const installed = wpCli( 'plugin get wp-ai-forms --field=version' ).trim();
		expect( installed ).toBe( expected );
	} );

	test( 'preview route serves the form to an admin with the right headers', async ( { page } ) => {
		const resp = await page.goto( `/?rapid_ai_form_preview=${ fx.styleFormId }` );
		expect( resp?.status() ).toBe( 200 );

		const headers = resp?.headers() ?? {};
		expect( headers[ 'x-frame-options' ] ).toBe( 'SAMEORIGIN' );
		expect( headers[ 'cache-control' ] ).toContain( 'no-cache' );

		// Form renders inside a minimal doc, with no admin bar.
		await expect(
			page.locator( `.raif-form[data-form-uuid="${ fx.styleFormUuid }"]` )
		).toBeVisible();
		await expect( page.locator( '#wpadminbar' ) ).toHaveCount( 0 );
	} );

	test( 'preview route is 403 for a logged-out visitor', async ( { browser, baseURL } ) => {
		// Fresh context with no admin storageState → unauthenticated.
		const anon = await browser.newContext( {
			storageState: { cookies: [], origins: [] },
			baseURL,
		} );
		const resp = await anon.request.get( `/?rapid_ai_form_preview=${ fx.styleFormId }` );
		expect( resp.status() ).toBe( 403 );
		await anon.close();
	} );
} );
