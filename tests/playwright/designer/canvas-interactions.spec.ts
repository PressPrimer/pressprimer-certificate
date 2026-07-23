/**
 * Canvas interactions (Feature 001 FR-002, Prompt 3.2).
 *
 * Drives the standalone harness (tests/playwright/designer/harness) -
 * the real Canvas + store with the formal starter layout. Storage is in
 * points: dragging by (40pt x zoom) screen pixels at different zooms
 * must produce identical stored coordinates.
 */
import { test, expect, type Page } from '@playwright/test';
import * as path from 'path';

const HARNESS_URL =
	'file://' + path.resolve( __dirname, 'harness', 'index.html' );

// Element geometry derives from the real starter document so starter
// redesigns can never silently strand these specs.
import { TITLE, NAME, QR, ELEMENT_COUNT } from './starter-fixture';

async function boot( page: Page, zoom: number | 'fit' ): Promise< void > {
	await page.goto( HARNESS_URL );
	await page.waitForSelector( '[data-ppcert-canvas-scale]' );
	await page.evaluate(
		( z ) => ( window as any ).__ppcertHarness.setZoom( z ),
		zoom
	);
	if ( 'fit' !== zoom ) {
		await page.waitForSelector( `[data-ppcert-canvas-scale="${ zoom }"]` );
	}
}

function getElement( page: Page, id: string ): Promise< any > {
	return page.evaluate( ( eid ) => {
		const state = ( window as any ).__ppcertHarness.getState();
		return state.layout.elements.find( ( el: any ) => el.id === eid );
	}, id );
}

function getSelection( page: Page ): Promise< string[] > {
	return page.evaluate(
		() => ( window as any ).__ppcertHarness.getState().selection
	);
}

function getOrder( page: Page ): Promise< string[] > {
	return page.evaluate( () =>
		( window as any ).__ppcertHarness
			.getState()
			.layout.elements.map( ( el: any ) => el.id )
	);
}

/**
 * Center of an element's rendered box in screen coordinates.
 * @param page
 * @param id
 */
async function centerOf( page: Page, id: string ) {
	const box = await page
		.locator( `[data-ppcert-el="${ id }"]` )
		.boundingBox();
	if ( ! box ) {
		throw new Error( `No rendered box for ${ id }` );
	}
	return {
		x: Math.round( box.x + box.width / 2 ),
		y: Math.round( box.y + box.height / 2 ),
	};
}

async function drag(
	page: Page,
	from: { x: number; y: number },
	dxPx: number,
	dyPx: number
): Promise< void > {
	await page.mouse.move( from.x, from.y );
	await page.mouse.down();
	await page.mouse.move( from.x + dxPx, from.y + dyPx, { steps: 5 } );
	await page.mouse.up();
}

// Drag with snapping disabled (Alt) - these specs verify raw pointer
// math; snapping behavior has its own suite (guardrails.spec.ts).
async function dragUnsnapped(
	page: Page,
	from: { x: number; y: number },
	dxPx: number,
	dyPx: number
): Promise< void > {
	await page.keyboard.down( 'Alt' );
	await drag( page, from, dxPx, dyPx );
	await page.keyboard.up( 'Alt' );
}

test.describe( 'canvas interactions', () => {
	for ( const zoom of [ 0.75, 1.5 ] ) {
		test( `drag stores zoom-independent points at ${
			zoom * 100
		}%`, async ( { page } ) => {
			await boot( page, zoom );

			// 40pt right, 20pt down: screen delta scales with zoom, the
			// stored point delta must not.
			const from = await centerOf( page, TITLE.id );
			await dragUnsnapped( page, from, 40 * zoom, 20 * zoom );

			const after = await getElement( page, TITLE.id );
			expect( after.x ).toBe( TITLE.x + 40 );
			expect( after.y ).toBe( TITLE.y + 20 );
		} );
	}

	test( 'arrow nudge is exactly 1pt, Shift nudge 10pt', async ( {
		page,
	} ) => {
		await boot( page, 1 );

		await page.mouse.click(
			...( Object.values( await centerOf( page, TITLE.id ) ) as [
				number,
				number,
			] )
		);
		expect( await getSelection( page ) ).toEqual( [ TITLE.id ] );

		await page.keyboard.press( 'ArrowRight' );
		await page.keyboard.press( 'Shift+ArrowDown' );
		await page.keyboard.press( 'ArrowUp' );

		const after = await getElement( page, TITLE.id );
		expect( after.x ).toBe( TITLE.x + 1 );
		expect( after.y ).toBe( TITLE.y + 10 - 1 );
	} );

	test( 'z-order context menu reorders elements and DOM', async ( {
		page,
	} ) => {
		await boot( page, 1 );

		// Send the title (topmost at its center) to the back.
		const titleCenter = await centerOf( page, TITLE.id );
		await page.mouse.click( titleCenter.x, titleCenter.y, {
			button: 'right',
		} );
		await page.getByText( 'Send to back' ).click();

		let order = await getOrder( page );
		expect( order[ 0 ] ).toBe( TITLE.id );

		// z renumbered 1..n after the move.
		const title = await getElement( page, TITLE.id );
		expect( title.z ).toBe( 1 );

		// DOM paint order follows: the title renders first.
		const domOrder = await page.$$eval( '[data-ppcert-el]', ( nodes ) =>
			nodes.map( ( node ) => node.getAttribute( 'data-ppcert-el' ) )
		);
		expect( domOrder[ 0 ] ).toBe( TITLE.id );

		// Send the name field backward one step: index 4 -> 3.
		const nameCenter = await centerOf( page, NAME.id );
		await page.mouse.click( nameCenter.x, nameCenter.y, {
			button: 'right',
		} );
		await page.getByText( 'Send backward' ).click();

		order = await getOrder( page );
		expect( order.indexOf( NAME.id ) ).toBe( 3 );
	} );

	test( 'resize via east handle is exact; QR stays square', async ( {
		page,
	} ) => {
		await boot( page, 1 );

		// Text element: east handle +25pt widens only.
		const titleCenter = await centerOf( page, TITLE.id );
		await page.mouse.click( titleCenter.x, titleCenter.y );

		const eastBox = await page
			.locator( '[data-ppcert-handle="e"]' )
			.boundingBox();
		await dragUnsnapped(
			page,
			{
				x: Math.round( eastBox!.x + eastBox!.width / 2 ),
				y: Math.round( eastBox!.y + eastBox!.height / 2 ),
			},
			25,
			0
		);

		const title = await getElement( page, TITLE.id );
		expect( title.w ).toBe( TITLE.w + 25 );
		expect( title.h ).toBe( TITLE.h );
		expect( title.x ).toBe( TITLE.x );

		// QR: south-east corner drag grows square by the larger delta.
		const qrCenter = await centerOf( page, QR.id );
		await page.mouse.click( qrCenter.x, qrCenter.y );

		const seBox = await page
			.locator( '[data-ppcert-handle="se"]' )
			.boundingBox();
		await dragUnsnapped(
			page,
			{
				x: Math.round( seBox!.x + seBox!.width / 2 ),
				y: Math.round( seBox!.y + seBox!.height / 2 ),
			},
			20,
			10
		);

		const qr = await getElement( page, QR.id );
		expect( qr.w ).toBe( QR.w + 20 );
		expect( qr.h ).toBe( QR.w + 20 );
		expect( qr.x ).toBe( QR.x );
		expect( qr.y ).toBe( QR.y );
	} );

	test( 'shift-click toggles; marquee selects intersecting', async ( {
		page,
	} ) => {
		await boot( page, 1 );

		const titleCenter = await centerOf( page, TITLE.id );
		const nameCenter = await centerOf( page, NAME.id );

		await page.mouse.click( titleCenter.x, titleCenter.y );
		await page.keyboard.down( 'Shift' );
		await page.mouse.click( nameCenter.x, nameCenter.y );
		await page.keyboard.up( 'Shift' );
		expect( ( await getSelection( page ) ).sort() ).toEqual(
			[ TITLE.id, NAME.id ].sort()
		);

		// Shift-click an already-selected element deselects it.
		await page.keyboard.down( 'Shift' );
		await page.mouse.click( titleCenter.x, titleCenter.y );
		await page.keyboard.up( 'Shift' );
		expect( await getSelection( page ) ).toEqual( [ NAME.id ] );

		// Marquee from the page corner over everything selects all.
		const pageBox = await page
			.locator( '.ppcert-designer__page' )
			.boundingBox();
		await drag(
			page,
			{
				x: Math.round( pageBox!.x + 5 ),
				y: Math.round( pageBox!.y + 5 ),
			},
			830,
			585
		);
		expect( ( await getSelection( page ) ).length ).toBe( ELEMENT_COUNT );

		// Click on empty page space clears the selection.
		await page.mouse.click(
			Math.round( pageBox!.x + 10 ),
			Math.round( pageBox!.y + 10 )
		);
		expect( await getSelection( page ) ).toEqual( [] );
	} );

	test( 'drag is one undo step and undo restores position', async ( {
		page,
	} ) => {
		await boot( page, 1 );

		const from = await centerOf( page, TITLE.id );
		await dragUnsnapped( page, from, 40, 20 );

		const historyDepth = await page.evaluate(
			() =>
				( window as any ).__ppcertHarness.getState().history.past.length
		);
		expect( historyDepth ).toBe( 1 );

		await page.evaluate( () =>
			( window as any ).__ppcertHarness.dispatch( { type: 'UNDO' } )
		);

		const after = await getElement( page, TITLE.id );
		expect( after.x ).toBe( TITLE.x );
		expect( after.y ).toBe( TITLE.y );
	} );

	test( 'fit-width is the computed clamp of container/page', async ( {
		page,
	} ) => {
		await boot( page, 'fit' );

		const { scaleAttr, expected } = await page.evaluate( () => {
			const surface = document.querySelector(
				'.ppcert-designer__surface'
			) as HTMLElement;
			const canvas = document.querySelector(
				'[data-ppcert-canvas-scale]'
			) as HTMLElement;
			const ruler = document.querySelector(
				'[data-ppcert-ruler="v"]'
			) as SVGElement | null;
			const state = ( window as any ).__ppcertHarness.getState();
			const available =
				surface.clientWidth -
				( ruler ? ruler.getBoundingClientRect().width : 0 );
			return {
				scaleAttr: Number(
					canvas.getAttribute( 'data-ppcert-canvas-scale' )
				),
				expected: Math.min(
					2,
					Math.max( 0.5, available / state.layout.page.width )
				),
			};
		} );

		expect( scaleAttr ).toBeCloseTo( expected, 5 );
	} );
} );
