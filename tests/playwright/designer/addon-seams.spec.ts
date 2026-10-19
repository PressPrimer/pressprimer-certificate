/**
 * Designer addon seams (2.1, Feature 2.1-004 FR-002, FR-006).
 *
 * The page panel seams Educator's custom sizes and wallet cards build
 * on (resizePage, the Size-menu filter, the custom-size slot, small-page
 * zoom), the history seam (attachHistoryState + historyState on undo and
 * redo), scaleElements, the image quick picks, and the safe margin
 * filter (Educator's 1/8 in card margin).
 */
import { test, expect, type Page } from '@playwright/test';
import * as path from 'path';

const HARNESS_URL =
	'file://' + path.resolve( __dirname, 'harness', 'index-app.html' );

async function boot( page: Page ): Promise< void > {
	await page.goto( HARNESS_URL );
	await page.waitForSelector( '[data-ppcert-canvas-scale]' );
}

test.describe( 'addon seams', () => {
	test( 'resizePage to a custom size: one undo step, "Custom size", the slot, and 400% zoom', async ( {
		page,
	} ) => {
		await boot( page );

		const before = await page.evaluate( () => {
			const api = ( window as any ).ppcert_designer_api;
			return JSON.parse( JSON.stringify( api.getLayout().page ) );
		} );

		const changed = await page.evaluate( () =>
			( window as any ).ppcert_designer_api.resizePage( {
				size: 'custom',
				width: 243,
				height: 153,
			} )
		);
		expect( changed ).toBe( true );

		const after = await page.evaluate(
			() => ( window as any ).ppcert_designer_api.getLayout().page
		);
		expect( after ).toMatchObject( {
			size: 'custom',
			orientation: 'landscape',
			width: 243,
			height: 153,
		} );

		await expect(
			page.locator( '[data-ppcert-prop="page-size"]' )
		).toContainText( 'Custom size' );
		await expect(
			page.locator( '#ppcert-designer-page-size-extension' )
		).toHaveCount( 1 );

		// Small page: the zoom menu offers 400%.
		await page.locator( '.ppcert-designer__zoom' ).click();
		await expect(
			page.locator( '.ant-select-item-option', { hasText: '400%' } )
		).toBeVisible();
		await page.keyboard.press( 'Escape' );

		// One undo restores the page exactly; the slot goes away.
		await page.evaluate( () =>
			( window as any ).__ppcertHarness.dispatch( { type: 'UNDO' } )
		);
		const restored = await page.evaluate(
			() => ( window as any ).ppcert_designer_api.getLayout().page
		);
		expect( restored ).toEqual( before );
		await expect(
			page.locator( '#ppcert-designer-page-size-extension' )
		).toHaveCount( 0 );

		// Invalid targets change nothing.
		expect(
			await page.evaluate( () =>
				( window as any ).ppcert_designer_api.resizePage( {
					size: 'custom',
					width: 'wide',
					height: 100,
				} )
			)
		).toBe( false );
	} );

	test( 'attached history state comes back on undo and redo, once', async ( {
		page,
	} ) => {
		await boot( page );

		const events = await page.evaluate( async () => {
			const api = ( window as any ).ppcert_designer_api;
			const harness = ( window as any ).__ppcertHarness;
			const seen: any[] = [];

			api.subscribe( ( change: any ) =>
				seen.push( [ change.historyDirection, change.historyState ] )
			);

			api.resizePage( { size: 'letter', orientation: 'portrait' } );
			api.attachHistoryState( { pages: 'before-resize' } );

			const tick = () =>
				new Promise( ( resolve ) => setTimeout( resolve, 50 ) );

			await tick();
			harness.dispatch( { type: 'UNDO' } );
			await tick();
			harness.dispatch( { type: 'REDO' } );
			await tick();

			const edited = JSON.parse( JSON.stringify( api.getLayout() ) );
			edited.elements = edited.elements.slice( 1 );
			api.applyLayout( edited );
			await tick();

			return seen;
		} );

		const directed = events.filter( ( event ) => null !== event[ 0 ] );
		expect( directed ).toEqual( [
			[ 'undo', { pages: 'before-resize' } ],
			[ 'redo', { pages: 'before-resize' } ],
		] );
		expect( events[ events.length - 1 ] ).toEqual( [ null, null ] );
	} );

	test( 'the safe margin follows ppcert.designer.safeMargin: the dashed line, snapping, and the Page panel note', async ( {
		page,
	} ) => {
		await boot( page );

		const guide = page.locator( '.ppcert-designer__safe-margin' );
		await expect( guide ).toHaveAttribute(
			'data-ppcert-safe-margin',
			'24'
		);
		await expect(
			page.locator( '[data-ppcert-safe-margin-help]' )
		).toBeVisible();

		await page.evaluate( () => {
			const harness = ( window as any ).__ppcertHarness;
			harness.addFilter(
				'ppcert.designer.safeMargin',
				'ppcert-test',
				() => 30
			);
			// Re-render the canvas.
			const api = ( window as any ).ppcert_designer_api;
			api.replaceLayout(
				JSON.parse( JSON.stringify( api.getLayout() ) )
			);
		} );

		await expect( guide ).toHaveAttribute(
			'data-ppcert-safe-margin',
			'30'
		);
		expect(
			await guide.evaluate( ( el ) => window.getComputedStyle( el ).top )
		).toBe( '30px' );

		// A drag that would leave the title's left edge at 29 pt locks
		// onto the filtered margin (24 pt is beyond the 4 pt tolerance).
		const { x, scale } = await page.evaluate( () => {
			const api = ( window as any ).ppcert_designer_api;
			const title = api
				.getLayout()
				.elements.find( ( el: any ) => 'el_frmtitle' === el.id );
			const node = document.querySelector(
				'[data-ppcert-canvas-scale]'
			) as HTMLElement;

			return {
				x: title.x,
				scale: Number( node.dataset.ppcertCanvasScale ),
			};
		} );

		const box = await page
			.locator( '[data-ppcert-el="el_frmtitle"]' )
			.boundingBox();
		const from = {
			x: Math.round( box!.x + box!.width / 2 ),
			y: Math.round( box!.y + box!.height / 2 ),
		};

		await page.mouse.move( from.x, from.y );
		await page.mouse.down();
		await page.mouse.move( from.x + ( 29 - x ) * scale, from.y, {
			steps: 8,
		} );
		await expect( page.locator( '[data-ppcert-guide="v"]' ) ).toBeVisible();
		await page.mouse.up();

		expect(
			await page.evaluate(
				() =>
					( window as any ).ppcert_designer_api
						.getLayout()
						.elements.find( ( el: any ) => 'el_frmtitle' === el.id )
						.x
			)
		).toBe( 30 );

		await page.evaluate( () =>
			( window as any ).__ppcertHarness.removeAllFilters(
				'ppcert.designer.safeMargin'
			)
		);
	} );

	test( 'scaleElements is the Page panel geometry', async ( { page } ) => {
		await boot( page );

		const scaled = await page.evaluate( () =>
			( window as any ).ppcert_designer_api.scaleElements(
				[
					{
						id: 'el_scaletst',
						type: 'text',
						x: 100,
						y: 50,
						w: 200,
						h: 40,
						z: 1,
						props: { font_size: 20 },
					},
				],
				{ width: 800, height: 600 },
				{ width: 400, height: 300 }
			)
		);

		expect( scaled[ 0 ] ).toMatchObject( {
			x: 50,
			y: 25,
			w: 100,
			h: 20,
			props: { font_size: 10 },
		} );
	} );

	test( 'the Size menu filter adds a sized option that resizes the page', async ( {
		page,
	} ) => {
		await boot( page );

		await page.evaluate( () => {
			const harness = ( window as any ).__ppcertHarness;
			harness.addFilter(
				'ppcert.designer.pageSizeOptions',
				'ppcert-test',
				( options: any[] ) => [
					...options,
					{
						value: 'wallet',
						label: 'Wallet card',
						width: 243,
						height: 153,
					},
				]
			);
			// Re-render the panel.
			const api = ( window as any ).ppcert_designer_api;
			api.setSelection( [] );
			api.replaceLayout(
				JSON.parse( JSON.stringify( api.getLayout() ) )
			);
		} );

		await page.locator( '[data-ppcert-prop="page-size"]' ).click();
		await page
			.locator( '.ant-select-item-option', { hasText: 'Wallet card' } )
			.click();

		const pageAfter = await page.evaluate(
			() => ( window as any ).ppcert_designer_api.getLayout().page
		);
		expect( pageAfter ).toMatchObject( {
			size: 'custom',
			width: 243,
			height: 153,
		} );
		await expect(
			page.locator( '[data-ppcert-prop="page-size"]' )
		).toContainText( 'Wallet card' );

		// Turning a custom page swaps its sides.
		await page
			.locator( '[data-ppcert-prop="page-orientation"]' )
			.getByText( 'Portrait' )
			.click();
		expect(
			await page.evaluate(
				() => ( window as any ).ppcert_designer_api.getLayout().page
			)
		).toMatchObject( {
			size: 'custom',
			orientation: 'portrait',
			width: 153,
			height: 243,
		} );

		await page.evaluate( () =>
			( window as any ).__ppcertHarness.removeAllFilters(
				'ppcert.designer.pageSizeOptions'
			)
		);
	} );

	test( 'image quick picks set the attachment in one undo step', async ( {
		page,
	} ) => {
		await boot( page );

		await page.evaluate( () => {
			const harness = ( window as any ).__ppcertHarness;
			const api = ( window as any ).ppcert_designer_api;

			harness.seedAttachment(
				77,
				'data:image/gif;base64,R0lGODlhAQABAAAAACw=',
				'image/png'
			);
			harness.addFilter(
				'ppcert.designer.imagePresets',
				'ppcert-test',
				() => [
					{
						id: 77,
						label: 'Brand logo',
						thumb: 'data:image/gif;base64,R0lGODlhAQABAAAAACw=',
						url: 'data:image/gif;base64,R0lGODlhAQABAAAAACw=',
						mime: 'image/png',
					},
				]
			);

			const layout = JSON.parse( JSON.stringify( api.getLayout() ) );
			layout.elements.push( {
				id: 'el_imgquick',
				type: 'image',
				x: 20,
				y: 20,
				w: 80,
				h: 80,
				z: layout.elements.length + 1,
				props: { attachment_id: 0, fit: 'contain', opacity: 1 },
			} );
			api.applyLayout( layout );
			api.setSelection( [ 'el_imgquick' ] );
		} );

		const depth = await page.evaluate(
			() =>
				( window as any ).__ppcertHarness.getState().history.past.length
		);

		await page.getByRole( 'button', { name: 'Brand logo' } ).click();

		const result = await page.evaluate( () => {
			const harness = ( window as any ).__ppcertHarness;
			const state = harness.getState();
			const element = state.layout.elements.find(
				( el: any ) => 'el_imgquick' === el.id
			);
			return {
				attachment: element.props.attachment_id,
				depth: state.history.past.length,
			};
		} );

		expect( result.attachment ).toBe( 77 );
		expect( result.depth ).toBe( depth + 1 );
		await expect(
			page.getByRole( 'button', { name: 'Brand logo' } )
		).toHaveAttribute( 'aria-pressed', 'true' );

		await page.evaluate( () =>
			( window as any ).__ppcertHarness.removeAllFilters(
				'ppcert.designer.imagePresets'
			)
		);
	} );
} );
