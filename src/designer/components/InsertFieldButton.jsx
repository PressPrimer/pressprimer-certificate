/**
 * Insert Merge Field button + menu (Feature 1.1-001 FR-004, shared)
 *
 * A bordered button (full-contrast, no placeholder text - Ryan's
 * accessibility review, 2026-08-19) opening the same grouped
 * label-plus-sample menu as the Elements panel's merge list, scoped to
 * the template's trigger. Used by the text panel and the Award tab's
 * certificate-name field (Feature 1.1-006); the caller owns where the
 * token lands.
 */

import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Popover, Typography } from 'antd';
import { DownOutlined, PlusOutlined } from '@ant-design/icons';
import { useDesignerStore } from '../hooks/useDesignerStore';
import { loadMergeFields } from '../mergeFields';

const { Text } = Typography;

/**
 * Load the trigger-scoped registry, grouped palette-style.
 *
 * @return {Array} [ { id, label, fields } ].
 */
export function useFieldGroups() {
	const { state } = useDesignerStore();
	const [ registry, setRegistry ] = useState( null );

	const scope = ( state.triggers || [] )
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

	if ( ! registry ) {
		return [];
	}

	return Object.keys( registry.groups )
		.map( ( groupId ) => ( {
			id: groupId,
			label: registry.groups[ groupId ],
			fields: registry.fields.filter(
				( field ) => field.group === groupId
			),
		} ) )
		.filter( ( group ) => group.fields.length > 0 );
}

/**
 * The button.
 *
 * @param {Object}   props          Props.
 * @param {Function} props.onInsert Receives the field key (no braces).
 * @param {string}   props.testId   data-ppcert-prop value for specs.
 * @return {JSX.Element} Button with its menu.
 */
export default function InsertFieldButton( {
	onInsert,
	testId = 'insert_merge_field',
} ) {
	const [ open, setOpen ] = useState( false );
	const groups = useFieldGroups();

	return (
		<Popover
			open={ open }
			onOpenChange={ setOpen }
			trigger={ [ 'click' ] }
			placement="bottomLeft"
			content={
				<div
					className="ppcert-designer__merge-menu"
					data-ppcert-insert-menu
				>
					{ groups.map( ( group ) => (
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
									data-ppcert-insert-field={ field.key }
									onClick={ () => {
										setOpen( false );
										onInsert( field.key );
									} }
								>
									<span>{ field.label }</span>
									<Text type="secondary" ellipsis>
										{ field.sample }
									</Text>
								</button>
							) ) }
						</div>
					) ) }
				</div>
			}
		>
			<Button
				size="small"
				block
				icon={ <PlusOutlined /> }
				disabled={ 0 === groups.length }
				data-ppcert-prop={ testId }
			>
				{ __( 'Insert merge field', 'pressprimer-certificate' ) }
				<DownOutlined className="ppcert-designer__insert-caret" />
			</Button>
		</Popover>
	);
}
