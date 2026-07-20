/**
 * PHP render bridge for the parity suite.
 *
 * Locates a PHP binary (env override → Local.app services → PATH) and
 * drives helpers/render-cli.php to produce PNGs from layout fixtures.
 */
import { execFileSync } from 'node:child_process';
import { existsSync, readdirSync } from 'node:fs';
import * as path from 'node:path';

const ROOT = path.resolve( __dirname, '..', '..', '..' );

/**
 * Find the PHP binary: PPCERT_PHP env, then the newest Local.app PHP 8.x
 * service, then `php` on PATH (CI).
 */
export function findPhp(): string {
	if ( process.env.PPCERT_PHP && existsSync( process.env.PPCERT_PHP ) ) {
		return process.env.PPCERT_PHP;
	}

	const servicesDir =
		'/Applications/Local.app/Contents/Resources/extraResources/lightning-services';

	try {
		const candidates = readdirSync( servicesDir )
			.filter( ( name ) => name.startsWith( 'php-8.' ) )
			.sort()
			.map( ( name ) =>
				path.join(
					servicesDir,
					name,
					'bin',
					'darwin-arm64',
					'bin',
					'php'
				)
			)
			.filter( ( candidate ) => existsSync( candidate ) );

		if ( candidates.length > 0 ) {
			return candidates[ candidates.length - 1 ];
		}
	} catch {
		// Services dir absent (CI/Linux) — fall through to PATH.
	}

	return 'php';
}

export interface RenderArgs {
	context?: string;
	dpi?: number;
	credential_id?: string;
	parity_debug?: boolean;
	[ key: string ]: unknown;
}

/**
 * Render a layout fixture to a PNG via the PHP CLI bridge.
 * @param layoutPath
 * @param mergePath
 * @param args
 * @param outPath
 */
export function renderPng(
	layoutPath: string,
	mergePath: string,
	args: RenderArgs,
	outPath: string
): void {
	execFileSync(
		findPhp(),
		[
			path.join(
				ROOT,
				'tests',
				'playwright',
				'helpers',
				'render-cli.php'
			),
			layoutPath,
			mergePath,
			JSON.stringify( args ),
			outPath,
		],
		{ stdio: [ 'ignore', 'pipe', 'inherit' ] }
	);
}

export const PATHS = {
	root: ROOT,
	sampleLayout: path.join(
		ROOT,
		'tests',
		'phpunit',
		'fixtures',
		'sample-document.json'
	),
	sampleMerge: path.join(
		ROOT,
		'tests',
		'playwright',
		'fixtures',
		'sample-merge-data.json'
	),
	goldens: path.join( ROOT, 'tests', 'playwright', 'goldens' ),
};
