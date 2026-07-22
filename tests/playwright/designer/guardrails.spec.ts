/**
 * Guardrails (Feature 001 FR-002/FR-008, Prompt 3.5).
 *
 * Snap-to-grid (4pt), dynamic alignment guides with Alt bypass, 50-step
 * undo/redo over long sessions, element deletion, the 100-element cap,
 * and the point rulers.
 */
import { test, expect, type Page } from '@playwright/test';
import * as path from 'path';

const HARNESS_URL =
	'file://' + path.resolve( __dirname, 'harness', 'index.html' );

const TITLE = { id: 'el_frmtitle', x: 121, y: 96, w: 600, h: 44 };
const QR = { id: 'el_frmqr001', x: 752, y: 475, w: 60, h: 60 };
const ELEMENT_COUNT = 11;

async function boot( page: Page ): Promise< void > {
	await page.goto( HARNESS_URL );
	await page.waitForSelector( '[data-ppcert-canvas-scale]' );
}

function getElement( page: Page, id: string ): Promise< any > {
	return page.evaluate( ( eid ) => {
		const state = ( window as any ).__ppcertHarness.getState();
		return state.layout.elements.find( ( el: any ) => el.id === eid );
	}, id );
}

function elementCount( page: Page ): Promise< number > {
	return page.evaluate(
		() =>
			( window as any ).__ppcertHarness.getState().layout.elements.length
	);
}

async function centerOf( page: Page, id: string ) {
	const box = await page
		.locator( `[data-ppcert-el="${ id }"]` )
		.boundingBox();
	return {
		x: Math.round( box!.x + box!.width / 2 ),
		y: Math.round( box!.y + box!.height / 2 ),
	};
}

test.describe( 'guardrails', () => {
	test( 'drag with no alignment match applies the exact pointer delta', async ( {
		page,
	} ) => {
		await boot( page );

		// No invisible grid (UX decision 2026-07-22): unless a guide is
		// shown, the element lands exactly where the pointer puts it.
		const from = await centerOf( page, TITLE.id );
		await page.mouse.move( from.x, from.y );
		await page.mouse.down();
		await page.mouse.move( from.x + 39, from.y + 18, { steps: 5 } );
		await page.mouse.up();

		const title = await getElement( page, TITLE.id );
		expect( title.x ).toBe( TITLE.x + 39 );
		expect( title.y ).toBe( TITLE.y + 18 );
	} );

	test( 'Alt suppresses alignment locking near a target', async ( {
		page,
	} ) => {
		await boot( page );

		// Without Alt this drag would lock the QR center to the page
		// center (421); with Alt held it lands on the raw delta.
		const from = await centerOf( page, QR.id );
		await page.keyboard.down( 'Alt' );
		await page.mouse.move( from.x, from.y );
		await page.mouse.down();
		await page.mouse.move( from.x - 358, from.y, { steps: 8 } );
		await page.mouse.up();
		await page.keyboard.up( 'Alt' );

		const qr = await getElement( page, QR.id );
		expect( qr.x ).toBe( QR.x - 358 );
		expect( qr.x + qr.w / 2 ).not.toBe( 421 );
	} );

	test( 'safe-margin line is a snap target with a visible guide', async ( {
		page,
	} ) => {
		await boot( page );

		// Proposed left edge lands at 23pt - within tolerance of the
		// drawn 24pt safe margin: it locks on with a guide.
		const from = await centerOf( page, TITLE.id );
		await page.mouse.move( from.x, from.y );
		await page.mouse.down();
		await page.mouse.move( from.x - 98, from.y, { steps: 8 } );

		await expect( page.locator( '[data-ppcert-guide="v"]' ) ).toBeVisible();

		await page.mouse.up();

		expect( ( await getElement( page, TITLE.id ) ).x ).toBe( 24 );
	} );

	test( 'alignment guide appears and snaps the QR center to page center', async ( {
		page,
	} ) => {
		await boot( page );

		// Proposed center lands within tolerance of the page center
		// (421): the guide shows mid-drag and the drop aligns exactly.
		const from = await centerOf( page, QR.id );
		await page.mouse.move( from.x, from.y );
		await page.mouse.down();
		await page.mouse.move( from.x - 358, from.y, { steps: 8 } );

		await expect( page.locator( '[data-ppcert-guide="v"]' ) ).toBeVisible();

		await page.mouse.up();

		const qr = await getElement( page, QR.id );
		expect( qr.x + qr.w / 2 ).toBe( 421 );
	} );

	test( 'a 60-nudge session undoes 50 steps and redoes back', async ( {
		page,
	} ) => {
		await boot( page );

		const from = await centerOf( page, TITLE.id );
		await page.mouse.click( from.x, from.y );

		await page.keyboard.press( 'ArrowRight', { delay: 5 } );
		for ( let i = 1; i < 60; i++ ) {
			await page.keyboard.press( 'ArrowRight', { delay: 5 } );
		}

		expect( ( await getElement( page, TITLE.id ) ).x ).toBe( TITLE.x + 60 );

		// History caps at 50 (FR-008): undoing everything lands 50 back.
		await page.evaluate( () => {
			for ( let i = 0; i < 55; i++ ) {
				( window as any ).__ppcertHarness.dispatch( {
					type: 'UNDO',
				} );
			}
		} );
		expect( ( await getElement( page, TITLE.id ) ).x ).toBe( TITLE.x + 10 );

		await page.evaluate( () => {
			for ( let i = 0; i < 55; i++ ) {
				( window as any ).__ppcertHarness.dispatch( {
					type: 'REDO',
				} );
			}
		} );
		expect( ( await getElement( page, TITLE.id ) ).x ).toBe( TITLE.x + 60 );
	} );

	test( 'Delete key and context menu remove elements; undo restores', async ( {
		page,
	} ) => {
		await boot( page );

		const titleCenter = await centerOf( page, TITLE.id );
		await page.mouse.click( titleCenter.x, titleCenter.y );
		await page.keyboard.press( 'Delete' );

		expect( await elementCount( page ) ).toBe( ELEMENT_COUNT - 1 );
		await expect(
			page.locator( `[data-ppcert-el="${ TITLE.id }"]` )
		).toHaveCount( 0 );

		// z renumbered 1..n after removal.
		const zs = await page.evaluate( () =>
			( window as any ).__ppcertHarness
				.getState()
				.layout.elements.map( ( el: any ) => el.z )
		);
		expect( zs ).toEqual(
			Array.from( { length: ELEMENT_COUNT - 1 }, ( _, i ) => i + 1 )
		);

		await page.evaluate( () =>
			( window as any ).__ppcertHarness.dispatch( { type: 'UNDO' } )
		);
		expect( await elementCount( page ) ).toBe( ELEMENT_COUNT );

		// Context menu delete.
		const qrCenter = await centerOf( page, QR.id );
		await page.mouse.click( qrCenter.x, qrCenter.y, {
			button: 'right',
		} );
		await page.getByText( 'Delete', { exact: true } ).click();
		expect( await elementCount( page ) ).toBe( ELEMENT_COUNT - 1 );
	} );

	test( '100-element cap disables the palette with an explanation', async ( {
		page,
	} ) => {
		await boot( page );

		// Grow the layout to exactly 100 elements.
		await page.evaluate( () => {
			const bridge = ( window as any ).__ppcertHarness;
			const layout = bridge.getState().layout;
			const elements = [ ...layout.elements ];

			for ( let i = elements.length; i < 100; i++ ) {
				elements.push( {
					id: `el_fill${ String( i ).padStart( 4, '0' ) }`,
					type: 'shape',
					x: 10,
					y: 10,
					w: 20,
					h: 20,
					z: i + 1,
					props: {
						shape: 'rect',
						stroke_color: '#000000',
						stroke_width: 1,
						fill_color: '',
						radius: 0,
					},
				} );
			}

			bridge.dispatch( {
				type: 'APPLY_LAYOUT',
				layout: { ...layout, elements },
			} );
		} );

		expect( await elementCount( page ) ).toBe( 100 );

		const textItem = page.locator( '[data-ppcert-palette="text"]' );
		await expect( textItem ).toHaveAttribute( 'aria-disabled', 'true' );

		await textItem.click();
		expect( await elementCount( page ) ).toBe( 100 );

		// Background (document root) stays available at the cap.
		await expect(
			page.locator( '[data-ppcert-palette="background"]' )
		).not.toHaveAttribute( 'aria-disabled', 'true' );
	} );

	test( 'rulers render by default and toggle off', async ( { page } ) => {
		await boot( page );

		await expect( page.locator( '[data-ppcert-ruler="h"]' ) ).toBeVisible();
		await expect( page.locator( '[data-ppcert-ruler="v"]' ) ).toBeVisible();

		await page.evaluate( () =>
			( window as any ).__ppcertHarness.setRulers( false )
		);

		await expect( page.locator( '[data-ppcert-ruler]' ) ).toHaveCount( 0 );
	} );
} );
