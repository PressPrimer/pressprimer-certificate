/**
 * General Tab Component
 *
 * The verification page mapping (Feature 008 FR-004): which page hosts
 * [ppcert_verify] / the verification block - QR codes and email links
 * resolve against it.
 *
 * @package
 * @since 1.0.0
 */

import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Form, Select, Spin, Typography, Alert } from 'antd';

const { Title, Paragraph, Text } = Typography;

/**
 * General Tab
 *
 * @param {Object}   props               Component props.
 * @param {Object}   props.settings      Current settings.
 * @param {Function} props.updateSetting Update a setting.
 * @param {Object}   props.settingsData  Full localized data.
 */
const GeneralTab = ( { settings, updateSetting, settingsData } ) => {
	const firstPages = settingsData.pages || [];
	const configured = settingsData.verificationPage || {};
	const pageId = Number( settings.verification_page_id ) || 0;

	// The picker searches every published page on the server (2.1,
	// Feature 2.1-007 FR-005); the first choices come with the screen.
	const [ pages, setPages ] = useState( firstPages );
	const [ searching, setSearching ] = useState( false );
	const [ chosen, setChosen ] = useState( null );
	const timer = useRef( null );

	useEffect( () => () => clearTimeout( timer.current ), [] );

	const search = ( term ) => {
		clearTimeout( timer.current );

		if ( '' === term.trim() ) {
			setPages( firstPages );
			setSearching( false );
			return;
		}

		setSearching( true );
		timer.current = setTimeout( () => {
			apiFetch( {
				path: `/ppcert/v1/settings/pages?search=${ encodeURIComponent(
					term
				) }`,
			} )
				.then( ( results ) => setPages( results || [] ) )
				.catch( () => setPages( [] ) )
				.finally( () => setSearching( false ) );
		}, 300 );
	};

	// Whether the configured page exists comes from the server; a page
	// chosen in this session obviously does.
	const pageExists =
		0 === pageId ||
		( chosen && chosen.id === pageId ) ||
		( configured.id === pageId && configured.exists !== false ) ||
		firstPages.some( ( page ) => page.id === pageId );

	// The selected page carries its own title (labelInValue), so the
	// list shows only what matches the search.
	const selected =
		( chosen && chosen.id === pageId && chosen ) ||
		firstPages.find( ( page ) => page.id === pageId ) ||
		( configured.id === pageId && configured.title ? configured : null );
	const value = pageId
		? {
				value: pageId,
				label: selected
					? selected.title
					: sprintf(
							/* translators: %d: page ID */
							__( 'Page #%d', 'pressprimer-certificate' ),
							pageId
					  ),
		  }
		: undefined;

	return (
		<div>
			<div className="ppcert-settings-section">
				<Title level={ 4 } className="ppcert-settings-section-title">
					{ __( 'Verification', 'pressprimer-certificate' ) }
				</Title>
				<Paragraph className="ppcert-settings-section-description">
					{ __(
						'The public page where visitors verify certificates. QR codes on rendered certificates and links in issuance emails point here.',
						'pressprimer-certificate'
					) }
				</Paragraph>

				{ ! pageExists && (
					<Alert
						type="warning"
						showIcon
						style={ { marginBottom: 16 } }
						message={ __(
							'The configured verification page no longer exists.',
							'pressprimer-certificate'
						) }
						description={ __(
							'Certificate QR codes and links will not resolve until you assign a new page. The page needs the Certificate Verification block or the [ppcert_verify] shortcode.',
							'pressprimer-certificate'
						) }
					/>
				) }

				<div className="ppcert-settings-field">
					<Form.Item
						label={ __(
							'Verification page',
							'pressprimer-certificate'
						) }
					>
						<Select
							style={ { maxWidth: 420, width: '100%' } }
							popupMatchSelectWidth={ false }
							showSearch
							filterOption={ false }
							onSearch={ search }
							onBlur={ () => search( '' ) }
							notFoundContent={
								searching ? (
									<Spin size="small" />
								) : (
									__(
										'No published pages match.',
										'pressprimer-certificate'
									)
								)
							}
							labelInValue
							value={ value }
							placeholder={ __(
								'Search for a page…',
								'pressprimer-certificate'
							) }
							onChange={ ( choice ) => {
								const id = choice ? Number( choice.value ) : 0;

								setChosen(
									id ? { id, title: choice.label } : null
								);
								updateSetting( 'verification_page_id', id );
							} }
							options={ pages.map( ( page ) => ( {
								value: page.id,
								label: page.title,
							} ) ) }
						/>
					</Form.Item>
					<Text type="secondary">
						{ __(
							'The page should contain the Certificate Verification block (or the [ppcert_verify] shortcode).',
							'pressprimer-certificate'
						) }
					</Text>
				</div>
			</div>
		</div>
	);
};

export default GeneralTab;
