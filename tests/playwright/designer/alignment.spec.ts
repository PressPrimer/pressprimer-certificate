/**
 * Alignment tools (Feature 1.1-004): mirrored-margin snapping with
 * measurement guides, and the align/distribute toolbar.
 */
import { test, expect, type Page } from '@playwright/test';
import * as path from 'path';

const HARNESS_URL =
	'file://' + path.resolve( __dirname, 'harness', 'index.html' );

const BOX_PROPS = {
	shape: 'rect',
	stroke_color: '#1f2a44',
	stroke_width: 2,
	fill_color: '',
	radius: 0,
};

// A4 landscape, 842 x 595. el_mirrleft's left margin is 40; dragging
// el_mirrdrag near x=702 puts its RIGHT margin (842 - x - 100) within
// snap tolerance of 40 - the support ticket's symmetry case.
const LAYOUT = {
	layout_schema_version: 2,
	page: { size: 'a4', orientation: 'landscape', width: 842, height: 595 },
	background: { color: '#ffffff', attachment_id: 0 },
	elements: [
		{
			id: 'el_mirrleft',
			type: 'shape',
			x: 40,
			y: 100,
			w: 60,
			h: 60,
			z: 1,
			props: { ...BOX_PROPS },
		},
		{
			id: 'el_mirrdrag',
			type: 'shape',
			x: 660,
			y: 310,
			w: 100,
			h: 60,
			z: 2,
			props: { ...BOX_PROPS },
		},
		{
			id: 'el_scatter3',
			type: 'shape',
			x: 300,
			y: 480,
			w: 80,
			h: 60,
			z: 3,
			props: { ...BOX_PROPS },
		},
	],
};

async function boot( page: Page ): Promise< void > {
	await page.goto( HARNESS_URL );
	await page.waitForSelector( '[data-ppcert-canvas-scale]' );

	await page.evaluate( ( layout: any ) => {
		( window as any ).__ppcertHarness.dispatch( {
			type: 'LOAD_TEMPLATE',
			template: { id: 0, title: 'Alignment', status: 'draft' },
			layout,
		} );
	}, LAYOUT );
}

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

function getElement( page: Page, id: string ): Promise< any > {
	return page.evaluate(
		( elementId: string ) =>
			( window as any ).__ppcertHarness
				.getState()
				.layout.elements.find( ( el: any ) => el.id === elementId ),
		id
	);
}

function selectIds( page: Page, ids: string[] ): Promise< void > {
	return page.evaluate( ( selection: string[] ) => {
		( window as any ).__ppcertHarness.dispatch( {
			type: 'SET_SELECTION',
			ids: selection,
		} );
	}, ids );
}

test.describe( 'mirrored-margin snapping', () => {
	test( 'dragging onto a mirror shows teal measurement badges and lands the margin exactly', async ( {
		page,
	} ) => {
		await boot( page );

		// el_mirrdrag starts at x=660; the trailing mirror sits at
		// x=702. Drag +40px to land at ~700, inside the 4pt tolerance.
		const from = await centerOf( page, 'el_mirrdrag' );

		await page.mouse.move( from.x, from.y );
		await page.mouse.down();
		await page.mouse.move( from.x + 40, from.y, { steps: 5 } );

		// Mid-drag: both measurement bars visible, labeled with the
		// shared margin.
		await expect( page.locator( '[data-ppcert-mirror-bar]' ) ).toHaveCount(
			2
		);
		await expect(
			page.locator( '[data-ppcert-mirror-bar="self"]' )
		).toContainText( '40 pt' );
		await expect(
			page.locator( '[data-ppcert-mirror-bar="partner"]' )
		).toContainText( '40 pt' );
		await expect(
			page.locator( '[data-ppcert-guide="mirror-v"]' )
		).toHaveCount( 1 );

		await page.mouse.up();

		// Released: right margin exactly equals the partner's left
		// margin (842 - 702 - 100 = 40).
		const dragged = await getElement( page, 'el_mirrdrag' );

		expect( dragged.x ).toBe( 702 );
		expect( dragged.y ).toBe( 310 );

		// Guides clear after the drop.
		await expect( page.locator( '[data-ppcert-mirror-bar]' ) ).toHaveCount(
			0
		);
	} );
} );

test.describe( 'align / distribute toolbar', () => {
	test( 'hidden under two selections, aligns left in one undo step', async ( {
		page,
	} ) => {
		await boot( page );

		await expect(
			page.locator( '[data-ppcert-align="left"]' )
		).toHaveCount( 0 );

		await selectIds( page, [
			'el_mirrleft',
			'el_mirrdrag',
			'el_scatter3',
		] );

		await page.click( '[data-ppcert-align="left"]' );

		for ( const id of [ 'el_mirrleft', 'el_mirrdrag', 'el_scatter3' ] ) {
			const el = await getElement( page, id );
			expect( el.x ).toBe( 40 );
		}

		// ONE undo restores every element (FR-005).
		await page.evaluate( () =>
			( window as any ).__ppcertHarness.dispatch( { type: 'UNDO' } )
		);

		expect( ( await getElement( page, 'el_mirrdrag' ) ).x ).toBe( 660 );
		expect( ( await getElement( page, 'el_scatter3' ) ).x ).toBe( 300 );
	} );

	test( 'distributes horizontal gaps evenly, first and last holding', async ( {
		page,
	} ) => {
		await boot( page );

		await selectIds( page, [
			'el_mirrleft',
			'el_mirrdrag',
			'el_scatter3',
		] );

		await page.click( '[data-ppcert-distribute="x"]' );

		// Sorted by x: mirrleft (40, w60), scatter3 (w80), mirrdrag
		// (660, w100). Span 40..760, sizes 240, gap = (720 - 240) / 2 =
		// 240. Positions: 40, 340, 660.
		expect( ( await getElement( page, 'el_mirrleft' ) ).x ).toBe( 40 );
		expect( ( await getElement( page, 'el_scatter3' ) ).x ).toBe( 340 );
		expect( ( await getElement( page, 'el_mirrdrag' ) ).x ).toBe( 660 );
	} );

	test( 'distribute disables with two selected', async ( { page } ) => {
		await boot( page );

		await selectIds( page, [ 'el_mirrleft', 'el_mirrdrag' ] );

		await expect(
			page.locator( '[data-ppcert-align="left"]' )
		).toBeEnabled();
		await expect(
			page.locator( '[data-ppcert-distribute="x"]' )
		).toBeDisabled();
	} );
} );
