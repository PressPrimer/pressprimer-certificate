/**
 * CompletionModal component
 *
 * The tour's finish stop.
 */

import { useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button } from 'antd';
import { CheckCircleOutlined, LeftOutlined } from '@ant-design/icons';
import EmailAskSlot from './EmailAskSlot';
import ProgressDots from './ProgressDots';

/**
 * CompletionModal component.
 *
 * @param {Object}   props             Component props.
 * @param {string}   props.title       Modal title.
 * @param {string}   props.content     Modal body text.
 * @param {number}   props.currentStep Current 1-based tour step.
 * @param {number}   props.totalSteps  Total tour step count.
 * @param {Function} props.onComplete  Complete tour handler.
 * @param {Function} props.onPrev      Back to the previous step.
 */
const CompletionModal = ( {
	title,
	content,
	currentStep,
	totalSteps,
	onComplete,
	onPrev,
} ) => {
	const completeBtnRef = useRef( null );

	/**
	 * Focus the complete button on mount and lock body scroll
	 */
	useEffect( () => {
		if ( completeBtnRef.current ) {
			completeBtnRef.current.focus();
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
				onComplete();
			}
		};

		document.addEventListener( 'keydown', handleKeyDown );
		return () => document.removeEventListener( 'keydown', handleKeyDown );
	}, [ onComplete ] );

	return (
		// eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions
		<div
			className="ppcert-onboarding-overlay"
			onKeyDown={ ( e ) => {
				if ( e.key === 'Escape' ) {
					onComplete();
				}
			} }
			role="dialog"
			aria-modal="true"
			aria-labelledby="ppcert-complete-title"
			tabIndex={ -1 }
		>
			<div className="ppcert-onboarding-modal ppcert-onboarding-modal--complete">
				<div className="ppcert-onboarding-modal__icon ppcert-onboarding-modal__icon--success">
					<CheckCircleOutlined />
				</div>

				<h2
					className="ppcert-onboarding-modal__title"
					id="ppcert-complete-title"
				>
					{ title }
				</h2>

				<p className="ppcert-onboarding-modal__content">{ content }</p>

				{ /* The email ask, eligibility resolved server-side. */ }
				<EmailAskSlot />

				<div className="ppcert-onboarding-modal__nav">
					<div className="ppcert-onboarding-modal__nav-left">
						{ onPrev && (
							<Button
								type="text"
								icon={ <LeftOutlined /> }
								className="ppcert-onboarding-modal__skip-btn"
								onClick={ onPrev }
							>
								{ __( 'Back', 'pressprimer-certificate' ) }
							</Button>
						) }
					</div>
					<div className="ppcert-onboarding-modal__nav-center">
						<ProgressDots
							currentStep={ currentStep }
							totalSteps={ totalSteps }
						/>
					</div>
					<div className="ppcert-onboarding-modal__nav-right">
						<Button
							ref={ completeBtnRef }
							type="primary"
							className="ppcert-onboarding-modal__complete-btn"
							onClick={ onComplete }
						>
							{ __( 'Close Tour', 'pressprimer-certificate' ) }
						</Button>
					</div>
				</div>
			</div>
		</div>
	);
};

export default CompletionModal;
