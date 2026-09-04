/**
 * Designer extension bridge (2.0, Feature 2.0-006 addon contract).
 *
 * The JS surface addon designer bundles build on:
 * window.ppcert_designer_api (layout in/out through the standard
 * mutation path, selection, subscriptions, boot data), and the
 * extension rail slot that stays invisible until an addon mounts
 * into it.
 */
import { test, expect, type Page } from '@playwright/test';
import * as path from 'path';

const HARNESS_URL =
	'file://' + path.resolve( __dirname, 'harness', 'index-app.html' );

import { ELEMENT_COUNT } from './starter-fixture';

async function boot( page: Page ): Promise< void > {
	await page.goto( HARNESS_URL );
	await page.waitForSelector( '[data-ppcert-canvas-scale]' );
}

test.describe( 'extension bridge', () => {
	test( 'the API publishes with the documented surface', async ( {
		page,
	} ) => {
		await boot( page );

		const surface = await page.evaluate( () => {
			const api = ( window as any ).ppcert_designer_api;
			return api ? Object.keys( api ).sort() : null;
		} );

		expect( surface ).toEqual(
			[
				'applyLayout',
				'getBoot',
				'getLayout',
				'getSelection',
				'getTemplate',
				'isDirty',
				'replaceLayout',
				'setSelection',
				'subscribe',
			].sort()
		);
	} );

	test( 'applyLayout mutates through the standard path: canvas, dirty state, and subscribers all see it', async ( {
		page,
	} ) => {
		await boot( page );

		await page.evaluate( () => {
			const api = ( window as any ).ppcert_designer_api;
			( window as any ).__bridgeEvents = [];
			api.subscribe( ( change: any ) =>
				( window as any ).__bridgeEvents.push(
					change.layout.elements.length
				)
			);

			const layout = JSON.parse( JSON.stringify( api.getLayout() ) );
			layout.elements = layout.elements.slice( 0, 3 );
			api.applyLayout( layout );
		} );

		// Subscribers notify on React's effect flush - poll past it.
		await expect
			.poll( () =>
				page.evaluate(
					() => ( window as any ).__bridgeEvents as number[]
				)
			)
			.toContain( 3 );

		const result = await page.evaluate( () => {
			const api = ( window as any ).ppcert_designer_api;
			return {
				after: api.getLayout().elements.length,
				dirty: api.isDirty(),
			};
		} );

		expect( result.after ).toBe( 3 );
		expect( result.dirty ).toBe( true );

		// The canvas re-rendered from the same store.
		expect( await page.locator( '[data-ppcert-el]' ).count() ).toBe( 3 );
	} );

	test( 'setSelection drives the canvas selection', async ( { page } ) => {
		await boot( page );

		const selected = await page.evaluate( () => {
			const api = ( window as any ).ppcert_designer_api;
			const first = api.getLayout().elements[ 0 ].id;
			api.setSelection( [ first ] );
			return api.getSelection();
		} );

		expect( selected ).toHaveLength( 1 );
	} );

	test( 'the extension rail slot exists and stays invisible while empty', async ( {
		page,
	} ) => {
		await boot( page );

		const rail = page.locator( '#ppcert-designer-extension-rail' );
		await expect( rail ).toHaveCount( 1 );
		await expect( rail ).toBeHidden();

		// An addon mounting content makes it visible (the :empty rule).
		await page.evaluate( () => {
			document.getElementById(
				'ppcert-designer-extension-rail'
			)!.innerHTML = '<div>rail</div>';
		} );
		await expect( rail ).toBeVisible();

		// The starter still renders untouched alongside it.
		expect( await page.locator( '[data-ppcert-el]' ).count() ).toBe(
			ELEMENT_COUNT
		);
	} );

	test( 'replaceLayout swaps without history: undo cannot resurrect the old page', async ( {
		page,
	} ) => {
		await boot( page );

		const result = await page.evaluate( () => {
			const api = ( window as any ).ppcert_designer_api;

			// An edit first (history entry exists), then a page-style
			// replace, then undo: history was reset by the replace, so
			// undo has nothing to act on and the new page stays.
			const edited = JSON.parse( JSON.stringify( api.getLayout() ) );
			edited.elements = edited.elements.slice( 0, 4 );
			api.applyLayout( edited );

			const other = JSON.parse( JSON.stringify( api.getLayout() ) );
			other.elements = [];
			api.replaceLayout( other );

			const dirtyAfterReplace = api.isDirty();

			( window as any ).__ppcertHarness.dispatch( { type: 'UNDO' } );

			return {
				after: api.getLayout().elements.length,
				dirtyAfterReplace,
			};
		} );

		expect( result.after ).toBe( 0 );
		expect( result.dirtyAfterReplace ).toBe( true );

		// Explicit dirty override.
		await page.evaluate( () => {
			const api = ( window as any ).ppcert_designer_api;
			api.replaceLayout(
				JSON.parse( JSON.stringify( api.getLayout() ) ),
				false
			);
		} );
		expect(
			await page.evaluate( () =>
				( window as any ).ppcert_designer_api.isDirty()
			)
		).toBe( false );
	} );
} );
