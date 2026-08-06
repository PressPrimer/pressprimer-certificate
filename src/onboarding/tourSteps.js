/**
 * Tour step definitions
 *
 * The guided build (Phase 5B item 2, modeled on Assignment 2.2):
 * instead of describing the product, the tour walks the user through
 * awarding a real certificate in the real admin UI:
 *
 * 1. Welcome modal — the five-minute pitch
 * 2. Pick a design — spotlight the starter gallery
 * 3. The canvas — spotlight the page (it IS the PDF)
 * 4. Publish — spotlight the designer toolbar
 * 5. Issue — spotlight the Issue Certificate button
 * 6. Your certificate — modal with the download + verify moment
 * 7. Completion modal
 *
 * Steps 2–4 live on the designer (ppcert-templates, action=new/edit —
 * creating from the gallery rewrites the URL to action=edit without a
 * reload, and the tour must not navigate away from the draft).
 */

import { createInterpolateElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Step type constants
 */
export const STEP_TYPE = {
	MODAL: 'modal',
	SPOTLIGHT: 'spotlight',
};

/**
 * Get onboarding data from PHP
 *
 * @return {Object} Localized data.
 */
const getData = () => window.ppcert_onboarding_data || {};

/**
 * The designer/gallery URL
 *
 * @return {string} Admin URL for the gallery.
 */
const getGalleryUrl = () =>
	getData().urls?.gallery || 'admin.php?page=ppcert-templates&action=new';

/**
 * The certificates screen URL
 *
 * @return {string} Admin URL for the Certificates screen.
 */
const getCertificatesUrl = () =>
	getData().urls?.certificates || 'admin.php?page=ppcert-certificates';

/**
 * Check whether the given URL params point at the designer
 *
 * Matches both action=new and action=edit: creating from the gallery
 * rewrites the URL to action=edit in place, and reloading the editor
 * keeps the tour on the user's draft.
 *
 * @param {URLSearchParams} urlParams Current URL search params.
 * @return {boolean} True when on the designer screen.
 */
const isOnDesigner = ( urlParams ) =>
	urlParams.get( 'page' ) === 'ppcert-templates' &&
	[ 'new', 'edit' ].includes( urlParams.get( 'action' ) || '' );

/**
 * Check whether the given URL params point at the Certificates screen
 *
 * @param {URLSearchParams} urlParams Current URL search params.
 * @return {boolean} True when on the Certificates screen.
 */
const isOnCertificates = ( urlParams ) =>
	urlParams.get( 'page' ) === 'ppcert-certificates';

/**
 * Tour steps
 *
 * Each step has:
 *   id               — unique identifier
 *   type             — MODAL or SPOTLIGHT
 *   title            — heading text
 *   content          — body text
 *   selector         — CSS selector for spotlight target (comma-separated for fallbacks)
 *   fallbackSelector — extra fallback if primary selector not found
 *   position         — preferred tooltip position (top, bottom, left, right)
 *   matches          — predicate ( URLSearchParams ) => boolean for page matching
 *   pageUrl          — URL (or function returning one) to navigate to for this step
 */
export const TOUR_STEPS = [
	// Step 1: Welcome modal.
	{
		id: 'welcome',
		type: STEP_TYPE.MODAL,
		title: __(
			"Let's award your first certificate",
			'pressprimer-certificate'
		),
		content: __(
			"In about five minutes you'll design a real certificate, publish it, and issue one to yourself, all in your actual admin screens. Everything can be changed later.",
			'pressprimer-certificate'
		),
		selector: null,
		fallbackSelector: null,
		position: null,
		pageUrl: null,
	},

	// Step 2: Pick a design in the starter gallery.
	{
		id: 'pick-design',
		type: STEP_TYPE.SPOTLIGHT,
		title: __( 'Pick a design', 'pressprimer-certificate' ),
		content: __(
			'Choose your certificate size, then click a design you like, or start blank. The tour continues as soon as your template is created.',
			'pressprimer-certificate'
		),
		selector: '.ppcert-designer__gallery',
		fallbackSelector: '.ppcert-designer-wrap',
		position: 'top',
		fitContent: true,
		matches: isOnDesigner,
		pageUrl: getGalleryUrl,
	},

	// Step 3: The canvas.
	{
		id: 'canvas',
		type: STEP_TYPE.SPOTLIGHT,
		title: __( 'This canvas is the PDF', 'pressprimer-certificate' ),
		content: __(
			'What you see here is exactly what recipients download. Click any element to edit it in the panel on the right, drag it to move it, and add new elements from the panel on the left. Fields in {{curly braces}} fill in automatically when a certificate is issued.',
			'pressprimer-certificate'
		),
		selector: '.ppcert-designer__page',
		fallbackSelector: '.ppcert-designer__canvas-region',
		position: 'right',
		matches: isOnDesigner,
		pageUrl: getGalleryUrl,
	},

	// Step 4: Publish with the real controls. The tour advances
	// automatically when it hears a published save; Next stays disabled
	// until then (gated in Onboarding.jsx).
	{
		id: 'publish',
		type: STEP_TYPE.SPOTLIGHT,
		title: __( 'Name it and publish', 'pressprimer-certificate' ),
		content: createInterpolateElement(
			__(
				'Click the template name in the toolbar to rename it, then click the checkmark or press Return to apply the name. Then click <strong>Publish</strong> in the top right of the toolbar. Only published templates can award certificates.',
				'pressprimer-certificate'
			),
			{ strong: <strong /> }
		),
		selector: '.ppcert-designer__toolbar',
		fallbackSelector: '.ppcert-designer',
		position: 'bottom',
		matches: isOnDesigner,
		pageUrl: getGalleryUrl,
	},

	// Step 5: Issue one to yourself. Advances automatically when the
	// issue succeeds (the screen then reloads into step 6).
	{
		id: 'issue',
		type: STEP_TYPE.SPOTLIGHT,
		title: __( 'Issue one to yourself', 'pressprimer-certificate' ),
		content: createInterpolateElement(
			__(
				'Click <strong>Issue Certificate</strong>, choose the template you just published, and pick yourself as the recipient. This creates a real, verifiable credential.',
				'pressprimer-certificate'
			),
			{ strong: <strong /> }
		),
		selector: '#ppcert-issue-open',
		fallbackSelector: '.wrap',
		position: 'bottom',
		matches: isOnCertificates,
		pageUrl: getCertificatesUrl,
	},

	// Step 6: The certificate moment (custom modal with the links).
	{
		id: 'certificate',
		type: STEP_TYPE.MODAL,
		title: __( 'That’s a real credential', 'pressprimer-certificate' ),
		content: __(
			'Your certificate has a unique credential ID, a downloadable PDF, and a public verification page where anyone can confirm it is genuine. The QR code printed on the certificate links straight to that verification page.',
			'pressprimer-certificate'
		),
		selector: null,
		fallbackSelector: null,
		position: null,
		pageUrl: null,
	},

	// Step 7: Completion modal.
	{
		id: 'complete',
		type: STEP_TYPE.MODAL,
		title: __( "You're all set!", 'pressprimer-certificate' ),
		content: __(
			"You designed, published, and issued a real certificate. To award certificates automatically, connect your template to a quiz, course, or assignment in the designer's Award tab. You can also set your brand colors, logo, and signature under Settings → Appearance. Replay this tour anytime from the dashboard.",
			'pressprimer-certificate'
		),
		selector: null,
		fallbackSelector: null,
		position: null,
		pageUrl: null,
	},
];

/**
 * Get a step by index (1-based)
 *
 * @param {number} stepNumber 1-based step number.
 * @return {Object|null} Step object or null.
 */
export const getStep = ( stepNumber ) => {
	const index = stepNumber - 1;
	return TOUR_STEPS[ index ] || null;
};

/**
 * Get the URL for a step (resolving functions)
 *
 * @param {number} stepNumber 1-based step number.
 * @return {string} URL string or empty.
 */
export const getStepUrl = ( stepNumber ) => {
	const step = getStep( stepNumber );
	if ( ! step || ! step.pageUrl ) {
		return '';
	}
	return typeof step.pageUrl === 'function' ? step.pageUrl() : step.pageUrl;
};

/**
 * Get the total number of steps
 *
 * @return {number} Total steps.
 */
export const getTotalSteps = () => TOUR_STEPS.length;

/**
 * Get a step's 1-based number by its id
 *
 * @param {string} stepId Step id (e.g. 'issue', 'certificate').
 * @return {number} 1-based step number, or 0 when not found.
 */
export const getStepNumberById = ( stepId ) =>
	TOUR_STEPS.findIndex( ( step ) => step.id === stepId ) + 1;

/**
 * Check whether the current page hosts the given step
 *
 * Modal steps render anywhere; spotlight steps match their screen via
 * the step's matches predicate.
 *
 * @param {number} stepNumber 1-based step number.
 * @return {boolean} True when the step can render here.
 */
export const isOnCorrectPage = ( stepNumber ) => {
	const step = getStep( stepNumber );

	if ( ! step || step.type !== STEP_TYPE.SPOTLIGHT ) {
		return true;
	}

	const urlParams = new URLSearchParams( window.location.search );

	return typeof step.matches === 'function'
		? step.matches( urlParams )
		: true;
};
