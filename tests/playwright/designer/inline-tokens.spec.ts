/**
 * Inline merge tokens in text elements (Feature 1.1-001, schema v2).
 *
 * Canvas substitution semantics: known tokens render their sample,
 * unknown grammar-matching tokens render empty (never syntax),
 * non-grammar brace runs stay literal, and the token toggle shows the
 * raw content. Pixel-level parity is covered by the parity suite.
 */
import { test, expect, type Page } from '@playwright/test';
import * as path from 'path';

const HARNESS_URL =
	'file://' + path.resolve( __dirname, 'harness', 'index.html' );

const TEXT_PROPS = {
	font_family: 'eb-garamond',
	font_size: 18,
	color: '#1f2a44',
	align: 'center',
	line_height: 1.3,
	bold: false,
	italic: false,
};

const LAYOUT = {
	layout_schema_version: 2,
	page: { size: 'a4', orientation: 'landscape', width: 842, height: 595 },
	background: { color: '#ffffff', attachment_id: 0 },
	elements: [
		{
			id: 'el_inlknown',
			type: 'text',
			x: 121,
			y: 120,
			w: 600,
			h: 30,
			z: 1,
			props: {
				...TEXT_PROPS,
				content:
					'ID {{certificate.credential_id}} for {{recipient.full_name}}',
			},
		},
		{
			id: 'el_inlunkwn',
			type: 'text',
			x: 121,
			y: 200,
			w: 600,
			h: 30,
			z: 2,
			props: {
				...TEXT_PROPS,
				content: 'Ref ({{source.nothing_here}}) end',
			},
		},
		{
			id: 'el_inllitrl',
			type: 'text',
			x: 121,
			y: 280,
			w: 600,
			h: 30,
			z: 3,
			props: {
				...TEXT_PROPS,
				content: '{{hello}} and {{Not.AToken}} stay literal',
			},
		},
	],
};

async function boot( page: Page ): Promise< void > {
	await page.goto( HARNESS_URL );
	await page.waitForSelector( '[data-ppcert-canvas-scale]' );

	await page.evaluate( ( layout: any ) => {
		( window as any ).__ppcertHarness.dispatch( {
			type: 'LOAD_TEMPLATE',
			template: { id: 0, title: 'Inline Tokens', status: 'draft' },
			layout,
		} );
	}, LAYOUT );
}

test.describe( 'inline merge tokens in text', () => {
	test( 'samples mode substitutes known tokens and empties unknown ones', async ( {
		page,
	} ) => {
		await boot( page );

		await expect(
			page.locator( '[data-ppcert-el="el_inlknown"]' )
		).toHaveText( 'ID 7Q4M-K9P2-XT3A for Jordan Rivera' );

		// Unknown grammar-matching token: empty, never leaked syntax.
		await expect(
			page.locator( '[data-ppcert-el="el_inlunkwn"]' )
		).toHaveText( 'Ref () end' );

		// Non-grammar brace runs are literal text.
		await expect(
			page.locator( '[data-ppcert-el="el_inllitrl"]' )
		).toHaveText( '{{hello}} and {{Not.AToken}} stay literal' );
	} );

	test( 'the token toggle shows raw content and flips back live', async ( {
		page,
	} ) => {
		await boot( page );

		await page.evaluate( () =>
			( window as any ).__ppcertHarness.setTokenView( true )
		);

		await expect(
			page.locator( '[data-ppcert-el="el_inlknown"]' )
		).toHaveText(
			'ID {{certificate.credential_id}} for {{recipient.full_name}}'
		);

		await page.evaluate( () =>
			( window as any ).__ppcertHarness.setTokenView( false )
		);

		await expect(
			page.locator( '[data-ppcert-el="el_inlknown"]' )
		).toHaveText( 'ID 7Q4M-K9P2-XT3A for Jordan Rivera' );
	} );

	test( 'the insert picker appends a token when the content box is untouched', async ( {
		page,
	} ) => {
		await boot( page );

		// Select the element whose content ends with a label.
		await page.click( '[data-ppcert-el="el_inlunkwn"]' );
		await page.waitForSelector( '[data-ppcert-prop="content"]' );

		// Straight to the picker - no caret was ever placed.
		await page.click( '[data-ppcert-prop="insert_merge_field"]' );
		await page.click(
			'[data-ppcert-insert-field="certificate.issue_date"]'
		);

		await expect(
			page.locator( '[data-ppcert-prop="content"]' )
		).toHaveValue(
			'Ref ({{source.nothing_here}}) end{{certificate.issue_date}}'
		);

		// The canvas substitutes immediately (harness sample values).
		await expect(
			page.locator( '[data-ppcert-el="el_inlunkwn"]' )
		).toHaveText( 'Ref () endJuly 22, 2026' );
	} );

	test( 'the insert picker drops the token at the user caret', async ( {
		page,
	} ) => {
		await boot( page );

		await page.click( '[data-ppcert-el="el_inlknown"]' );
		await page.waitForSelector( '[data-ppcert-prop="content"]' );

		// Place the caret three characters in ("ID " ... ). Home/arrow
		// keys are not portable across platforms in textareas, so the
		// caret is set directly and the same `select` event a real
		// user interaction fires drives the caret tracking.
		await page.click( '[data-ppcert-prop="content"]' );
		await page.evaluate( () => {
			const el = document.querySelector(
				'[data-ppcert-prop="content"]'
			) as HTMLTextAreaElement;

			el.setSelectionRange( 3, 3 );
			el.dispatchEvent( new Event( 'select', { bubbles: true } ) );
		} );

		await page.click( '[data-ppcert-prop="insert_merge_field"]' );
		await page.click( '[data-ppcert-insert-field="site.name"]' );

		await expect(
			page.locator( '[data-ppcert-prop="content"]' )
		).toHaveValue(
			'ID {{site.name}}{{certificate.credential_id}} for {{recipient.full_name}}'
		);

		await expect(
			page.locator( '[data-ppcert-el="el_inlknown"]' )
		).toHaveText( 'ID Acme Academy7Q4M-K9P2-XT3A for Jordan Rivera' );
	} );

	test( 'a loaded v1 document is migrated and interpolates after load', async ( {
		page,
	} ) => {
		await page.goto( HARNESS_URL );
		await page.waitForSelector( '[data-ppcert-canvas-scale]' );

		// LOAD_TEMPLATE migrates v1 to v2 in memory (stamp-only), so the
		// canvas treats every in-designer document as current-version.
		await page.evaluate( ( layout: any ) => {
			( window as any ).__ppcertHarness.dispatch( {
				type: 'LOAD_TEMPLATE',
				template: { id: 0, title: 'Migrated', status: 'draft' },
				layout: { ...layout, layout_schema_version: 1 },
			} );
		}, LAYOUT );

		const version = await page.evaluate(
			() =>
				( window as any ).__ppcertHarness.getState().layout
					.layout_schema_version
		);

		expect( version ).toBe( 2 );

		await expect(
			page.locator( '[data-ppcert-el="el_inlknown"]' )
		).toHaveText( 'ID 7Q4M-K9P2-XT3A for Jordan Rivera' );
	} );
} );
