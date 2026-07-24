/**
 * Email course card
 *
 * The quiet dashboard surface for the email ask: admins only,
 * dismissible via X (permanent, separate from declining), and
 * suppressed entirely once the user has answered anywhere.
 * Eligibility is resolved server-side.
 */

import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { CloseOutlined } from '@ant-design/icons';
import EmailOptinAsk from '../../shared/components/EmailOptinAsk';

/**
 * Email course card component.
 *
 * @param {Object} props       Component props.
 * @param {Object} props.optin Server-resolved opt-in data (eligible, privacyUrl).
 * @return {JSX.Element|null} Rendered component.
 */
const EmailCourseCard = ( { optin = {} } ) => {
	const [ hidden, setHidden ] = useState( false );

	if ( ! optin.eligible || hidden ) {
		return null;
	}

	/**
	 * Dismiss the card. Hides this surface permanently, but leaves
	 * the ask itself unanswered (other surfaces stay available).
	 */
	const handleDismiss = () => {
		setHidden( true );

		apiFetch( {
			path: '/ppcert/v1/email-optin',
			method: 'POST',
			data: {
				decision: 'dismiss',
				source: 'dashboard-card',
			},
		} ).catch( () => {
			// Best-effort: the card is already hidden for this view.
		} );
	};

	return (
		<div className="ppcert-dashboard-card ppcert-dashboard-email-card">
			<button
				type="button"
				className="ppcert-dashboard-email-card__dismiss"
				onClick={ handleDismiss }
				aria-label={ __( 'Dismiss', 'pressprimer-certificate' ) }
			>
				<CloseOutlined />
			</button>

			<EmailOptinAsk
				source="dashboard-card"
				privacyUrl={ optin.privacyUrl || '' }
			/>
		</div>
	);
};

export default EmailCourseCard;
