/**
 * Activity chart - certificates awarded over time
 *
 * A single zero-filled daily series from GET /ppcert/v1/dashboard/chart.
 * The range select refetches; day boundaries are UTC (matching the
 * stored issued_at values) and labels localize in the viewer's locale.
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Spin, Select, Empty } from 'antd';
import {
	LineChart,
	Line,
	XAxis,
	YAxis,
	CartesianGrid,
	Tooltip,
	ResponsiveContainer,
} from 'recharts';

/**
 * Format a UTC date string for axis display.
 *
 * @param {string} dateStr Date string in YYYY-MM-DD format.
 * @return {string} Formatted date.
 */
const formatDateLabel = ( dateStr ) => {
	const date = new Date( dateStr + 'T00:00:00' );
	return date.toLocaleDateString( undefined, {
		month: 'short',
		day: 'numeric',
	} );
};

/**
 * Custom tooltip.
 *
 * @param {Object}  props         Tooltip props from Recharts.
 * @param {boolean} props.active  Whether the tooltip is active.
 * @param {Array}   props.payload Tooltip data points.
 * @param {string}  props.label   X-axis label value.
 * @return {JSX.Element|null} Tooltip element or null.
 */
const ChartTooltip = ( { active, payload, label } ) => {
	if ( ! active || ! payload || ! payload.length ) {
		return null;
	}

	const date = new Date( label + 'T00:00:00' );
	const formattedDate = date.toLocaleDateString( undefined, {
		weekday: 'short',
		month: 'short',
		day: 'numeric',
		year: 'numeric',
	} );

	return (
		<div className="ppcert-chart-tooltip">
			<p className="ppcert-chart-tooltip-date">{ formattedDate }</p>
			<p style={ { color: payload[ 0 ].color } }>
				{ __( 'Awarded', 'pressprimer-certificate' ) }:{ ' ' }
				{ payload[ 0 ].value }
			</p>
		</div>
	);
};

/**
 * Activity chart component.
 *
 * @param {Object}  props               Component props.
 * @param {boolean} props.parentLoading Parent loading state.
 * @return {JSX.Element} Rendered component.
 */
const ActivityChart = ( { parentLoading } ) => {
	const [ data, setData ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ days, setDays ] = useState( 90 );

	const fetchData = useCallback( async () => {
		setLoading( true );
		try {
			const response = await apiFetch( {
				path: `/ppcert/v1/dashboard/chart?days=${ days }`,
			} );

			if ( response.success && response.data ) {
				setData( response.data );
			}
		} catch {
			// Failed to fetch - the chart shows its empty state.
		} finally {
			setLoading( false );
		}
	}, [ days ] );

	useEffect( () => {
		fetchData();
	}, [ fetchData ] );

	// Thin the axis labels (and with them the vertical grid lines, which
	// follow the ticks) to roughly five to seven per range - the density
	// of the Quiz dashboard's 30-day view.
	const getTickInterval = () => {
		if ( data.length <= 30 ) {
			return 6; // Weekly.
		}
		if ( data.length <= 90 ) {
			return 13; // Bi-weekly.
		}
		if ( data.length <= 180 ) {
			return 29; // Monthly.
		}
		if ( data.length <= 365 ) {
			return 59; // Bi-monthly.
		}
		return 119; // Quarterly for 2 years.
	};

	const hasData = data.some( ( d ) => d.issued > 0 );

	const rangeOptions = [
		{ value: 30, label: __( 'Last 30 days', 'pressprimer-certificate' ) },
		{ value: 90, label: __( 'Last 90 days', 'pressprimer-certificate' ) },
		{
			value: 180,
			label: __( 'Last 6 months', 'pressprimer-certificate' ),
		},
		{ value: 365, label: __( 'Last year', 'pressprimer-certificate' ) },
		{ value: 730, label: __( 'Last 2 years', 'pressprimer-certificate' ) },
	];

	return (
		<div className="ppcert-dashboard-card ppcert-activity-chart-card">
			<div className="ppcert-dashboard-card-header">
				<h3 className="ppcert-dashboard-card-title">
					{ __( 'Certificates Awarded', 'pressprimer-certificate' ) }
				</h3>
				<Select
					value={ days }
					onChange={ setDays }
					options={ rangeOptions }
					size="small"
					className="ppcert-chart-range-select"
					popupMatchSelectWidth={ false }
					placement="bottomRight"
				/>
			</div>

			<Spin spinning={ loading || parentLoading }>
				{ /* Keyboard and screen-reader access to the series comes
				     from recharts' built-in accessibility layer. */ }
				<div className="ppcert-activity-chart-container">
					{ hasData ? (
						<ResponsiveContainer width="100%" height={ 225 }>
							<LineChart
								data={ data }
								margin={ {
									top: 10,
									right: 30,
									left: 0,
									bottom: 0,
								} }
							>
								<CartesianGrid
									strokeDasharray="3 3"
									stroke="#f0f0f0"
								/>
								<XAxis
									dataKey="date"
									tickFormatter={ formatDateLabel }
									interval={ getTickInterval() }
									tick={ { fontSize: 11, fill: '#8c8c8c' } }
									axisLine={ { stroke: '#d9d9d9' } }
									tickLine={ { stroke: '#d9d9d9' } }
								/>
								<YAxis
									allowDecimals={ false }
									tick={ { fontSize: 11, fill: '#8c8c8c' } }
									axisLine={ { stroke: '#d9d9d9' } }
									tickLine={ { stroke: '#d9d9d9' } }
									width={ 40 }
								/>
								<Tooltip content={ <ChartTooltip /> } />
								<Line
									type="monotone"
									dataKey="issued"
									name={ __(
										'Awarded',
										'pressprimer-certificate'
									) }
									stroke="#1890ff"
									strokeWidth={ 2 }
									dot={ false }
									activeDot={ { r: 4 } }
								/>
							</LineChart>
						</ResponsiveContainer>
					) : (
						<Empty
							image={ Empty.PRESENTED_IMAGE_SIMPLE }
							description={ __(
								'No certificates awarded in this period yet.',
								'pressprimer-certificate'
							) }
							style={ { padding: '60px 0' } }
						/>
					) }
				</div>
			</Spin>
		</div>
	);
};

export default ActivityChart;
