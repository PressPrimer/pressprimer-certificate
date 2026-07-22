<?php
/**
 * Parity harness QR bridge (CLI)
 *
 * Emits the QR matrix JSON for a credential id, using the same URL
 * derivation and encoder the PDF renderer uses - the canvas paints this
 * exact matrix, keeping one encoder (ADR-004) across both parity sides.
 *
 *   php qr-cli.php <credential_id>
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

if ( $argc < 2 ) {
	fwrite( STDERR, "Usage: qr-cli.php <credential_id>\n" );
	exit( 1 );
}

require dirname( __DIR__, 2 ) . '/phpunit/bootstrap.php';

$ppcert_credential = (string) $argv[1];

// Mirror PressPrimer_Certificate_PDF_Renderer::qr_content(): the CLI
// bootstrap has no plugin bootstrap file, so the home_url fallback runs
// on both sides.
$ppcert_url = function_exists( 'ppcert_verification_url' )
	? ppcert_verification_url( $ppcert_credential )
	: home_url( '/?ppcert_id=' . rawurlencode( $ppcert_credential ) );

$ppcert_qr = PressPrimer_Certificate_QR_Service::generate( $ppcert_url );

if ( is_wp_error( $ppcert_qr ) ) {
	fwrite( STDERR, 'QR failed: ' . $ppcert_qr->get_error_message() . "\n" );
	exit( 1 );
}

echo wp_json_encode( $ppcert_qr );
