/**
 * PDF-side parity baseline (Feature 007 FR-005).
 *
 * Two guards, per starter template once starters exist (2.5 baselines the
 * schema sample document; 5.1 extends to all starters):
 *
 * 1. Golden image: render_png() output must match the committed golden
 *    within the pixel threshold. Goldens regenerate DELIBERATELY only
 *    (npm run parity:update-golden) - never automatically.
 * 2. Bounding boxes: the parity-debug raster's per-element boxes must
 *    match the layout's declared geometry within the 1 pt drift budget.
 *
 * The canvas half arrives in Phase 3 (Prompt 3.8) and closes the
 * canvas-vs-PDF loop against these same fixtures and thresholds.
 */
import { test, expect } from '@playwright/test';
import * as fs from 'node:fs';
import * as path from 'node:path';
import * as os from 'node:os';
import { renderPng, PATHS } from '../helpers/render';
import { comparePngs, PIXEL_DIFF_MAX_RATIO } from '../helpers/compare';
import { extractBoxes, boxDriftFailures } from '../helpers/boxes';

const DPI = 150;
const SCALE = DPI / 72;

const RENDER_ARGS = {
	context: 'parity',
	dpi: DPI,
	credential_id: 'SAMPLE000000',
};

function tmpPng( name: string ): string {
	return path.join(
		fs.mkdtempSync( path.join( os.tmpdir(), 'ppcert-parity-' ) ),
		name
	);
}

test( 'sample document matches the committed golden image', () => {
	const golden = path.join( PATHS.goldens, 'sample-document-150dpi.png' );

	expect(
		fs.existsSync( golden ),
		'Golden missing - generate deliberately with: npm run parity:update-golden'
	).toBeTruthy();

	const rendered = tmpPng( 'rendered.png' );
	renderPng( PATHS.sampleLayout, PATHS.sampleMerge, RENDER_ARGS, rendered );

	const diffPath = tmpPng( 'diff.png' );
	const result = comparePngs( rendered, golden, diffPath );

	expect(
		result.ratio,
		`${ ( result.ratio * 100 ).toFixed(
			3
		) }% pixels differ (diff: ${ diffPath })`
	).toBeLessThanOrEqual( PIXEL_DIFF_MAX_RATIO );

	fs.unlinkSync( rendered );
} );

test( 'element bounding boxes stay within the 1 pt drift budget', () => {
	const layout = JSON.parse( fs.readFileSync( PATHS.sampleLayout, 'utf8' ) );
	const debugGolden = path.join(
		PATHS.goldens,
		'sample-document-150dpi-debug.png'
	);

	expect(
		fs.existsSync( debugGolden ),
		'Debug golden missing - generate deliberately with: npm run parity:update-golden'
	).toBeTruthy();

	const debugPng = tmpPng( 'debug.png' );
	renderPng(
		PATHS.sampleLayout,
		PATHS.sampleMerge,
		{ ...RENDER_ARGS, parity_debug: true },
		debugPng
	);

	const currentBoxes = extractBoxes( debugPng, layout.elements.length );
	const baselineBoxes = extractBoxes( debugGolden, layout.elements.length );

	// Guard 1 - renderer fidelity: rendered boxes match the declared
	// geometry of the fixture being rendered (catches renderer drift).
	const rendererFailures = boxDriftFailures(
		currentBoxes,
		layout.elements,
		SCALE
	);
	expect(
		rendererFailures,
		`Renderer drift beyond 1 pt vs declared geometry: ${ JSON.stringify(
			rendererFailures
		) }`
	).toEqual( [] );

	// Guard 2 - baseline stability: rendered boxes match the committed
	// debug golden (catches fixture nudges and any geometry change that
	// was not a deliberate golden regeneration).
	const baselineElements = Array.from(
		{ length: layout.elements.length },
		( _unused, index ) => {
			const box = baselineBoxes.get( index );
			expect(
				box,
				`element ${ index } missing from the debug golden`
			).toBeTruthy();
			return {
				x: box!.x / SCALE,
				y: box!.y / SCALE,
				w: box!.w / SCALE,
				h: box!.h / SCALE,
			};
		}
	);
	const baselineFailures = boxDriftFailures(
		currentBoxes,
		baselineElements,
		SCALE
	);
	expect(
		baselineFailures,
		`Geometry drift beyond 1 pt vs the committed golden: ${ JSON.stringify(
			baselineFailures
		) }`
	).toEqual( [] );

	fs.unlinkSync( debugPng );
} );
