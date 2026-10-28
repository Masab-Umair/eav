<?php
/**
 * Front-end dashboard: shortcode, access control and assets.
 *
 * @package ElementorApplicationVetting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EAV_Dashboard {

	const SHORTCODE = 'application_vetting_dashboard';

	public function init() {
		add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
		add_action( 'template_redirect', array( $this, 'guard' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	/**
	 * Does this post contain the dashboard shortcode?
	 * Elementor keeps its content in post meta, so check that as well.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	private function page_has_dashboard( $post ) {
		if ( has_shortcode( (string) $post->post_content, self::SHORTCODE ) ) {
			return true;
		}
		$elementor = get_post_meta( $post->ID, '_elementor_data', true );
		return is_string( $elementor ) && false !== strpos( $elementor, '[' . self::SHORTCODE );
	}

	/**
	 * Redirect anyone who is not a logged-in administrator before any output.
	 */
	public function guard() {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post || ! $this->page_has_dashboard( $post ) ) {
			return;
		}

		// Elementor previews render only a placeholder for this shortcode.
		if ( $this->is_builder_context() ) {
			return;
		}

		// Private page: never cache it, never index it.
		nocache_headers();
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		add_filter( 'wp_robots', 'wp_robots_no_robots' );

		if ( EAV_Helpers::user_can_manage() ) {
			return;
		}

		wp_safe_redirect( EAV_Helpers::login_url( get_permalink( $post ) ) );
		exit;
	}

	public function register_assets() {
		wp_register_style( 'eav-dashboard', EAV_URL . 'assets/css/dashboard.css', array(), EAV_VERSION );
		wp_register_script( 'eav-dashboard', EAV_URL . 'assets/js/dashboard.js', array(), EAV_VERSION, true );
	}

	/**
	 * Is Elementor's editor or preview rendering this?
	 *
	 * @return bool
	 */
	private function is_builder_context() {
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance ) ) {
			$plugin = \Elementor\Plugin::$instance;
			if ( ( isset( $plugin->editor ) && $plugin->editor->is_edit_mode() )
				|| ( isset( $plugin->preview ) && $plugin->preview->is_preview_mode() ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Shortcode output.
	 *
	 * @return string
	 */
	public function render() {
		if ( $this->is_builder_context() ) {
			return '<p class="eav-root">' . esc_html__( 'The vetting dashboard appears here on the live page.', 'elementor-application-vetting' ) . '</p>';
		}

		if ( ! EAV_Helpers::user_can_manage() ) {
			// Fallback for shortcodes the guard could not see (theme templates, widgets).
			$return = get_permalink();
			$url    = EAV_Helpers::login_url( $return ? $return : home_url( '/' ) );
			if ( ! headers_sent() ) {
				wp_safe_redirect( $url );
				exit;
			}
			return '<script>window.location.replace(' . wp_json_encode( $url ) . ');</script>'
				. '<noscript><p><a href="' . esc_url( $url ) . '">' . esc_html__( 'Log in as an administrator to view this page.', 'elementor-application-vetting' ) . '</a></p></noscript>';
		}

		wp_enqueue_style( 'eav-dashboard' );
		wp_enqueue_script( 'eav-dashboard' );

		$brand = $this->brand_css();
		if ( '' !== $brand ) {
			wp_add_inline_style( 'eav-dashboard', $brand );
		}
		wp_add_inline_script( 'eav-dashboard', 'window.EAV_CONFIG = ' . wp_json_encode( $this->js_config() ) . ';', 'before' );

		$statuses = EAV_Helpers::statuses();

		ob_start();
		include EAV_DIR . 'templates/dashboard.php';
		return ob_get_clean();
	}

	/**
	 * CSS variable overrides from the Settings page.
	 *
	 * @return string
	 */
	private function brand_css() {
		$map      = array(
			'color_primary'  => '--eav-primary',
			'color_burgundy' => '--eav-burgundy',
			'color_teal'     => '--eav-teal',
			'color_orange'   => '--eav-orange',
		);
		$defaults = EAV_Helpers::defaults();
		$options  = EAV_Helpers::options();
		$css      = '';

		foreach ( $map as $key => $var ) {
			$color = sanitize_hex_color( $options[ $key ] );
			if ( $color && strtolower( $color ) !== strtolower( $defaults[ $key ] ) ) {
				$css .= $var . ':' . $color . ';';
			}
		}
		return '' === $css ? '' : '.eav-root{' . $css . '}';
	}

	/**
	 * Strip anything that is not a valid CSS class token.
	 *
	 * @param string $value Raw setting.
	 * @return string
	 */
	private function icon_class( $value ) {
		return trim( preg_replace( '/[^A-Za-z0-9 _-]/', '', (string) $value ) );
	}

	/**
	 * Data and strings for dashboard.js.
	 *
	 * @return array
	 */
	private function js_config() {
		$ajax_path = wp_parse_url( admin_url( 'admin-ajax.php' ), PHP_URL_PATH );
		$td        = 'elementor-application-vetting';

		return array(
			'ajaxUrl' => $ajax_path ? $ajax_path : '/wp-admin/admin-ajax.php',
			'nonce'   => wp_create_nonce( 'eav_dashboard' ),
			'icons'   => array(
				'close' => $this->icon_class( EAV_Helpers::opt( 'icon_close' ) ),
				'send'  => $this->icon_class( EAV_Helpers::opt( 'icon_send' ) ),
			),
			'i18n'    => array(
				'loading'         => __( 'Loading applications', $td ),
				'genericError'    => __( 'Something went wrong. Reload the page and try again.', $td ),
				'networkError'    => __( 'The request could not reach the server. Check your connection and try again.', $td ),
				'close'           => __( 'Close', $td ),
				'back'            => __( 'Back to application', $td ),
				'submitted'       => __( 'Submitted', $td ),
				'notProvided'     => __( 'Not provided', $td ),
				'noEmail'         => __( 'No email address', $td ),
				'noEmailNote'     => __( 'No valid email address was found for this applicant, so a decision email cannot be sent.', $td ),
				'subjectSent'     => __( 'Subject sent:', $td ),
				'pendingNone'     => __( 'No applications are waiting for a decision.', $td ),
				'pendingOne'      => __( '1 application is waiting for your decision.', $td ),
				/* translators: %d: number of applications */
				'pendingMany'     => __( '%d applications are waiting for your decision.', $td ),
				'approve'         => __( 'Approve', $td ),
				'reject'          => __( 'Reject', $td ),
				'composeApprove'  => __( 'Approve application', $td ),
				'composeReject'   => __( 'Reject application', $td ),
				'to'              => __( 'To', $td ),
				'subject'         => __( 'Subject', $td ),
				'messageLabel'    => __( 'Email message', $td ),
				'sendApprove'     => __( 'Send and approve', $td ),
				'sendReject'      => __( 'Send and reject', $td ),
				'sending'         => __( 'Sending', $td ),
				'copyMe'          => __( 'Send me a copy', $td ),
				'subjectRequired' => __( 'Add a subject before sending.', $td ),
				'bodyRequired'    => __( 'Write a message before sending.', $td ),
				'discardConfirm'  => __( 'Discard this email? The application stays pending.', $td ),
				'toolbarLabel'    => __( 'Text formatting', $td ),
				'linkAddress'     => __( 'Link address', $td ),
				'linkApply'       => __( 'Add link', $td ),
				'linkRemove'      => __( 'Remove link', $td ),
				'tools'           => array(
					'bold'      => __( 'Bold', $td ),
					'italic'    => __( 'Italic', $td ),
					'underline' => __( 'Underline', $td ),
					'bullets'   => __( 'Bullets', $td ),
					'numbers'   => __( 'Numbers', $td ),
					'link'      => __( 'Link', $td ),
					'clear'     => __( 'Clear formatting', $td ),
				),
				'empty'           => array(
					'pending'  => array(
						'title' => __( 'No pending applications', $td ),
						'text'  => __( 'New submissions from the Apply page will appear here.', $td ),
					),
					'approved' => array(
						'title' => __( 'No approved applications yet', $td ),
						'text'  => __( 'Applications you approve will be listed here.', $td ),
					),
					'rejected' => array(
						'title' => __( 'No rejected applications yet', $td ),
						'text'  => __( 'Applications you reject will be listed here.', $td ),
					),
				),
			),
		);
	}
}
