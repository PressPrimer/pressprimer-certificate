<?php
/**
 * Parity harness render bridge (CLI)
 *
 * Renders a layout fixture to a PNG for the Playwright parity suite:
 *   php render-cli.php <layout.json> <merge.json> <args.json> <out.png>
 *
 * Reuses the PHPUnit bootstrap for the WordPress stubs and autoloader -
 * the renderer runs exactly as it does in the unit suite (GD path unless
 * Imagick is present and args request otherwise).
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

if ( $argc < 5 ) {
	fwrite( STDERR, "Usage: render-cli.php <layout.json> <merge.json> <args.json> <out.png>\n" );
	exit( 1 );
}

require dirname( __DIR__, 2 ) . '/phpunit/bootstrap.php';

$ppcert_layout = json_decode( (string) file_get_contents( $argv[1] ), true );
$ppcert_merge  = json_decode( (string) file_get_contents( $argv[2] ), true );
$ppcert_args   = json_decode( (string) $argv[3], true );

if ( ! is_array( $ppcert_layout ) || ! is_array( $ppcert_merge ) || ! is_array( $ppcert_args ) ) {
	fwrite( STDERR, "Invalid input JSON\n" );
	exit( 1 );
}

// Parity fixtures for the addon font contract: args.custom_fonts maps
// a custom slug to a bundled family it aliases - the entry is built
// with the absolute-path keys exactly as an Educator upload registers
// (Feature 2.0-006 font pipeline contract).
if ( ! empty( $ppcert_args['custom_fonts'] ) && is_array( $ppcert_args['custom_fonts'] ) ) {
	$ppcert_custom = $ppcert_args['custom_fonts'];

	add_filter(
		'ppcert_designer_fonts',
		static function ( $ppcert_fonts ) use ( $ppcert_custom ) {
			foreach ( $ppcert_custom as $ppcert_slug => $ppcert_alias ) {
				if ( ! isset( $ppcert_fonts[ $ppcert_alias ]['variants'] ) ) {
					continue;
				}

				$ppcert_variants = [];

				foreach ( $ppcert_fonts[ $ppcert_alias ]['variants'] as $ppcert_variant => $ppcert_def ) {
					$ppcert_variants[ $ppcert_variant ] = [
						'tcpdf_font' => $ppcert_def['tcpdf_font'],
						'tcpdf_file' => PPCERT_PLUGIN_DIR . 'fonts/tcpdf/' . $ppcert_def['tcpdf_font'] . '.php',
						'ttf_file'   => PPCERT_PLUGIN_DIR . 'fonts/' . $ppcert_def['ttf'],
						'metrics'    => $ppcert_def['metrics'],
					];
				}

				$ppcert_fonts[ $ppcert_slug ] = [
					'label'    => $ppcert_slug,
					'group'    => 'custom',
					'variants' => $ppcert_variants,
				];
			}

			return $ppcert_fonts;
		}
	);
}

$ppcert_renderer = new PressPrimer_Certificate_PDF_Renderer();
$ppcert_png      = $ppcert_renderer->render_png( $ppcert_layout, $ppcert_merge, $ppcert_args );

if ( is_wp_error( $ppcert_png ) ) {
	fwrite( STDERR, 'Render failed: ' . $ppcert_png->get_error_message() . "\n" );
	exit( 1 );
}

if ( ! rename( $ppcert_png, $argv[4] ) ) {
	fwrite( STDERR, "Could not move output to {$argv[4]}\n" );
	exit( 1 );
}

echo "ok\n";
