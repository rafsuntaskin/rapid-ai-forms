import { test, expect } from '@playwright/test';
import { readFixtures, type Fixtures } from '../helpers';

/**
 * Form editor Style tab — scenarios 7-9 from docs/SMOKE-TEST.md.
 *
 * Runs against a dedicated seeded "E2E Style" form so mutating custom CSS never
 * touches the forms the other specs rely on. Scenario 9's frontend checks use
 * the admin-only preview route (/?rapid_ai_form_preview={id}) — same renderer,
 * no published page needed, and the admin storageState satisfies its gate.
 *
 * Serial: the persistence/scoping test must read the saved CSS before the
 * sanitizer test overwrites it.
 */
const CRIMSON = 'rgb(220, 20, 60)';

test.describe.configure( { mode: 'serial' } );

let fx: Fixtures;
test.beforeAll( () => {
	fx = readFixtures();
} );

function editorUrl( fixtures: Fixtures ): string {
	return `/wp-admin/admin.php?page=rapid-ai-forms#/forms/${ fixtures.styleFormId }`;
}

test.describe( 'Editor · Form / Style tabs', () => {
	test( 'has both tabs; the Style tab reveals the CSS editor + preview', async ( { page } ) => {
		await page.goto( editorUrl( fx ) );

		// Both tabs present; Save + Back action bar sits outside the tabs.
		await expect( page.getByRole( 'tab', { name: 'Form' } ) ).toBeVisible();
		await expect( page.getByRole( 'tab', { name: 'Style' } ) ).toBeVisible();
		await expect( page.getByRole( 'button', { name: 'Save', exact: true } ).first() ).toBeVisible();
		await expect( page.getByRole( 'link', { name: /Back to forms/ } ).first() ).toBeVisible();

		// Form tab is default → CSS editor not mounted yet.
		await expect( page.getByLabel( 'Custom CSS' ) ).toHaveCount( 0 );

		await page.getByRole( 'tab', { name: 'Style' } ).click();
		await expect( page.getByLabel( 'Custom CSS' ) ).toBeVisible();
		await expect( page.locator( '.raif-styling__preview iframe' ) ).toBeVisible();
	} );

	test( 'typing CSS restyles the preview iframe live (debounced inject)', async ( { page } ) => {
		await page.goto( editorUrl( fx ) );
		await page.getByRole( 'tab', { name: 'Style' } ).click();

		await page.getByLabel( 'Custom CSS' ).fill( `label { color: ${ CRIMSON }; }` );

		// StylingPanel injects into the iframe's scoped <style> after ~600ms;
		// toHaveCSS polls until it applies (no manual sleep needed).
		const label = page
			.frameLocator( '.raif-styling__preview iframe' )
			.locator( '.raif-form label' )
			.first();
		await expect( label ).toHaveCSS( 'color', CRIMSON );
	} );
} );

test.describe( 'Editor · custom CSS persistence + scoping', () => {
	test( 'CSS persists after save and scopes on the rendered form', async ( { page } ) => {
		await page.goto( editorUrl( fx ) );
		await page.getByRole( 'tab', { name: 'Style' } ).click();
		await page.getByLabel( 'Custom CSS' ).fill( `label { color: ${ CRIMSON }; }` );
		await page.getByRole( 'button', { name: 'Save', exact: true } ).first().click();
		await expect( page.getByText( /Saved/ ).first() ).toBeVisible();

		// Reload the editor → CSS round-tripped through settings.custom_css.
		await page.goto( editorUrl( fx ) );
		await page.getByRole( 'tab', { name: 'Style' } ).click();
		await expect( page.getByLabel( 'Custom CSS' ) ).toHaveValue( new RegExp( CRIMSON.replace( /[()]/g, '\\$&' ) ) );

		// Rendered form: a scoped <style id="raif-css-{uuid}"> colors the labels.
		await page.goto( `/?rapid_ai_form_preview=${ fx.styleFormId }` );
		await expect( page.locator( `#raif-css-${ fx.styleFormUuid }` ) ).toHaveCount( 1 );
		await expect(
			page.locator( `.raif-form[data-form-uuid="${ fx.styleFormUuid }"] label` ).first()
		).toHaveCSS( 'color', CRIMSON );
	} );

	test( 'sanitizer strips style/script breakout on save', async ( { page } ) => {
		await page.goto( editorUrl( fx ) );
		await page.getByRole( 'tab', { name: 'Style' } ).click();
		await page
			.getByLabel( 'Custom CSS' )
			.fill( 'label { color: rgb(0, 128, 0); }\n</style><script>window.__raifPwned = true;</script>' );
		await page.getByRole( 'button', { name: 'Save', exact: true } ).first().click();
		await expect( page.getByText( /Saved/ ).first() ).toBeVisible();

		await page.goto( `/?rapid_ai_form_preview=${ fx.styleFormId }` );

		// No injected script ran, and the scoped <style> holds no <script token.
		expect( await page.evaluate( () => ( window as unknown as { __raifPwned?: boolean } ).__raifPwned ) ).toBeFalsy();
		const styleText = await page.locator( `#raif-css-${ fx.styleFormUuid }` ).textContent();
		expect( styleText ).not.toContain( '<script' );
		expect( styleText ).not.toContain( '</style' );
		// The valid rule before the payload survived sanitization.
		expect( styleText ).toContain( 'rgb(0, 128, 0)' );
	} );
} );
