<?php
/**
 * Dashboard markup. The review panel and toast are moved to <body> by
 * dashboard.js so theme containers cannot clip or offset them.
 *
 * @var array $statuses Status slug => label.
 *
 * @package ElementorApplicationVetting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div id="eav-app" class="eav-root eav-app">

	<header class="eav-header">
		<h2 class="eav-title"><?php esc_html_e( 'Practitioner applications', 'elementor-application-vetting' ); ?></h2>
		<p class="eav-subtitle" id="eav-subtitle"></p>
	</header>

	<nav class="eav-tabs" aria-label="<?php esc_attr_e( 'Application status', 'elementor-application-vetting' ); ?>">
		<?php foreach ( $statuses as $slug => $label ) : ?>
			<button type="button" class="eav-tab" data-status="<?php echo esc_attr( $slug ); ?>" aria-current="<?php echo 'pending' === $slug ? 'true' : 'false'; ?>">
				<?php echo esc_html( $label ); ?>
				<span class="eav-tab__count" data-count="<?php echo esc_attr( $slug ); ?>"></span>
			</button>
		<?php endforeach; ?>
	</nav>

	<div id="eav-grid" class="eav-grid" aria-live="polite"></div>

	<p class="eav-more">
		<button type="button" id="eav-load-more" class="eav-btn eav-btn--quiet" hidden><?php esc_html_e( 'Show more', 'elementor-application-vetting' ); ?></button>
	</p>

	<div id="eav-layer" class="eav-root eav-layer">
		<div class="eav-overlay" data-eav-close></div>
		<aside class="eav-panel" role="dialog" aria-modal="true" aria-labelledby="eav-panel-title" tabindex="-1">
			<div class="eav-panel__content" id="eav-panel-content"></div>
		</aside>
	</div>

	<div id="eav-toast" class="eav-root eav-toast" role="status" aria-live="polite"></div>
</div>
