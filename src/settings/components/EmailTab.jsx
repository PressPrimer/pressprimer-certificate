/**
 * Email Tab Component
 *
 * The issuance email (Feature 008 FR-004): toggle, sender, and subject
 * and body pre-filled with the shipping defaults. The token list
 * follows the PressPrimer Quiz pattern - a vertical click-to-copy list
 * with descriptions. The PDF always attaches (no size threshold -
 * Ryan, 2026-07-23).
 *
 * Since 2.1 (Feature 2.1-001) the tab mirrors PressPrimer Assignment's
 * Email settings: an Email logo, From name and address, the body in
 * Assignment's rich-text editor, and a test email with a Send to
 * address. Labels keep Certificate's sentence case; help text is
 * Assignment's.
 *
 * @package
 * @since 1.0.0
 */

import { useCallback, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Button, Form, Input, message, Space, Switch, Typography } from 'antd';
import {
	DeleteOutlined,
	PictureOutlined,
	SendOutlined,
} from '@ant-design/icons';
import UpsellPrompt from '../../shared/components/UpsellPrompt';
import RichTextEditor from '../../shared/components/RichTextEditor';
import { toEditorHtml } from '../../shared/email-body';
import useAttachmentUrl from '../useAttachmentUrl';

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
 * @param {boolean}  props.hasChanges    Whether the page has unsaved changes.
 * @param {Function} props.onSave        Save every tab's settings; resolves true on success.
 * @param {Object}   props.fieldErrors   Server field errors from the last save ({ key: message }).
 */
const EmailTab = ( {
	settings,
	updateSetting,
	settingsData,
	hasChanges = false,
	onSave = null,
	fieldErrors = {},
} ) => {
	const defaults = settingsData.emailDefaults || {};
	const placeholders = settingsData.emailPlaceholders || {};
	// Premium touchpoints for this surface, resolved server-side by the
	// touchpoint registry (empty for non-admins or when the tier is active).
	const touchpoints = settingsData.touchpoints || {};
	const enabled =
		settings.email_issued_enabled === undefined ||
		!! Number( settings.email_issued_enabled );

	const [ testing, setTesting ] = useState( false );
	const [ testTo, setTestTo ] = useState(
		placeholders.currentUserEmail || ''
	);
	const [ logoUrl, setLogoUrl ] = useState( '' );

	const logoId = Number( settings.email_logo_id ) || 0;
	const bodyHtml = toEditorHtml(
		settings.email_issued_body ?? defaults.body ?? ''
	);

	useAttachmentUrl( logoId, setLogoUrl );

	/**
	 * Open the media library to choose the Email logo (Assignment's
	 * modal: images only, one selection).
	 */
	const selectLogo = useCallback( () => {
		if ( ! window.wp || ! window.wp.media ) {
			return;
		}

		const frame = window.wp.media( {
			title: __( 'Select Email Logo', 'pressprimer-certificate' ),
			button: {
				text: __( 'Use this image', 'pressprimer-certificate' ),
			},
			multiple: false,
			library: { type: 'image' },
		} );

		frame.on( 'select', () => {
			const attachment = frame
				.state()
				.get( 'selection' )
				.first()
				.toJSON();

			setLogoUrl( attachment.url || '' );
			updateSetting( 'email_logo_id', attachment.id );
		} );

		frame.open();
	}, [ updateSetting ] );

	const sendTest = async () => {
		if ( ! testTo || ! testTo.includes( '@' ) ) {
			message.error(
				__(
					'Enter a valid email address to send the test to.',
					'pressprimer-certificate'
				)
			);
			return;
		}

		setTesting( true );

		try {
			// The test sends the SAVED settings, so unsaved edits on this
			// page are saved first (Ryan, 2026-09-17); a failed save
			// already toasted its own error, so stop quietly.
			if ( hasChanges && onSave ) {
				const saved = await onSave();

				if ( ! saved ) {
					return;
				}
			}

			const response = await apiFetch( {
				path: '/ppcert/v1/settings/test-email',
				method: 'POST',
				data: { to: testTo.trim() },
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
					{ __( 'Email Settings', 'pressprimer-certificate' ) }
				</Title>
				<Paragraph className="ppcert-settings-section-description">
					{ __(
						'Configure the emails sent by the plugin.',
						'pressprimer-certificate'
					) }
				</Paragraph>

				<div className="ppcert-settings-field">
					<Form.Item
						label={ __( 'Email logo', 'pressprimer-certificate' ) }
						help={ __(
							'Logo displayed at the top of emails. Max width 400px, max height 150px.',
							'pressprimer-certificate'
						) }
					>
						{ logoId > 0 ? (
							<div className="ppcert-email-logo">
								<div className="ppcert-email-logo__preview">
									{ logoUrl && (
										<img
											src={ logoUrl }
											alt={ __(
												'Email logo',
												'pressprimer-certificate'
											) }
										/>
									) }
								</div>
								<Button
									icon={ <DeleteOutlined /> }
									onClick={ () => {
										setLogoUrl( '' );
										updateSetting( 'email_logo_id', 0 );
									} }
									danger
								>
									{ __(
										'Remove',
										'pressprimer-certificate'
									) }
								</Button>
							</div>
						) : (
							<Button
								icon={ <PictureOutlined /> }
								onClick={ selectLogo }
							>
								{ __(
									'Select Logo',
									'pressprimer-certificate'
								) }
							</Button>
						) }
					</Form.Item>
				</div>

				<div className="ppcert-settings-field">
					<Form.Item
						label={ __( 'From name', 'pressprimer-certificate' ) }
						help={ __(
							'Name shown in the "From" field of emails sent by the plugin.',
							'pressprimer-certificate'
						) }
					>
						<Input
							style={ { maxWidth: 420 } }
							value={ settings.email_from_name || '' }
							placeholder={ placeholders.siteName || '' }
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
						validateStatus={
							fieldErrors.email_from_address ? 'error' : ''
						}
						help={
							fieldErrors.email_from_address ||
							__(
								'Email address shown in the "From" field of emails sent by the plugin.',
								'pressprimer-certificate'
							)
						}
					>
						<Input
							style={ { maxWidth: 420 } }
							type="email"
							value={ settings.email_from_address || '' }
							placeholder={ placeholders.adminEmail || '' }
							onChange={ ( event ) =>
								updateSetting(
									'email_from_address',
									event.target.value
								)
							}
						/>
					</Form.Item>
				</div>
			</div>

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
						<RichTextEditor
							id="ppcert-email-issued-body"
							value={ bodyHtml }
							onChange={ ( html ) => {
								// TinyMCE also fires change when focus
								// leaves after an edit; identical content
								// must not mark the page unsaved.
								if ( html !== bodyHtml ) {
									updateSetting( 'email_issued_body', html );
								}
							} }
							rows={ 10 }
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
							token="{certificate_button}"
							description={ __(
								'A "View Certificate" button that opens the certificate page',
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
			</div>

			<div className="ppcert-settings-section">
				<Title level={ 4 } className="ppcert-settings-section-title">
					{ __( 'Test Email', 'pressprimer-certificate' ) }
				</Title>
				<Paragraph className="ppcert-settings-section-description">
					{ __(
						'Send a test email to verify your email configuration and preview your templates.',
						'pressprimer-certificate'
					) }
				</Paragraph>

				<div className="ppcert-settings-field">
					<Form.Item
						label={ __( 'Send to', 'pressprimer-certificate' ) }
					>
						<Space.Compact style={ { maxWidth: 460 } }>
							<Input
								type="email"
								value={ testTo }
								onChange={ ( event ) =>
									setTestTo( event.target.value )
								}
								placeholder={ __(
									'Enter email address',
									'pressprimer-certificate'
								) }
								style={ { width: 280 } }
							/>
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
						</Space.Compact>
						<div style={ { marginTop: 8 } }>
							<Text type="secondary">
								{ __(
									'Sends this email with sample values. Unsaved changes on this page are saved first.',
									'pressprimer-certificate'
								) }
							</Text>
						</div>
					</Form.Item>
				</div>
			</div>

			{ /* Expiry reminders touchpoint (2.0, Feature 2.0-005):
			     renders where Educator's reminder email section mounts
			     once active. Server-gated; a sibling of the slot, never
			     inside it, so the addon's React root owns the slot. */ }
			{ touchpoints[ 'email-tab' ] && (
				<UpsellPrompt
					touchpoint={ touchpoints[ 'email-tab' ] }
					style={ { marginTop: 24 } }
				/>
			) }

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
