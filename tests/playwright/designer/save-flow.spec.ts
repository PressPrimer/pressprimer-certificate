/**
 * Save / publish / preview client flow (Feature 001 FR-007, Prompt 3.6).
 *
 * Drives the full DesignerApp harness (index-app.html) with a mocked
 * transport persisting to localStorage: edit -> save -> hard reload ->
 * identical layout; the client adopts the server's response verbatim
 * (hostile markers stripped - the real validator rebuild is asserted in
 * PHPUnit); conflict flow; preview popup; unsaved-changes guard.
 */
import { test, expect, type Page } from '@playwright/test';
import * as path from 'path';

const HARNESS_URL =
	'file://' + path.resolve( __dirname, 'harness', 'index-app.html' );

const TITLE_ID = 'el_frmtitle';

async function boot( page: Page ): Promise< void > {
	await page.goto( HARNESS_URL );
	await page.evaluate( () =>
		window.localStorage.removeItem( 'ppcert_harness_template' )
	);
	await page.reload();
	await page.waitForSelector( '[data-ppcert-canvas-scale]' );
}

function getElement( page: Page, id: string ): Promise< any > {
	return page.evaluate( ( eid ) => {
		const state = ( window as any ).__ppcertHarness.getState();
		return state.layout.elements.find( ( el: any ) => el.id === eid );
	}, id );
}

function isDirty( page: Page ): Promise< boolean > {
	return page.evaluate(
		() => ( window as any ).__ppcertHarness.getState().dirty
	);
}

async function selectAndNudge( page: Page, id: string ): Promise< void > {
	const box = await page
		.locator( `[data-ppcert-el="${ id }"]` )
		.boundingBox();
	await page.mouse.click(
		Math.round( box!.x + box!.width / 2 ),
		Math.round( box!.y + box!.height / 2 )
	);
	await page.keyboard.press( 'ArrowRight' );
}

test.describe( 'save flow', () => {
	test( 'edit, save, hard reload: identical layout; dirty lifecycle', async ( {
		page,
	} ) => {
		await boot( page );

		const saveButton = page.locator( '[data-ppcert-action="save"]' );
		await expect( saveButton ).toBeDisabled();

		await selectAndNudge( page, TITLE_ID );
		expect( await isDirty( page ) ).toBe( true );
		await expect( saveButton ).toBeEnabled();

		const editedX = ( await getElement( page, TITLE_ID ) ).x;

		await saveButton.click();
		await expect( page.getByText( 'Template saved.' ) ).toBeVisible();
		expect( await isDirty( page ) ).toBe( false );
		await expect( saveButton ).toBeDisabled();

		// Hard reload: the layout comes back exactly as saved.
		await page.reload();
		await page.waitForSelector( '[data-ppcert-canvas-scale]' );

		expect( ( await getElement( page, TITLE_ID ) ).x ).toBe( editedX );
	} );

	test( 'client adopts the server response verbatim (markers stripped)', async ( {
		page,
	} ) => {
		await boot( page );

		// Inject hostile markers the way a devtools user would.
		await page.evaluate( () => {
			const bridge = ( window as any ).__ppcertHarness;
			const layout = bridge.getState().layout;
			bridge.dispatch( {
				type: 'APPLY_LAYOUT',
				layout: {
					...layout,
					hostile_root: 'nope',
					elements: layout.elements.map( ( el: any, i: number ) =>
						0 === i
							? {
									...el,
									onclick: 'evil()',
									props: {
										...el.props,
										injected: '<script>x</script>',
									},
							  }
							: el
					),
				},
			} );
		} );

		await page.locator( '[data-ppcert-action="save"]' ).click();
		await expect( page.getByText( 'Template saved.' ) ).toBeVisible();

		const layout = await page.evaluate(
			() => ( window as any ).__ppcertHarness.getState().layout
		);

		expect( layout.hostile_root ).toBeUndefined();
		expect( layout.elements[ 0 ].onclick ).toBeUndefined();
		expect( layout.elements[ 0 ].props.injected ).toBeUndefined();
	} );

	test( 'conflict shows the warning with Save anyway', async ( { page } ) => {
		await boot( page );

		await selectAndNudge( page, TITLE_ID );

		// Another window saved meanwhile: stored updated_at moves ahead.
		await page.evaluate( () => {
			const raw = window.localStorage.getItem(
				'ppcert_harness_template'
			);
			const stored = raw
				? JSON.parse( raw )
				: { updated_at: '2026-07-01T00:00:00Z' };
			const bridge = ( window as any ).__ppcertHarness;
			const template = {
				...( stored.layout ? stored : bridge.getState().template ),
				updated_at: '2026-07-20T09:00:00Z',
			};
			window.localStorage.setItem(
				'ppcert_harness_template',
				JSON.stringify( {
					...template,
					layout: template.layout || bridge.getState().layout,
				} )
			);
		} );

		await page.locator( '[data-ppcert-action="save"]' ).click();

		await expect( page.getByText( 'Changed elsewhere' ) ).toBeVisible();
		expect( await isDirty( page ) ).toBe( true );

		await page.getByRole( 'button', { name: 'Save anyway' } ).click();
		await expect( page.getByText( 'Template saved.' ) ).toBeVisible();
		expect( await isDirty( page ) ).toBe( false );
	} );

	test( 'publish transitions status and updates the toolbar', async ( {
		page,
	} ) => {
		await boot( page );

		await page.locator( '[data-ppcert-action="publish"]' ).click();
		await expect( page.getByText( 'Template published.' ) ).toBeVisible();

		await expect(
			page.locator( '[data-ppcert-action="unpublish"]' )
		).toBeVisible();

		const status = await page.evaluate(
			() => ( window as any ).__ppcertHarness.getState().template.status
		);
		expect( status ).toBe( 'published' );
	} );

	test( 'preview opens a tab pointed at the rendered PDF URL', async ( {
		page,
	} ) => {
		await boot( page );

		const popupPromise = page.waitForEvent( 'popup' );
		await page.locator( '[data-ppcert-action="preview"]' ).click();
		const popup = await popupPromise;

		await popup.waitForURL( /#ppcert-preview/ );
	} );

	test( 'unsaved changes arm the navigation guard; saving disarms it', async ( {
		page,
	} ) => {
		await boot( page );

		const guardArmed = () =>
			page.evaluate( () => {
				const event = new Event( 'beforeunload', {
					cancelable: true,
				} );
				window.dispatchEvent( event );
				return event.defaultPrevented;
			} );

		expect( await guardArmed() ).toBe( false );

		await selectAndNudge( page, TITLE_ID );
		expect( await guardArmed() ).toBe( true );

		await page.locator( '[data-ppcert-action="save"]' ).click();
		await expect( page.getByText( 'Template saved.' ) ).toBeVisible();
		expect( await guardArmed() ).toBe( false );
	} );
} );
