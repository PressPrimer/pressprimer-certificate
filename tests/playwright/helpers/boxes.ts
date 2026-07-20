/**
 * Bounding-box extraction and drift assertions (FR-005).
 *
 * The renderer's parity-debug mode paints each element as a solid rect in
 * a deterministic index color; these utilities recover the rendered boxes
 * from the raster and assert them against the layout's declared geometry
 * within the 1 pt drift budget.
 */
import * as fs from 'node:fs';
import { PNG } from 'pngjs';
import { BOX_DRIFT_MAX_PT } from './compare';

export interface Box {
	x: number;
	y: number;
	w: number;
	h: number;
}

/**
 * Mirror of PressPrimer_Certificate_PDF_Renderer::parity_color() - the
 * two implementations must stay identical.
 * @param index
 */
export function parityColor( index: number ): [ number, number, number ] {
	return [
		40 + ( ( index * 2 ) % 200 ),
		70 + ( ( Math.floor( index / 100 ) * 60 ) % 150 ),
		90 + ( ( index * 3 ) % 160 ),
	];
}

/**
 * Extract per-element pixel bounding boxes from a parity-debug raster.
 * @param pngPath
 * @param elementCount
 */
export function extractBoxes(
	pngPath: string,
	elementCount: number
): Map< number, Box > {
	const png = PNG.sync.read( fs.readFileSync( pngPath ) );

	const wanted = new Map< string, number >();
	for ( let i = 0; i < elementCount; i++ ) {
		wanted.set( parityColor( i ).join( ',' ), i );
	}

	const bounds = new Map<
		number,
		{ minX: number; minY: number; maxX: number; maxY: number }
	>();

	for ( let y = 0; y < png.height; y++ ) {
		for ( let x = 0; x < png.width; x++ ) {
			const offset = ( png.width * y + x ) * 4;
			const key = `${ png.data[ offset ] },${ png.data[ offset + 1 ] },${
				png.data[ offset + 2 ]
			}`;
			const index = wanted.get( key );

			if ( index === undefined ) {
				continue;
			}

			const entry = bounds.get( index );
			if ( ! entry ) {
				bounds.set( index, { minX: x, minY: y, maxX: x, maxY: y } );
			} else {
				entry.minX = Math.min( entry.minX, x );
				entry.minY = Math.min( entry.minY, y );
				entry.maxX = Math.max( entry.maxX, x );
				entry.maxY = Math.max( entry.maxY, y );
			}
		}
	}

	const boxes = new Map< number, Box >();
	for ( const [ index, b ] of bounds ) {
		boxes.set( index, {
			x: b.minX,
			y: b.minY,
			w: b.maxX - b.minX + 1,
			h: b.maxY - b.minY + 1,
		} );
	}

	return boxes;
}

export interface DriftFailure {
	index: number;
	edge: string;
	driftPt: number;
}

/**
 * Assert extracted pixel boxes against declared point geometry.
 *
 * @param boxes    Extracted pixel boxes by element index.
 * @param elements Layout elements (declared x/y/w/h in points).
 * @param scale    Pixels per point of the raster.
 * @return Failures beyond the drift budget (empty = pass).
 */
export function boxDriftFailures(
	boxes: Map< number, Box >,
	elements: Array< { x: number; y: number; w: number; h: number } >,
	scale: number
): DriftFailure[] {
	const failures: DriftFailure[] = [];
	// Rasterization quantizes edges to whole pixels; allow that rounding
	// on top of the drift budget.
	const tolerancePx = BOX_DRIFT_MAX_PT * scale + 1;

	elements.forEach( ( element, index ) => {
		const box = boxes.get( index );

		if ( ! box ) {
			failures.push( {
				index,
				edge: 'missing',
				driftPt: Number.POSITIVE_INFINITY,
			} );
			return;
		}

		const edges: Array< [ string, number, number ] > = [
			[ 'x', box.x, element.x * scale ],
			[ 'y', box.y, element.y * scale ],
			[ 'w', box.w, element.w * scale ],
			[ 'h', box.h, element.h * scale ],
		];

		for ( const [ edge, actual, expected ] of edges ) {
			const driftPx = Math.abs( actual - expected );

			if ( driftPx > tolerancePx ) {
				failures.push( { index, edge, driftPt: driftPx / scale } );
			}
		}
	} );

	return failures;
}
