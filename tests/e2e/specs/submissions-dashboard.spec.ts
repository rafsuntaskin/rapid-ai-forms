import { test, expect } from '@playwright/test';
import { readFixtures, type Fixtures } from '../helpers';

/**
 * Submissions dashboard — scenarios 4 (filter by form) and 5 (detail modal +
 * email preview) from docs/SMOKE-TEST.md.
 *
 * Seeded fixtures (seed.php): a Contact form (notifications OFF) with a
 * submission whose message is `contactMessage`, and an RSVP form
 * (notifications ON) with a submission for guest `rsvpGuest`.
 */
const SUBMISSIONS = '/wp-admin/admin.php?page=rapid-ai-forms-submissions';

let fx: Fixtures;
test.beforeAll( () => {
	fx = readFixtures();
} );

test.describe( 'Submissions · filter by form', () => {
	test( 'select + deep link narrow the list to one form', async ( { page } ) => {
		// Unfiltered: both forms' submissions are present.
		await page.goto( SUBMISSIONS );
		await expect( page.getByText( fx.contactMessage ) ).toBeVisible();
		await expect( page.getByText( fx.rsvpGuest ) ).toBeVisible();
		// Cross-form list shows a form pill on each row.
		expect(
			await page.locator( '.raif-submissions__form-pill' ).count()
		).toBeGreaterThan( 0 );

		// Filter to the RSVP form via the SelectControl.
		await page
			.getByLabel( 'Filter by form' )
			.selectOption( { label: fx.rsvpFormTitle } );

		await expect( page.getByText( fx.rsvpGuest ) ).toBeVisible();
		await expect( page.getByText( fx.contactMessage ) ).toHaveCount( 0 );
		// Pill is hidden while filtered to a single form.
		await expect( page.locator( '.raif-submissions__form-pill' ) ).toHaveCount( 0 );

		// Deep link applies the filter on load and the select reflects it.
		await page.goto( `${ SUBMISSIONS }&form_id=${ fx.rsvpFormId }` );
		await expect( page.getByText( fx.rsvpGuest ) ).toBeVisible();
		await expect( page.getByText( fx.contactMessage ) ).toHaveCount( 0 );
		await expect( page.getByLabel( 'Filter by form' ) ).toHaveValue(
			String( fx.rsvpFormId )
		);
	} );
} );

test.describe( 'Submissions · detail modal', () => {
	test( 'shows fields, IP/UA, and the email preview (notifications on)', async ( { page } ) => {
		await page.goto( `${ SUBMISSIONS }&form_id=${ fx.rsvpFormId }` );
		await page.locator( '.raif-submissions__row' ).first().getByRole( 'button', { name: 'View' } ).click();

		const modal = page.locator( '.raif-submission-modal' );
		await expect( modal ).toBeVisible();

		// Field table: values (scoped to the fields list to avoid the email echo).
		const fields = modal.locator( '.raif-submission-modal__fields' );
		await expect( fields.getByText( fx.rsvpGuest ) ).toBeVisible();
		await expect( fields.getByText( '3', { exact: true } ) ).toBeVisible();
		// Seeded IP + user agent rows.
		await expect( fields.getByText( '203.0.113.7' ) ).toBeVisible();
		await expect( fields.getByText( 'E2E-Agent/1.0' ) ).toBeVisible();

		// Email preview: subject with a resolved {full_name} tag, body via {all_fields}.
		const email = modal.locator( '.raif-submission-modal__email' );
		await expect( email ).toBeVisible();
		await expect(
			modal.locator( '.raif-submission-modal__email-subject' )
		).toContainText( `New RSVP from ${ fx.rsvpGuest }` );
		await expect(
			modal.locator( '.raif-submission-modal__email-body' )
		).toContainText( `Full name: ${ fx.rsvpGuest }` );
	} );

	test( 'omits the email section when notifications are off', async ( { page } ) => {
		await page.goto( `${ SUBMISSIONS }&form_id=${ fx.contactFormId }` );
		await page.locator( '.raif-submissions__row' ).first().getByRole( 'button', { name: 'View' } ).click();

		const modal = page.locator( '.raif-submission-modal' );
		await expect( modal ).toBeVisible();
		await expect( modal.locator( '.raif-submission-modal__email' ) ).toHaveCount( 0 );
	} );
} );
