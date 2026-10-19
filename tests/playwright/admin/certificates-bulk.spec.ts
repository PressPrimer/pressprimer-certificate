/**
 * Certificates screen bulk actions (2.1, Feature 2.1-002).
 *
 * The real assets/js/ppcert-admin.js on a harness page standing in for
 * the WordPress list screen (harness/fake-wp.js): selected rows with a
 * reason; select all matching, the typed DELETE, and the progress bar;
 * a run interrupted mid-job, then resumed from the notice; discard.
 */
import { test, expect, type Page } from '@playwright/test';
import * as path from 'path';

const HARNESS_URL =
	'file://' + path.resolve( __dirname, 'harness', 'certificates.html' );

async function open( page: Page, reset = true ): Promise< void > {
	await page.goto( HARNESS_URL + ( reset ? '?reset=1' : '' ) );
	await page.waitForSelector( '#the-list tr' );
}

async function harness( page: Page ): Promise< any > {
	return page.evaluate( () => ( window as any ).__ppcertHarness.state() );
}

const modal = ( page: Page ) => page.locator( '.ppcert-modal' );

test.describe( 'Certificates bulk actions', () => {
	test( 'selected rows: revoke with one reason', async ( { page } ) => {
		await open( page );

		const boxes = page.locator( 'input[name="certificate[]"]' );
		for ( const index of [ 0, 1, 2 ] ) {
			await boxes.nth( index ).check();
		}
		const ids = await boxes.evaluateAll( ( inputs ) =>
			inputs
				.filter( ( input: any ) => input.checked )
				.map( ( input: any ) => Number( input.value ) )
		);

		await page.selectOption(
			'#bulk-action-selector-top',
			'ppcert-bulk-revoke'
		);
		await page.click( '#doaction' );

		await expect( modal( page ) ).toContainText( 'Revoke 3 certificates?' );
		await expect( page.locator( '.ppcert-bulk-banner' ) ).toHaveCount( 0 );

		await page.fill( '#ppcert-modal-input', 'Course retired' );
		await modal( page ).getByRole( 'button', { name: 'Revoke' } ).click();

		await expect( modal( page ) ).toContainText(
			'3 certificates revoked.'
		);

		const state = await harness( page );
		expect( state.lastCreate ).toMatchObject( {
			action: 'revoke',
			ids,
			reason: 'Course retired',
		} );
		expect(
			state.certificates
				.filter( ( cert: any ) => 'revoked' === cert.status )
				.map( ( cert: any ) => cert.id )
				.sort()
		).toEqual( [ ...ids ].sort() );

		// OK reloads the list with the result.
		await modal( page ).getByRole( 'button', { name: 'OK' } ).click();
		await page.waitForSelector( '#the-list tr' );
		await expect( page.locator( '#the-list tr' ).first() ).toContainText(
			'Revoked Course retired'
		);
		expect(
			await page.evaluate(
				() => ( window as any ).__ppcertHarness.submitted
			)
		).toBeFalsy();
	} );

	test( 'all matching: banner, typed DELETE, and progress', async ( {
		page,
	} ) => {
		await open( page );

		await page.click( '#cb-select-all-1' );

		const banner = page.locator( '.ppcert-bulk-banner' );
		await expect( banner ).toContainText(
			'All 20 certificates on this page are selected.'
		);
		await expect( banner ).toHaveAttribute(
			'title',
			/Template: Safety Basics/
		);

		await banner
			.getByRole( 'button', {
				name: 'Select all 30 certificates that match these filters.',
			} )
			.click();
		await expect( banner ).toContainText(
			'All 30 certificates that match these filters are selected.'
		);

		// Clear selection, then select all matching again.
		await banner
			.getByRole( 'button', { name: 'Clear selection.' } )
			.click();
		await expect( banner ).toHaveCount( 0 );
		await expect(
			page.locator( 'input[name="certificate[]"]:checked' )
		).toHaveCount( 0 );

		await page.click( '#cb-select-all-1' );
		await banner.getByRole( 'button', { name: /Select all 30/ } ).click();

		// Unchecking one row drops back to a page selection.
		await page.locator( 'input[name="certificate[]"]' ).first().uncheck();
		await expect( banner ).toHaveCount( 0 );
		await page.locator( 'input[name="certificate[]"]' ).first().check();
		await expect( banner ).toContainText(
			'All 20 certificates on this page'
		);
		await banner.getByRole( 'button', { name: /Select all 30/ } ).click();

		// The bottom menu: core forwards to the top Apply.
		await page.selectOption(
			'#bulk-action-selector-bottom',
			'ppcert-bulk-delete'
		);
		await page.click( '#doaction2' );

		await expect( modal( page ) ).toContainText(
			'Permanently delete 30 certificates?'
		);
		await expect( modal( page ) ).toContainText(
			'Template: Safety Basics'
		);

		const confirm = modal( page ).getByRole( 'button', {
			name: 'Delete permanently',
		} );
		await expect( confirm ).toBeDisabled();
		await page.fill( '#ppcert-modal-typed', 'delete' );
		await expect( confirm ).toBeDisabled();
		await page.fill( '#ppcert-modal-typed', 'DELETE' );
		await expect( confirm ).toBeEnabled();

		await page.evaluate( () =>
			( window as any ).__ppcertHarness.set( { chunk: 4, delay: 150 } )
		);
		await confirm.click();

		// The progress state counts up.
		await expect( modal( page ) ).toContainText(
			/Deleting [1-9]\d* of 30…/
		);
		await expect(
			modal( page ).locator( '.ppcert-modal-progress' )
		).toHaveAttribute( 'aria-valuemax', '30' );
		await expect( modal( page ) ).toContainText(
			'Keep this page open until it finishes.'
		);

		await expect( modal( page ) ).toContainText(
			'30 certificates deleted.',
			{
				timeout: 15_000,
			}
		);

		const state = await harness( page );
		expect( state.lastCreate ).toMatchObject( {
			action: 'delete',
			filters: { template_id: 1 },
			confirm: 'DELETE',
		} );
		expect( state.lastCreate.ids ).toBeUndefined();
		expect( state.certificates ).toHaveLength( 15 );
		expect( state.lock ).toBe( '' );
	} );

	test( 'an interrupted job resumes from the notice', async ( { page } ) => {
		await open( page );
		await page.evaluate( () =>
			( window as any ).__ppcertHarness.set( {
				chunk: 5,
				failRunAfter: 1,
			} )
		);

		await page.click( '#cb-select-all-1' );
		await page
			.locator( '.ppcert-bulk-banner' )
			.getByRole( 'button', { name: /Select all 30/ } )
			.click();
		await page.selectOption(
			'#bulk-action-selector-top',
			'ppcert-bulk-revoke'
		);
		await page.click( '#doaction' );
		await modal( page ).getByRole( 'button', { name: 'Revoke' } ).click();

		// The second run fails, as if the page had closed.
		await expect( modal( page ) ).toContainText(
			'You are probably offline.'
		);
		await modal( page ).getByRole( 'button', { name: 'OK' } ).click();
		await page.waitForSelector( '#the-list tr' );

		const notice = page.locator( '.ppcert-bulk-resume-notice' );
		await expect( notice ).toContainText(
			'A bulk revoke stopped at 5 of 30 certificates.'
		);

		await page.evaluate( () =>
			( window as any ).__ppcertHarness.set( { failRunAfter: 0 } )
		);
		await notice.getByRole( 'button', { name: 'Resume' } ).click();

		await expect( modal( page ) ).toContainText(
			'30 certificates revoked.',
			{
				timeout: 15_000,
			}
		);

		const state = await harness( page );
		expect(
			state.certificates.filter(
				( cert: any ) => 'revoked' === cert.status
			)
		).toHaveLength( 30 );

		await modal( page ).getByRole( 'button', { name: 'OK' } ).click();
		await page.waitForSelector( '#the-list tr' );
		await expect(
			page.locator( '.ppcert-bulk-resume-notice' )
		).toHaveCount( 0 );
	} );

	test( 'discard from the notice leaves the rest alone', async ( {
		page,
	} ) => {
		await open( page );
		await page.evaluate( async () => {
			await ( window as any ).wp.apiFetch( {
				path: '/ppcert/v1/certificates/bulk',
				method: 'POST',
				data: { action: 'revoke', ids: [ 1030, 1029 ] },
			} );
		} );
		await open( page, false );

		const notice = page.locator( '.ppcert-bulk-resume-notice' );
		await expect( notice ).toContainText(
			'A bulk revoke stopped at 0 of 2'
		);

		await notice.getByRole( 'button', { name: 'Discard' } ).click();
		await expect( modal( page ) ).toContainText(
			'Discard this bulk action?'
		);
		await modal( page ).getByRole( 'button', { name: 'Discard' } ).click();
		await expect( notice ).toHaveCount( 0 );

		const state = await harness( page );
		expect( Object.values( state.jobs )[ 0 ] ).toMatchObject( {
			status: 'discarded',
		} );
		expect( state.lock ).toBe( '' );
		expect(
			state.certificates.filter(
				( cert: any ) => 'revoked' === cert.status
			)
		).toHaveLength( 0 );
	} );
} );
