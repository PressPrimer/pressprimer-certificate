/**
 * Certificate Verification block
 *
 * The [ppcert_verify] equivalent (shortcode/block parity rule): a
 * dynamic block whose server render wraps the shortcode handler. The
 * shortcode takes no attributes in 1.0, so the block declares none -
 * when the shortcode grows an attribute, the matching inspector
 * control lands here in the same commit.
 *
 * @package
 * @since 1.0.0
 */

import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { Placeholder } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Shield icon
 */
const shieldIcon = (
	<svg
		xmlns="http://www.w3.org/2000/svg"
		viewBox="0 0 24 24"
		width="24"
		height="24"
	>
		<path
			fill="currentColor"
			d="M12 2 4 5v6c0 5.25 3.4 9.74 8 11 4.6-1.26 8-5.75 8-11V5l-8-3zm-1.2 14.5-3.6-3.6 1.4-1.4 2.2 2.2 4.6-4.6 1.4 1.4-6 6z"
		/>
	</svg>
);

/**
 * Edit component: a static editor preview of the lookup form
 *
 * @return {JSX.Element} Block edit component.
 */
function Edit() {
	const blockProps = useBlockProps();

	return (
		<div { ...blockProps }>
			<Placeholder
				icon={ shieldIcon }
				label={ __(
					'Certificate Verification',
					'pressprimer-certificate'
				) }
				instructions={ __(
					'Visitors enter a credential ID here to verify a certificate. The form renders on the published page.',
					'pressprimer-certificate'
				) }
			>
				<div
					style={ {
						display: 'flex',
						gap: '8px',
						alignItems: 'center',
						width: '100%',
					} }
				>
					<input
						type="text"
						disabled
						placeholder="XXXX-XXXX-XXXX"
						style={ {
							fontFamily: 'monospace',
							letterSpacing: '0.08em',
							padding: '6px 10px',
							flex: '0 1 20ch',
						} }
					/>
					<button
						type="button"
						disabled
						className="components-button is-secondary"
					>
						{ __( 'Verify', 'pressprimer-certificate' ) }
					</button>
				</div>
			</Placeholder>
		</div>
	);
}

registerBlockType( 'pressprimer-certificate/verify', {
	apiVersion: 3,
	title: __( 'Certificate Verification', 'pressprimer-certificate' ),
	description: __(
		'A credential ID lookup form visitors use to verify certificates.',
		'pressprimer-certificate'
	),
	category: 'pressprimer-certificate',
	icon: shieldIcon,
	supports: {
		html: false,
		align: true,
	},
	edit: Edit,
	// Dynamic block: the server render_callback produces the output.
	save: () => null,
} );
