/**
 * EmailOptinAsk component
 *
 * The plugin's single email ask (the free onboarding email course),
 * shared by every surface: the tour's finish stop and the dashboard
 * card. The hard rules, matching the Assignment opt-in:
 *
 * - The email field is NEVER pre-filled.
 * - Nothing is sent until the user clicks the button with a typed,
 *   valid email.
 * - The disclosure states exactly what is collected (the email
 *   address and nothing else) and links the privacy policy.
 * - No decline button: each surface provides its own quiet exit
 *   (closing the tour, dismissing the card).
 */

import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Input, Button } from 'antd';
import { CheckCircleOutlined } from '@ant-design/icons';
import './EmailOptinAsk.css';

/**
 * EmailOptinAsk component.
 *
 * @param {Object}   props            Component props.
 * @param {string}   props.source     Surface tag (wizard | dashboard-card).
 * @param {string}   props.privacyUrl Privacy policy URL.
 * @param {Function} props.onAnswered Called with 'opted_in' after an opt-in is recorded.
 */
const EmailOptinAsk = ( { source, privacyUrl, onAnswered } ) => {
	const [ email, setEmail ] = useState( '' );
	const [ submitting, setSubmitting ] = useState( false );
	const [ done, setDone ] = useState( false );
	const [ error, setError ] = useState( null );

	/**
	 * Submit the opt-in
	 */
	const handleSubmit = async () => {
		setSubmitting( true );
		setError( null );

		try {
			await apiFetch( {
				path: '/ppcert/v1/email-optin',
				method: 'POST',
				data: {
					decision: 'opt_in',
					source,
					email,
				},
			} );

			setDone( true );

			if ( onAnswered ) {
				onAnswered( 'opted_in' );
			}
		} catch ( err ) {
			setError(
				err.message ||
					__( 'Something went wrong.', 'pressprimer-certificate' )
			);
		} finally {
			setSubmitting( false );
		}
	};

	// Opted in: the confirmation replaces the form. Subscription
	// starts immediately; every email carries an unsubscribe link.
	if ( done ) {
		return (
			<div className="ppcert-email-ask ppcert-email-ask--done">
				<CheckCircleOutlined className="ppcert-email-ask__done-icon" />
				<p className="ppcert-email-ask__done-text">
					{ __(
						"You're in! Your first email is on its way.",
						'pressprimer-certificate'
					) }
				</p>
			</div>
		);
	}

	return (
		<div className="ppcert-email-ask">
			<h3 className="ppcert-email-ask__headline">
				{ __(
					'Get more out of your certificates',
					'pressprimer-certificate'
				) }
			</h3>

			<p className="ppcert-email-ask__body">
				{ __(
					"Sign up below and we'll send you a free 5-part email course on building a great certificate program, followed by occasional tips and product updates. Unsubscribe anytime.",
					'pressprimer-certificate'
				) }
			</p>

			<div className="ppcert-email-ask__field-row">
				<Input
					type="email"
					placeholder={ __(
						'you@example.com',
						'pressprimer-certificate'
					) }
					value={ email }
					onChange={ ( e ) => setEmail( e.target.value ) }
					onPressEnter={ handleSubmit }
					aria-label={ __(
						'Email address',
						'pressprimer-certificate'
					) }
				/>
				<Button
					type="primary"
					loading={ submitting }
					onClick={ handleSubmit }
				>
					{ __( 'Sign me up!', 'pressprimer-certificate' ) }
				</Button>
			</div>

			{ error && <p className="ppcert-email-ask__error">{ error }</p> }

			<p className="ppcert-email-ask__disclosure">
				{ __(
					'We only collect your email address.',
					'pressprimer-certificate'
				) }{ ' ' }
				{ privacyUrl && (
					<a
						href={ privacyUrl }
						target="_blank"
						rel="noopener noreferrer"
					>
						{ __( 'Privacy policy', 'pressprimer-certificate' ) }
					</a>
				) }
			</p>
		</div>
	);
};

export default EmailOptinAsk;
