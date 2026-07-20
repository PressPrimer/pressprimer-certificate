<?php
/**
 * Font pipeline build script
 *
 * Converts the bundled OFL TTFs in fonts/ to TCPDF font files in
 * fonts/tcpdf/ and emits fonts/manifest.json - the single font map
 * consumed by both the PDF renderer and the designer's
 * ppcert_designer_fonts output (Feature 007 FR-003).
 *
 * Dev-time only: run via `npm run build:fonts`; the converted output and
 * manifest are committed so production never converts at runtime. This
 * script is excluded from the release ZIP, and its direct TCPDF_FONTS use
 * is build tooling - the runtime isolation rule (TR-001: only the PDF
 * renderer touches TCPDF) applies to plugin code, not build scripts.
 *
 * @package PressPrimer_Certificate
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$ppcert_root = dirname( __DIR__ );

require $ppcert_root . '/vendor/autoload.php';

/**
 * The bundled font set. Slugs are the layout font_family values
 * (CONVENTIONS.md); families are provisional until Prompt 5.1 finalizes
 * the bundle alongside the starter templates.
 */
$ppcert_font_config = [
	'playfair-display' => [
		'label'        => 'Playfair Display',
		'license'      => 'SIL OFL 1.1',
		'license_file' => 'playfair-display/OFL.txt',
		'source'       => 'Static instances generated from the upstream variable fonts (github.com/clauseggers/Playfair, fonts/VF-TTF) via fontTools varLib.instancer at wght 400/700',
		'variants'     => [
			'regular'     => 'playfair-display/PlayfairDisplay-Regular.ttf',
			'bold'        => 'playfair-display/PlayfairDisplay-Bold.ttf',
			'italic'      => 'playfair-display/PlayfairDisplay-Italic.ttf',
			'bold_italic' => 'playfair-display/PlayfairDisplay-BoldItalic.ttf',
		],
	],
	'source-sans-3'    => [
		'label'        => 'Source Sans 3',
		'license'      => 'SIL OFL 1.1',
		'license_file' => 'source-sans-3/LICENSE.md',
		'source'       => 'adobe-fonts/source-sans release 3.052R (github.com/adobe-fonts/source-sans)',
		'variants'     => [
			'regular'     => 'source-sans-3/SourceSans3-Regular.ttf',
			'bold'        => 'source-sans-3/SourceSans3-Bold.ttf',
			'italic'      => 'source-sans-3/SourceSans3-It.ttf',
			'bold_italic' => 'source-sans-3/SourceSans3-BoldIt.ttf',
		],
	],
];

$ppcert_fonts_dir = $ppcert_root . '/fonts/';
$ppcert_out_dir   = $ppcert_fonts_dir . 'tcpdf/';

if ( ! is_dir( $ppcert_out_dir ) && ! mkdir( $ppcert_out_dir, 0755, true ) ) {
	fwrite( STDERR, "Cannot create {$ppcert_out_dir}\n" );
	exit( 1 );
}

/**
 * Read the metrics TCPDF wrote into a generated font definition file.
 *
 * @param string $path Generated .php font file.
 * @return array Metrics subset for the manifest.
 */
function ppcert_read_font_metrics( $path ) {
	$name = '';
	$desc = [];
	$up   = 0;
	$ut   = 0;
	$dw   = 0;

	include $path;

	return [
		'ascent'     => isset( $desc['Ascent'] ) ? (int) $desc['Ascent'] : 0,
		'descent'    => isset( $desc['Descent'] ) ? (int) $desc['Descent'] : 0,
		'cap_height' => isset( $desc['CapHeight'] ) ? (int) $desc['CapHeight'] : 0,
		'units'      => 'per-1000-em',
	];
}

$manifest = [
	'manifest_version' => 1,
	'families'         => [],
	// FR-004 text fitting rule: shrink-to-fit in 0.5 pt steps down to 60%
	// of the specified size, then truncate with ellipsis. The canvas
	// implements the identical rule from these same values - fitting is
	// part of the parity contract.
	'fitting'          => [
		'shrink_step_pt' => 0.5,
		'min_scale'      => 0.6,
		'overflow'       => 'ellipsis',
	],
];

foreach ( $ppcert_font_config as $slug => $family ) {
	echo "== {$family['label']} ({$slug})\n";

	$entry = [
		'label'        => $family['label'],
		'license'      => $family['license'],
		'license_file' => $family['license_file'],
		'source'       => $family['source'],
		'variants'     => [],
	];

	foreach ( $family['variants'] as $variant => $relative_ttf ) {
		$ttf = $ppcert_fonts_dir . $relative_ttf;

		if ( ! is_readable( $ttf ) ) {
			fwrite( STDERR, "Missing TTF: {$ttf}\n" );
			exit( 1 );
		}

		$font_key = TCPDF_FONTS::addTTFfont( $ttf, 'TrueTypeUnicode', '', 32, $ppcert_out_dir );

		if ( false === $font_key ) {
			fwrite( STDERR, "Conversion failed: {$ttf}\n" );
			exit( 1 );
		}

		$definition = $ppcert_out_dir . $font_key . '.php';

		$entry['variants'][ $variant ] = [
			'tcpdf_font' => $font_key,
			'ttf'        => $relative_ttf,
			'metrics'    => ppcert_read_font_metrics( $definition ),
		];

		echo "   {$variant}: {$font_key}\n";
	}

	$manifest['families'][ $slug ] = $entry;
}

$manifest_path = $ppcert_fonts_dir . 'manifest.json';
$encoded       = json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";

if ( false === file_put_contents( $manifest_path, $encoded ) ) {
	fwrite( STDERR, "Cannot write {$manifest_path}\n" );
	exit( 1 );
}

echo "\nManifest written: fonts/manifest.json (" . count( $manifest['families'] ) . " families)\n";
