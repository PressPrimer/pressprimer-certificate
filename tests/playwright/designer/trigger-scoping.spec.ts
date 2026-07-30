/**
 * Trigger-scoped merge fields and the 1.0 single-trigger scope
 * (Award tab review pass, 2026-07-22).
 *
 * The palette's source fields follow the template's staged trigger:
 * no trigger = no source fields plus an unlock hint; a staged trigger
 * scopes the registry to its integration and relabels the source group
 * with the integration's noun. The source-meta picker unlocks when the
 * trigger points at a post-backed source. Templates carry ONE trigger:
 * the Add button disables once a trigger exists.
 */
import { test, expect, type Page } from '@playwright/test';
import * as path from 'path';

const HARNESS_URL =
	'file://' + path.resolve( __dirname, 'harness', 'index-app.html' );

async function boot( page: Page ): Promise< void > {
	await page.goto( HARNESS_URL );
	await page.evaluate( () => {
		window.localStorage.removeItem( 'ppcert_harness_template' );
		window.localStorage.removeItem( 'ppcert_harness_triggers' );
	} );
	await page.reload();
	await page.waitForSelector( '[data-ppcert-canvas-scale]' );
}

async function stageDoubleTrigger(
	page: Page,
	minScore = ''
): Promise< void > {
	await page.getByRole( 'tab', { name: 'Award' } ).click();
	await page.waitForSelector( '[data-ppcert-triggers]' );
	await page.click( '[data-ppcert-trigger-add]' );
	await page.click( '[data-ppcert-trigger-integration]' );
	await page
		.locator( '.ant-select-item-option', { hasText: 'Double LMS' } )
		.click();
	await page.click( '[data-ppcert-trigger-type]' );
	await page
		.locator( '.ant-select-item-option', { hasText: 'Course completed' } )
		.click();
	await page.click( '[data-ppcert-trigger-source]' );
	await page.keyboard.type( 'botany' );
	await page
		.locator( '.ant-select-item-option', { hasText: 'Advanced Botany' } )
		.click();

	if ( '' !== minScore ) {
		await page
			.locator( 'input[data-ppcert-condition="min_score"]' )
			.fill( minScore );
	}

	await page.click( '[data-ppcert-trigger-submit]' );
}

async function openMergeMenu( page: Page ): Promise< void > {
	await page.click( '[data-ppcert-palette="merge_field"]' );
	await page.waitForSelector( '[data-ppcert-merge-menu]' );
}

function getLastElement( page: Page ): Promise< any > {
	return page.evaluate( () => {
		const state = ( window as any ).__ppcertHarness.getState();
		return state.layout.elements[ state.layout.elements.length - 1 ];
	} );
}

test.describe( 'trigger-scoped merge fields', () => {
	test( 'without a trigger: no source fields, and the hint explains how to unlock them', async ( {
		page,
	} ) => {
		await boot( page );
		await openMergeMenu( page );

		await expect(
			page.locator( '[data-ppcert-merge-field="source.course_title"]' )
		).toHaveCount( 0 );
		await expect(
			page.locator( '[data-ppcert-merge-source-hint]' )
		).toContainText( 'Add a trigger in the Award tab' );

		// Core fields are always offered.
		await expect(
			page.locator(
				'[data-ppcert-merge-field="certificate.credential_id"]'
			)
		).toBeVisible();

		// No post-backed source: the source-meta picker stays locked.
		await expect(
			page.locator( '[data-ppcert-merge-field="__source_meta"]' )
		).toBeDisabled();
	} );

	test( 'a staged trigger unlocks its integration fields under its own noun', async ( {
		page,
	} ) => {
		await boot( page );
		await stageDoubleTrigger( page );
		await openMergeMenu( page );

		// Source fields appear, grouped under the integration's noun.
		await expect(
			page.locator( '[data-ppcert-merge-field="source.course_title"]' )
		).toBeVisible();
		await expect(
			page.locator( '[data-ppcert-merge-menu]' )
		).toContainText( 'Course' );
		await expect(
			page.locator( '[data-ppcert-merge-source-hint]' )
		).toHaveCount( 0 );

		// Inserting one places the scoped token.
		await page.click( '[data-ppcert-merge-field="source.course_title"]' );
		const added = await getLastElement( page );
		expect( added.props.token ).toBe( '{{source.course_title}}' );

		// Removing the trigger takes the fields away again.
		await page.getByRole( 'tab', { name: 'Award' } ).click();
		await page.click( '[data-ppcert-trigger-remove="0"]' );
		await openMergeMenu( page );
		await expect(
			page.locator( '[data-ppcert-merge-field="source.course_title"]' )
		).toHaveCount( 0 );
	} );

	test( 'the source-meta picker unlocks with a post-backed trigger source', async ( {
		page,
	} ) => {
		await boot( page );
		await stageDoubleTrigger( page );
		await openMergeMenu( page );

		await page.click( '[data-ppcert-merge-field="__source_meta"]' );
		await page.waitForSelector( '[data-ppcert-meta-key="course_code"]' );
		await expect(
			page.locator( '[data-ppcert-meta-key="course_code"]' )
		).toContainText( 'BOT-301' );

		await page.click( '[data-ppcert-meta-key="course_code"]' );

		const added = await getLastElement( page );
		expect( added.props.token ).toBe( '{{source.meta.course_code}}' );
	} );
} );

test.describe( 'single trigger per template (1.0 scope)', () => {
	test( 'the Add button disables once a trigger exists', async ( {
		page,
	} ) => {
		await boot( page );
		await stageDoubleTrigger( page );

		await expect(
			page.locator( '[data-ppcert-trigger-add]' )
		).toBeDisabled();

		// Removing the trigger re-enables adding.
		await page.click( '[data-ppcert-trigger-remove="0"]' );
		await expect(
			page.locator( '[data-ppcert-trigger-add]' )
		).toBeEnabled();
	} );

	test( 'an inserted source field renders its SAMPLE on the canvas', async ( {
		page,
	} ) => {
		await boot( page );
		await stageDoubleTrigger( page );

		// Insert Course Title from the scoped merge menu (Ryan's
		// 2026-07-30 report: the canvas stayed on the raw token because
		// the registry cache handed the samples effect a stale scope).
		await openMergeMenu( page );
		await page
			.locator( '[data-ppcert-merge-field="source.course_title"]' )
			.click();

		const inserted = page
			.locator( '[data-ppcert-el]' )
			.filter( { hasText: /Advanced Botany|source\.course_title/ } )
			.first();

		// Samples mode is the default: the sample renders, not the token.
		await expect( inserted ).toContainText( 'Advanced Botany' );
		await expect( inserted ).not.toContainText( 'source.course_title' );

		// Tokens mode flips to the raw token and back.
		await page.getByText( 'Tokens', { exact: true } ).click();
		await expect( inserted ).toContainText( 'source.course_title' );
		await page.getByText( 'Samples', { exact: true } ).click();
		await expect( inserted ).toContainText( 'Advanced Botany' );
	} );

	test( 'the trigger card names the source and summarizes conditions', async ( {
		page,
	} ) => {
		await boot( page );
		await stageDoubleTrigger( page, '80' );

		const card = page.locator( '[data-ppcert-trigger-row="0"]' );
		await expect( card ).toContainText( 'Double LMS' );
		await expect( card ).toContainText( 'Course' );
		await expect( card ).toContainText( 'Advanced Botany' );
		await expect(
			page.locator( '[data-ppcert-trigger-conditions="0"]' )
		).toContainText( 'Minimum score (%): 80' );
	} );

	test( 'a blank condition shows no summary line, and the form explains why blank is fine', async ( {
		page,
	} ) => {
		await boot( page );
		await page.getByRole( 'tab', { name: 'Award' } ).click();
		await page.waitForSelector( '[data-ppcert-triggers]' );
		await page.click( '[data-ppcert-trigger-add]' );
		await page.click( '[data-ppcert-trigger-integration]' );
		await page
			.locator( '.ant-select-item-option', { hasText: 'Double LMS' } )
			.click();
		await page.click( '[data-ppcert-trigger-type]' );
		await page
			.locator( '.ant-select-item-option', {
				hasText: 'Course completed',
			} )
			.click();

		// The schema's help renders as a tooltip on the condition label.
		await expect(
			page.locator( '[data-ppcert-condition-help="min_score"]' )
		).toBeVisible();
		await page.hover( '[data-ppcert-condition-help="min_score"]' );
		await expect(
			page.getByText( 'Leave blank to award on any passing score.' )
		).toBeVisible();

		await page.click( '[data-ppcert-trigger-source]' );
		await page.keyboard.type( 'botany' );
		await page
			.locator( '.ant-select-item-option', {
				hasText: 'Advanced Botany',
			} )
			.click();
		await page.click( '[data-ppcert-trigger-submit]' );

		// Blank min_score (and default-off toggle / default select /
		// empty note): no conditions line at all.
		await expect(
			page.locator( '[data-ppcert-trigger-conditions="0"]' )
		).toHaveCount( 0 );
	} );
} );
