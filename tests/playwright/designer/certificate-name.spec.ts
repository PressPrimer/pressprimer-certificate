/**
 * Certificate name (Feature 1.1-006): the Award tab's name pattern
 * field with the shared Insert Merge Field button, and the Any nudge.
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
	await page.waitForSelector( '[data-ppcert-certificate-name]' );
}

function getSettings( page: Page ): Promise< any > {
	return page.evaluate(
		() => ( window as any ).__ppcertHarness.getState().template.settings
	);
}

async function stageAnyCourseTrigger( page: Page ): Promise< void > {
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
	await page
		.locator(
			'.ant-select-dropdown:not(.ant-select-dropdown-hidden) .ant-select-item-option'
		)
		.first()
		.click();
	await page.click( '[data-ppcert-trigger-submit]' );
}

test.describe( 'certificate name', () => {
	test( 'typing a pattern and inserting a field stages the setting', async ( {
		page,
	} ) => {
		await boot( page );

		await page.fill( '[data-ppcert-certificate-name]', 'Award ' );
		await page.click( '[data-ppcert-prop="insert_name_field"]' );
		await page.click(
			'[data-ppcert-insert-field="certificate.credential_id"]'
		);

		await expect(
			page.locator( '[data-ppcert-certificate-name]' )
		).toHaveValue( 'Award {{certificate.credential_id}}' );

		const settings = await getSettings( page );
		expect( settings.certificate_name ).toBe(
			'Award {{certificate.credential_id}}'
		);
	} );

	test( 'clearing the field drops the setting and keeps validity', async ( {
		page,
	} ) => {
		await boot( page );

		await page.fill( '[data-ppcert-certificate-name]', 'Named' );
		await page.locator( '[data-ppcert-certificate-name]' ).blur();
		expect( ( await getSettings( page ) ).certificate_name ).toBe(
			'Named'
		);

		await page.fill( '[data-ppcert-certificate-name]', '' );
		await page.locator( '[data-ppcert-certificate-name]' ).blur();
		expect( ( await getSettings( page ) ).certificate_name ).toBe(
			undefined
		);
	} );

	test( 'an Any trigger with no name offers a suggestion that applies', async ( {
		page,
	} ) => {
		await boot( page );

		await expect( page.locator( '[data-ppcert-name-nudge]' ) ).toHaveCount(
			0
		);

		await stageAnyCourseTrigger( page );

		const nudge = page.locator( '[data-ppcert-name-nudge]' );
		await expect( nudge ).toBeVisible();
		await expect( nudge ).toContainText(
			'{{source.course_title}} Certificate'
		);

		await page.click( '[data-ppcert-name-nudge-apply]' );

		await expect(
			page.locator( '[data-ppcert-certificate-name]' )
		).toHaveValue( '{{source.course_title}} Certificate' );
		expect( ( await getSettings( page ) ).certificate_name ).toBe(
			'{{source.course_title}} Certificate'
		);

		// Applied: the nudge is gone.
		await expect( nudge ).toHaveCount( 0 );
	} );
} );
