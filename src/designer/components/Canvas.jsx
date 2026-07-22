/**
 * Designer canvas - true-size page render (Feature 001 FR-002)
 *
 * Prompt 3.1 scope: a read-only true-size render of the loaded layout
 * (absolutely positioned DOM - never <canvas>, so text metrics match the
 * browser and parity screenshots are honest). Selection, drag, resize,
 * and zoom interactions arrive in Prompt 3.2; element components with
 * full prop fidelity in 3.3.
 *
 * @package
 */

import { __ } from '@wordpress/i18n';

const SAFE_MARGIN_PT = 24;

/**
 * Minimal element rendering for the 3.1 shell.
 *
 * @param {Object} props         Props.
 * @param {Object} props.element Clean element.
 * @return {JSX.Element} Element box.
 */
function ElementBox( { element } ) {
	const base = {
		position: 'absolute',
		left: element.x,
		top: element.y,
		width: element.w,
		height: element.h,
	};

	switch ( element.type ) {
		case 'text':
		case 'merge_field': {
			const p = element.props;
			return (
				<div
					style={ {
						...base,
						color: p.color,
						fontSize: p.font_size,
						lineHeight: p.line_height,
						fontWeight: p.bold ? 700 : 400,
						fontStyle: p.italic ? 'italic' : 'normal',
						textAlign: p.align,
						overflow: 'hidden',
					} }
					className={
						element.type === 'merge_field'
							? 'ppcert-designer__el-merge'
							: undefined
					}
				>
					{ element.type === 'merge_field' ? p.token : p.content }
				</div>
			);
		}

		case 'shape': {
			const p = element.props;
			return (
				<div
					style={ {
						...base,
						border:
							p.stroke_width > 0
								? `${ p.stroke_width }px solid ${ p.stroke_color }`
								: 'none',
						background: p.fill_color || 'transparent',
						borderRadius: p.radius || 0,
					} }
				/>
			);
		}

		case 'qr':
			return (
				<div
					className="ppcert-designer__el-qr"
					style={ { ...base, background: element.props.dark_color } }
				>
					QR
				</div>
			);

		case 'image':
		case 'signature':
		default:
			return (
				<div className="ppcert-designer__el-media" style={ base }>
					{ element.type }
				</div>
			);
	}
}

/**
 * The canvas.
 *
 * @param {Object} props        Props.
 * @param {Object} props.layout Layout document.
 * @return {JSX.Element} Canvas surface.
 */
export default function Canvas( { layout } ) {
	if ( ! layout ) {
		return null;
	}

	const { width, height } = layout.page;

	return (
		<div className="ppcert-designer__surface">
			<div
				className="ppcert-designer__page"
				style={ {
					width,
					height,
					background: layout.background?.color || '#ffffff',
				} }
				aria-label={ __(
					'Certificate canvas',
					'pressprimer-certificate'
				) }
			>
				<div
					className="ppcert-designer__safe-margin"
					style={ {
						inset: SAFE_MARGIN_PT,
					} }
				/>
				{ layout.elements.map( ( element ) => (
					<ElementBox key={ element.id } element={ element } />
				) ) }
			</div>
		</div>
	);
}
