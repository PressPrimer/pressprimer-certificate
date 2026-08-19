/**
 * Align / distribute toolbar (1.1, Feature 004 FR-003/FR-004)
 *
 * Appears in the designer toolbar with two or more elements selected:
 * align edges/centers against the selection's bounding box, distribute
 * gaps evenly with three or more. Every action dispatches ONE
 * APPLY_LAYOUT, so each is a single undo step (FR-005). Tooltips open
 * downward - nothing may render under the admin bar (CLAUDE.md).
 */

import { __ } from '@wordpress/i18n';
import { Button, Divider, Tooltip } from 'antd';
import {
	AlignLeftOutlined,
	AlignCenterOutlined,
	AlignRightOutlined,
	VerticalAlignTopOutlined,
	VerticalAlignMiddleOutlined,
	VerticalAlignBottomOutlined,
	ColumnWidthOutlined,
	ColumnHeightOutlined,
} from '@ant-design/icons';
import { useDesignerStore } from '../hooks/useDesignerStore';
import { alignSelection, distributeSelection } from '../schema/geometry';

const ALIGN_ACTIONS = [
	{
		edge: 'left',
		icon: <AlignLeftOutlined />,
		label: __( 'Align left', 'pressprimer-certificate' ),
	},
	{
		edge: 'center',
		icon: <AlignCenterOutlined />,
		label: __( 'Align center', 'pressprimer-certificate' ),
	},
	{
		edge: 'right',
		icon: <AlignRightOutlined />,
		label: __( 'Align right', 'pressprimer-certificate' ),
	},
	{
		edge: 'top',
		icon: <VerticalAlignTopOutlined />,
		label: __( 'Align top', 'pressprimer-certificate' ),
	},
	{
		edge: 'middle',
		icon: <VerticalAlignMiddleOutlined />,
		label: __( 'Align middle', 'pressprimer-certificate' ),
	},
	{
		edge: 'bottom',
		icon: <VerticalAlignBottomOutlined />,
		label: __( 'Align bottom', 'pressprimer-certificate' ),
	},
];

const DISTRIBUTE_ACTIONS = [
	{
		axis: 'x',
		icon: <ColumnWidthOutlined />,
		label: __( 'Distribute horizontally', 'pressprimer-certificate' ),
	},
	{
		axis: 'y',
		icon: <ColumnHeightOutlined />,
		label: __( 'Distribute vertically', 'pressprimer-certificate' ),
	},
];

/**
 * The toolbar segment.
 *
 * @return {JSX.Element|null} Buttons, or null under two selected.
 */
export default function AlignToolbar() {
	const { state, dispatch } = useDesignerStore();
	const selection = state.selection || [];

	if ( ! state.layout || selection.length < 2 ) {
		return null;
	}

	const apply = ( layout ) => dispatch( { type: 'APPLY_LAYOUT', layout } );
	const canDistribute = selection.length >= 3;

	return (
		<>
			<Divider type="vertical" />
			{ ALIGN_ACTIONS.map( ( action ) => (
				<Tooltip
					key={ action.edge }
					title={ action.label }
					placement="bottom"
				>
					<Button
						type="text"
						icon={ action.icon }
						aria-label={ action.label }
						data-ppcert-align={ action.edge }
						onClick={ () =>
							apply(
								alignSelection(
									state.layout,
									selection,
									action.edge
								)
							)
						}
					/>
				</Tooltip>
			) ) }
			{ DISTRIBUTE_ACTIONS.map( ( action ) => (
				<Tooltip
					key={ action.axis }
					title={
						canDistribute
							? action.label
							: __(
									'Select three or more elements to distribute.',
									'pressprimer-certificate'
							  )
					}
					placement="bottom"
				>
					<Button
						type="text"
						icon={ action.icon }
						disabled={ ! canDistribute }
						aria-label={ action.label }
						data-ppcert-distribute={ action.axis }
						onClick={ () =>
							apply(
								distributeSelection(
									state.layout,
									selection,
									action.axis
								)
							)
						}
					/>
				</Tooltip>
			) ) }
		</>
	);
}
