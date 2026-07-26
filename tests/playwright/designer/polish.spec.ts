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

	test( 'page preset change rescales the design uniformly and is undoable', async ( {
		page,
	} ) => {
		await page.goto( CANVAS_URL );
		await page.waitForSelector( '[data-ppcert-canvas-scale]' );

		const before = await page.evaluate( () => {
			const layout = ( window as any ).__ppcertHarness.getState().layout;
			return {
				page: layout.page,
				title: layout.elements.find(
					( el: any ) => 'el_frmtitle' === el.id
				),
				qr: layout.elements.find(
					( el: any ) => 'el_frmqr001' === el.id
				),
			};
		} );

		// A4 landscape -> A4 portrait.
		await page
			.locator(
				'[data-ppcert-prop="page-orientation"] .ant-segmented-item',
				{ hasText: 'Portrait' }
			)
			.click();

		// The rescale is announced, never silent.
		await expect(
			page.getByText( 'Design scaled to fit the new page.', {
				exact: false,
			} )
		).toBeVisible();

		const after = await page.evaluate( () => {
			const layout = ( window as any ).__ppcertHarness.getState().layout;
			return {
				page: layout.page,
				title: layout.elements.find(
					( el: any ) => 'el_frmtitle' === el.id
				),
				qr: layout.elements.find(
					( el: any ) => 'el_frmqr001' === el.id
				),
			};
		} );

		// Uniform scale by the smaller axis ratio, centered on the long
		// axis - same math as updatePagePreset.
		const scale = Math.min(
			after.page.width / before.page.width,
			after.page.height / before.page.height
		);
		const offsetX = ( after.page.width - before.page.width * scale ) / 2;
		const offsetY = ( after.page.height - before.page.height * scale ) / 2;

		expect( after.title.w ).toBeCloseTo( before.title.w * scale, 1 );
		expect( after.title.x ).toBeCloseTo(
			before.title.x * scale + offsetX,
			1
		);
		expect( after.title.y ).toBeCloseTo(
			before.title.y * scale + offsetY,
			1
		);
		expect( after.title.props.font_size ).toBeCloseTo(
			before.title.props.font_size * scale,
			1
		);

		// Uniform scaling keeps the QR square.
		expect( after.qr.w ).toBeCloseTo( after.qr.h, 5 );

		// One undo step restores the exact original design.
		await page.evaluate( () =>
			( window as any ).__ppcertHarness.dispatch( { type: 'UNDO' } )
		);

		const restored = await page.evaluate( () => {
			const layout = ( window as any ).__ppcertHarness.getState().layout;
			return layout.elements.find(
				( el: any ) => 'el_frmtitle' === el.id
			);
		} );

		expect( restored.x ).toBe( before.title.x );
		expect( restored.w ).toBe( before.title.w );
		expect( restored.props.font_size ).toBe( before.title.props.font_size );
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

		// Clicking the name starts editing; Enter commits.
		await page.locator( '.ppcert-designer__title' ).click();
		const input = page.locator( '.ppcert-designer__title-input input' );
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

	test( 'rename commits with the mouse: blur and the check button', async ( {
		page,
	} ) => {
		await page.goto( APP_URL );
		await page.evaluate( () =>
			window.localStorage.removeItem( 'ppcert_harness_template' )
		);
		await page.reload();
		await page.waitForSelector( '[data-ppcert-canvas-scale]' );

		// Blur commit: type a name, then click elsewhere on the page.
		await page.locator( '.ppcert-designer__title' ).click();
		const input = page.locator( '.ppcert-designer__title-input input' );
		await input.fill( 'Blur Committed Name' );
		await page.locator( '.ppcert-designer__page' ).click();

		await expect( page.locator( '.ppcert-designer__title' ) ).toContainText(
			'Blur Committed Name'
		);

		// Check-button commit: the enter affordance is clickable.
		await page.locator( '.ppcert-designer__title' ).click();
		await input.fill( 'Check Committed Name' );
		await page
			.locator( '.ppcert-designer__title-input [aria-label="Save name"]' )
			.click();

		await expect( page.locator( '.ppcert-designer__title' ) ).toContainText(
			'Check Committed Name'
		);

		// Escape cancels without renaming.
		await page.locator( '.ppcert-designer__title' ).click();
		await input.fill( 'Never Applied' );
		await input.press( 'Escape' );

		await expect( page.locator( '.ppcert-designer__title' ) ).toContainText(
			'Check Committed Name'
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
