/**
 * Manual issuance modal (Feature 003 FR-003)
 *
 * Mounts beside the Certificates screen title: an Issue Certificate
 * button opening an Ant Design modal - published-template select,
 * recipient search (GET /ppcert/v1/users/search, capability-gated,
 * LIMIT 20 server-side), and the duplicate "Issue anyway" flow (003
 * Edge Cases: admins may legitimately reissue).
 */

import { render, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Button, DatePicker, Modal, Select, message } from 'antd';
import { PlusOutlined } from '@ant-design/icons';
import dayjs from 'dayjs';

// Ecosystem convention: toasts clear the WP admin bar.
message.config( { top: 50, duration: 5, maxCount: 3 } );

/**
 * The Issue button + modal.
 *
 * @return {JSX.Element} App.
 */
function IssueApp() {
	// The dashboard's "Issue a Certificate" quick action deep-links here
	// with ?ppcert_issue=1 to open the modal immediately.
	const [ open, setOpen ] = useState(
		() =>
			new URLSearchParams( window.location.search ).get(
				'ppcert_issue'
			) === '1'
	);
	const [ templateId, setTemplateId ] = useState( null );
	const [ recipient, setRecipient ] = useState( null );
	const [ earnedDate, setEarnedDate ] = useState( () => dayjs() );
	const [ users, setUsers ] = useState( [] );
	const [ submitting, setSubmitting ] = useState( false );

	const templates = ( window.ppcert_issue_data || {} ).templates || [];

	const reset = () => {
		setTemplateId( null );
		setRecipient( null );
		setEarnedDate( dayjs() );
		setUsers( [] );
	};

	const searchUsers = ( term ) =>
		apiFetch( {
			path: `/ppcert/v1/users/search?search=${ encodeURIComponent(
				term
			) }`,
		} )
			.then( setUsers )
			.catch( () => setUsers( [] ) );

	const issue = ( force ) => {
		setSubmitting( true );

		apiFetch( {
			path: '/ppcert/v1/certificates',
			method: 'POST',
			data: {
				template_id: templateId,
				recipient_id: recipient,
				earned_date: earnedDate
					? earnedDate.format( 'YYYY-MM-DD' )
					: undefined,
				force,
			},
		} )
			.then( ( certificate ) => {
				setSubmitting( false );
				setOpen( false );
				reset();
				message.success(
					sprintf(
						/* translators: %s: credential ID */
						__(
							'Certificate issued: %s',
							'pressprimer-certificate'
						),
						certificate.credential_id
					)
				);

				// The PHP list table renders server-side: reload to show
				// the new row (preserving any active filters).
				window.setTimeout( () => window.location.reload(), 800 );
			} )
			.catch( ( error ) => {
				setSubmitting( false );

				if ( error && 'ppcert_duplicate_certificate' === error.code ) {
					Modal.confirm( {
						title: __(
							'Already issued',
							'pressprimer-certificate'
						),
						content: sprintf(
							/* translators: %s: existing credential ID */
							__(
								'This recipient already has a certificate from this template (%s). Issue another one anyway?',
								'pressprimer-certificate'
							),
							( error.data && error.data.credential_id ) || ''
						),
						okText: __( 'Issue anyway', 'pressprimer-certificate' ),
						cancelText: __( 'Cancel', 'pressprimer-certificate' ),
						onOk: () => issue( true ),
					} );
					return;
				}

				message.error(
					( error && error.message ) ||
						__(
							'The certificate could not be issued.',
							'pressprimer-certificate'
						)
				);
			} );
	};

	return (
		<>
			<Button
				type="primary"
				icon={ <PlusOutlined /> }
				data-ppcert-issue-open
				onClick={ () => setOpen( true ) }
			>
				{ __( 'Issue Certificate', 'pressprimer-certificate' ) }
			</Button>

			<Modal
				open={ open }
				maskClosable={ false }
				title={ __( 'Issue Certificate', 'pressprimer-certificate' ) }
				okText={ __( 'Issue', 'pressprimer-certificate' ) }
				okButtonProps={ {
					disabled: ! templateId || ! recipient,
					loading: submitting,
					'data-ppcert-issue-submit': true,
				} }
				onOk={ () => issue( false ) }
				onCancel={ () => {
					setOpen( false );
					reset();
				} }
			>
				<div className="ppcert-issue__form">
					<label
						className="ppcert-issue__label"
						htmlFor="ppcert-issue-template"
					>
						{ __( 'Template', 'pressprimer-certificate' ) }
					</label>
					<Select
						id="ppcert-issue-template"
						value={ templateId }
						data-ppcert-issue-template
						placeholder={ __(
							'Choose a published template…',
							'pressprimer-certificate'
						) }
						popupMatchSelectWidth={ false }
						getPopupContainer={ ( node ) => node.parentElement }
						onChange={ setTemplateId }
						options={ templates.map( ( template ) => ( {
							value: template.id,
							label: template.title,
						} ) ) }
						className="ppcert-issue__control"
					/>

					<label
						className="ppcert-issue__label"
						htmlFor="ppcert-issue-recipient"
					>
						{ __( 'Recipient', 'pressprimer-certificate' ) }
					</label>
					<Select
						id="ppcert-issue-recipient"
						showSearch
						value={ recipient }
						data-ppcert-issue-recipient
						placeholder={ __(
							'Search users…',
							'pressprimer-certificate'
						) }
						filterOption={ false }
						popupMatchSelectWidth={ false }
						getPopupContainer={ ( node ) => node.parentElement }
						onSearch={ searchUsers }
						onFocus={ () => searchUsers( '' ) }
						onChange={ setRecipient }
						options={ users.map( ( user ) => ( {
							value: user.id,
							label: `${ user.name } (${ user.email })`,
						} ) ) }
						className="ppcert-issue__control"
					/>

					<label
						className="ppcert-issue__label"
						htmlFor="ppcert-issue-earned"
					>
						{ __( 'Earned date', 'pressprimer-certificate' ) }
					</label>
					<DatePicker
						id="ppcert-issue-earned"
						value={ earnedDate }
						data-ppcert-issue-earned
						allowClear={ false }
						getPopupContainer={ ( node ) => node.parentElement }
						disabledDate={ ( current ) =>
							current && current > dayjs().endOf( 'day' )
						}
						onChange={ ( value ) =>
							setEarnedDate( value || dayjs() )
						}
						className="ppcert-issue__control"
					/>
				</div>
			</Modal>
		</>
	);
}

const root = document.getElementById( 'ppcert-issue-root' );

if ( root ) {
	render( <IssueApp />, root );
}
