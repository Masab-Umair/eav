<?php
/**
 * Application custom post type and wp-admin views.
 *
 * Meta keys used on each application:
 *   _eav_status          pending | approved | rejected
 *   _eav_name            Applicant display name
 *   _eav_first_name      First name (used for email placeholders)
 *   _eav_last_name       Last name
 *   _eav_email           Applicant email (the only address decision emails go to)
 *   _eav_form            Identifier of the form that produced the record
 *   _eav_fields          Array of id, label, type, value rows
 *   _eav_decided_at      Unix timestamp of the decision
 *   _eav_decided_by      User ID of the administrator who decided
 *   _eav_email_subject   Subject of the decision email
 *   _eav_email_body      HTML body of the decision email
 *
 * @package ElementorApplicationVetting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EAV_Post_Type {

	/**
	 * Register the post type. Private, administrators only.
	 */
	public static function register() {
		// Use post-type-specific capabilities so this private post type cannot
		// override WordPress capabilities used by unrelated admin screens.
		$capabilities = array(
			'edit_post'              => 'edit_eav_application',
			'read_post'              => 'read_eav_application',
			'delete_post'            => 'delete_eav_application',
			'edit_posts'             => 'edit_eav_applications',
			'edit_others_posts'      => 'edit_others_eav_applications',
			'delete_posts'           => 'delete_eav_applications',
			'publish_posts'          => 'publish_eav_applications',
			'read_private_posts'     => 'read_private_eav_applications',
			'delete_private_posts'   => 'delete_private_eav_applications',
			'delete_published_posts' => 'delete_published_eav_applications',
			'delete_others_posts'    => 'delete_others_eav_applications',
			'edit_private_posts'     => 'edit_private_eav_applications',
			'edit_published_posts'   => 'edit_published_eav_applications',
			'create_posts'           => 'do_not_allow',
		);

		// Translate only this post type's custom caps to the administrator cap.
		add_filter(
			'map_meta_cap',
			static function ( $required_caps, $cap, $user_id, $args ) use ( $capabilities ) {
				if ( in_array( $cap, $capabilities, true ) && 'do_not_allow' !== $cap ) {
					return array( 'manage_options' );
				}
				return $required_caps;
			},
			10,
			4
		);

		register_post_type(
			EAV_CPT,
			array(
				'labels'              => array(
					'name'               => __( 'Applications', 'elementor-application-vetting' ),
					'singular_name'      => __( 'Application', 'elementor-application-vetting' ),
					'menu_name'          => __( 'Applications', 'elementor-application-vetting' ),
					'all_items'          => __( 'All applications', 'elementor-application-vetting' ),
					'edit_item'          => __( 'View application', 'elementor-application-vetting' ),
					'search_items'       => __( 'Search applications', 'elementor-application-vetting' ),
					'not_found'          => __( 'No applications yet.', 'elementor-application-vetting' ),
					'not_found_in_trash' => __( 'No applications in the trash.', 'elementor-application-vetting' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_nav_menus'   => false,
				'show_in_rest'        => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'menu_position'       => 26,
				'supports'            => array( 'title' ),
				'map_meta_cap'        => true,
				'capabilities'        => $capabilities,
			)
		);
	}

	/**
	 * Hook the wp-admin screens.
	 */
	public static function init_admin() {
		add_filter( 'manage_' . EAV_CPT . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . EAV_CPT . '_posts_custom_column', array( __CLASS__, 'column_content' ), 10, 2 );
		add_action( 'add_meta_boxes_' . EAV_CPT, array( __CLASS__, 'add_meta_boxes' ) );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
	}

	/**
	 * List table columns.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public static function columns( $columns ) {
		return array(
			'cb'         => isset( $columns['cb'] ) ? $columns['cb'] : '',
			'title'      => __( 'Applicant', 'elementor-application-vetting' ),
			'eav_email'  => __( 'Email', 'elementor-application-vetting' ),
			'eav_status' => __( 'Status', 'elementor-application-vetting' ),
			'date'       => __( 'Submitted', 'elementor-application-vetting' ),
		);
	}

	/**
	 * List table cell content.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public static function column_content( $column, $post_id ) {
		if ( 'eav_email' === $column ) {
			echo esc_html( get_post_meta( $post_id, '_eav_email', true ) );
		}
		if ( 'eav_status' === $column ) {
			echo esc_html( EAV_Helpers::status_label( get_post_meta( $post_id, '_eav_status', true ) ) );
		}
	}

	/**
	 * Remove quick edit; applications are decided on the front-end dashboard.
	 *
	 * @param array   $actions Row actions.
	 * @param WP_Post $post    Post.
	 * @return array
	 */
	public static function row_actions( $actions, $post ) {
		if ( EAV_CPT === $post->post_type ) {
			unset( $actions['inline hide-if-no-js'] );
		}
		return $actions;
	}

	/**
	 * Register the details meta box.
	 */
	public static function add_meta_boxes() {
		add_meta_box(
			'eav_details',
			__( 'Submitted application', 'elementor-application-vetting' ),
			array( __CLASS__, 'render_meta_box' ),
			EAV_CPT,
			'normal',
			'high'
		);
	}

	/**
	 * Read-only view of everything the applicant submitted.
	 *
	 * @param WP_Post $post Application.
	 */
	public static function render_meta_box( $post ) {
		$fields = get_post_meta( $post->ID, '_eav_fields', true );
		$fields = is_array( $fields ) ? $fields : array();
		$status = get_post_meta( $post->ID, '_eav_status', true );

		echo '<p><strong>' . esc_html__( 'Status:', 'elementor-application-vetting' ) . '</strong> ' . esc_html( EAV_Helpers::status_label( $status ) ) . '</p>';

		if ( empty( $fields ) ) {
			echo '<p>' . esc_html__( 'No form data was stored for this application.', 'elementor-application-vetting' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><tbody>';
		foreach ( $fields as $field ) {
			printf(
				'<tr><th style="width:240px;">%1$s</th><td>%2$s</td></tr>',
				esc_html( $field['label'] ),
				wp_kses_post( nl2br( make_clickable( esc_html( $field['value'] ) ) ) )
			);
		}
		echo '</tbody></table>';
	}
}
