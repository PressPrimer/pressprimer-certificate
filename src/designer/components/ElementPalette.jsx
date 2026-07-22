/**
 * Element palette (Feature 001 FR-003, Feature 002 FR-004)
 *
 * Entries come from the ppcert_designer_element_types registry (boot
 * data) so Educator 2.0 types appear without core changes. Clicking an
 * entry adds an element with the registry's validator-clean defaults,
 * centered with a small cascade, and selects it.
 *
 * Merge Field opens grouped registry fields (GET /ppcert/v1/merge-fields)
 * scoped to the template's staged trigger - source fields belong to the
 * trigger's integration, and without a trigger the source section
 * explains how to unlock them. The user-meta picker is always
 * available; the source-meta picker activates once the staged trigger
 * points at a post-backed source (Prompt 3.7). Background edits the
 * document root via the properties panel.
 */

import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { List, Popover, Tooltip, Typography } from 'antd';
import {
	FontSizeOutlined,
	TagOutlined,
	PictureOutlined,
	EditOutlined,
	BorderOutlined,
	QrcodeOutlined,
	BgColorsOutlined,
	AppstoreOutlined,
	UserOutlined,
} from '@ant-design/icons';
import { useDesignerStore } from '../hooks/useDesignerStore';
import { getBoot } from '../boot';
import {
	MAX_ELEMENTS,
	addElement,
	generateElementId,
	roundPt,
} from '../schema/geometry';
import { loadMergeFields } from '../mergeFields';
import { getTriggerTypes } from '../api';
import MetaPickerModal from './MetaPickerModal';

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

	const [ registry, setRegistry ] = useState( null );
	const [ triggerTypes, setTriggerTypes ] = useState( [] );
	const [ mergeOpen, setMergeOpen ] = useState( false );
	const [ pickerOpen, setPickerOpen ] = useState( false );
	const [ pickerScope, setPickerScope ] = useState( 'user' );

	// Source fields follow the staged trigger (single in 1.0): the
	// registry refetches whenever the template's trigger types change.
	const triggers = state.triggers || [];
	const scope = triggers
		.map( ( trigger ) => trigger.trigger_type )
		.sort()
		.join( ',' );

	useEffect( () => {
		let active = true;

		loadMergeFields( '' === scope ? [] : scope.split( ',' ) ).then(
			( data ) => active && setRegistry( data )
		);

		return () => {
			active = false;
		};
	}, [ scope ] );

	useEffect( () => {
		getTriggerTypes().then( setTriggerTypes );
	}, [] );

	// The source-meta picker unlocks when the staged trigger points at a
	// post-backed source (Feature 002 FR-004 / Prompt 3.7).
	const postSourceTrigger = triggers.find( ( trigger ) => {
		const type = triggerTypes.find(
			( t ) => t.id === trigger.trigger_type
		);

		return (
			type &&
			Array.isArray( type.source_post_types ) &&
			type.source_post_types.length > 0 &&
			trigger.source_ref &&
			/^\d+$/.test( String( trigger.source_ref ) )
		);
	} );

	const atCap =
		!! state.layout && state.layout.elements.length >= MAX_ELEMENTS;

	const addFromType = ( type, propsOverride = {} ) => {
		if ( ! state.layout || ! type || ! type.default_box || atCap ) {
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
			props: { ...type.default_props, ...propsOverride },
		};

		dispatch( {
			type: 'APPLY_LAYOUT',
			layout: addElement( state.layout, element ),
		} );
		dispatch( { type: 'SET_SELECTION', ids: [ element.id ] } );
	};

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

		if ( 'merge_field' === type.key ) {
			// The Popover's own click trigger manages open state.
			return;
		}

		addFromType( type );
	};

	const insertToken = ( token ) => {
		const mergeType = getBoot().element_types.merge_field;
		addFromType( mergeType, { token } );
		setMergeOpen( false );
	};

	const groupedFields = () => {
		if ( ! registry ) {
			return [];
		}

		const groups = [];

		Object.keys( registry.groups ).forEach( ( groupId ) => {
			const fields = registry.fields.filter(
				( field ) => field.group === groupId
			);

			if ( fields.length ) {
				groups.push( {
					id: groupId,
					label: registry.groups[ groupId ],
					fields,
				} );
			}
		} );

		return groups;
	};

	const hasSourceFields = groupedFields().some(
		( group ) => 'source' === group.id
	);

	const sourceMetaButton = (
		<button
			type="button"
			className="ppcert-designer__merge-item"
			data-ppcert-merge-field="__source_meta"
			disabled={ ! postSourceTrigger }
			onClick={
				postSourceTrigger
					? () => {
							setMergeOpen( false );
							setPickerScope( 'post' );
							setPickerOpen( true );
					  }
					: undefined
			}
		>
			<span>{ __( 'Source meta…', 'pressprimer-certificate' ) }</span>
		</button>
	);

	const mergeContent = (
		<div className="ppcert-designer__merge-menu" data-ppcert-merge-menu>
			{ groupedFields().map( ( group ) => (
				<div key={ group.id }>
					<Text
						type="secondary"
						className="ppcert-designer__panel-heading"
					>
						{ group.label }
					</Text>
					{ group.fields.map( ( field ) => (
						<button
							key={ field.key }
							type="button"
							className="ppcert-designer__merge-item"
							data-ppcert-merge-field={ field.key }
							onClick={ () =>
								insertToken( `{{${ field.key }}}` )
							}
						>
							<span>{ field.label }</span>
							<Text type="secondary" ellipsis>
								{ field.sample }
							</Text>
						</button>
					) ) }
				</div>
			) ) }

			{ ! hasSourceFields && (
				<div data-ppcert-merge-source-hint>
					<Text
						type="secondary"
						className="ppcert-designer__panel-heading"
					>
						{ __( 'Source', 'pressprimer-certificate' ) }
					</Text>
					<Text
						type="secondary"
						className="ppcert-designer__merge-hint"
					>
						{ __(
							'Add a trigger in the Award tab to unlock fields from your quiz or course plugin.',
							'pressprimer-certificate'
						) }
					</Text>
				</div>
			) }

			<div>
				<Text
					type="secondary"
					className="ppcert-designer__panel-heading"
				>
					{ __( 'Custom meta', 'pressprimer-certificate' ) }
				</Text>
				<button
					type="button"
					className="ppcert-designer__merge-item"
					data-ppcert-merge-field="__user_meta"
					onClick={ () => {
						setMergeOpen( false );
						setPickerScope( 'user' );
						setPickerOpen( true );
					} }
				>
					<span>
						<UserOutlined />{ ' ' }
						{ __( 'Custom user meta…', 'pressprimer-certificate' ) }
					</span>
				</button>
				{ postSourceTrigger ? (
					sourceMetaButton
				) : (
					<Tooltip
						title={ __(
							'Available once this template has an award trigger with a source.',
							'pressprimer-certificate'
						) }
						placement="right"
					>
						{ sourceMetaButton }
					</Tooltip>
				) }
			</div>
		</div>
	);

	return (
		<div className="ppcert-designer__palette-inner">
			<Text type="secondary" className="ppcert-designer__panel-heading">
				{ __( 'Elements', 'pressprimer-certificate' ) }
			</Text>
			<List
				size="small"
				dataSource={ types }
				renderItem={ ( type ) => {
					// The 100-element cap (FR-002): adders disable with an
					// explanation; Background (document root) stays live.
					const capped = atCap && 'background' !== type.key;

					const item = (
						<List.Item
							className={
								'ppcert-designer__palette-item' +
								( capped
									? ' ppcert-designer__palette-item--inert'
									: '' )
							}
							data-ppcert-palette={ type.key }
							aria-disabled={ capped || undefined }
							onClick={ capped ? undefined : () => onAdd( type ) }
						>
							{ ICONS[ type.key ] || <AppstoreOutlined /> }
							<span>{ type.label }</span>
						</List.Item>
					);

					if ( capped ) {
						return (
							<Tooltip
								title={ __(
									'This certificate has reached the 100-element limit.',
									'pressprimer-certificate'
								) }
								placement="right"
							>
								{ item }
							</Tooltip>
						);
					}

					if ( 'merge_field' !== type.key ) {
						return item;
					}

					// A plain div hosts the trigger: antd List.Item does
					// not forward refs, which silently breaks Popover's
					// cloned-child event wiring.
					return (
						<Popover
							content={ mergeContent }
							open={ mergeOpen }
							onOpenChange={ setMergeOpen }
							trigger={ [ 'click' ] }
							placement="rightTop"
						>
							<div>{ item }</div>
						</Popover>
					);
				} }
			/>

			<MetaPickerModal
				open={ pickerOpen }
				scope={ pickerScope }
				postId={
					'post' === pickerScope && postSourceTrigger
						? parseInt( postSourceTrigger.source_ref, 10 )
						: 0
				}
				onInsert={ ( key ) =>
					insertToken(
						'post' === pickerScope
							? `{{source.meta.${ key }}}`
							: `{{recipient.meta.${ key }}}`
					)
				}
				onClose={ () => setPickerOpen( false ) }
			/>
		</div>
	);
}
