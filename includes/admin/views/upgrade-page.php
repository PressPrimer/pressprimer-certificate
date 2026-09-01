<?php
/**
 * Upgrade page view.
 *
 * Rendered by PressPrimer_Certificate_Upgrade_Page::render_page().
 * Receives the following variables in scope:
 *
 * @var array  $features           Comparison features array (from get_comparison_features).
 * @var array  $tiers              Tier registry with `active` flags; URLs already resolved (UTM-tagged buy link, or the KB URL for owned tiers).
 * @var string $featured           Featured tier id ('' when all tiers are owned).
 * @var bool   $all_active         True when every tier is owned (US-3: calm page).
 * @var string $pricing_hero_url   UTM-tagged pricing URL for the hero CTA.
 * @var string $pricing_footer_url UTM-tagged pricing URL for the footer CTA.
 * @var string $pricing_sticky_url UTM-tagged pricing URL for the sticky bar.
 * @var string $kb_url             Knowledge Base URL (owned states).
 * @var string $logo_url           URL of the white PressPrimer logo SVG.
 * @var string $hero_mascot_url    URL of the hero mascot image.
 * @var string $footer_mascot_url  URL of the footer mascot image.
 * @var PressPrimer_Certificate_Upgrade_Page $upgrade_page The controller instance, for render_cell_value().
 *
 * @package PressPrimer_Certificate
 * @subpackage Admin
 * @since 2.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Allowlist for the comparison cell markup produced by render_cell_value().
$ppcert_upgrade_cell_allowed_html = [
	'span' => [
		'class'      => true,
		'aria-label' => true,
	],
];
?>
<div class="wrap ppcert-upgrade-page">

	<section class="ppcert-upgrade-hero">
		<div class="ppcert-upgrade-hero-content">
			<img src="<?php echo esc_url( $logo_url ); ?>"
				alt="<?php esc_attr_e( 'PressPrimer', 'pressprimer-certificate' ); ?>"
				class="ppcert-upgrade-hero-logo" />
			<?php if ( $all_active ) : ?>
				<h1><?php esc_html_e( 'You Have the Complete PressPrimer Certificate Suite', 'pressprimer-certificate' ); ?></h1>
				<p class="ppcert-upgrade-intro">
					<?php esc_html_e( 'Every tier is active on this site — thank you. The Knowledge Base covers everything from issuer setup to the verification API, and our support team is a message away.', 'pressprimer-certificate' ); ?>
				</p>
				<a href="<?php echo esc_url( $kb_url ); ?>"
					class="button button-primary button-hero ppcert-upgrade-cta"
					target="_blank"
					rel="noopener noreferrer">
					<?php esc_html_e( 'Browse the Knowledge Base', 'pressprimer-certificate' ); ?>
					<span class="ppcert-upgrade-cta-arrow" aria-hidden="true">&rarr;</span>
				</a>
			<?php else : ?>
				<h1><?php esc_html_e( 'Do More with PressPrimer Certificate', 'pressprimer-certificate' ); ?></h1>
				<p class="ppcert-upgrade-intro">
					<?php esc_html_e( 'The plugin you\'re using now is complete and free on WordPress.org — design, award, and verify certificates forever. The premium tiers add professional issuing power: custom fonts and multi-page designs, bulk awarding, issuer brands with public credential pages, and the audit and white-label tools organizations need.', 'pressprimer-certificate' ); ?>
				</p>
				<a href="<?php echo esc_url( $pricing_hero_url ); ?>"
					class="button button-primary button-hero ppcert-upgrade-cta"
					target="_blank"
					rel="noopener noreferrer">
					<?php esc_html_e( 'View Pricing & Upgrade', 'pressprimer-certificate' ); ?>
					<span class="ppcert-upgrade-cta-arrow" aria-hidden="true">&rarr;</span>
				</a>
			<?php endif; ?>
		</div>
		<?php if ( $hero_mascot_url ) : ?>
			<div class="ppcert-upgrade-hero-mascot-wrap" aria-hidden="true">
				<img src="<?php echo esc_url( $hero_mascot_url ); ?>"
					alt=""
					class="ppcert-upgrade-hero-mascot"
					role="presentation" />
			</div>
		<?php endif; ?>
	</section>

	<section class="ppcert-upgrade-tiers" aria-labelledby="ppcert-upgrade-tiers-heading">
		<header class="ppcert-upgrade-section-header">
			<?php if ( $all_active ) : ?>
				<h2 id="ppcert-upgrade-tiers-heading"><?php esc_html_e( 'Your Plan', 'pressprimer-certificate' ); ?></h2>
				<p><?php esc_html_e( 'Everything below is active on this site.', 'pressprimer-certificate' ); ?></p>
			<?php else : ?>
				<h2 id="ppcert-upgrade-tiers-heading"><?php esc_html_e( 'Choose the Plan That Fits Your Program', 'pressprimer-certificate' ); ?></h2>
				<p><?php esc_html_e( 'School includes Educator, and Enterprise includes School. Every premium tier includes priority support and a 14-day money-back guarantee.', 'pressprimer-certificate' ); ?></p>
			<?php endif; ?>
		</header>

		<div class="ppcert-upgrade-tier-cards">
			<?php foreach ( $tiers as $ppcert_tier_slug => $ppcert_tier ) : ?>
				<?php
				$ppcert_is_owned    = ! empty( $ppcert_tier['active'] );
				$ppcert_is_featured = ! $ppcert_is_owned && $ppcert_tier_slug === $featured;
				$ppcert_card_class  = 'ppcert-upgrade-tier-card ppcert-upgrade-tier-' . sanitize_html_class( $ppcert_tier_slug );
				if ( $ppcert_is_featured ) {
					$ppcert_card_class .= ' is-featured';
				}
				if ( $ppcert_is_owned ) {
					$ppcert_card_class .= ' is-owned';
				}
				?>
				<div class="<?php echo esc_attr( $ppcert_card_class ); ?>">
					<?php if ( $ppcert_is_owned ) : ?>
						<span class="ppcert-upgrade-tier-badge ppcert-upgrade-tier-badge-owned">
							<?php esc_html_e( 'Active on This Site', 'pressprimer-certificate' ); ?>
						</span>
					<?php elseif ( $ppcert_is_featured ) : ?>
						<span class="ppcert-upgrade-tier-badge">
							<?php esc_html_e( 'Most Popular', 'pressprimer-certificate' ); ?>
						</span>
					<?php endif; ?>

					<h3 class="ppcert-upgrade-tier-name"><?php echo esc_html( isset( $ppcert_tier['name'] ) ? $ppcert_tier['name'] : $ppcert_tier_slug ); ?></h3>
					<?php if ( ! empty( $ppcert_tier['tagline'] ) ) : ?>
						<p class="ppcert-upgrade-tier-tagline"><?php echo esc_html( $ppcert_tier['tagline'] ); ?></p>
					<?php endif; ?>
					<?php if ( ! empty( $ppcert_tier['description'] ) ) : ?>
						<p class="ppcert-upgrade-tier-description"><?php echo esc_html( $ppcert_tier['description'] ); ?></p>
					<?php endif; ?>

					<?php if ( ! empty( $ppcert_tier['highlights'] ) && is_array( $ppcert_tier['highlights'] ) ) : ?>
						<ul class="ppcert-upgrade-tier-highlights">
							<?php foreach ( $ppcert_tier['highlights'] as $ppcert_highlight ) : ?>
								<li>
									<span class="ppcert-upgrade-tier-check" aria-hidden="true">&#10003;</span>
									<?php echo esc_html( $ppcert_highlight ); ?>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>

					<?php if ( $ppcert_is_owned ) : ?>
						<a href="<?php echo esc_url( $ppcert_tier['url'] ); ?>"
							class="button button-secondary ppcert-upgrade-tier-link"
							target="_blank"
							rel="noopener noreferrer">
							<?php esc_html_e( 'View Documentation', 'pressprimer-certificate' ); ?>
						</a>
					<?php else : ?>
						<a href="<?php echo esc_url( $ppcert_tier['url'] ); ?>"
							class="button ppcert-upgrade-tier-link <?php echo $ppcert_is_featured ? 'button-primary' : 'button-secondary'; ?>"
							target="_blank"
							rel="noopener noreferrer">
							<?php
							printf(
								/* translators: %s: tier name (Educator, School, or Enterprise) */
								esc_html__( 'Get %s', 'pressprimer-certificate' ),
								esc_html( isset( $ppcert_tier['name'] ) ? $ppcert_tier['name'] : $ppcert_tier_slug )
							);
							?>
						</a>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
	</section>

	<section class="ppcert-upgrade-comparison" aria-labelledby="ppcert-upgrade-comparison-heading">
		<header class="ppcert-upgrade-section-header">
			<h2 id="ppcert-upgrade-comparison-heading"><?php esc_html_e( 'Compare Every Feature', 'pressprimer-certificate' ); ?></h2>
			<p><?php esc_html_e( 'A complete side-by-side of what\'s in each plan.', 'pressprimer-certificate' ); ?></p>
		</header>

		<div class="ppcert-upgrade-table-scroller">
			<table class="ppcert-upgrade-table">
				<thead>
					<tr>
						<th scope="col" class="ppcert-upgrade-col-feature">
							<?php esc_html_e( 'Feature', 'pressprimer-certificate' ); ?>
						</th>
						<th scope="col" class="ppcert-upgrade-col-tier ppcert-upgrade-col-free">
							<span class="ppcert-upgrade-col-name"><?php esc_html_e( 'Free', 'pressprimer-certificate' ); ?></span>
						</th>
						<th scope="col" class="ppcert-upgrade-col-tier ppcert-upgrade-col-educator">
							<span class="ppcert-upgrade-col-name"><?php esc_html_e( 'Educator', 'pressprimer-certificate' ); ?></span>
						</th>
						<th scope="col" class="ppcert-upgrade-col-tier ppcert-upgrade-col-school">
							<span class="ppcert-upgrade-col-name"><?php esc_html_e( 'School', 'pressprimer-certificate' ); ?></span>
							<?php if ( 'school' === $featured ) : ?>
								<span class="ppcert-upgrade-col-pill"><?php esc_html_e( 'Popular', 'pressprimer-certificate' ); ?></span>
							<?php endif; ?>
						</th>
						<th scope="col" class="ppcert-upgrade-col-tier ppcert-upgrade-col-enterprise">
							<span class="ppcert-upgrade-col-name"><?php esc_html_e( 'Enterprise', 'pressprimer-certificate' ); ?></span>
						</th>
					</tr>
				</thead>
				<tbody>
					<?php
					$ppcert_upgrade_current_category = '';
					foreach ( $features as $ppcert_row ) :
						$ppcert_row_category = isset( $ppcert_row['category'] ) ? (string) $ppcert_row['category'] : '';

						// Print a category header row whenever the category changes.
						if ( $ppcert_row_category !== $ppcert_upgrade_current_category ) :
							$ppcert_upgrade_current_category = $ppcert_row_category;
							?>
							<tr class="ppcert-upgrade-category-row">
								<th colspan="5" scope="colgroup">
									<?php echo esc_html( $ppcert_upgrade_current_category ); ?>
								</th>
							</tr>
							<?php
						endif;
						?>
						<tr>
							<th scope="row" class="ppcert-upgrade-cell-label">
								<?php echo esc_html( isset( $ppcert_row['feature'] ) ? (string) $ppcert_row['feature'] : '' ); ?>
							</th>
							<?php foreach ( [ 'free', 'educator', 'school', 'enterprise' ] as $ppcert_tier_key ) : ?>
								<td class="ppcert-upgrade-cell ppcert-upgrade-cell-<?php echo esc_attr( $ppcert_tier_key ); ?>">
									<?php
									echo wp_kses(
										$upgrade_page->render_cell_value( isset( $ppcert_row[ $ppcert_tier_key ] ) ? $ppcert_row[ $ppcert_tier_key ] : false ),
										$ppcert_upgrade_cell_allowed_html
									);
									?>
								</td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</section>

	<?php if ( ! $all_active ) : ?>
		<section class="ppcert-upgrade-footer-cta" aria-labelledby="ppcert-upgrade-footer-heading">
			<?php if ( $footer_mascot_url ) : ?>
				<div class="ppcert-upgrade-footer-mascot-wrap" aria-hidden="true">
					<img src="<?php echo esc_url( $footer_mascot_url ); ?>"
						alt=""
						class="ppcert-upgrade-footer-mascot"
						role="presentation" />
				</div>
			<?php endif; ?>
			<div class="ppcert-upgrade-footer-content">
				<h2 id="ppcert-upgrade-footer-heading"><?php esc_html_e( 'Try Risk-Free for 14 Days', 'pressprimer-certificate' ); ?></h2>
				<p>
					<?php esc_html_e( 'Every premium plan comes with a 14-day money-back guarantee. If PressPrimer Certificate isn\'t the right fit, we\'ll refund your purchase — no questions asked.', 'pressprimer-certificate' ); ?>
				</p>
				<a href="<?php echo esc_url( $pricing_footer_url ); ?>"
					class="button button-primary ppcert-upgrade-footer-button"
					target="_blank"
					rel="noopener noreferrer">
					<?php esc_html_e( 'View Pricing & Upgrade', 'pressprimer-certificate' ); ?>
				</a>
			</div>
		</section>

		<div class="ppcert-upgrade-sticky-bar" role="region" aria-label="<?php esc_attr_e( 'Upgrade actions', 'pressprimer-certificate' ); ?>">
			<div class="ppcert-upgrade-sticky-bar-inner">
				<div class="ppcert-upgrade-sticky-bar-text">
					<strong><?php esc_html_e( 'Ready to upgrade PressPrimer Certificate?', 'pressprimer-certificate' ); ?></strong>
					<span><?php esc_html_e( 'Compare plans and pick the tier that fits your program. 14-day money-back guarantee.', 'pressprimer-certificate' ); ?></span>
				</div>
				<a href="<?php echo esc_url( $pricing_sticky_url ); ?>"
					class="button button-primary button-hero ppcert-upgrade-sticky-bar-cta"
					target="_blank"
					rel="noopener noreferrer">
					<?php esc_html_e( 'Upgrade', 'pressprimer-certificate' ); ?>
				</a>
			</div>
		</div>
	<?php endif; ?>

</div>
