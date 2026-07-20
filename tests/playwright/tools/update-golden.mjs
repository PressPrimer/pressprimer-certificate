/**
 * Deliberate golden-image regeneration (never automatic).
 *
 *   npm run parity:update-golden
 *
 * Re-renders every golden from its fixture at the pinned DPI and commits
 * the result to tests/playwright/goldens/. Run this ONLY when a visual
 * change is intended and reviewed - the parity suite treats the goldens
 * as the ground truth.
 */
import { execFileSync } from 'node:child_process';
import { existsSync, readdirSync, mkdirSync } from 'node:fs';
import * as path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve( path.dirname( fileURLToPath( import.meta.url ) ), '..', '..', '..' );

function findPhp() {
	if ( process.env.PPCERT_PHP && existsSync( process.env.PPCERT_PHP ) ) {
		return process.env.PPCERT_PHP;
	}

	const servicesDir =
		'/Applications/Local.app/Contents/Resources/extraResources/lightning-services';

	try {
		const candidates = readdirSync( servicesDir )
			.filter( ( name ) => name.startsWith( 'php-8.' ) )
			.sort()
			.map( ( name ) => path.join( servicesDir, name, 'bin', 'darwin-arm64', 'bin', 'php' ) )
			.filter( ( candidate ) => existsSync( candidate ) );

		if ( candidates.length > 0 ) {
			return candidates[ candidates.length - 1 ];
		}
	} catch {
		// Fall through.
	}

	return 'php';
}

const GOLDENS = [
	{
		layout: path.join( ROOT, 'tests', 'phpunit', 'fixtures', 'sample-document.json' ),
		merge: path.join( ROOT, 'tests', 'playwright', 'fixtures', 'sample-merge-data.json' ),
		args: { context: 'parity', dpi: 150, credential_id: 'SAMPLE000000' },
		out: path.join( ROOT, 'tests', 'playwright', 'goldens', 'sample-document-150dpi.png' ),
	},
	{
		// Parity-debug golden: the geometry baseline. Box comparisons run
		// against THIS committed raster, so a fixture or renderer drift
		// beyond the budget fails even when the pixel ratio stays small.
		layout: path.join( ROOT, 'tests', 'phpunit', 'fixtures', 'sample-document.json' ),
		merge: path.join( ROOT, 'tests', 'playwright', 'fixtures', 'sample-merge-data.json' ),
		args: { context: 'parity', dpi: 150, credential_id: 'SAMPLE000000', parity_debug: true },
		out: path.join( ROOT, 'tests', 'playwright', 'goldens', 'sample-document-150dpi-debug.png' ),
	},
];

mkdirSync( path.join( ROOT, 'tests', 'playwright', 'goldens' ), { recursive: true } );

for ( const golden of GOLDENS ) {
	execFileSync(
		findPhp(),
		[
			path.join( ROOT, 'tests', 'playwright', 'helpers', 'render-cli.php' ),
			golden.layout,
			golden.merge,
			JSON.stringify( golden.args ),
			golden.out,
		],
		{ stdio: 'inherit' }
	);
	console.log( `golden updated: ${ path.relative( ROOT, golden.out ) }` );
}
