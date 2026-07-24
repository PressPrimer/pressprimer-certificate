/**
 * Top templates - ranked by certificates awarded
 *
 * All-time counts from the boot fetch; titles link to the designer.
 */

import { __, _n, sprintf } from '@wordpress/i18n';
import { Empty, Skeleton } from 'antd';
import { TrophyOutlined } from '@ant-design/icons';

/**
 * Medal colors for the top three ranks.
 *
 * @param {number} index Zero-based rank.
 * @return {string} Hex color.
 */
const getMedalColor = ( index ) => {
	switch ( index ) {
		case 0:
			return '#faad14'; // Gold.
		case 1:
			return '#8c8c8c'; // Silver.
		case 2:
			return '#d48806'; // Bronze.
		default:
			return '#d9d9d9';
	}
};

/**
 * Top templates component.
 *
 * @param {Object}  props              Component props.
 * @param {Array}   props.templates    Ranked template rows.
 * @param {boolean} props.loading      Loading state.
 * @param {string}  props.templatesUrl The Templates screen URL.
 * @return {JSX.Element} Rendered component.
 */
const TopTemplates = ( { templates = [], loading, templatesUrl } ) => {
	const renderContent = () => {
		if ( loading ) {
			return (
				<div className="ppcert-top-templates-loading">
					{ [ 1, 2, 3 ].map( ( i ) => (
						<Skeleton.Input
							key={ i }
							active
							size="small"
							block
							style={ { marginBottom: 12 } }
						/>
					) ) }
				</div>
			);
		}

		if ( templates.length === 0 ) {
			return (
				<Empty
					image={ Empty.PRESENTED_IMAGE_SIMPLE }
					description={ __(
						'No certificates issued yet.',
						'pressprimer-certificate'
					) }
				/>
			);
		}

		return (
			<div className="ppcert-top-templates-list">
				{ templates.map( ( template, index ) => {
					const title =
						template.title ||
						__( '(deleted template)', 'pressprimer-certificate' );

					const editUrl =
						templatesUrl && template.title
							? `${ templatesUrl }&action=edit&template_id=${ template.template_id }`
							: '';

					return (
						<div
							key={ template.template_id }
							className="ppcert-top-template-item"
						>
							<div className="ppcert-top-template-rank">
								{ index < 3 ? (
									<TrophyOutlined
										style={ {
											color: getMedalColor( index ),
											fontSize: 18,
										} }
									/>
								) : (
									<span className="ppcert-top-template-rank-number">
										{ index + 1 }
									</span>
								) }
							</div>
							<div className="ppcert-top-template-info">
								{ editUrl ? (
									<a
										href={ editUrl }
										className="ppcert-top-template-title"
									>
										{ title }
									</a>
								) : (
									<span className="ppcert-top-template-title">
										{ title }
									</span>
								) }
								<span className="ppcert-top-template-count">
									{ sprintf(
										/* translators: %d: number of certificates */
										_n(
											'%d certificate',
											'%d certificates',
											template.total,
											'pressprimer-certificate'
										),
										template.total
									) }
								</span>
							</div>
						</div>
					);
				} ) }
			</div>
		);
	};

	return (
		<div className="ppcert-dashboard-card">
			<h3 className="ppcert-dashboard-card-title">
				{ __( 'Top Templates', 'pressprimer-certificate' ) }
			</h3>
			{ renderContent() }
		</div>
	);
};

export default TopTemplates;
