/**
 * Pixel comparison utilities and the FR-005 parity thresholds.
 *
 * Threshold changes are parity-contract changes: <= 1.0% differing pixels
 * after anti-aliasing tolerance, and no element bounding-box drift beyond
 * 1 pt. Any relaxation requires explicit sign-off (a parity failure is a
 * release blocker).
 */
import * as fs from 'node:fs';
import { PNG } from 'pngjs';
import pixelmatch from 'pixelmatch';

/** Maximum ratio of differing pixels (FR-005). */
export const PIXEL_DIFF_MAX_RATIO = 0.01;

/** Maximum element bounding-box drift in points (FR-005). */
export const BOX_DRIFT_MAX_PT = 1.0;

/** Per-pixel pixelmatch threshold (anti-aliasing tolerance). */
export const PIXELMATCH_THRESHOLD = 0.1;

export interface CompareResult {
	width: number;
	height: number;
	diffPixels: number;
	ratio: number;
}

/**
 * Compare two PNGs with pixelmatch; images must share dimensions.
 * @param aPath
 * @param bPath
 * @param diffOutPath
 */
export function comparePngs(
	aPath: string,
	bPath: string,
	diffOutPath?: string
): CompareResult {
	const a = PNG.sync.read( fs.readFileSync( aPath ) );
	const b = PNG.sync.read( fs.readFileSync( bPath ) );

	if ( a.width !== b.width || a.height !== b.height ) {
		throw new Error(
			`Dimension mismatch: ${ a.width }x${ a.height } vs ${ b.width }x${ b.height }`
		);
	}

	const diff = new PNG( { width: a.width, height: a.height } );

	const diffPixels = pixelmatch(
		a.data,
		b.data,
		diff.data,
		a.width,
		a.height,
		{
			threshold: PIXELMATCH_THRESHOLD,
			includeAA: false,
		}
	);

	if ( diffOutPath ) {
		fs.writeFileSync( diffOutPath, PNG.sync.write( diff ) );
	}

	return {
		width: a.width,
		height: a.height,
		diffPixels,
		ratio: diffPixels / ( a.width * a.height ),
	};
}
