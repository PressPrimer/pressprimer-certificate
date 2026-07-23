/**
 * Trigger panel (Feature 001 FR-006, Feature 004 TR-002, Prompt 3.7).
 *
 * Drives the Award tab in the full app harness against double-adapter
 * fixtures: add/edit/deactivate flows, the schema-generated conditions
 * form (number min/max), staged-edit save through the toolbar, and the
 * orphaned-source warning badge.
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
	await page.getByRole( 'tab', { name: 'Award' } ).click();
	await page.waitForSelector( '[data-ppcert-triggers]' );
}

function getTriggersState( page: Page ): Promise< any[] > {
	return page.evaluate(
		() => ( window as any ).__ppcertHarness.getState().triggers
	);
}

async function addDoubleTrigger( page: Page ): Promise< void > {
	await page.click( '[data-ppcert-trigger-add]' );
	await page.click( '[data-ppcert-trigger-integration]' );
	await page
		.locator( '.ant-select-item-option', { hasText: 'Double LMS' } )
		.click();
	await page.click( '[data-ppcert-trigger-type]' );
	await page
		.locator( '.ant-select-item-option', { hasText: 'Course completed' } )
		.click();

	// Source search narrows via the adapter's picker.
	await page.click( '[data-ppcert-trigger-source]' );
	await page.keyboard.type( 'botany' );
	await page
		.locator( '.ant-select-item-option', { hasText: 'Advanced Botany' } )
		.click();
}

test.describe( 'trigger panel', () => {
	test( 'add flow: schema form renders, staged edit saves the exact payload', async ( {
		page,
	} ) => {
		await boot( page );

		await addDoubleTrigger( page );

		// The generated number field carries the schema's bounds.
		const minScore = page.locator(
			'input[data-ppcert-condition="min_score"]'
		);
		await expect( minScore ).toHaveAttribute( 'aria-valuemin', '0' );
		await expect( minScore ).toHaveAttribute( 'aria-valuemax', '100' );

		await minScore.fill( '80' );

		await page.click( '[data-ppcert-trigger-submit]' );

		// Row staged: dirty flag + pending-save notice + Save enabled.
		await expect(
			page.locator( '[data-ppcert-trigger-row="0"]' )
		).toContainText( 'Advanced Botany' );
		expect(
			await page.evaluate(
				() => ( window as any ).__ppcertHarness.getState().triggersDirty
			)
		).toBe( true );

		await page.locator( '[data-ppcert-action="save"]' ).click();
		await expect( page.getByText( 'Template saved.' ) ).toBeVisible();

		// The PUT payload matches the schema exactly.
		const sent = await page.evaluate(
			() => ( window as any ).__ppcertLastTriggersPut
		);
		expect( sent ).toHaveLength( 1 );
		expect( sent[ 0 ].trigger_type ).toBe( 'double_lms' );
		expect( sent[ 0 ].source_ref ).toBe( '102' );
		expect( sent[ 0 ].is_active ).toBe( true );
		expect( sent[ 0 ].conditions ).toEqual( {
			min_score: 80,
			notify: false,
			mode: 'full',
			note: '',
		} );

		// Adopted server truth: no longer dirty.
		expect(
			await page.evaluate(
				() => ( window as any ).__ppcertHarness.getState().triggersDirty
			)
		).toBe( false );

		// Survives a hard reload (GET round-trip).
		await page.reload();
		await page.waitForSelector( '[data-ppcert-canvas-scale]' );
		await page.getByRole( 'tab', { name: 'Award' } ).click();
		await expect(
			page.locator( '[data-ppcert-trigger-row="0"]' )
		).toContainText( 'Advanced Botany' );
	} );

	test( 'edit updates conditions; the row toggle deactivates', async ( {
		page,
	} ) => {
		await boot( page );
		await addDoubleTrigger( page );
		await page.click( '[data-ppcert-trigger-submit]' );

		// Edit: switch the completion mode.
		await page.click( '[data-ppcert-trigger-edit="0"]' );
		await page.click( '[data-ppcert-condition="mode"]' );
		await page
			.locator( '.ant-select-item-option', {
				hasText: 'lessons_only',
			} )
			.click();
		await page.click( '[data-ppcert-trigger-submit]' );

		let triggers = await getTriggersState( page );
		expect( triggers[ 0 ].conditions.mode ).toBe( 'lessons_only' );
		expect( triggers[ 0 ].is_active ).toBe( true );

		// Deactivate from the row.
		await page.click( '[data-ppcert-trigger-toggle="0"]' );

		triggers = await getTriggersState( page );
		expect( triggers[ 0 ].is_active ).toBe( false );

		// Save persists the deactivation.
		await page.locator( '[data-ppcert-action="save"]' ).click();
		await expect( page.getByText( 'Template saved.' ) ).toBeVisible();

		const sent = await page.evaluate(
			() => ( window as any ).__ppcertLastTriggersPut
		);
		expect( sent[ 0 ].is_active ).toBe( false );
	} );

	test( 'orphaned source shows the warning badge', async ( { page } ) => {
		await page.goto( HARNESS_URL );
		await page.evaluate( () => {
			window.localStorage.removeItem( 'ppcert_harness_template' );
			window.localStorage.setItem(
				'ppcert_harness_triggers',
				JSON.stringify( [
					{
						trigger_type: 'double_lms',
						type_label: 'Double LMS',
						type_available: true,
						source_ref: '999',
						source_label: '',
						source_found: false,
						conditions: {},
						is_active: true,
					},
				] )
			);
		} );
		await page.reload();
		await page.waitForSelector( '[data-ppcert-canvas-scale]' );
		await page.getByRole( 'tab', { name: 'Award' } ).click();

		await expect(
			page.locator( '[data-ppcert-trigger-warning]' )
		).toBeVisible();
	} );

	test( 'remove empties the list back to the empty state', async ( {
		page,
	} ) => {
		await boot( page );
		await addDoubleTrigger( page );
		await page.click( '[data-ppcert-trigger-submit]' );

		await page.click( '[data-ppcert-trigger-remove="0"]' );

		expect( await getTriggersState( page ) ).toHaveLength( 0 );
		await expect( page.getByText( 'No trigger yet.' ) ).toBeVisible();
	} );

	test( 'hierarchical types cascade: course first, then its lessons', async ( {
		page,
	} ) => {
		await boot( page );

		await page.click( '[data-ppcert-trigger-add]' );
		await page.click( '[data-ppcert-trigger-integration]' );
		await page
			.locator( '.ant-select-item-option', { hasText: 'Double LMS' } )
			.click();
		await page.click( '[data-ppcert-trigger-type]' );
		await page
			.locator( '.ant-select-item-option', {
				hasText: 'Lesson completed',
			} )
			.click();

		// The source select waits for the cascade (the data attribute
		// lands on antd's wrapper div, so assert the disabled class).
		await expect(
			page.locator( '[data-ppcert-trigger-source]' )
		).toHaveClass( /ant-select-disabled/ );

		// Course level: pick Botany 101.
		await page.click( '[data-ppcert-trigger-level="course"]' );
		await page
			.locator( '.ant-select-item-option', { hasText: 'Botany 101' } )
			.click();

		// Now the source select offers only that course's lessons.
		await page.click( '[data-ppcert-trigger-source]' );
		await expect(
			page.locator( '.ant-select-item-option', { hasText: 'Brushes' } )
		).toHaveCount( 0 );
		await page
			.locator( '.ant-select-item-option', { hasText: 'Leaves' } )
			.click();

		await page.click( '[data-ppcert-trigger-submit]' );

		const card = page.locator( '[data-ppcert-trigger-row="0"]' );
		await expect( card ).toContainText( 'Lesson completed (Double LMS)' );
		await expect( card ).toContainText( 'Leaves' );

		// The staged payload stores the final source only.
		await page.locator( '[data-ppcert-action="save"]' ).click();
		await expect( page.getByText( 'Template saved.' ) ).toBeVisible();

		const sent = await page.evaluate(
			() => ( window as any ).__ppcertLastTriggersPut
		);
		expect( sent[ 0 ].trigger_type ).toBe( 'double_lms_lesson' );
		expect( sent[ 0 ].source_ref ).toBe( '202' );
	} );

	test( 'the disabled Add button explains the single-trigger rule', async ( {
		page,
	} ) => {
		await boot( page );
		await addDoubleTrigger( page );
		await page.click( '[data-ppcert-trigger-submit]' );

		await expect(
			page.locator( '[data-ppcert-trigger-add]' )
		).toBeDisabled();

		await page.hover( '[data-ppcert-trigger-add]' );
		await expect(
			page.getByText(
				'Only one trigger can be added per certificate. To use another trigger, clone the certificate.'
			)
		).toBeVisible();
	} );
} );
