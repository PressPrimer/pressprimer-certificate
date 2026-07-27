/**
 * My Certificates block
 *
 * The [ppcert_my_certificates] equivalent (shortcode/block parity
 * rule): a dynamic block whose server render wraps the shortcode
 * handler. The shortcode takes no attributes in 1.0, so the block
 * declares none - when the shortcode grows an attribute, the matching
 * inspector control lands here in the same commit.
 *
 * @package
 * @since 1.0.0
 */

import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { Placeholder } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Award ribbon icon
 */
const awardIcon = (
	<svg
		xmlns="http://www.w3.org/2000/svg"
		viewBox="0 0 24 24"
		width="24"
		height="24"
	>
		<path
			fill="currentColor"
			d="M12 2a7 7 0 0 0-4 12.74V22l4-2 4 2v-7.26A7 7 0 0 0 12 2zm0 2a5 5 0 1 1 0 10 5 5 0 0 1 0-10zm0 2a3 3 0 1 0 0 6 3 3 0 0 0 0-6z"
		/>
	</svg>
);

/**
 * Edit component: a static editor preview of the certificate list
 *
 * @return {JSX.Element} Block edit component.
 */
function Edit() {
	const blockProps = useBlockProps();

	return (
		<div { ...blockProps }>
			<Placeholder
				icon={ awardIcon }
				label={ __( 'My Certificates', 'pressprimer-certificate' ) }
				instructions={ __(
					'Logged-in visitors see their earned certificates here: name, earned date, expiry, verification, and PDF download.',
					'pressprimer-certificate'
				) }
			>
				<div
					style={ {
						width: '100%',
						border: '1px solid #dcdcde',
						borderRadius: '8px',
						padding: '12px 16px',
						background: '#fff',
					} }
				>
					<strong>
						{ __( 'Certificate name', 'pressprimer-certificate' ) }
					</strong>
					<div style={ { color: '#50575e', fontSize: '13px' } }>
						{ __(
							'Earned date - Expires date - Verify - Download PDF',
							'pressprimer-certificate'
						) }
					</div>
				</div>
			</Placeholder>
		</div>
	);
}

registerBlockType( 'pressprimer-certificate/my-certificates', {
	apiVersion: 3,
	title: __( 'My Certificates', 'pressprimer-certificate' ),
	description: __(
		"Lists the logged-in visitor's earned certificates with verification and download links.",
		'pressprimer-certificate'
	),
	category: 'pressprimer-certificate',
	icon: awardIcon,
	supports: {
		html: false,
		align: true,
	},
	edit: Edit,
	// Dynamic block: output comes from the server render callback.
	save: () => null,
} );
