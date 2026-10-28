<?php
/**
 * Shared helpers.
 *
 * @package ElementorApplicationVetting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EAV_Helpers {

	const OPTION = 'eav_settings';

	const STATUS_PENDING  = 'pending';
	const STATUS_APPROVED = 'approved';
	const STATUS_REJECTED = 'rejected';

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'form_ids'            => 'apply-practitioner',
			'first_name_field'    => '',
			'last_name_field'     => '',
			'email_field'         => '',
			'log_unmatched'       => 0,
			'from_name'           => '',
			'from_email'          => '',
			'reply_to'            => '',
			'color_primary'       => '#4a2268',
			'color_burgundy'      => '#8c1d40',
			'color_teal'          => '#13766f',
			'color_orange'        => '#de7a2b',
			'approve_subject'     => 'Your practitioner application',
			'approve_body'        => "Hello {first_name},\n\n",
			'reject_subject'      => 'Your practitioner application',
			'reject_body'         => "Hello {first_name},\n\n",
			'icon_close'          => '',
			'icon_send'           => '',
			'delete_on_uninstall' => 0,
		);
	}

	/**
	 * Move data from the previous MAV identifiers to the EAV identifiers.
	 */
	public static function migrate_legacy_data() {
		if ( get_option( 'eav_legacy_migration_complete', false ) ) {
			return;
		}

		$legacy_options  = get_option( 'mav_settings', false );
		$current_options = get_option( self::OPTION, false );
		if ( is_array( $legacy_options ) ) {
			$options = is_array( $current_options )
				? array_merge( $legacy_options, $current_options )
				: $legacy_options;
			update_option( self::OPTION, $options );
			delete_option( 'mav_settings' );
		}

		$legacy_ids = get_posts(
			array(
				'post_type'   => 'mav_application',
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
			)
		);

		foreach ( $legacy_ids as $post_id ) {
			$post_id = (int) $post_id;
			foreach ( get_post_meta( $post_id ) as $meta_key => $values ) {
				if ( 0 !== strpos( $meta_key, '_mav_' ) ) {
					continue;
				}

				$new_key = '_eav_' . substr( $meta_key, 5 );
				foreach ( $values as $value ) {
					update_post_meta( $post_id, $new_key, wp_slash( $value ) );
				}
				delete_post_meta( $post_id, $meta_key );
			}

			$result = wp_update_post(
				array(
					'ID'        => $post_id,
					'post_type' => EAV_CPT,
				),
				true
			);
			if ( is_wp_error( $result ) || ! $result ) {
				self::log( 'Could not migrate application ' . $post_id . ' from the previous post type.', true );
				return;
			}
		}

		update_option( 'eav_legacy_migration_complete', 1 );
	}

	/**
	 * Saved settings merged over the defaults.
	 *
	 * @return array
	 */
	public static function options() {
		$saved = get_option( self::OPTION, array() );
		if ( is_array( $saved ) ) {
			$subjects_updated = false;
			foreach ( array( 'approve_subject', 'reject_subject' ) as $key ) {
				if ( isset( $saved[ $key ] ) && 'Your Mynd Myself practitioner application' === $saved[ $key ] ) {
					$saved[ $key ]  = 'Your practitioner application';
					$subjects_updated = true;
				}
			}
			if ( $subjects_updated ) {
				update_option( self::OPTION, $saved );
			}
		}
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/**
	 * Single setting value.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function opt( $key ) {
		$options = self::options();
		return isset( $options[ $key ] ) ? $options[ $key ] : '';
	}

	/**
	 * Status slug => label.
	 *
	 * @return array
	 */
	public static function statuses() {
		return array(
			self::STATUS_PENDING  => __( 'Pending', 'elementor-application-vetting' ),
			self::STATUS_APPROVED => __( 'Approved', 'elementor-application-vetting' ),
			self::STATUS_REJECTED => __( 'Rejected', 'elementor-application-vetting' ),
		);
	}

	/**
	 * Label for one status.
	 *
	 * @param string $status Status slug.
	 * @return string
	 */
	public static function status_label( $status ) {
		$all = self::statuses();
		return isset( $all[ $status ] ) ? $all[ $status ] : $status;
	}

	/**
	 * Only logged-in administrators may use the dashboard.
	 *
	 * @return bool
	 */
	public static function user_can_manage() {
		$cap = apply_filters( 'mav_required_capability', apply_filters( 'eav_required_capability', 'manage_options' ) );
		return is_user_logged_in() && current_user_can( $cap );
	}

	/**
	 * Login URL that returns the visitor to the dashboard page.
	 * Logged-in non-admins get reauth=1 so wp-login.php shows the form
	 * instead of bouncing them straight back (which would loop).
	 *
	 * @param string $return_url Page to return to after login.
	 * @return string
	 */
	public static function login_url( $return_url ) {
		$url = wp_login_url( $return_url );
		if ( is_user_logged_in() ) {
			$url = add_query_arg( 'reauth', '1', $url );
		}
		return $url;
	}

	/**
	 * Lowercase letters and digits only.
	 *
	 * @param string $value Raw string.
	 * @return string
	 */
	private static function norm( $value ) {
		return preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $value ) );
	}

	/**
	 * Work out name and email from the captured field rows.
	 * Uses the field IDs configured in settings first, then auto-detects.
	 *
	 * @param array $rows Rows of id, label, type, value.
	 * @return array first, last, name, email.
	 */
	public static function detect_identity( array $rows ) {
		$options = self::options();

		$by_id = array();
		foreach ( $rows as $row ) {
			$by_id[ strtolower( $row['id'] ) ] = $row['value'];
		}
		$pick = function ( $configured ) use ( $by_id ) {
			$key = strtolower( trim( (string) $configured ) );
			return ( '' !== $key && isset( $by_id[ $key ] ) ) ? trim( $by_id[ $key ] ) : '';
		};

		$first = $pick( $options['first_name_field'] );
		$last  = $pick( $options['last_name_field'] );
		$email = $pick( $options['email_field'] );
		$full  = '';

		$first_keys = array( 'firstname', 'fname', 'givenname' );
		$last_keys  = array( 'lastname', 'lname', 'surname', 'familyname' );
		$full_keys  = array( 'name', 'fullname', 'yourname' );

		foreach ( $rows as $row ) {
			$value = trim( $row['value'] );
			if ( '' === $value ) {
				continue;
			}
			$id_n    = self::norm( $row['id'] );
			$label_n = self::norm( $row['label'] );

			if ( '' === $first && ( in_array( $id_n, $first_keys, true ) || in_array( $label_n, $first_keys, true ) ) ) {
				$first = $value;
			}
			if ( '' === $last && ( in_array( $id_n, $last_keys, true ) || in_array( $label_n, $last_keys, true ) ) ) {
				$last = $value;
			}
			if ( '' === $full && ( in_array( $id_n, $full_keys, true ) || in_array( $label_n, $full_keys, true ) ) ) {
				$full = $value;
			}
			if ( '' === $email && 'email' === $row['type'] && ! preg_match( '/confirm|verify|repeat|retype/', $id_n . $label_n ) ) {
				$email = $value;
			}
		}

		// Last resort: any email-typed value.
		if ( '' === $email ) {
			foreach ( $rows as $row ) {
				if ( 'email' === $row['type'] && is_email( trim( $row['value'] ) ) ) {
					$email = trim( $row['value'] );
					break;
				}
			}
		}

		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) {
			$email = '';
		}

		$name = trim( $first . ' ' . $last );
		if ( '' === $name ) {
			$name = $full;
		}
		if ( '' === $name && '' !== $email ) {
			$name = strstr( $email, '@', true );
		}
		if ( '' === $name ) {
			$name = __( 'Unnamed applicant', 'elementor-application-vetting' );
		}

		return array(
			'first' => $first,
			'last'  => $last,
			'name'  => $name,
			'email' => $email,
		);
	}

	/**
	 * Replace {first_name}, {last_name}, {full_name}, {site_name}.
	 *
	 * @param string $template Template text.
	 * @param array  $identity first, last, name.
	 * @param bool   $escape   HTML-escape the inserted values (use for body HTML).
	 * @return string
	 */
	public static function apply_placeholders( $template, array $identity, $escape = false ) {
		$values = array(
			'{first_name}' => $identity['first'],
			'{last_name}'  => $identity['last'],
			'{full_name}'  => $identity['name'],
			'{site_name}'  => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
		);
		if ( '' === $values['{first_name}'] ) {
			$values['{first_name}'] = $identity['name'];
		}
		if ( $escape ) {
			$values = array_map( 'esc_html', $values );
		}
		return strtr( (string) $template, $values );
	}

	/**
	 * Write to the PHP error log.
	 *
	 * @param string $message Message.
	 * @param bool   $force   Log even when WP_DEBUG is off.
	 */
	public static function log( $message, $force = false ) {
		if ( $force || ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			error_log( '[Application Vetting] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
