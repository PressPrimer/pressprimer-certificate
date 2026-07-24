/**
 * Stats cards - key certificate metrics
 *
 * Total awarded, recent awarded, recent verifications, and published
 * templates. The "recent" window arrives from the REST response so the
 * labels always match the server's aggregation.
 */

import { __, sprintf } from '@wordpress/i18n';
import {
	SafetyCertificateOutlined,
	RiseOutlined,
	CheckCircleOutlined,
	FileTextOutlined,
} from '@ant-design/icons';

/**
 * Stats cards component.
 *
 * @param {Object}  props         Component props.
 * @param {Object}  props.stats   Statistics data from the REST response.
 * @param {boolean} props.loading Loading state.
 * @return {JSX.Element} Rendered component.
 */
const StatsCards = ( { stats, loading } ) => {
	// Consistent blue styling for all card icons (ecosystem pattern).
	const iconColor = '#1890ff';
	const iconBgColor = '#e6f7ff';

	const windowDays = stats?.window_days ?? 30;

	const cards = [
		{
			key: 'total',
			label: __( 'Certificates Awarded', 'pressprimer-certificate' ),
			value: stats?.total_certificates ?? '-',
			icon: <SafetyCertificateOutlined />,
		},
		{
			key: 'issued_recent',
			label: sprintf(
				/* translators: %d: number of days */
				__( 'Awarded (%d days)', 'pressprimer-certificate' ),
				windowDays
			),
			value: stats?.issued_recent ?? '-',
			icon: <RiseOutlined />,
		},
		{
			key: 'verified_recent',
			label: sprintf(
				/* translators: %d: number of days */
				__( 'Verifications (%d days)', 'pressprimer-certificate' ),
				windowDays
			),
			value: stats?.verified_recent ?? '-',
			icon: <CheckCircleOutlined />,
		},
		{
			key: 'published_templates',
			label: __( 'Published Templates', 'pressprimer-certificate' ),
			value: stats?.published_templates ?? '-',
			icon: <FileTextOutlined />,
		},
	];

	return (
		<div className="ppcert-stats-cards">
			{ cards.map( ( card ) => (
				<div key={ card.key } className="ppcert-stats-card">
					<div
						className="ppcert-stats-card-icon"
						style={ {
							color: iconColor,
							backgroundColor: iconBgColor,
						} }
					>
						{ card.icon }
					</div>
					<div className="ppcert-stats-card-content">
						<div className="ppcert-stats-card-value">
							{ loading ? '-' : card.value }
						</div>
						<div className="ppcert-stats-card-label">
							{ card.label }
						</div>
					</div>
				</div>
			) ) }
		</div>
	);
};

export default StatsCards;
