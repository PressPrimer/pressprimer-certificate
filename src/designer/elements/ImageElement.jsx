/**
 * Image / signature canvas element (layout-schema `image`/`signature`)
 *
 * Layouts store attachment IDs only; the URL resolves through the
 * cached media lookup. Empty and missing attachments render placeholder
 * boxes so the element stays selectable.
 */

import { __ } from '@wordpress/i18n';
import { useAttachmentUrl } from '../hooks/useAttachment';

const FIT_TO_OBJECT_FIT = {
	contain: 'contain',
	cover: 'cover',
	stretch: 'fill',
};

/**
 * The element.
 *
 * @param {Object} props         Props.
 * @param {Object} props.element Clean element.
 * @param {Object} props.box     Visual box.
 * @return {JSX.Element} Rendered image.
 */
export default function ImageElement( { element, box } ) {
	const p = element.props;
	const { url, missing } = useAttachmentUrl( p.attachment_id );

	if ( ! p.attachment_id || ! url ) {
		let label = __( 'Image', 'pressprimer-certificate' );

		if ( missing ) {
			label = __( 'Missing image', 'pressprimer-certificate' );
		} else if ( 'signature' === element.type ) {
			label = __( 'Signature', 'pressprimer-certificate' );
		}

		return (
			<div
				className="ppcert-designer__el-media"
				style={ { width: box.w, height: box.h } }
			>
				{ label }
			</div>
		);
	}

	return (
		<img
			src={ url }
			alt=""
			draggable={ false }
			style={ {
				width: box.w,
				height: box.h,
				objectFit: FIT_TO_OBJECT_FIT[ p.fit ] || 'contain',
				opacity: undefined === p.opacity ? 1 : p.opacity,
				display: 'block',
			} }
		/>
	);
}
