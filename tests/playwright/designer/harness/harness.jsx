/**
 * Designer canvas test harness (Feature 001 FR-002)
 *
 * Mounts the real Canvas + store against a bundled starter layout with
 * no WordPress involved - the designer-canvas Playwright project drives
 * this page. Everything (React, antd, the canvas) is bundled by
 * webpack.harness.config.js, so the page runs from file://.
 *
 * The window.__ppcertHarness bridge exposes state reads, dispatch, and
 * zoom for the specs.
 */

import { render, useEffect, useState } from '@wordpress/element';
import {
	DesignerProvider,
	useDesignerStore,
} from '../../../../src/designer/hooks/useDesignerStore';
import Canvas from '../../../../src/designer/components/Canvas';
import starter from '../../../../templates/starter-formal-landscape.json';
import '../../../../src/designer/style.css';

/**
 * Harness app: load the starter, expose the bridge, render the canvas.
 *
 * @return {JSX.Element|null} Canvas.
 */
function Harness() {
	const { state, dispatch } = useDesignerStore();
	const [ zoom, setZoom ] = useState( 1 );

	useEffect( () => {
		if ( state.layout ) {
			return;
		}

		const layout = { ...starter };
		delete layout._meta;

		dispatch( {
			type: 'LOAD_TEMPLATE',
			template: { id: 0, title: 'Harness', status: 'draft' },
			layout,
		} );
	}, [ state.layout, dispatch ] );

	// Reassigned every render so getState always returns current state.
	window.__ppcertHarness = {
		ready: true,
		getState: () => state,
		dispatch,
		setZoom,
	};

	if ( ! state.layout ) {
		return null;
	}

	return <Canvas layout={ state.layout } zoom={ zoom } />;
}

render(
	<DesignerProvider>
		<Harness />
	</DesignerProvider>,
	document.getElementById( 'root' )
);
