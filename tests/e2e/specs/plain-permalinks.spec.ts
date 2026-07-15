import { test, expect } from '@playwright/test';
import { readFixtures, wpCli, type Fixtures } from '../helpers';

/**
 * Regression for the plain-permalink REST bug (fixed in c085958).
 *
 * On a site using PLAIN permalinks, rest_url() is `…/?rest_route=/…/v1/`. A
 * query-string REST path (e.g. `submissions?form_id=6`) used to collide on the
 * second `?` and 404, breaking the admin submissions filter/pagination. The
 * client now folds query args in with @wordpress/url addQueryArgs.
 *
 * This spec switches the site to plain permalinks, drives a real submission,
 * and asserts the admin filtered list (the query-string call) loads — then
 * restores pretty permalinks so the rest of the suite is unaffected.
 */
const TIME_TRAP_WAIT = 2500;

test.describe.configure( { mode: 'serial' } );

let fx: Fixtures;

test.beforeAll( () => {
	fx = readFixtures();
	// Plain permalinks → rest_url() uses ?rest_route= (the bug condition).
	wpCli( "rewrite structure '' --hard" );
	wpCli( 'rewrite flush --hard' );
} );

test.afterAll( () => {
	// Restore pretty permalinks for the rest of the suite / later runs.
	wpCli( "rewrite structure '/%postname%/' --hard" );
	wpCli( 'rewrite flush --hard' );
} );

test.describe( 'Plain permalinks · REST query-string calls', () => {
	test( 'submits and the admin filtered list loads (no rest_route 404)', async ( { page } ) => {
		// Navigate by ?page_id — the pretty page URL 404s under plain permalinks.
		await page.goto( `/?page_id=${ fx.contactPageId }` );
		const form = page.locator( `.raif-form[data-form-uuid="${ fx.contactFormUuid }"]` );
		await expect( form ).toBeVisible();

		await form.locator( 'input[name="your_name"]' ).fill( 'Plain Perma' );
		await form.locator( 'input[name="email"]' ).fill( 'plain@example.com' );
		await form.locator( 'textarea[name="message"]' ).fill( 'plain permalink works' );
		await page.waitForTimeout( TIME_TRAP_WAIT );
		await form.locator( '.raif-form__submit' ).click();
		await expect( form.locator( '.raif-form__message.is-success' ) ).toContainText( 'Thank you' );

		// The admin submissions list, filtered by form_id — this is the
		// query-string REST call that used to 404 on plain permalinks.
		await page.goto( `/wp-admin/admin.php?page=rapid-ai-forms-submissions&form_id=${ fx.contactFormId }` );
		await expect( page.getByText( 'Failed to load submissions' ) ).toHaveCount( 0 );
		await expect( page.locator( '.raif-submissions__row' ).first() ).toBeVisible( { timeout: 20_000 } );
		await expect( page.getByText( 'plain permalink works' ) ).toBeVisible();
	} );
} );
