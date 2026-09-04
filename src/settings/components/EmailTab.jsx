/**
 * Email Tab Component
 *
 * The issuance email (Feature 008 FR-004): toggle, sender, and subject
 * and body pre-filled with the shipping defaults. The token list
 * follows the PressPrimer Quiz pattern - a vertical click-to-copy list
 * with descriptions. The PDF always attaches (no size threshold -
 * Ryan, 2026-07-23).
 *
 * @package
 * @since 1.0.0
 */

import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Button, Form, Input, message, Space, Switch, Typography } from 'antd';
import { SendOutlined } from '@ant-design/icons';

const { Title, Paragraph, Text } = Typography;

/**
 * Copy text to the clipboard with an execCommand fallback.
 *
 * @param {string} text Text to copy.
 * @return {Promise} Resolves when copied.
 */
const copyToClipboard = ( text ) => {
	if ( window.navigator.clipboard ) {
		return window.navigator.clipboard.writeText( text );
	}

	return new Promise( ( resolve, reject ) => {
		const textArea = document.createElement( 'textarea' );
		textArea.value = text;
		textArea.style.position = 'fixed';
		textArea.style.left = '-9999px';
		document.body.appendChild( textArea );
		textArea.select();

		try {
			document.execCommand( 'copy' );
			resolve();
		} catch ( err ) {
			reject( err );
		} finally {
			document.body.removeChild( textArea );
		}
	} );
};

/**
 * Token item with click-to-copy (the Quiz house pattern).
 *
 * @param {Object} props             Component props.
 * @param {string} props.token       Token literal.
 * @param {string} props.description What it substitutes.
 */
const TokenItem = ( { token, description } ) => {
	const handleCopy = async () => {
		try {
			await copyToClipboard( token );
			message.success( __( 'Copied!', 'pressprimer-certificate' ) );
		} catch ( err ) {
			message.error( __( 'Failed to copy', 'pressprimer-certificate' ) );
		}
	};

	return (
		<Paragraph style={ { marginBottom: 4 } }>
			<Text
				code
				onClick={ handleCopy }
				style={ { cursor: 'pointer' } }
				title={ __( 'Click to copy', 'pressprimer-certificate' ) }
			>
				{ token }
			</Text>{ ' ' }
			- { description }
		</Paragraph>
	);
};

/**
 * Email Tab
 *
 * @param {Object}   props               Component props.
 * @param {Object}   props.settings      Current settings.
 * @param {Function} props.updateSetting Update a setting.
 * @param {Object}   props.settingsData  Full localized data.
 */
const EmailTab = ( { settings, updateSetting, settingsData } ) => {
	const defaults = settingsData.emailDefaults || {};
	const enabled =
		settings.email_issued_enabled === undefined ||
		!! Number( settings.email_issued_enabled );

	const [ testing, setTesting ] = useState( false );

	const sendTest = async () => {
		setTesting( true );

		try {
			const response = await apiFetch( {
				path: '/ppcert/v1/settings/test-email',
				method: 'POST',
			} );
			message.success( response.message );
		} catch ( error ) {
			message.error(
				error.message ||
					__(
						'The test email could not be sent.',
						'pressprimer-certificate'
					)
			);
		} finally {
			setTesting( false );
		}
	};

	return (
		<div>
			<div className="ppcert-settings-section">
				<Title level={ 4 } className="ppcert-settings-section-title">
					{ __( 'Issuance Email', 'pressprimer-certificate' ) }
				</Title>
				<Paragraph className="ppcert-settings-section-description">
					{ __(
						'Sent to the recipient when a certificate is issued, with the certificate PDF attached and a verification link in the body.',
						'pressprimer-certificate'
					) }
				</Paragraph>

				<div className="ppcert-settings-field">
					<Form.Item
						label={ __(
							'Send issuance email',
							'pressprimer-certificate'
						) }
					>
						<Switch
							checked={ enabled }
							onChange={ ( checked ) =>
								updateSetting(
									'email_issued_enabled',
									checked ? 1 : 0
								)
							}
						/>
					</Form.Item>
				</div>

				<div className="ppcert-settings-field">
					<Form.Item
						label={ __( 'From name', 'pressprimer-certificate' ) }
					>
						<Input
							style={ { maxWidth: 420 } }
							value={ settings.email_from_name || '' }
							onChange={ ( event ) =>
								updateSetting(
									'email_from_name',
									event.target.value
								)
							}
						/>
					</Form.Item>
				</div>

				<div className="ppcert-settings-field">
					<Form.Item
						label={ __(
							'From address',
							'pressprimer-certificate'
						) }
					>
						<Input
							style={ { maxWidth: 420 } }
							type="email"
							value={ settings.email_from_address || '' }
							onChange={ ( event ) =>
								updateSetting(
									'email_from_address',
									event.target.value
								)
							}
						/>
					</Form.Item>
				</div>

				<div className="ppcert-settings-field">
					<Form.Item
						label={ __( 'Subject', 'pressprimer-certificate' ) }
					>
						<Input
							value={
								settings.email_issued_subject ??
								defaults.subject ??
								''
							}
							onChange={ ( event ) =>
								updateSetting(
									'email_issued_subject',
									event.target.value
								)
							}
						/>
					</Form.Item>
				</div>

				<div className="ppcert-settings-field">
					<Form.Item
						label={ __( 'Body', 'pressprimer-certificate' ) }
					>
						<Input.TextArea
							rows={ 8 }
							value={
								settings.email_issued_body ??
								defaults.body ??
								''
							}
							onChange={ ( event ) =>
								updateSetting(
									'email_issued_body',
									event.target.value
								)
							}
						/>
					</Form.Item>
				</div>

				<div className="ppcert-token-list">
					<Text strong>
						{ __( 'Available Tokens:', 'pressprimer-certificate' ) }
					</Text>
					<Text type="secondary" style={ { marginLeft: 8 } }>
						{ __( '(click to copy)', 'pressprimer-certificate' ) }
					</Text>
					<div style={ { marginTop: 8 } }>
						<TokenItem
							token="{recipient_name}"
							description={ __(
								"The recipient's name as it appears on the certificate",
								'pressprimer-certificate'
							) }
						/>
						<TokenItem
							token="{subject}"
							description={ __(
								'The certificate title (template name)',
								'pressprimer-certificate'
							) }
						/>
						<TokenItem
							token="{credential_id}"
							description={ __(
								'The formatted credential ID, e.g. 7Q4M-K9P2-XT3A',
								'pressprimer-certificate'
							) }
						/>
						<TokenItem
							token="{verification_url}"
							description={ __(
								'Link to verify the certificate on the public verification page',
								'pressprimer-certificate'
							) }
						/>
						<TokenItem
							token="{issuer_name}"
							description={ __(
								'The issuing site or organization name',
								'pressprimer-certificate'
							) }
						/>
						<TokenItem
							token="{site_name}"
							description={ __(
								'The site title from Settings → General',
								'pressprimer-certificate'
							) }
						/>
					</div>
					<div style={ { marginTop: 16 } }>
						<Text strong>
							{ __(
								'Certificate merge fields:',
								'pressprimer-certificate'
							) }
						</Text>
						<Paragraph
							type="secondary"
							style={ { marginTop: 4, marginBottom: 8 } }
						>
							{ __(
								'Any merge field from the certificate designer works here too, wrapped in double braces. The email uses the same values printed on the certificate. A few examples:',
								'pressprimer-certificate'
							) }
						</Paragraph>
						<TokenItem
							token="{{source.course_title}}"
							description={ __(
								'The course that earned the certificate (quiz and assignment fields work the same way)',
								'pressprimer-certificate'
							) }
						/>
						<TokenItem
							token="{{recipient.first_name}}"
							description={ __(
								"The recipient's first name",
								'pressprimer-certificate'
							) }
						/>
						<TokenItem
							token="{{certificate.expiry_date}}"
							description={ __(
								'When the certificate expires, blank when it never does',
								'pressprimer-certificate'
							) }
						/>
					</div>
				</div>

				<div className="ppcert-settings-field">
					<Space align="center">
						<Button
							icon={ <SendOutlined /> }
							onClick={ sendTest }
							loading={ testing }
						>
							{ __(
								'Send Test Email',
								'pressprimer-certificate'
							) }
						</Button>
						<Text type="secondary">
							{ __(
								'Sends this email to your own address with sample values. The test uses the last saved settings.',
								'pressprimer-certificate'
							) }
						</Text>
					</Space>
				</div>
			</div>

			{ /* Addon extension slot (2.0, Feature 2.0-006 applied to
			     settings): addon email sections (Educator's E-005
			     reminder email) mount their own React root here;
			     :empty CSS keeps it invisible until an extension does. */ }
			<div
				id="ppcert-settings-email-extension"
				className="ppcert-settings-email-extension"
			/>
		</div>
	);
};

export default EmailTab;
