/**
 * Onboarding - React entry point (Phase 5B item 2)
 *
 * Bootstraps the guided setup tour when the PHP backend has
 * determined the current user should see it. Follows the
 * Quiz/Assignment onboarding pattern.
 */

import { render, unmountComponentAtNode } from '@wordpress/element';
import Onboarding from './components/Onboarding';
import { clearSetupSession } from './setupSession';
import './style.css';

/**
 * Initialize the onboarding overlay
 */
const initOnboarding = () => {
	const data = window.ppcert_onboarding_data;

	if ( ! data || ! data.state || ! data.state.should_show ) {
		return;
	}

	let root = document.getElementById( 'ppcert-onboarding-root' );
	if ( ! root ) {
		root = document.createElement( 'div' );
		root.id = 'ppcert-onboarding-root';
		document.body.appendChild( root );
	}

	render( <Onboarding />, root );
};

/**
 * Expose a global function to relaunch the tour.
 * Called from the dashboard "Replay the Setup Tour" button.
 */
window.ppcertLaunchOnboarding = () => {
	const data = window.ppcert_onboarding_data;

	if ( ! data ) {
		return;
	}

	// A relaunched tour starts fresh — forget the previous run's
	// records.
	clearSetupSession();

	// Reset via AJAX, then re-render from step 1.
	const formData = new FormData();
	formData.append( 'action', 'ppcert_onboarding_progress' );
	formData.append( 'nonce', data.nonce || '' );
	formData.append( 'action_type', 'reset' );

	fetch( data.ajaxUrl || '', {
		method: 'POST',
		credentials: 'same-origin',
		body: formData,
	} ).then( () => {
		data.state.should_show = true;
		data.state.current_step = 1;
		data.state.completed = false;
		data.state.started = false;

		const root = document.getElementById( 'ppcert-onboarding-root' );
		if ( root ) {
			unmountComponentAtNode( root );
		}

		initOnboarding();
	} );
};

/**
 * Boot when the DOM is ready
 */
if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', initOnboarding );
} else {
	initOnboarding();
}
