/**
 * CertificateModal component
 *
 * The tour's step 6 — the certificate moment: the freshly issued
 * credential with its PDF download and public verification links.
 * The credential travels via session storage across the Certificates
 * screen's post-issue reload.
 */

import { useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button } from 'antd';
import {
	SafetyCertificateOutlined,
	LeftOutlined,
	DownloadOutlined,
} from '@ant-design/icons';
import ProgressDots from './ProgressDots';
import { readIssuedCredential } from '../setupSession';

/**
 * Build a URL from a localized PPCERTCRED template.
 *
 * @param {string} template   URL template containing PPCERTCRED.
 * @param {string} credential Credential ID (stored form).
 * @return {string} URL, or empty when either part is missing.
 */
const fillUrl = ( template, credential ) => {
	if ( ! template || ! credential ) {
		return '';
	}
	return template.replace( 'PPCERTCRED', encodeURIComponent( credential ) );
};

/**
 * CertificateModal component.
 *
 * @param {Object}   props             Component props.
 * @param {string}   props.title       Modal title.
 * @param {string}   props.content     Modal body text.
 * @param {number}   props.currentStep Current 1-based tour step.
 * @param {number}   props.totalSteps  Total tour step count.
 * @param {Function} props.onNext      Next step handler.
 * @param {Function} props.onPrev      Back to the previous step.
 * @param {Function} props.onClose     Close handler.
 */
const CertificateModal = ( {
	title,
	content,
	currentStep,
	totalSteps,
	onNext,
	onPrev,
	onClose,
} ) => {
	const nextBtnRef = useRef( null );

	const data = window.ppcert_onboarding_data || {};
	const credential = readIssuedCredential();
	const pdfUrl = fillUrl( data.pdfUrlTemplate, credential );

	/**
	 * Focus the next button on mount and lock body scroll
	 */
	useEffect( () => {
		if ( nextBtnRef.current ) {
			nextBtnRef.current.focus();
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
				onClose();
			}
		};

		document.addEventListener( 'keydown', handleKeyDown );
		return () => document.removeEventListener( 'keydown', handleKeyDown );
	}, [ onClose ] );

	return (
		// eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions
		<div
			className="ppcert-onboarding-overlay"
			onKeyDown={ ( e ) => {
				if ( e.key === 'Escape' ) {
					onClose();
				}
			} }
			role="dialog"
			aria-modal="true"
			aria-labelledby="ppcert-certificate-title"
			tabIndex={ -1 }
		>
			<div className="ppcert-onboarding-modal ppcert-onboarding-modal--certificate">
				<div className="ppcert-onboarding-modal__icon ppcert-onboarding-modal__icon--success">
					<SafetyCertificateOutlined />
				</div>

				<h2
					className="ppcert-onboarding-modal__title"
					id="ppcert-certificate-title"
				>
					{ title }
				</h2>

				<p className="ppcert-onboarding-modal__content">{ content }</p>

				{ credential && (
					<div className="ppcert-onboarding-credential">
						<span className="ppcert-onboarding-credential__label">
							{ __(
								'Your credential ID',
								'pressprimer-certificate'
							) }
						</span>
						<span className="ppcert-onboarding-credential__value">
							{ credential }
						</span>
					</div>
				) }

				{ pdfUrl && (
					<div className="ppcert-onboarding-modal__links">
						<Button
							icon={ <DownloadOutlined /> }
							href={ pdfUrl }
							target="_blank"
						>
							{ __(
								'Download the PDF',
								'pressprimer-certificate'
							) }
						</Button>
					</div>
				) }

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
							ref={ nextBtnRef }
							type="primary"
							onClick={ onNext }
						>
							{ __( 'Next', 'pressprimer-certificate' ) }
						</Button>
					</div>
				</div>
			</div>
		</div>
	);
};

export default CertificateModal;
