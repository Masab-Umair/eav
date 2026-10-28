<?php
/**
 * Plugin loader.
 *
 * @package ElementorApplicationVetting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EAV_Plugin {

	/**
	 * @var EAV_Plugin|null
	 */
	private static $instance = null;

	/**
	 * @return EAV_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( 'EAV_Post_Type', 'register' ) );
		add_action( 'init', array( 'EAV_Helpers', 'migrate_legacy_data' ), 20 );

		// Registered on every request type: Elementor submits forms through admin-ajax.
		$capture = new EAV_Capture();
		$capture->init();

		$dashboard = new EAV_Dashboard();
		$dashboard->init();

		$ajax = new EAV_Ajax();
		$ajax->init();

		if ( is_admin() ) {
			EAV_Post_Type::init_admin();
			$settings = new EAV_Settings();
			$settings->init();
		}
	}

	/**
	 * Activation: register the post type and seed default settings.
	 */
	public static function activate() {
		EAV_Post_Type::register();
		EAV_Helpers::migrate_legacy_data();
		if ( false === get_option( EAV_Helpers::OPTION ) ) {
			add_option( EAV_Helpers::OPTION, EAV_Helpers::defaults() );
		}
	}
}
