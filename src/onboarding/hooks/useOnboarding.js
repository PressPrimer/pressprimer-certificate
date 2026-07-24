/**
 * useOnboarding hook
 *
 * Manages tour state, step navigation, and AJAX persistence.
 * Follows the Quiz/Assignment useOnboarding pattern.
 */

import { useState, useCallback } from '@wordpress/element';
import {
	getStep,
	getStepUrl,
	getTotalSteps,
	isOnCorrectPage,
	STEP_TYPE,
} from '../tourSteps';
import { clearSetupSession } from '../setupSession';

/**
 * Get onboarding data from PHP
 *
 * @return {Object} Localized data.
 */
const getData = () => window.ppcert_onboarding_data || {};

/**
 * Send an AJAX request to update onboarding progress
 *
 * Returns a promise so callers can wait for persistence before
 * triggering page navigation. keepalive lets the request survive the
 * page reloads the tour choreographs (the issue screen reloads
 * itself shortly after a successful issue).
 *
 * @param {string} actionType The action type (start, next, prev, skip, complete, reset).
 * @param {Object} extra      Additional form data fields.
 * @return {Promise} Fetch promise.
 */
const sendProgress = ( actionType, extra = {} ) => {
	const data = getData();
	const formData = new FormData();
	formData.append( 'action', 'ppcert_onboarding_progress' );
	formData.append( 'nonce', data.nonce || '' );
	formData.append( 'action_type', actionType );

	Object.entries( extra ).forEach( ( [ key, value ] ) => {
		formData.append( key, value );
	} );

	return fetch( data.ajaxUrl || '', {
		method: 'POST',
		credentials: 'same-origin',
		keepalive: true,
		body: formData,
	} ).catch( () => {
		// Silently fail — progress is best-effort.
	} );
};

/**
 * useOnboarding hook
 *
 * @return {Object} Onboarding state and actions.
 */
const useOnboarding = () => {
	const data = getData();
	const initialState = data.state || {};

	const [ isLoading, setIsLoading ] = useState( false );
	const [ isActive, setIsActive ] = useState( initialState.should_show );
	const [ currentStep, setCurrentStep ] = useState(
		initialState.current_step || 1
	);
	const totalSteps = getTotalSteps();

	/**
	 * Start the guided build (transition from welcome modal to step 2)
	 *
	 * Navigates to the starter gallery.
	 */
	const startTour = useCallback( () => {
		// Hide the tour UI until the gallery page takes over — rendering
		// step 2 on the current page would flash the floating fallback.
		setIsLoading( true );

		// A fresh run must never target a previous run's records.
		clearSetupSession();

		// Persist the landing step BEFORE navigating: 'start' alone
		// stores step 1, which would re-show the welcome modal on the
		// gallery page.
		sendProgress( 'start', { step: 2 } ).then( () => {
			const url = getStepUrl( 2 );

			if ( url ) {
				window.location.href = url;
			}
		} );
	}, [] );

	/**
	 * Go to the next step
	 */
	const nextStep = useCallback( () => {
		const next = currentStep + 1;

		if ( next > totalSteps ) {
			setCurrentStep( totalSteps );
			sendProgress( 'complete' );
			return;
		}

		setCurrentStep( next );

		const step = getStep( next );
		const needsNavigation =
			step &&
			step.type === STEP_TYPE.SPOTLIGHT &&
			! isOnCorrectPage( next );

		if ( needsNavigation ) {
			setIsLoading( true );
		}

		// Wait for AJAX before navigating to avoid a race condition.
		sendProgress( 'next', { step: next } ).then( () => {
			if ( needsNavigation ) {
				const stepUrl = getStepUrl( next );
				if ( stepUrl ) {
					window.location.href = stepUrl;
				}
			}
		} );
	}, [ currentStep, totalSteps ] );

	/**
	 * Go to the previous step
	 */
	const prevStep = useCallback( () => {
		const prev = Math.max( 1, currentStep - 1 );
		setCurrentStep( prev );

		const step = getStep( prev );
		const needsNavigation =
			step &&
			step.type === STEP_TYPE.SPOTLIGHT &&
			! isOnCorrectPage( prev );

		if ( needsNavigation ) {
			setIsLoading( true );
		}

		sendProgress( 'prev', { step: prev } ).then( () => {
			if ( needsNavigation ) {
				const stepUrl = getStepUrl( prev );
				if ( stepUrl ) {
					window.location.href = stepUrl;
				}
			}
		} );
	}, [ currentStep ] );

	/**
	 * Skip the tour
	 *
	 * @param {boolean} permanent Whether to permanently skip.
	 */
	const skipTour = useCallback( ( permanent = false ) => {
		setIsActive( false );
		sendProgress( 'skip', { permanent: permanent ? 'true' : 'false' } );
	}, [] );

	/**
	 * Complete the tour
	 */
	const completeTour = useCallback( () => {
		setIsActive( false );
		sendProgress( 'complete' );
	}, [] );

	/**
	 * Close the tour (temporary, for current session)
	 */
	const closeTour = useCallback( () => {
		setIsActive( false );
		sendProgress( 'skip', { permanent: 'false' } );
	}, [] );

	return {
		isLoading,
		isActive,
		currentStep,
		totalSteps,
		startTour,
		nextStep,
		prevStep,
		skipTour,
		completeTour,
		closeTour,
	};
};

export default useOnboarding;
