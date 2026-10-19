/**
 * A3 and Legal in the Page panel (2.1, Feature 2.1-003 FR-001, FR-002).
 *
 * The Size menu lists the registry sizes in menu order; a jump to A3
 * holds scaled font sizes, stroke widths, and corner radii to the
 * validator's maximums (what the canvas shows is what saves); a Legal
 * switch and turn are each one undo step.
 */
import { test, expect, type Page } from '@playwright/test';
import * as path from 'path';

const CANVAS_URL =
	'file://' + path.resolve( __dirname, 'harness', 'index.html' );

async function boot( page: Page ): Promise< void > {
	await page.goto( CANVAS_URL );
	await page.waitForSelector( '[data-ppcert-canvas-scale]' );
}

async function layoutState( page: Page ): Promise< any > {
	return page.evaluate( () =>
		JSON.parse(
			JSON.stringify(
				( window as any ).__ppcertHarness.getState().layout
			)
		)
	);
}

async function chooseSize( page: Page, label: string ): Promise< void > {
	await page.click( '[data-ppcert-prop="page-size"]' );
	await page
		.locator( '.ant-select-item-option', { hasText: label } )
		.first()
		.click();
}

test.describe( 'A3 and Legal', () => {
	test( 'the Size menu lists Letter, Legal, A4, A3 in order', async ( {
		page,
	} ) => {
		await boot( page );

		await page.click( '[data-ppcert-prop="page-size"]' );
		const labels = await page
			.locator( '.ant-select-item-option' )
			.allInnerTexts();

		expect( labels.map( ( label ) => label.trim() ) ).toEqual( [
			'Letter',
			'Legal',
			'A4',
			'A3',
		] );
	} );

	test( 'A3 scale-up stops at the validator maximums and is one undo step', async ( {
		page,
	} ) => {
		await boot( page );

		// A 160 pt title, an 18 pt stroke, and a 90 pt radius: A4 to A3
		// scales by 1191 / 842, past every maximum.
		await page.evaluate( () => {
			const harness = ( window as any ).__ppcertHarness;
			const layout = JSON.parse(
				JSON.stringify( harness.getState().layout )
			);

			for ( const element of layout.elements ) {
				if ( 'el_frmtitle' === element.id ) {
					element.props.font_size = 160;
				}

				if ( 'el_frminner' === element.id ) {
					element.props.stroke_width = 18;
					element.props.radius = 90;
				}
			}

			harness.dispatch( { type: 'APPLY_LAYOUT', layout } );
		} );

		const before = await layoutState( page );

		await chooseSize( page, 'A3' );

		await expect(
			page.getByText( 'Design scaled to fit the new page.', {
				exact: false,
			} )
		).toBeVisible();

		const after = await layoutState( page );
		const title = after.elements.find(
			( el: any ) => 'el_frmtitle' === el.id
		);
		const inner = after.elements.find(
			( el: any ) => 'el_frminner' === el.id
		);

		expect( after.page ).toMatchObject( {
			size: 'a3',
			orientation: 'landscape',
			width: 1191,
			height: 842,
		} );
		expect( title.props.font_size ).toBe( 200 );
		expect( inner.props.stroke_width ).toBe( 20 );
		expect( inner.props.radius ).toBe( 100 );

		// Untouched values still scale.
		const subtitle = after.elements.find(
			( el: any ) =>
				'el_frmtitle' !== el.id &&
				'number' === typeof el.props.font_size
		);
		const subtitleBefore = before.elements.find(
			( el: any ) => subtitle.id === el.id
		);
		expect( subtitle.props.font_size ).toBeCloseTo(
			( subtitleBefore.props.font_size * 1191 ) / 842,
			0
		);

		await expect(
			page.locator( '[data-ppcert-prop="page-size"]' )
		).toContainText( 'A3' );
		await expect( page.getByText( '1191 × 842 pt' ) ).toBeVisible();

		// One undo restores the A4 design exactly.
		await page.evaluate( () =>
			( window as any ).__ppcertHarness.dispatch( { type: 'UNDO' } )
		);
		expect( await layoutState( page ) ).toEqual( before );
	} );

	test( 'Legal switch and turn, each one undo step', async ( { page } ) => {
		await boot( page );

		const original = await layoutState( page );

		await chooseSize( page, 'Legal' );

		let state = await layoutState( page );
		expect( state.page ).toMatchObject( {
			size: 'legal',
			orientation: 'landscape',
			width: 1008,
			height: 612,
		} );

		await page
			.locator(
				'[data-ppcert-prop="page-orientation"] .ant-segmented-item',
				{ hasText: 'Portrait' }
			)
			.click();

		state = await layoutState( page );
		expect( state.page ).toMatchObject( {
			size: 'legal',
			orientation: 'portrait',
			width: 612,
			height: 1008,
		} );
		await expect(
			page.locator( '[data-ppcert-prop="page-size"]' )
		).toContainText( 'Legal' );
		await expect( page.getByText( '612 × 1008 pt' ) ).toBeVisible();

		// Every element stays on the page.
		for ( const element of state.elements ) {
			expect( element.x ).toBeGreaterThanOrEqual( -612 );
			expect( element.x + element.w ).toBeLessThanOrEqual( 2 * 612 );
		}

		await page.evaluate( () =>
			( window as any ).__ppcertHarness.dispatch( { type: 'UNDO' } )
		);
		expect( ( await layoutState( page ) ).page ).toMatchObject( {
			size: 'legal',
			orientation: 'landscape',
		} );

		await page.evaluate( () =>
			( window as any ).__ppcertHarness.dispatch( { type: 'UNDO' } )
		);
		expect( await layoutState( page ) ).toEqual( original );
	} );
} );
