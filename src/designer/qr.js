/**
 * Canvas QR matrix access (Feature 006 FR-005, Feature 007 FR-005)
 *
 * The canvas never encodes QR codes itself - one encoder exists
 * (ADR-004, PHP-side). The sample matrix for the design surface arrives
 * in boot data (admin) or is seeded directly (parity harness); the
 * QrElement just paints modules.
 */

import { getBoot } from './boot';

let seeded = null;

/**
 * The sample QR matrix, or null when unavailable.
 *
 * @return {Object|null} { matrix, modules, total_modules, quiet_zone }.
 */
export function getSampleQr() {
	if ( seeded ) {
		return seeded;
	}

	const boot = getBoot();

	return boot.sample_qr && boot.sample_qr.matrix ? boot.sample_qr : null;
}

/**
 * Seed the sample matrix (harness / tests).
 *
 * @param {Object} qr QR payload from the PHP encoder.
 */
export function seedSampleQr( qr ) {
	seeded = qr;
}
