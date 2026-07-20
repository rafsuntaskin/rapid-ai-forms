import { test, expect } from '@playwright/test';

/**
 * Scenario 11 (Settings) from docs/SMOKE-TEST.md: the provider dropdown, the
 * per-provider credential fields + Verify gate, and the api_key masking
 * guarantee (GET /settings never returns a stored key).
 */
const SETTINGS = '/wp-admin/admin.php?page=rapid-ai-forms-settings';

test.describe( 'Settings', () => {
	test( 'provider dropdown drives the credential fields and the Verify gate', async ( { page } ) => {
		await page.goto( SETTINGS );
		const provider = page.getByLabel( 'Active provider' );
		await expect( provider ).toBeVisible();

		// OpenAI-compatible exposes API Key + Model + Base URL.
		await provider.selectOption( 'openai_compatible' );
		await expect( page.getByLabel( 'API Key' ) ).toBeVisible();
		await expect( page.getByLabel( 'Model' ) ).toBeVisible();
		await expect( page.getByLabel( 'Base URL' ) ).toBeVisible();

		// Anthropic: no Base URL, and Verify is disabled until a key is entered.
		await provider.selectOption( 'anthropic' );
		await expect( page.getByLabel( 'Base URL' ) ).toHaveCount( 0 );
		const verify = page.getByRole( 'button', { name: 'Verify connection' } );
		await expect( verify ).toBeDisabled();
		await page.getByLabel( 'API Key' ).fill( 'sk-anthropic-test' );
		await expect( verify ).toBeEnabled();
	} );

	test( 'GET /settings masks a stored api_key', async ( { page } ) => {
		await page.goto( SETTINGS );
		const settings = await page.evaluate( async () => {
			const g = ( window as unknown as { RAPID_AI_FORMS_ADMIN: { restUrl: string; nonce: string } } )
				.RAPID_AI_FORMS_ADMIN;
			const r = await fetch( `${ g.restUrl }settings`, { headers: { 'X-WP-Nonce': g.nonce } } );
			return r.json();
		} );

		const openai = settings.providers.openai_compatible;
		// Seed saved a real key; the endpoint returns presence, never the value.
		expect( openai.api_key ).toBe( '' );
		expect( openai.api_key_set ).toBe( true );
		// Sanity: no provider block leaks a non-empty api_key.
		for ( const cfg of Object.values( settings.providers ) as Array< { api_key?: string } > ) {
			expect( cfg.api_key ?? '' ).toBe( '' );
		}
	} );
} );
