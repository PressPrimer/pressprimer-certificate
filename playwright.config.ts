import { defineConfig } from '@playwright/test';

/**
 * PressPrimer Certificate — Playwright suite.
 *
 * parity-pdf: the PDF-side parity harness (no browser involved — specs
 * drive the PHP renderer through a CLI bridge and compare rasters).
 * designer-canvas: browser specs against the standalone canvas harness
 * (tests/playwright/designer/harness — build with `npm run
 * build:harness` first; `npm run test:designer` does both). Phase 3
 * closes the canvas-vs-PDF parity loop on top of these projects
 * (Feature 007 FR-005 — a parity failure is a release blocker).
 */
/**
 * Deterministic text rendering across platforms: Linux Chromium
 * (FreeType) applies LCD subpixel AA and subpixel glyph positioning
 * that macOS (CoreText) does not, which pushed the text-heaviest
 * starter past the parity budget on CI while passing locally. Grayscale
 * AA + whole-pixel positioning renders near-identically on both.
 */
const RENDER_ARGS = [
	'--disable-lcd-text',
	'--disable-font-subpixel-positioning',
	'--force-color-profile=srgb',
];

export default defineConfig( {
	testDir: './tests/playwright',
	fullyParallel: false,
	timeout: 120_000,
	reporter: [ [ 'list' ] ],
	projects: [
		{
			name: 'parity-pdf',
			testMatch: /parity\/.*\.spec\.ts/,
			use: {
				browserName: 'chromium',
				launchOptions: { args: RENDER_ARGS },
			},
		},
		{
			name: 'designer-canvas',
			testMatch: /designer\/.*\.spec\.ts/,
			use: {
				browserName: 'chromium',
				viewport: { width: 1400, height: 900 },
				launchOptions: { args: RENDER_ARGS },
			},
		},
	],
} );
