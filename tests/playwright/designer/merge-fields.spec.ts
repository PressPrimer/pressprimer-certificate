/**
 * Merge fields in the designer (Feature 001 FR-004, Feature 002 FR-004,
 * Prompt 3.4).
 *
 * Palette group insertion, the user-meta picker (list + raw key), the
 * sample-vs-token canvas rendering, and the token toggle.
 */
import { test, expect, type Page } from '@playwright/test';
import * as path from 'path';

const HARNESS_URL =
	'file://' + path.resolve( __dirname, 'harness', 'index.html' );

import { STARTER, NAME } from './starter-fixture';

const NAME_ID = NAME.id;

// The starter's own merge fields - derived so starter redesigns keep
// this spec honest.
const STARTER_MERGE_COUNT = STARTER.elements.filter(
	( el ) => 'merge_field' === el.type
).length;

async function boot( page: Page ): Promise< void > {
	await page.goto( HARNESS_URL );
	await page.waitForSelector( '[data-ppcert-canvas-scale]' );
}

function getLastElement( page: Page ): Promise< any > {
	return page.evaluate( () => {
		const state = ( window as any ).__ppcertHarness.getState();
		return state.layout.elements[ state.layout.elements.length - 1 ];
	} );
}

async function openMergeMenu( page: Page ): Promise< void > {
	await page.click( '[data-ppcert-palette="merge_field"]' );
	await page.waitForSelector( '[data-ppcert-merge-menu]' );
}

test.describe( 'merge fields', () => {
	test( 'inserting a registry field creates the element and renders its sample', async ( {
		page,
	} ) => {
		await boot( page );

		await openMergeMenu( page );
		await page.click(
			'[data-ppcert-merge-field="certificate.credential_id"]'
		);

		const added = await getLastElement( page );
		expect( added.type ).toBe( 'merge_field' );
		expect( added.props.token ).toBe( '{{certificate.credential_id}}' );

		// Canvas shows the registry sample, not the token (FR-004).
		await expect(
			page.locator( `[data-ppcert-el="${ added.id }"]` )
		).toHaveText( '7Q4M-K9P2-XT3A' );
	} );

	test( 'user-meta picker inserts via the key list', async ( { page } ) => {
		await boot( page );

		await openMergeMenu( page );
		await page.click( '[data-ppcert-merge-field="__user_meta"]' );

		// The picker lists fixture keys with samples.
		await page.waitForSelector( '[data-ppcert-meta-key="license_no"]' );
		await expect(
			page.locator( '[data-ppcert-meta-key="license_no"]' )
		).toContainText( 'LIC-2201' );

		// Search narrows the list.
		await page.fill( 'input[data-ppcert-meta-search]', 'member' );
		await expect(
			page.locator( '[data-ppcert-meta-key="license_no"]' )
		).toHaveCount( 0 );
		await page.click( '[data-ppcert-meta-key="membership_tier"]' );

		const added = await getLastElement( page );
		expect( added.props.token ).toBe(
			'{{recipient.meta.membership_tier}}'
		);

		// Meta tokens have no registry sample: the raw token renders.
		await expect(
			page.locator( `[data-ppcert-el="${ added.id }"]` )
		).toHaveText( '{{recipient.meta.membership_tier}}' );
	} );

	test( 'user-meta picker inserts via the raw-key input', async ( {
		page,
	} ) => {
		await boot( page );

		await openMergeMenu( page );
		await page.click( '[data-ppcert-merge-field="__user_meta"]' );
		await page.waitForSelector( '[data-ppcert-meta-raw]' );

		await page.fill( 'input[data-ppcert-meta-raw]', 'custom_badge_id' );
		await page
			.locator(
				'.ant-input-search:has(input[data-ppcert-meta-raw]) button'
			)
			.last()
			.click();

		const added = await getLastElement( page );
		expect( added.props.token ).toBe(
			'{{recipient.meta.custom_badge_id}}'
		);
	} );

	test( 'token toggle flips every merge element between sample and token', async ( {
		page,
	} ) => {
		await boot( page );

		// Insert one registry field on top of the starter's own merge
		// elements.
		await openMergeMenu( page );
		await page.click(
			'[data-ppcert-merge-field="recipient.display_name"]'
		);

		// Sample view: the starter's name field shows the sample.
		const nameEl = page.locator( `[data-ppcert-el="${ NAME_ID }"]` );
		await expect( nameEl ).toHaveText( 'Jordan Rivera' );

		const mergeCount = await page
			.locator( '[data-ppcert-merge-display]' )
			.count();
		expect( mergeCount ).toBe( STARTER_MERGE_COUNT + 1 );

		// Flip to token view: every merge element shows raw tokens.
		await page.evaluate( () =>
			( window as any ).__ppcertHarness.setTokenView( true )
		);

		await expect( nameEl ).toHaveText( '{{recipient.full_name}}' );
		expect(
			await page.locator( '[data-ppcert-merge-display="token"]' ).count()
		).toBe( mergeCount );

		// And back.
		await page.evaluate( () =>
			( window as any ).__ppcertHarness.setTokenView( false )
		);
		await expect( nameEl ).toHaveText( 'Jordan Rivera' );
		expect(
			await page.locator( '[data-ppcert-merge-display="sample"]' ).count()
		).toBe( mergeCount );
	} );
} );
