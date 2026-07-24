/**
 * Recent certificates - the newest awards
 *
 * Issued_at values arrive as UTC MySQL datetimes; they normalize to
 * ISO-with-Z before parsing so relative times are correct in every
 * viewer timezone (UTC in, localized out).
 */

import { __ } from '@wordpress/i18n';
import { Table, Tag, Empty, Button } from 'antd';
import { UserOutlined, ArrowRightOutlined } from '@ant-design/icons';

/**
 * Format a UTC MySQL datetime relative to now.
 *
 * @param {string} dateStr Date string (MySQL format, UTC).
 * @return {string} Formatted date.
 */
const formatDate = ( dateStr ) => {
	if ( ! dateStr ) {
		return '-';
	}

	let normalized = dateStr;
	if (
		! dateStr.endsWith( 'Z' ) &&
		! dateStr.includes( '+' ) &&
		! dateStr.includes( 'T' )
	) {
		normalized = dateStr.replace( ' ', 'T' ) + 'Z';
	}

	const date = new Date( normalized );

	if ( isNaN( date.getTime() ) ) {
		return '-';
	}

	const diffMins = Math.floor( ( new Date() - date ) / 60000 );
	if ( diffMins < 1 ) {
		return __( 'Just now', 'pressprimer-certificate' );
	}
	if ( diffMins < 60 ) {
		return `${ diffMins }m ${ __( 'ago', 'pressprimer-certificate' ) }`;
	}

	const diffHours = Math.floor( diffMins / 60 );
	if ( diffHours < 24 ) {
		return `${ diffHours }h ${ __( 'ago', 'pressprimer-certificate' ) }`;
	}

	const diffDays = Math.floor( diffHours / 24 );
	if ( diffDays < 7 ) {
		return `${ diffDays }d ${ __( 'ago', 'pressprimer-certificate' ) }`;
	}

	return date.toLocaleDateString();
};

const STATUS_CONFIG = {
	issued: {
		label: __( 'Issued', 'pressprimer-certificate' ),
		color: 'green',
	},
	expired: {
		label: __( 'Expired', 'pressprimer-certificate' ),
		color: 'orange',
	},
	revoked: {
		label: __( 'Revoked', 'pressprimer-certificate' ),
		color: 'red',
	},
};

/**
 * Recent certificates component.
 *
 * @param {Object}  props                 Component props.
 * @param {Array}   props.certificates    Recent certificate rows.
 * @param {boolean} props.loading         Loading state.
 * @param {string}  props.certificatesUrl The Certificates screen URL.
 * @return {JSX.Element} Rendered component.
 */
const RecentCertificates = ( {
	certificates = [],
	loading,
	certificatesUrl,
} ) => {
	const columns = [
		{
			title: __( 'Recipient', 'pressprimer-certificate' ),
			dataIndex: 'recipient_name',
			key: 'recipient',
			render: ( name ) => (
				<div className="ppcert-recent-recipient">
					<UserOutlined className="ppcert-recent-recipient-icon" />
					<span>
						{ name || __( 'Unknown', 'pressprimer-certificate' ) }
					</span>
				</div>
			),
		},
		{
			title: __( 'Certificate', 'pressprimer-certificate' ),
			dataIndex: 'template_title',
			key: 'template',
			ellipsis: true,
			render: ( title ) =>
				title || __( '(deleted template)', 'pressprimer-certificate' ),
		},
		{
			title: __( 'Credential ID', 'pressprimer-certificate' ),
			dataIndex: 'credential_id',
			key: 'credential',
			render: ( credential, row ) =>
				row.verify_url ? (
					<a
						href={ row.verify_url }
						target="_blank"
						rel="noreferrer"
						className="ppcert-recent-credential"
					>
						{ credential }
					</a>
				) : (
					<span className="ppcert-recent-credential">
						{ credential }
					</span>
				),
		},
		{
			title: __( 'Status', 'pressprimer-certificate' ),
			dataIndex: 'status',
			key: 'status',
			render: ( status ) => {
				const config = STATUS_CONFIG[ status ] || {
					label: status,
					color: 'default',
				};
				return <Tag color={ config.color }>{ config.label }</Tag>;
			},
		},
		{
			title: __( 'Earned', 'pressprimer-certificate' ),
			dataIndex: 'issued_at',
			key: 'earned',
			render: formatDate,
		},
	];

	return (
		<div className="ppcert-dashboard-card ppcert-dashboard-card--large">
			<div className="ppcert-dashboard-card-header">
				<h3 className="ppcert-dashboard-card-title">
					{ __( 'Recent Certificates', 'pressprimer-certificate' ) }
				</h3>
				{ certificatesUrl && (
					<Button
						type="link"
						href={ certificatesUrl }
						className="ppcert-dashboard-card-action"
					>
						{ __( 'View all', 'pressprimer-certificate' ) }
						<ArrowRightOutlined />
					</Button>
				) }
			</div>

			<div className="ppcert-recent-table">
				<Table
					dataSource={ certificates }
					columns={ columns }
					rowKey="id"
					pagination={ false }
					size="small"
					loading={ loading }
					locale={ {
						emptyText: (
							<Empty
								image={ Empty.PRESENTED_IMAGE_SIMPLE }
								description={ __(
									'No certificates issued yet.',
									'pressprimer-certificate'
								) }
							/>
						),
					} }
				/>
			</div>
		</div>
	);
};

export default RecentCertificates;
