/**
 * Element components & properties panel (Feature 001 FR-003/FR-005,
 * Prompt 3.3).
 *
 * Drives the harness editor (palette + canvas + panel). Prop edits must
 * reflect on the canvas and survive a state-level save/load round-trip;
 * bold/italic gate on the font family's bundled variants.
 */
import { test, expect, type Page } from '@playwright/test';
import * as path from 'path';

const HARNESS_URL =
	'file://' + path.resolve( __dirname, 'harness', 'index.html' );

const TITLE_ID = 'el_frmtitle';
const QR_ID = 'el_frmqr001';
const ELEMENT_COUNT = 11;

const PNG_1PX =
	'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

async function boot( page: Page ): Promise< void > {
	await page.goto( HARNESS_URL );
	await page.waitForSelector( '[data-ppcert-canvas-scale]' );
}

function getState( page: Page ): Promise< any > {
	return page.evaluate( () =>
		JSON.parse(
			JSON.stringify( ( window as any ).__ppcertHarness.getState() )
		)
	);
}

function getElement( page: Page, id: string ): Promise< any > {
	return page.evaluate( ( eid ) => {
		const state = ( window as any ).__ppcertHarness.getState();
		return state.layout.elements.find( ( el: any ) => el.id === eid );
	}, id );
}

// Patch element props through the store (panel-equivalent mutation).
function patchProps(
	page: Page,
	id: string,
	patch: Record< string, unknown >
): Promise< void > {
	return page.evaluate(
		( args ) => {
			const bridge = ( window as any ).__ppcertHarness;
			const layout = bridge.getState().layout;
			bridge.dispatch( {
				type: 'APPLY_LAYOUT',
				layout: {
					...layout,
					elements: layout.elements.map( ( el: any ) =>
						el.id === args.id
							? { ...el, props: { ...el.props, ...args.patch } }
							: el
					),
				},
			} );
		},
		{ id, patch }
	);
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

test.describe( 'element properties', () => {
	test( 'palette adds a text element with registry defaults; undo removes it', async ( {
		page,
	} ) => {
		await boot( page );

		await page.click( '[data-ppcert-palette="text"]' );

		const state = await getState( page );
		expect( state.layout.elements.length ).toBe( ELEMENT_COUNT + 1 );

		const added = state.layout.elements[ ELEMENT_COUNT ];
		expect( added.type ).toBe( 'text' );
		expect( added.id ).toMatch( /^el_[a-z0-9]{8}$/ );
		expect( added.z ).toBe( ELEMENT_COUNT + 1 );
		expect( added.props.font_family ).toBe( 'source-sans-3' );
		expect( state.selection ).toEqual( [ added.id ] );

		// The canvas renders it with the default content.
		await expect(
			page.locator( `[data-ppcert-el="${ added.id }"]` )
		).toHaveText( 'Your text' );

		await page.evaluate( () =>
			( window as any ).__ppcertHarness.dispatch( { type: 'UNDO' } )
		);
		expect( ( await getState( page ) ).layout.elements.length ).toBe(
			ELEMENT_COUNT
		);
	} );

	test( 'X input moves the element; QR width edit stays square', async ( {
		page,
	} ) => {
		await boot( page );

		await selectElement( page, TITLE_ID );
		const xInput = page.locator( 'input[data-ppcert-prop="box-x"]' );
		await xInput.fill( '300' );
		await xInput.press( 'Enter' );

		expect( ( await getElement( page, TITLE_ID ) ).x ).toBe( 300 );

		// Canvas reflects the stored position (zoom 1: px == pt offset).
		const pageBox = await page
			.locator( '.ppcert-designer__page' )
			.boundingBox();
		const titleBox = await page
			.locator( `[data-ppcert-el="${ TITLE_ID }"]` )
			.boundingBox();
		expect( titleBox!.x - pageBox!.x ).toBeCloseTo( 300, 0 );

		await selectElement( page, QR_ID );
		const wInput = page.locator( 'input[data-ppcert-prop="box-w"]' );
		await wInput.fill( '90' );
		await wInput.press( 'Enter' );

		const qr = await getElement( page, QR_ID );
		expect( qr.w ).toBe( 90 );
		expect( qr.h ).toBe( 90 );
	} );

	test( 'font size and align edits reflect on the canvas', async ( {
		page,
	} ) => {
		await boot( page );

		await selectElement( page, TITLE_ID );

		const sizeInput = page.locator( 'input[data-ppcert-prop="font_size"]' );
		await sizeInput.fill( '48' );
		await sizeInput.press( 'Enter' );

		const rendered = page.locator(
			`[data-ppcert-el="${ TITLE_ID }"] > div`
		);
		await expect( rendered ).toHaveCSS( 'font-size', '48px' );

		await page
			.locator( '[data-ppcert-prop="align"] .ant-segmented-item' )
			.last()
			.click();
		await expect( rendered ).toHaveCSS( 'text-align', 'right' );
		expect( ( await getElement( page, TITLE_ID ) ).props.align ).toBe(
			'right'
		);
	} );

	test( 'variant gating: switching to a family lacking italic resets and disables it', async ( {
		page,
	} ) => {
		await boot( page );

		await selectElement( page, TITLE_ID );

		// Turn italic on while the family (playfair) bundles it.
		const italicButton = page.locator( '[data-ppcert-prop="italic"]' );
		await expect( italicButton ).toBeEnabled();
		await italicButton.click();
		expect( ( await getElement( page, TITLE_ID ) ).props.italic ).toBe(
			true
		);

		// Switch to the fixture family that lacks italic.
		await page.click( '[data-ppcert-prop="font_family"]' );
		await page
			.locator( '.ant-select-item-option', {
				hasText: 'Test NoItalic',
			} )
			.click();

		const props = ( await getElement( page, TITLE_ID ) ).props;
		expect( props.font_family ).toBe( 'test-noitalic' );
		expect( props.italic ).toBe( false );
		await expect( italicButton ).toBeDisabled();

		// The canvas never falls back to synthetic italic.
		await expect(
			page.locator( `[data-ppcert-el="${ TITLE_ID }"] > div` )
		).toHaveCSS( 'font-style', 'normal' );
	} );

	test( 'image element renders the resolved attachment and honors fit', async ( {
		page,
	} ) => {
		await boot( page );

		await page.evaluate( ( url ) => {
			( window as any ).__ppcertHarness.seedAttachment( 123, url );
		}, PNG_1PX );

		await page.click( '[data-ppcert-palette="image"]' );
		const added = ( await getState( page ) ).layout.elements.slice(
			-1
		)[ 0 ];

		await patchProps( page, added.id, { attachment_id: 123 } );

		const img = page.locator( `[data-ppcert-el="${ added.id }"] img` );
		await expect( img ).toHaveAttribute( 'src', PNG_1PX );
		await expect( img ).toHaveCSS( 'object-fit', 'contain' );

		// Fit select drives object-fit.
		await page.click( '[data-ppcert-prop="fit"]' );
		await page
			.locator( '.ant-select-item-option', { hasText: 'Cover' } )
			.click();
		await expect( img ).toHaveCSS( 'object-fit', 'cover' );
	} );

	test( 'shape type switch renders an ellipse', async ( { page } ) => {
		await boot( page );

		await page.click( '[data-ppcert-palette="shape"]' );
		const added = ( await getState( page ) ).layout.elements.slice(
			-1
		)[ 0 ];
		expect( added.type ).toBe( 'shape' );

		await page.click( '[data-ppcert-prop="shape"]' );
		await page
			.locator( '.ant-select-item-option', { hasText: 'Ellipse' } )
			.click();

		await expect(
			page.locator( `[data-ppcert-el="${ added.id }"] > div` )
		).toHaveCSS( 'border-radius', '50%' );
	} );

	test( 'background palette entry routes to the page section; color edit paints the page', async ( {
		page,
	} ) => {
		await boot( page );

		// Select something first so the panel starts element-scoped.
		await selectElement( page, TITLE_ID );
		await page.click( '[data-ppcert-palette="background"]' );

		expect( ( await getState( page ) ).selection ).toEqual( [] );
		await expect(
			page.locator( '[data-ppcert-prop="background-pick"]' )
		).toBeVisible();

		await page.evaluate( () => {
			const bridge = ( window as any ).__ppcertHarness;
			const layout = bridge.getState().layout;
			bridge.dispatch( {
				type: 'APPLY_LAYOUT',
				layout: {
					...layout,
					background: { ...layout.background, color: '#ff0000' },
				},
			} );
		} );

		await expect( page.locator( '.ppcert-designer__page' ) ).toHaveCSS(
			'background-color',
			'rgb(255, 0, 0)'
		);
	} );

	test( 'edited layout survives a state-level save/load round-trip', async ( {
		page,
	} ) => {
		await boot( page );

		// A spread of edits: box, text props, QR colors.
		await selectElement( page, TITLE_ID );
		const xInput = page.locator( 'input[data-ppcert-prop="box-x"]' );
		await xInput.fill( '250' );
		await xInput.press( 'Enter' );
		await patchProps( page, TITLE_ID, { font_size: 30, color: '#123456' } );
		await patchProps( page, QR_ID, { dark_color: '#222222' } );

		const before = ( await getState( page ) ).layout;

		// Save/load is JSON serialization + LOAD_TEMPLATE (3.6 adds REST).
		await page.evaluate( () => {
			const bridge = ( window as any ).__ppcertHarness;
			const serialized = JSON.parse(
				JSON.stringify( bridge.getState().layout )
			);
			bridge.dispatch( {
				type: 'LOAD_TEMPLATE',
				template: { id: 0, title: 'Harness', status: 'draft' },
				layout: serialized,
			} );
		} );

		const after = ( await getState( page ) ).layout;
		expect( after ).toEqual( before );

		// And the canvas still renders the edited values.
		await expect(
			page.locator( `[data-ppcert-el="${ TITLE_ID }"] > div` )
		).toHaveCSS( 'font-size', '30px' );
	} );
} );
