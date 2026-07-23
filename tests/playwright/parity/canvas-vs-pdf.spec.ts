/**
 * Canvas-vs-PDF parity (Feature 007 FR-005 - release blocker).
 *
 * Closes the loop opened in 2.5: for each provisional starter, the
 * designer canvas (real webfonts, sample data, the PHP encoder's QR
 * matrix, 100% zoom, chrome stripped) is screenshotted at 2x and
 * compared against render_png() at 144 DPI - the same physical scale.
 * Both sides also assert element bounding boxes against the declared
 * layout geometry within the 1 pt drift budget.
 */
import { test, expect, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import * as fs from 'node:fs';
import * as os from 'node:os';
import * as path from 'node:path';
import { findPhp, renderPng, PATHS } from '../helpers/render';
import {
	comparePngs,
	PIXEL_DIFF_MAX_RATIO,
	BOX_DRIFT_MAX_PT,
} from '../helpers/compare';
import { extractBoxes, boxDriftFailures } from '../helpers/boxes';

// 2x device pixels on the canvas == 144 DPI on the PDF raster.
const DPI = 144;
const SCALE = DPI / 72;

const CREDENTIAL = '7Q4MK9P2XT3A';

const HARNESS_URL =
	'file://' +
	path.resolve( __dirname, '..', 'designer', 'harness', 'index.html' );

const SAMPLES_PATH = path.join(
	PATHS.root,
	'tests',
	'playwright',
	'fixtures',
	'starter-samples.json'
);

const STARTERS = [
	'starter-formal-landscape',
	'starter-formal-portrait',
	'starter-modern-landscape',
	'starter-modern-portrait',
	'starter-playful-landscape',
	'starter-playful-portrait',
	'starter-formal-landscape-letter',
	'starter-formal-portrait-letter',
	'starter-modern-landscape-letter',
	'starter-modern-portrait-letter',
	'starter-playful-landscape-letter',
	'starter-playful-portrait-letter',
];

test.use( { viewport: { width: 1100, height: 800 }, deviceScaleFactor: 2 } );

function tmpDir(): string {
	return fs.mkdtempSync( path.join( os.tmpdir(), 'ppcert-cparity-' ) );
}

/**
 * Starter layout with its _meta block stripped, written to a temp file.
 * @param slug
 * @param dir
 */
function starterLayout( slug: string, dir: string ) {
	const raw = JSON.parse(
		fs.readFileSync(
			path.join( PATHS.root, 'templates', `${ slug }.json` ),
			'utf8'
		)
	);
	delete raw._meta;

	const layoutPath = path.join( dir, `${ slug }.json` );
	fs.writeFileSync( layoutPath, JSON.stringify( raw ) );

	return { layout: raw, layoutPath };
}

/** The PHP encoder's QR matrix for the sample credential. */
function sampleQr(): unknown {
	const out = execFileSync( findPhp(), [
		path.join( PATHS.root, 'tests', 'playwright', 'helpers', 'qr-cli.php' ),
		CREDENTIAL,
	] );

	return JSON.parse( out.toString() );
}

/**
 * Boot the canvas harness into parity mode with a starter layout.
 * @param page
 * @param layout
 * @param qr
 */
async function bootCanvas(
	page: Page,
	layout: unknown,
	qr: unknown
): Promise< void > {
	await page.goto( HARNESS_URL );
	await page.waitForSelector( '[data-ppcert-canvas-scale]' );

	const samples = JSON.parse( fs.readFileSync( SAMPLES_PATH, 'utf8' ) );

	await page.evaluate(
		( args: any ) => {
			const bridge = ( window as any ).__ppcertHarness;

			bridge.seedQr( args.qr );
			bridge.seedSamples( {
				groups: {},
				fields: Object.keys( args.samples ).map( ( key ) => ( {
					key,
					group: 'parity',
					label: key,
					sample: args.samples[ key ],
				} ) ),
			} );

			bridge.dispatch( {
				type: 'LOAD_TEMPLATE',
				template: { id: 0, title: 'Parity', status: 'draft' },
				layout: args.layout,
			} );

			bridge.setZoom( 1 );
			bridge.setParity( true );
		},
		{ layout, qr, samples }
	);

	await page.waitForSelector( '[data-ppcert-canvas-scale="1"]' );

	// Real webfonts must be active before measurement or screenshot.
	await page.evaluate( () => ( document as any ).fonts.ready );

	// One settled frame after font-driven re-measure.
	await page.evaluate(
		() =>
			new Promise( ( resolve ) =>
				requestAnimationFrame( () => requestAnimationFrame( resolve ) )
			)
	);
}

for ( const slug of STARTERS ) {
	test( `${ slug }: canvas matches the PDF raster within parity thresholds`, async ( {
		page,
	} ) => {
		const dir = tmpDir();
		const { layout, layoutPath } = starterLayout( slug, dir );

		// PDF side.
		const pdfPng = path.join( dir, 'pdf.png' );
		renderPng(
			layoutPath,
			SAMPLES_PATH,
			{ context: 'parity', dpi: DPI, credential_id: CREDENTIAL },
			pdfPng
		);

		// Canvas side.
		await bootCanvas( page, layout, sampleQr() );

		const canvasPng = path.join( dir, 'canvas.png' );
		await page
			.locator( '.ppcert-designer__page' )
			.screenshot( { path: canvasPng } );

		// Pixel comparison (FR-005: <= 1.0% after AA tolerance).
		const diffPath = path.join( dir, 'diff.png' );
		const result = comparePngs( canvasPng, pdfPng, diffPath );

		expect(
			result.ratio,
			`${ ( result.ratio * 100 ).toFixed(
				3
			) }% pixels differ (diff: ${ diffPath })`
		).toBeLessThanOrEqual( PIXEL_DIFF_MAX_RATIO );

		// Canvas bounding boxes vs declared geometry (<= 1 pt).
		const pageBox = await page
			.locator( '.ppcert-designer__page' )
			.boundingBox();

		for ( const element of layout.elements ) {
			const domBox = await page
				.locator( `[data-ppcert-el="${ element.id }"]` )
				.boundingBox();

			const drifts = {
				x: Math.abs( domBox!.x - pageBox!.x - element.x ),
				y: Math.abs( domBox!.y - pageBox!.y - element.y ),
				w: Math.abs( domBox!.width - element.w ),
				h: Math.abs( domBox!.height - element.h ),
			};

			for ( const [ edge, drift ] of Object.entries( drifts ) ) {
				expect(
					drift,
					`${ element.id } ${ edge } drifts ${ drift.toFixed(
						2
					) }pt on the canvas`
				).toBeLessThanOrEqual( BOX_DRIFT_MAX_PT );
			}
		}

		// PDF bounding boxes vs declared geometry (parity-debug raster).
		const debugPng = path.join( dir, 'debug.png' );
		renderPng(
			layoutPath,
			SAMPLES_PATH,
			{
				context: 'parity',
				dpi: DPI,
				credential_id: CREDENTIAL,
				parity_debug: true,
			},
			debugPng
		);

		const boxes = extractBoxes( debugPng, layout.elements.length );
		const failures = boxDriftFailures( boxes, layout.elements, SCALE );

		expect(
			failures,
			`PDF box drift: ${ JSON.stringify( failures ) }`
		).toEqual( [] );
	} );
}
