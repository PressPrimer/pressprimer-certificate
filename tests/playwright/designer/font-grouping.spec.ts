/**
 * Font control grouping (2.0, Feature 2.0-006 / Educator E-001).
 *
 * Registry entries carrying group: 'custom' (Educator uploads) render
 * under a "Your fonts" group above "Bundled" in the font Select; with
 * no custom entries the flat, ungrouped list is unchanged.
 */
import { test, expect, type Page } from '@playwright/test';
import * as path from 'path';

const HARNESS_URL =
	'file://' + path.resolve( __dirname, 'harness', 'index.html' );

import { TITLE } from './starter-fixture';

async function boot( page: Page ): Promise< void > {
	await page.goto( HARNESS_URL );
	await page.waitForSelector( '[data-ppcert-canvas-scale]' );
}

async function selectElement( page: Page, id: string ): Promise< void > {
	const box = await page
		.locator( `[data-ppcert-el="${ id }"]` )
		.boundingBox();
	await page.mouse.click(
		Math.round( box!.x + box!.width / 2 ),
		Math.round( box!.y + box!.height / 2 )
	);
}

test.describe( 'font grouping', () => {
	test( 'custom fonts group under "Your fonts" above the bundled set', async ( {
		page,
	} ) => {
		await boot( page );

		// Register a custom entry the way the Educator filter output
		// reaches boot data.
		await page.evaluate( () => {
			( window as any ).ppcert_designer_data.fonts[ 'my-upload' ] = {
				label: 'My Upload',
				group: 'custom',
				variants: {
					regular: { tcpdf_font: 'myupload', metrics: {} },
				},
			};
		} );

		await selectElement( page, TITLE.id );
		await page.click( '[data-ppcert-prop="font_family"]' );

		const groups = page.locator( '.ant-select-item-group' );
		await expect( groups ).toHaveCount( 2 );
		await expect( groups.nth( 0 ) ).toHaveText( 'Your fonts' );
		await expect( groups.nth( 1 ) ).toHaveText( 'Bundled' );

		// The custom entry sits in the menu and is selectable.
		await page
			.locator( '.ant-select-item-option', { hasText: 'My Upload' } )
			.click();

		const family = await page.evaluate(
			( id ) =>
				( window as any ).__ppcertHarness
					.getState()
					.layout.elements.find( ( el: any ) => el.id === id ).props
					.font_family,
			TITLE.id
		);
		expect( family ).toBe( 'my-upload' );
	} );

	test( 'without custom fonts the list stays flat and ungrouped', async ( {
		page,
	} ) => {
		await boot( page );

		await selectElement( page, TITLE.id );
		await page.click( '[data-ppcert-prop="font_family"]' );

		await expect( page.locator( '.ant-select-item-group' ) ).toHaveCount(
			0
		);
		await expect(
			page.locator( '.ant-select-item-option' ).first()
		).toBeVisible();
	} );
} );
