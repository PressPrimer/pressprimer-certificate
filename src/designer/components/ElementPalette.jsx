/**
 * Element palette (Feature 001 FR-003)
 *
 * Entries come from the ppcert_designer_element_types registry (boot
 * data) so Educator 2.0 types appear without core changes. Clicking an
 * entry adds an element with the registry's validator-clean defaults,
 * centered with a small cascade, and selects it.
 *
 * merge_field activates with the registry routes (Prompt 3.4);
 * background edits the document root via the properties panel.
 */

import { __ } from '@wordpress/i18n';
import { List, Tooltip, Typography } from 'antd';
import {
	FontSizeOutlined,
	TagOutlined,
	PictureOutlined,
	EditOutlined,
	BorderOutlined,
	QrcodeOutlined,
	BgColorsOutlined,
	AppstoreOutlined,
} from '@ant-design/icons';
import { useDesignerStore } from '../hooks/useDesignerStore';
import { getBoot } from '../boot';
import { addElement, generateElementId, roundPt } from '../schema/geometry';

const { Text } = Typography;

const ICONS = {
	text: <FontSizeOutlined />,
	merge_field: <TagOutlined />,
	image: <PictureOutlined />,
	signature: <EditOutlined />,
	shape: <BorderOutlined />,
	qr: <QrcodeOutlined />,
	background: <BgColorsOutlined />,
};

/**
 * The palette.
 *
 * @return {JSX.Element} Palette list.
 */
export default function ElementPalette() {
	const { state, dispatch } = useDesignerStore();
	const types = Object.values( getBoot().element_types );

	const onAdd = ( type ) => {
		if ( ! state.layout ) {
			return;
		}

		if ( 'background' === type.key ) {
			// Background is a palette entry for UX only: it edits the
			// document root. Clearing the selection surfaces the Page
			// section (background controls) in the properties panel.
			dispatch( { type: 'SET_SELECTION', ids: [] } );
			return;
		}

		if ( ! type.default_box ) {
			return;
		}

		const { page, elements } = state.layout;
		const cascade = 12 * ( elements.length % 8 );
		const element = {
			id: generateElementId( elements.map( ( el ) => el.id ) ),
			type: type.key,
			x: roundPt( ( page.width - type.default_box.w ) / 2 + cascade ),
			y: roundPt( ( page.height - type.default_box.h ) / 2 + cascade ),
			w: type.default_box.w,
			h: type.default_box.h,
			props: { ...type.default_props },
		};

		dispatch( {
			type: 'APPLY_LAYOUT',
			layout: addElement( state.layout, element ),
		} );
		dispatch( { type: 'SET_SELECTION', ids: [ element.id ] } );
	};

	return (
		<div className="ppcert-designer__palette-inner">
			<Text type="secondary" className="ppcert-designer__panel-heading">
				{ __( 'Elements', 'pressprimer-certificate' ) }
			</Text>
			<List
				size="small"
				dataSource={ types }
				renderItem={ ( type ) => {
					const inert = 'merge_field' === type.key;
					const item = (
						<List.Item
							className={
								'ppcert-designer__palette-item' +
								( inert
									? ' ppcert-designer__palette-item--inert'
									: '' )
							}
							data-ppcert-palette={ type.key }
							onClick={ inert ? undefined : () => onAdd( type ) }
						>
							{ ICONS[ type.key ] || <AppstoreOutlined /> }
							<span>{ type.label }</span>
						</List.Item>
					);

					return inert ? (
						<Tooltip
							title={ __(
								'Merge fields activate in an upcoming step.',
								'pressprimer-certificate'
							) }
							placement="right"
						>
							{ item }
						</Tooltip>
					) : (
						item
					);
				} }
			/>
		</div>
	);
}
