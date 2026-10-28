<?php
/**
 * Plugin Name:       Elementor Application Vetting
 * Description:       Captures Elementor Pro practitioner applications and adds a front-end dashboard for reviewing them and approving or rejecting each one by email.
 * Version:           1.2.0
 * Requires at least: 6.0 
 * Requires PHP:      7.4
 * Author:            Masab
 * Author URI:        https://masab.vercel.app/
 * License:           GPL-2.0-or-later
 * Text Domain:       elementor-application-vetting
 *
 * @package ElementorApplicationVetting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EAV_VERSION', '1.2.0' );
define( 'EAV_FILE', __FILE__ );
define( 'EAV_DIR', plugin_dir_path( __FILE__ ) );
define( 'EAV_URL', plugin_dir_url( __FILE__ ) );

/*
 * Custom post type slug. WordPress limits post type keys to 20 characters,
 * so "practitioner_application" (24) cannot be used.
 */
define( 'EAV_CPT', 'eav_application' );

require_once EAV_DIR . 'includes/class-eav-helpers.php';
require_once EAV_DIR . 'includes/class-eav-post-type.php';
require_once EAV_DIR . 'includes/class-eav-capture.php';
require_once EAV_DIR . 'includes/class-eav-mailer.php';
require_once EAV_DIR . 'includes/class-eav-dashboard.php';
require_once EAV_DIR . 'includes/class-eav-ajax.php';
require_once EAV_DIR . 'includes/class-eav-settings.php';
require_once EAV_DIR . 'includes/class-eav-plugin.php';

register_activation_hook( __FILE__, array( 'EAV_Plugin', 'activate' ) );

EAV_Plugin::instance();
