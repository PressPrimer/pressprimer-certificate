/**
 * Designer polish pass (Ryan's Phase 3 review, 2026-07-22).
 *
 * Page preset chooser, template rename, and the Samples|Tokens
 * segmented control.
 */
import { test, expect, type Page } from '@playwright/test';
import * as path from 'path';

const CANVAS_URL =
	'file://' + path.resolve( __dirname, 'harness', 'index.html' );
const APP_URL =
	'file://' + path.resolve( __dirname, 'harness', 'index-app.html' );

test.describe( 'designer polish', () => {
	test( 'page preset chooser switches size and orientation', async ( {
		page,
	} ) => {
		await page.goto( CANVAS_URL );
		await page.waitForSelector( '[data-ppcert-canvas-scale]' );

		// No selection: the Page section shows the preset controls.
		await page.click( '[data-ppcert-prop="page-size"]' );
		await page
			.locator( '.ant-select-item-option', { hasText: 'Letter' } )
			.click();

		let pageState = await page.evaluate(
			() => ( window as any ).__ppcertHarness.getState().layout.page
		);
		expect( pageState.size ).toBe( 'letter' );
		expect( pageState.width ).toBe( 792 );
		expect( pageState.height ).toBe( 612 );

		await page
			.locator(
				'[data-ppcert-prop="page-orientation"] .ant-segmented-item',
				{ hasText: 'Portrait' }
			)
			.click();

		pageState = await page.evaluate(
			() => ( window as any ).__ppcertHarness.getState().layout.page
		);
		expect( pageState.orientation ).toBe( 'portrait' );
		expect( pageState.width ).toBe( 612 );
		expect( pageState.height ).toBe( 792 );

		// The canvas page resizes with the preset.
		const box = await page
			.locator( '.ppcert-designer__page' )
			.boundingBox();
		expect( Math.round( box!.width ) ).toBe( 612 );
		expect( Math.round( box!.height ) ).toBe( 792 );

		// Undoable like any layout mutation.
		await page.evaluate( () =>
			( window as any ).__ppcertHarness.dispatch( { type: 'UNDO' } )
		);
		pageState = await page.evaluate(
			() => ( window as any ).__ppcertHarness.getState().layout.page
		);
		expect( pageState.width ).toBe( 792 );
	} );

	test( 'toolbar rename marks dirty and persists with save', async ( {
		page,
	} ) => {
		await page.goto( APP_URL );
		await page.evaluate( () =>
			window.localStorage.removeItem( 'ppcert_harness_template' )
		);
		await page.reload();
		await page.waitForSelector( '[data-ppcert-canvas-scale]' );

		// Ant Typography editable: pencil icon starts editing.
		await page
			.locator( '.ppcert-designer__title .ant-typography-edit' )
			.click();
		const input = page.locator( '.ppcert-designer__title textarea' );
		await input.fill( 'Spring Cohort Certificate' );
		await input.press( 'Enter' );

		expect(
			await page.evaluate(
				() => ( window as any ).__ppcertHarness.getState().dirty
			)
		).toBe( true );

		await page.locator( '[data-ppcert-action="save"]' ).click();
		await expect( page.getByText( 'Template saved.' ) ).toBeVisible();

		await page.reload();
		await page.waitForSelector( '[data-ppcert-canvas-scale]' );
		await expect( page.locator( '.ppcert-designer__title' ) ).toContainText(
			'Spring Cohort Certificate'
		);
	} );

	test( 'Samples|Tokens segmented flips merge display both ways', async ( {
		page,
	} ) => {
		await page.goto( APP_URL );
		await page.evaluate( () =>
			window.localStorage.removeItem( 'ppcert_harness_template' )
		);
		await page.reload();
		await page.waitForSelector( '[data-ppcert-canvas-scale]' );

		const nameEl = page.locator( '[data-ppcert-el="el_frmname1"]' );

		await page
			.locator( '.ant-segmented-item', { hasText: 'Tokens' } )
			.click();
		await expect( nameEl ).toContainText( '{{recipient.full_name}}' );

		await page
			.locator( '.ant-segmented-item', { hasText: 'Samples' } )
			.click();
		await expect( nameEl ).not.toContainText( '{{' );
	} );
} );
