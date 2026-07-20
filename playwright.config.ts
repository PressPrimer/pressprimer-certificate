import { defineConfig } from '@playwright/test';

/**
 * PressPrimer Certificate — Playwright suite.
 *
 * Phase 2: the PDF-side parity harness (no browser involved — specs drive
 * the PHP renderer through a CLI bridge and compare rasters). Phase 3
 * adds the designer canvas projects, closing the canvas-vs-PDF loop
 * (Feature 007 FR-005 — a parity failure is a release blocker).
 */
export default defineConfig( {
	testDir: './tests/playwright',
	fullyParallel: false,
	timeout: 120_000,
	reporter: [ [ 'list' ] ],
	projects: [
		{
			name: 'parity-pdf',
			testMatch: /parity\/.*\.spec\.ts/,
		},
	],
} );
