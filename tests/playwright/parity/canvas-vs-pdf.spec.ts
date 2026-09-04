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
	// 2.0 (Feature 2.0-001): the Geometric family, all four variants.
	'starter-geometric-landscape',
	'starter-geometric-portrait',
	'starter-geometric-landscape-letter',
	'starter-geometric-portrait-letter',
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
 * @param extraCss
 */
async function bootCanvas(
	page: Page,
	layout: unknown,
	qr: unknown,
	extraCss = ''
): Promise< void > {
	await page.goto( HARNESS_URL );
	await page.waitForSelector( '[data-ppcert-canvas-scale]' );

	// Addon-font fixtures inject their @font-face before the layout
	// mounts, exactly as the free enqueue inlines Educator faces.
	if ( extraCss ) {
		await page.addStyleTag( { content: extraCss } );
	}

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

/**
 * Inline-token fixture layout (schema v2, Feature 1.1-001) written to
 * a temp file. Lives in tests/playwright/fixtures/ - NOT templates/ -
 * because parity fixtures never ship in the plugin or seed the
 * gallery.
 * @param slug
 * @param dir
 */
function fixtureLayout( slug: string, dir: string ) {
	const raw = JSON.parse(
		fs.readFileSync(
			path.join(
				PATHS.root,
				'tests',
				'playwright',
				'fixtures',
				`${ slug }.json`
			),
			'utf8'
		)
	);

	const layoutPath = path.join( dir, `${ slug }.json` );
	fs.writeFileSync( layoutPath, JSON.stringify( raw ) );

	return { layout: raw, layoutPath };
}

const INLINE_TOKEN_FIXTURES = [
	'parity-inline-tokens',
	'parity-inline-tokens-portrait',
];

/**
 * The parity assertion body, shared by starters and fixtures.
 *
 * For multi-page (v3) fixtures the PDF side rasterizes one page of the
 * full document (`pdfPage`) while the canvas renders that page's
 * elements as a single-page document (`canvasLayout`) - per-page parity
 * proves elements render identically on any page.
 * @param page
 * @param layout
 * @param layoutPath
 * @param dir
 * @param opts
 * @param opts.pdfPage
 * @param opts.canvasLayout
 * @param opts.renderArgs
 * @param opts.extraCss
 */
async function runParityCase(
	page: Page,
	layout: any,
	layoutPath: string,
	dir: string,
	opts: {
		pdfPage?: number;
		canvasLayout?: any;
		renderArgs?: Record< string, unknown >;
		extraCss?: string;
	} = {}
): Promise< void > {
	const canvasLayout = opts.canvasLayout ?? layout;
	const pageArg = opts.pdfPage ? { page: opts.pdfPage } : {};

	// PDF side.
	const pdfPng = path.join( dir, 'pdf.png' );
	renderPng(
		layoutPath,
		SAMPLES_PATH,
		{
			context: 'parity',
			dpi: DPI,
			credential_id: CREDENTIAL,
			...pageArg,
			...( opts.renderArgs || {} ),
		},
		pdfPng
	);

	// Canvas side.
	await bootCanvas( page, canvasLayout, sampleQr(), opts.extraCss || '' );

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

	for ( const element of canvasLayout.elements ) {
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
			...pageArg,
		},
		debugPng
	);

	// The raster clips elements at the page edge, so bleed elements
	// (schema-supported: positions may run to -page) compare against
	// their page-clipped boxes, not the declared ones (2.0, the
	// Geometric starters are the first bleeding fixtures).
	const pageW = canvasLayout.page.width;
	const pageH = canvasLayout.page.height;
	const clipped = canvasLayout.elements.map( ( element: any ) => {
		const x = Math.max( 0, element.x );
		const y = Math.max( 0, element.y );

		return {
			...element,
			x,
			y,
			w: Math.min( element.x + element.w, pageW ) - x,
			h: Math.min( element.y + element.h, pageH ) - y,
		};
	} );

	const boxes = extractBoxes( debugPng, clipped.length );
	const failures = boxDriftFailures( boxes, clipped, SCALE );

	expect(
		failures,
		`PDF box drift: ${ JSON.stringify( failures ) }`
	).toEqual( [] );
}

for ( const slug of STARTERS ) {
	test( `${ slug }: canvas matches the PDF raster within parity thresholds`, async ( {
		page,
	} ) => {
		const dir = tmpDir();
		const { layout, layoutPath } = starterLayout( slug, dir );

		await runParityCase( page, layout, layoutPath, dir );
	} );
}

for ( const slug of INLINE_TOKEN_FIXTURES ) {
	test( `${ slug }: inline-token text matches the PDF raster within parity thresholds`, async ( {
		page,
	} ) => {
		const dir = tmpDir();
		const { layout, layoutPath } = fixtureLayout( slug, dir );

		await runParityCase( page, layout, layoutPath, dir );
	} );
}

// The v3 multi-page fixture (schema v3, Feature 2.0-006 FR-002; grown
// to three pages with the QR on page 3 at Educator Prompt 2.4 - E-002's
// permanent fixture): every page of the document is captured
// individually from the rendered PDF and compared against the canvas
// rendering that page's elements as a v2 single-page document. One test
// per page so a failure names the page that drifted.
const MULTIPAGE_SLUG = 'parity-multipage';
const MULTIPAGE_PAGES = 3;

for ( let pageNumber = 1; pageNumber <= MULTIPAGE_PAGES; pageNumber++ ) {
	test( `${ MULTIPAGE_SLUG } page ${ pageNumber }: matches the PDF raster within parity thresholds`, async ( {
		page,
	} ) => {
		const dir = tmpDir();
		const { layout, layoutPath } = fixtureLayout( MULTIPAGE_SLUG, dir );

		expect(
			layout.pages.length,
			'fixture page count drives the test matrix'
		).toBe( MULTIPAGE_PAGES );

		const canvasLayout = {
			layout_schema_version: 2,
			page: layout.page,
			background: layout.background,
			elements: layout.pages[ pageNumber - 1 ].elements,
		};

		await runParityCase( page, layout, layoutPath, dir, {
			pdfPage: pageNumber,
			canvasLayout,
		} );
	} );
}

/**
 * Addon font contract fixtures (2.0, Feature 2.0-006 / Educator E-001).
 *
 * 'Uploaded': a custom-slug family registered exactly as an Educator
 * upload (absolute tcpdf_file/ttf_file via the fonts filter, aliasing
 * bundled Quicksand) must render pixel-identically on canvas and PDF.
 *
 * 'Deleted': the same layout with a family nobody registers - the
 * renderer substitutes the default font with a warning, and the canvas
 * fallback stack must land on the identical face.
 */
test.describe( 'addon font contract', () => {
	test( 'parity: filter-registered custom font', async ( { page } ) => {
		const dir = tmpDir();
		const { layout, layoutPath } = fixtureLayout(
			'parity-custom-font',
			dir
		);

		const ttfUrl =
			'file://' +
			path.join(
				PATHS.root,
				'fonts',
				'quicksand',
				'Quicksand-Regular.ttf'
			);

		await runParityCase( page, layout, layoutPath, dir, {
			renderArgs: {
				custom_fonts: { 'parity-custom-font': 'quicksand' },
			},
			extraCss: `@font-face{font-family:"parity-custom-font";src:url("${ ttfUrl }") format("truetype");font-weight:400;font-style:normal;font-display:block;}`,
		} );
	} );

	test( 'parity: deleted-font default substitution', async ( { page } ) => {
		const dir = tmpDir();
		const { layout } = fixtureLayout( 'parity-custom-font', dir );

		// Nobody registers this family on either side.
		const deleted = JSON.parse(
			JSON.stringify( layout ).replace(
				/parity-custom-font/g,
				'parity-deleted-font'
			)
		);
		const layoutPath = path.join( dir, 'parity-deleted-font.json' );
		fs.writeFileSync( layoutPath, JSON.stringify( deleted ) );

		await runParityCase( page, deleted, layoutPath, dir );
	} );
} );
