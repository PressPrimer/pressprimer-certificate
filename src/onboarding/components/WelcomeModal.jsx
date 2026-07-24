/**
 * WelcomeModal component
 *
 * First step of the guided build — pitches the five-minute path to a
 * first certificate, with options to skip or permanently dismiss.
 * (No template picker here: the real starter gallery IS step 2.)
 */

import { useState, useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Checkbox } from 'antd';

/**
 * WelcomeModal component.
 *
 * @param {Object}   props         Component props.
 * @param {string}   props.title   Modal title.
 * @param {string}   props.content Modal body text.
 * @param {Function} props.onStart Start handler.
 * @param {Function} props.onSkip  Skip handler (receives permanent flag).
 */
const WelcomeModal = ( { title, content, onStart, onSkip } ) => {
	const [ dontShowAgain, setDontShowAgain ] = useState( false );

	const data = window.ppcert_onboarding_data || {};
	const startBtnRef = useRef( null );

	const logoUrl = data.pluginUrl
		? data.pluginUrl + 'assets/images/PressPrimer-Logo.svg'
		: '';

	/**
	 * Focus the start button on mount and lock body scroll
	 */
	useEffect( () => {
		if ( startBtnRef.current ) {
			startBtnRef.current.focus();
		}

		document.body.style.overflow = 'hidden';

		return () => {
			document.body.style.overflow = '';
		};
	}, [] );

	/**
	 * Handle escape key
	 */
	useEffect( () => {
		const handleKeyDown = ( e ) => {
			if ( e.key === 'Escape' ) {
				onSkip( dontShowAgain );
			}
		};

		document.addEventListener( 'keydown', handleKeyDown );
		return () => document.removeEventListener( 'keydown', handleKeyDown );
	}, [ onSkip, dontShowAgain ] );

	return (
		// eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions
		<div
			className="ppcert-onboarding-overlay"
			onClick={ ( e ) => {
				if ( e.target === e.currentTarget ) {
					onSkip( dontShowAgain );
				}
			} }
			onKeyDown={ ( e ) => {
				if ( e.key === 'Escape' ) {
					onSkip( dontShowAgain );
				}
			} }
			role="dialog"
			aria-modal="true"
			aria-labelledby="ppcert-welcome-title"
			tabIndex={ -1 }
		>
			<div className="ppcert-onboarding-modal ppcert-onboarding-modal--welcome">
				{ logoUrl && (
					<div className="ppcert-onboarding-modal__logo">
						<img
							src={ logoUrl }
							alt="PressPrimer"
							className="ppcert-onboarding-modal__logo-img"
						/>
					</div>
				) }

				<h2
					className="ppcert-onboarding-modal__title"
					id="ppcert-welcome-title"
				>
					{ title }
				</h2>

				<p className="ppcert-onboarding-modal__content">{ content }</p>

				<div className="ppcert-onboarding-modal__actions">
					<Button
						ref={ startBtnRef }
						type="primary"
						size="large"
						className="ppcert-onboarding-modal__start-btn"
						onClick={ () => onStart() }
					>
						{ __( "Let's Go!", 'pressprimer-certificate' ) }
					</Button>

					<Button
						type="text"
						className="ppcert-onboarding-modal__skip-btn"
						onClick={ () => onSkip( dontShowAgain ) }
					>
						{ __( 'Skip Tour', 'pressprimer-certificate' ) }
					</Button>

					<Checkbox
						className="ppcert-onboarding-modal__checkbox"
						checked={ dontShowAgain }
						onChange={ ( e ) =>
							setDontShowAgain( e.target.checked )
						}
					>
						{ __(
							"Don't show this again",
							'pressprimer-certificate'
						) }
					</Checkbox>
				</div>
			</div>
		</div>
	);
};

export default WelcomeModal;
