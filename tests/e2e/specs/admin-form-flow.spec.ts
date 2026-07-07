import { test, expect } from '@playwright/test';

/**
 * Admin SPA: create a form and generate its fields with the (stubbed) AI
 * provider, then confirm it persists and appears in the list.
 */
test.describe( 'Admin · AI form creation', () => {
	test( 'generates fields from a prompt and saves the form', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=rapid-ai-forms' );
		await expect( page.getByRole( 'heading', { name: 'Forms', exact: true } ) ).toBeVisible();

		await page.getByRole( 'button', { name: '+ New form' } ).click();
		await expect( page ).toHaveURL( /#\/forms\/\d+/ );

		// The prompt field is enabled once the (stub) provider reports configured.
		const promptField = page.getByLabel( 'Prompt' );
		await expect( promptField ).toBeEnabled();
		await promptField.fill( 'a volunteer signup form' );
		await page.getByRole( 'button', { name: 'Generate fields' } ).click();

		// The stub provider returns "Volunteer Signup" with these fields.
		await expect( page.getByText( 'Volunteer Signup' ).first() ).toBeVisible();
		await expect( page.getByText( 'Full name' ).first() ).toBeVisible();
		await expect( page.getByText( 'Availability' ).first() ).toBeVisible();

		// Two Save buttons + two "Saved" status labels render (top + bottom bars).
		await page.getByRole( 'button', { name: 'Save', exact: true } ).first().click();
		await expect( page.getByText( /Saved/ ).first() ).toBeVisible();

		// It shows up in the list.
		await page.goto( '/wp-admin/admin.php?page=rapid-ai-forms' );
		await expect( page.getByText( 'Volunteer Signup' ).first() ).toBeVisible();
	} );
} );
