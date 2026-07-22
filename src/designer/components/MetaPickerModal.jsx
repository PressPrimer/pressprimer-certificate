/**
 * Meta-key picker modal (Feature 002 FR-004)
 *
 * REST-backed key search with one sampled value per key so designers
 * trust a field before placing it, plus the always-available raw-key
 * input ("Use a key not listed"). Scope 'user' inserts
 * {{recipient.meta.<key>}}; 'post' inserts {{source.meta.<key>}}.
 */

import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Empty, Input, List, Modal, Spin, Typography } from 'antd';
import { searchMetaKeys } from '../mergeFields';

const { Text } = Typography;

const KEY_PATTERN = /^[a-z0-9_-]{1,64}$/;

/**
 * The modal.
 *
 * @param {Object}   props          Props.
 * @param {boolean}  props.open     Visibility.
 * @param {string}   props.scope    'user' or 'post'.
 * @param {number}   props.postId   Source post id (post scope).
 * @param {Function} props.onInsert Called with the meta key.
 * @param {Function} props.onClose  Close handler.
 * @return {JSX.Element} Modal.
 */
export default function MetaPickerModal( {
	open,
	scope,
	postId = 0,
	onInsert,
	onClose,
} ) {
	const [ search, setSearch ] = useState( '' );
	const [ items, setItems ] = useState( [] );
	const [ loading, setLoading ] = useState( false );
	const [ rawKey, setRawKey ] = useState( '' );

	useEffect( () => {
		if ( ! open ) {
			return undefined;
		}

		let active = true;
		setLoading( true );

		const timer = setTimeout( () => {
			searchMetaKeys( scope, search, postId ).then( ( results ) => {
				if ( active ) {
					setItems( results );
					setLoading( false );
				}
			} );
		}, 200 );

		return () => {
			active = false;
			clearTimeout( timer );
		};
	}, [ open, scope, search, postId ] );

	const rawValid = KEY_PATTERN.test( rawKey );

	const insert = ( key ) => {
		onInsert( key );
		onClose();
	};

	return (
		<Modal
			open={ open }
			onCancel={ onClose }
			footer={ null }
			title={
				'post' === scope
					? __( 'Source meta field', 'pressprimer-certificate' )
					: __( 'User meta field', 'pressprimer-certificate' )
			}
			className="ppcert-designer__meta-picker"
		>
			<Input.Search
				placeholder={ __(
					'Search meta keys…',
					'pressprimer-certificate'
				) }
				allowClear
				value={ search }
				data-ppcert-meta-search
				onChange={ ( event ) => setSearch( event.target.value ) }
			/>

			{ loading ? (
				<div className="ppcert-designer__meta-loading">
					<Spin />
				</div>
			) : (
				<List
					size="small"
					className="ppcert-designer__meta-list"
					dataSource={ items }
					locale={ {
						emptyText: (
							<Empty
								image={ Empty.PRESENTED_IMAGE_SIMPLE }
								description={ __(
									'No matching meta keys.',
									'pressprimer-certificate'
								) }
							/>
						),
					} }
					renderItem={ ( item ) => (
						<List.Item
							className="ppcert-designer__meta-item"
							data-ppcert-meta-key={ item.key }
							onClick={ () => insert( item.key ) }
						>
							<code>{ item.key }</code>
							<Text
								type="secondary"
								ellipsis
								className="ppcert-designer__meta-sample"
							>
								{ item.sample }
							</Text>
						</List.Item>
					) }
				/>
			) }

			<div className="ppcert-designer__meta-raw">
				<Text type="secondary">
					{ __( 'Use a key not listed', 'pressprimer-certificate' ) }
				</Text>
				<Input.Search
					placeholder="meta_key"
					value={ rawKey }
					data-ppcert-meta-raw
					status={ '' !== rawKey && ! rawValid ? 'error' : undefined }
					enterButton={ __( 'Insert', 'pressprimer-certificate' ) }
					onChange={ ( event ) =>
						setRawKey( event.target.value.toLowerCase() )
					}
					onSearch={ () => rawValid && insert( rawKey ) }
				/>
			</div>
		</Modal>
	);
}
