/**
 * Onboarding component
 *
 * Main orchestrator: renders the correct component for the current
 * step (modal or spotlight), listens for the designer and issue
 * bridges (template created/saved, certificate issued) to auto-advance
 * the action stops, and handles page navigation between screens.
 *
 * Follows the Quiz/Assignment Onboarding pattern.
 */

import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import useOnboarding from '../hooks/useOnboarding';
import { getStep, isOnCorrectPage, STEP_TYPE } from '../tourSteps';
import {
	readSavedTemplate,
	writeSavedTemplate,
	writeIssuedCredential,
} from '../setupSession';
import WelcomeModal from './WelcomeModal';
import CertificateModal from './CertificateModal';
import CompletionModal from './CompletionModal';
import SpotlightTooltip from './SpotlightTooltip';

/**
 * Find a valid CSS selector from a comma-separated list.
 *
 * @param {string} selector Primary selector (comma-separated).
 * @return {string|null} First matching selector or null.
 */
const findValidSelector = ( selector ) => {
	if ( ! selector ) {
		return null;
	}

	const selectors = selector.split( ',' ).map( ( s ) => s.trim() );
	for ( const sel of selectors ) {
		if ( document.querySelector( sel ) ) {
			return sel;
		}
	}

	return null;
};

/**
 * Onboarding component.
 */
const Onboarding = () => {
	const {
		isActive,
		isLoading,
		currentStep,
		totalSteps,
		startTour,
		nextStep,
		prevStep,
		skipTour,
		completeTour,
		closeTour,
	} = useOnboarding();

	// undefined = still resolving (render nothing yet),
	// null      = given up (render the floating fallback),
	// string    = found.
	const [ resolvedSelector, setResolvedSelector ] = useState( undefined );

	// The template created/saved during the tour — the design and
	// publish stops gate their Next buttons on it.
	const [ savedTemplate, setSavedTemplate ] = useState( readSavedTemplate );

	// Whether the screen's own Ant Design modal is open (the issue
	// stop's target opens one, and it renders below the tour overlay).
	const [ foreignModalOpen, setForeignModalOpen ] = useState( false );

	// The Certificates screen reloads itself shortly after an issue
	// succeeds; the tour stays hidden until then so the certificate
	// stop renders once, after the reload, instead of flashing twice.
	const [ awaitingReload, setAwaitingReload ] = useState( false );

	const step = getStep( currentStep );

	/**
	 * Step aside while the issue modal is open
	 *
	 * The issue stop points at a button that opens the Certificates
	 * screen's own modal, which stacks below the tour overlay — the
	 * tour hides while it is open. Cancel brings the spotlight back;
	 * issuing advances the step via the issued bridge.
	 */
	useEffect( () => {
		if ( step?.id !== 'issue' ) {
			setForeignModalOpen( false );
			return;
		}

		const check = () => {
			const wrap = document.querySelector( '.ant-modal-wrap' );
			setForeignModalOpen( !! wrap && wrap.style.display !== 'none' );
		};

		const interval = setInterval( check, 300 );
		check();

		return () => clearInterval( interval );
	}, [ step ] );

	/**
	 * Listen for the designer bridges
	 *
	 * ppcert:template-created — fires when the gallery creates the
	 * draft; remembers it and advances the pick-design stop.
	 * ppcert:template-saved — fires on every designer save; a
	 * published save advances the publish stop.
	 */
	useEffect( () => {
		const onCreated = ( event ) => {
			const { id, status } = event.detail || {};

			if ( id ) {
				setSavedTemplate( { id, status: status || 'draft' } );
				writeSavedTemplate( id, status || 'draft' );
			}

			if ( isActive && step?.id === 'pick-design' ) {
				nextStep();
			}
		};

		const onSaved = ( event ) => {
			const { id, status } = event.detail || {};

			if ( id ) {
				setSavedTemplate( { id, status: status || null } );
				writeSavedTemplate( id, status );
			}

			if (
				isActive &&
				step?.id === 'publish' &&
				status === 'published'
			) {
				nextStep();
			}
		};

		window.addEventListener( 'ppcert:template-created', onCreated );
		window.addEventListener( 'ppcert:template-saved', onSaved );
		return () => {
			window.removeEventListener( 'ppcert:template-created', onCreated );
			window.removeEventListener( 'ppcert:template-saved', onSaved );
		};
	}, [ isActive, step, nextStep ] );

	/**
	 * Listen for the issue bridge
	 *
	 * The Certificates screen reloads itself shortly after a
	 * successful issue; the credential goes to session storage and the
	 * step advances (keepalive AJAX) so the reload lands on the
	 * certificate stop.
	 */
	useEffect( () => {
		const onIssued = ( event ) => {
			const { credential_id: credentialId } = event.detail || {};

			if ( credentialId ) {
				writeIssuedCredential( credentialId );
			}

			if ( isActive && step?.id === 'issue' ) {
				setAwaitingReload( true );

				// Safety valve: if the reload never comes, show the
				// tour again rather than leaving it hidden.
				setTimeout( () => setAwaitingReload( false ), 4000 );

				nextStep();
			}
		};

		window.addEventListener( 'ppcert:certificate-issued', onIssued );
		return () =>
			window.removeEventListener( 'ppcert:certificate-issued', onIssued );
	}, [ isActive, step, nextStep ] );

	/**
	 * Resolve selector for spotlight steps
	 *
	 * Polls until the target exists: the React admin screens mount
	 * asynchronously, so a single early check races the page and falls
	 * back to highlighting the whole container.
	 */
	useEffect( () => {
		if (
			! step ||
			step.type !== STEP_TYPE.SPOTLIGHT ||
			! isOnCorrectPage( currentStep )
		) {
			setResolvedSelector( undefined );
			return;
		}

		setResolvedSelector( undefined );

		const startedAt = Date.now();
		let poll = null;

		const tryResolve = () => {
			const found = findValidSelector( step.selector );

			if ( found ) {
				setResolvedSelector( found );
				if ( poll ) {
					clearInterval( poll );
					poll = null;
				}
				return;
			}

			// Primary target never appeared — settle for the fallback
			// container, or the floating tooltip if even that is gone.
			if ( Date.now() - startedAt > 4000 ) {
				setResolvedSelector(
					step.fallbackSelector &&
						document.querySelector( step.fallbackSelector )
						? step.fallbackSelector
						: null
				);
				if ( poll ) {
					clearInterval( poll );
					poll = null;
				}
			}
		};

		poll = setInterval( tryResolve, 200 );
		tryResolve();

		return () => {
			if ( poll ) {
				clearInterval( poll );
			}
		};
	}, [ step, currentStep ] );

	if ( ! isActive || ! step || isLoading || awaitingReload ) {
		return null;
	}

	// Welcome modal (step 1).
	if ( step.id === 'welcome' ) {
		return (
			<WelcomeModal
				title={ step.title }
				content={ step.content }
				onStart={ startTour }
				onSkip={ skipTour }
			/>
		);
	}

	// The certificate moment (step 6).
	if ( step.id === 'certificate' ) {
		return (
			<CertificateModal
				title={ step.title }
				content={ step.content }
				currentStep={ currentStep }
				totalSteps={ totalSteps }
				onNext={ nextStep }
				onPrev={ prevStep }
				onClose={ closeTour }
			/>
		);
	}

	// Completion modal (last step).
	if ( step.id === 'complete' ) {
		return (
			<CompletionModal
				title={ step.title }
				content={ step.content }
				currentStep={ currentStep }
				totalSteps={ totalSteps }
				onComplete={ completeTour }
				onPrev={ prevStep }
			/>
		);
	}

	// Spotlight steps.
	if ( step.type === STEP_TYPE.SPOTLIGHT ) {
		// A mid-tour step renders ONLY on the screen it belongs to.
		// On any other admin page the tour stays out of the way; it
		// resumes when the user returns (or replays from the
		// dashboard).
		if ( ! isOnCorrectPage( currentStep ) ) {
			return null;
		}

		// The screen's own modal is up (issuing in progress) — the tour
		// steps aside until it closes or the issue succeeds.
		if ( foreignModalOpen ) {
			return null;
		}

		// Still waiting for the target to mount — render nothing rather
		// than flashing the floating fallback.
		if ( resolvedSelector === undefined ) {
			return null;
		}

		// If no valid selector found, show a floating tooltip.
		if ( ! resolvedSelector ) {
			return (
				<div className="ppcert-onboarding-floating">
					<div className="ppcert-onboarding-floating__content">
						<button
							type="button"
							className="ppcert-onboarding-floating__close"
							onClick={ closeTour }
							aria-label={ __(
								'Close',
								'pressprimer-certificate'
							) }
						>
							&times;
						</button>
						<h4 className="ppcert-onboarding-floating__title">
							{ step.title }
						</h4>
						<p className="ppcert-onboarding-floating__text">
							{ step.content }
						</p>
						<div className="ppcert-onboarding-floating__nav">
							{ currentStep > 1 && (
								<button
									type="button"
									className="ppcert-onboarding-floating__btn ppcert-onboarding-floating__btn--back"
									onClick={ prevStep }
								>
									{ __( 'Back', 'pressprimer-certificate' ) }
								</button>
							) }
							<span className="ppcert-onboarding-floating__step">
								{ currentStep } / { totalSteps }
							</span>
							<button
								type="button"
								className="ppcert-onboarding-floating__btn ppcert-onboarding-floating__btn--next"
								onClick={ nextStep }
							>
								{ currentStep === totalSteps
									? __( 'Finish', 'pressprimer-certificate' )
									: __( 'Next', 'pressprimer-certificate' ) }
							</button>
						</div>
					</div>
				</div>
			);
		}

		// Action-stop gates: users advance by doing the real thing, not
		// by clicking past it — otherwise the next stop has nothing to
		// point at and they get lost.
		let nextDisabled = false;
		let nextDisabledReason = null;

		if ( step.id === 'pick-design' && ! savedTemplate.id ) {
			nextDisabled = true;
			nextDisabledReason = __(
				'Pick a design (or start blank) to continue.',
				'pressprimer-certificate'
			);
		}

		if ( step.id === 'publish' && savedTemplate.status !== 'published' ) {
			nextDisabled = true;
			nextDisabledReason = __(
				'Click Publish to continue.',
				'pressprimer-certificate'
			);
		}

		return (
			<SpotlightTooltip
				selector={ resolvedSelector }
				title={ step.title }
				content={ step.content }
				position={ step.position }
				fitContent={ !! step.fitContent }
				nextDisabled={ nextDisabled }
				nextDisabledReason={ nextDisabledReason }
				currentStep={ currentStep }
				totalSteps={ totalSteps }
				onPrev={ currentStep > 1 ? prevStep : null }
				onNext={ nextStep }
				onSkip={ closeTour }
				onClose={ closeTour }
			/>
		);
	}

	return null;
};

export default Onboarding;
