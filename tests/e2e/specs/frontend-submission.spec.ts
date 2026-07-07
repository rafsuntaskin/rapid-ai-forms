import { test, expect } from '@playwright/test';
import { readFixtures, type Fixtures } from '../helpers';

/**
 * Frontend form flow: render the seeded contact form, then exercise required
 * validation (client + server) and a successful, stored submission.
 *
 * Note the deliberate ~2.5s waits before submitting: the Submission_Guard
 * time-trap silently discards anything submitted within MIN_FILL_SECONDS of the
 * token being issued, so a too-fast submit would "succeed" without storing.
 */
const TIME_TRAP_WAIT = 2500;

let fx: Fixtures;
test.beforeAll( () => {
	fx = readFixtures();
} );

function formLocator( page ) {
	return page.locator( `.raif-form[data-form-uuid="${ fx.contactFormUuid }"]` );
}

test.describe( 'Frontend · contact form', () => {
	test( 'renders fields with required markers', async ( { page } ) => {
		await page.goto( fx.contactPageUrl );
		const form = formLocator( page );
		await expect( form ).toBeVisible();
		await expect( form.locator( 'input[name="your_name"]' ) ).toBeVisible();
		await expect( form.locator( 'input[name="email"]' ) ).toBeVisible();
		await expect( form.locator( 'textarea[name="message"]' ) ).toBeVisible();
		await expect( form.locator( 'input[name="your_name"]' ) ).toHaveAttribute( 'required', '' );
		await expect( form.locator( 'input[name="email"]' ) ).toHaveAttribute( 'required', '' );
	} );

	test( 'native required attribute blocks an empty submit', async ( { page } ) => {
		await page.goto( fx.contactPageUrl );
		const form = formLocator( page );
		await page.waitForTimeout( TIME_TRAP_WAIT );
		await form.locator( '.raif-form__submit' ).click();

		// Browser blocks the submit: no success, first required field is invalid.
		await expect( form.locator( '.raif-form__message.is-success' ) ).toHaveCount( 0 );
		const valid = await form
			.locator( 'input[name="your_name"]' )
			.evaluate( ( el: HTMLInputElement ) => el.validity.valid );
		expect( valid ).toBe( false );
	} );

	test( 'server-side validation surfaces inline field errors', async ( { page } ) => {
		await page.goto( fx.contactPageUrl );
		const form = formLocator( page );
		// Drop native required so the request actually reaches the server validator.
		await form.locator( '[required]' ).evaluateAll( ( els ) =>
			els.forEach( ( el ) => el.removeAttribute( 'required' ) )
		);
		await page.waitForTimeout( TIME_TRAP_WAIT );
		await form.locator( '.raif-form__submit' ).click();

		await expect( form.locator( '.raif-field-error' ).first() ).toBeVisible();
		await expect( form.locator( '.raif-form__message.is-error' ) ).toBeVisible();
	} );

	test( 'a complete submission succeeds and is stored', async ( { page } ) => {
		await page.goto( fx.contactPageUrl );
		const form = formLocator( page );
		await form.locator( 'input[name="your_name"]' ).fill( 'Jane Tester' );
		await form.locator( 'input[name="email"]' ).fill( 'jane@example.com' );
		await form.locator( 'textarea[name="message"]' ).fill( 'Playwright says hi' );
		await page.waitForTimeout( TIME_TRAP_WAIT );
		await form.locator( '.raif-form__submit' ).click();

		await expect( form.locator( '.raif-form__message.is-success' ) ).toContainText( 'Thank you' );

		// The stored row surfaces in the admin submissions list (message excerpt).
		// Allow generous time for the React list + REST round-trip on a cold env.
		await page.goto( `/wp-admin/admin.php?page=rapid-ai-forms-submissions&form_id=${ fx.contactFormId }` );
		await expect( page.locator( '.raif-submissions__row' ).first() ).toBeVisible( { timeout: 20_000 } );
		await expect( page.getByText( 'Playwright says hi' ) ).toBeVisible();
	} );
} );
