<?php
/**
 * AJAX endpoints for the dashboard. Every request is checked for a valid
 * nonce and the administrator capability.
 *
 * @package ElementorApplicationVetting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EAV_Ajax {

	const PER_PAGE = 12;

	public function init() {
		add_action( 'wp_ajax_eav_list', array( $this, 'handle_list' ) );
		add_action( 'wp_ajax_eav_get', array( $this, 'handle_get' ) );
		add_action( 'wp_ajax_eav_decide', array( $this, 'handle_decide' ) );
		add_action( 'wp_ajax_mav_list', array( $this, 'handle_list' ) );
		add_action( 'wp_ajax_mav_get', array( $this, 'handle_get' ) );
		add_action( 'wp_ajax_mav_decide', array( $this, 'handle_decide' ) );
	}

	/**
	 * Reject anything that is not a nonce-checked administrator request.
	 */
	private function guard() {
		if ( ! EAV_Helpers::user_can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'elementor-application-vetting' ) ), 403 );
		}
		if ( ! check_ajax_referer( 'eav_dashboard', 'nonce', false ) && ! check_ajax_referer( 'mav_dashboard', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Your session has expired. Reload the page and try again.', 'elementor-application-vetting' ) ), 403 );
		}
	}

	/**
	 * Validate a requested status slug.
	 *
	 * @param string $status Raw value.
	 * @return string
	 */
	private function clean_status( $status ) {
		$status = sanitize_key( $status );
		return array_key_exists( $status, EAV_Helpers::statuses() ) ? $status : EAV_Helpers::STATUS_PENDING;
	}

	/**
	 * Load an application or stop with a 404.
	 *
	 * @param int $id Post ID.
	 * @return WP_Post
	 */
	private function get_application( $id ) {
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || EAV_CPT !== $post->post_type || 'publish' !== $post->post_status ) {
			wp_send_json_error( array( 'message' => __( 'That application could not be found.', 'elementor-application-vetting' ) ), 404 );
		}
		return $post;
	}

	/**
	 * Applications per status.
	 *
	 * @return array
	 */
	private function counts() {
		$counts = array();
		foreach ( array_keys( EAV_Helpers::statuses() ) as $status ) {
			$query            = new WP_Query(
				array(
					'post_type'              => EAV_CPT,
					'post_status'            => 'publish',
					'posts_per_page'         => 1,
					'fields'                 => 'ids',
					'meta_key'               => '_eav_status', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'             => $status, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'update_post_term_cache' => false,
					'update_post_meta_cache' => false,
				)
			);
			$counts[ $status ] = (int) $query->found_posts;
		}
		return $counts;
	}

	/**
	 * Card data.
	 *
	 * @param WP_Post $post Application.
	 * @return array
	 */
	private function summary( $post ) {
		$status = (string) get_post_meta( $post->ID, '_eav_status', true );
		$ts     = (int) get_post_time( 'U', true, $post );
		$name   = (string) get_post_meta( $post->ID, '_eav_name', true );
		$at     = (int) get_post_meta( $post->ID, '_eav_decided_at', true );

		return array(
			'id'           => $post->ID,
			'name'         => '' !== $name ? $name : $post->post_title,
			'email'        => (string) get_post_meta( $post->ID, '_eav_email', true ),
			'status'       => $status,
			'status_label' => EAV_Helpers::status_label( $status ),
			'date'         => wp_date( get_option( 'date_format' ), $ts ),
			'date_iso'     => gmdate( 'c', $ts ),
			'decided'      => $at ? wp_date( get_option( 'date_format' ), $at ) : '',
		);
	}

	/**
	 * List applications for one status.
	 */
	public function handle_list() {
		$this->guard();

		$status = $this->clean_status( isset( $_POST['status'] ) ? wp_unslash( $_POST['status'] ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$paged  = isset( $_POST['page'] ) ? max( 1, (int) $_POST['page'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$query = new WP_Query(
			array(
				'post_type'           => EAV_CPT,
				'post_status'         => 'publish',
				'posts_per_page'      => self::PER_PAGE,
				'paged'               => $paged,
				'orderby'             => 'date',
				'order'               => 'DESC',
				'ignore_sticky_posts' => true,
				'meta_key'            => '_eav_status', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'          => $status, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = $this->summary( $post );
		}

		wp_send_json_success(
			array(
				'items'    => $items,
				'has_more' => $paged < (int) $query->max_num_pages,
				'counts'   => $this->counts(),
				'status'   => $status,
			)
		);
	}

	/**
	 * Full detail for one application, plus email starter text.
	 */
	public function handle_get() {
		$this->guard();

		$post = $this->get_application( isset( $_POST['id'] ) ? (int) $_POST['id'] : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$summary = $this->summary( $post );
		$fields  = get_post_meta( $post->ID, '_eav_fields', true );
		$fields  = is_array( $fields ) ? array_values( $fields ) : array();

		$identity = array(
			'first' => (string) get_post_meta( $post->ID, '_eav_first_name', true ),
			'last'  => (string) get_post_meta( $post->ID, '_eav_last_name', true ),
			'name'  => $summary['name'],
		);

		$compose = array();
		foreach ( array( 'approve', 'reject' ) as $type ) {
			$subject         = EAV_Helpers::apply_placeholders( EAV_Helpers::opt( $type . '_subject' ), $identity, false );
			$body            = EAV_Helpers::apply_placeholders( EAV_Helpers::opt( $type . '_body' ), $identity, true );
			$compose[ $type ] = array(
				'subject' => sanitize_text_field( $subject ),
				'body'    => wpautop( wp_kses_post( $body ) ),
			);
		}

		$decision = null;
		if ( EAV_Helpers::STATUS_PENDING !== $summary['status'] ) {
			$at      = (int) get_post_meta( $post->ID, '_eav_decided_at', true );
			$by_user = get_userdata( (int) get_post_meta( $post->ID, '_eav_decided_by', true ) );
			$when    = $at ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $at ) : '';

			if ( $by_user ) {
				/* translators: 1: Approved or Rejected, 2: date and time, 3: administrator name */
				$line = sprintf( __( '%1$s on %2$s by %3$s', 'elementor-application-vetting' ), $summary['status_label'], $when, $by_user->display_name );
			} else {
				/* translators: 1: Approved or Rejected, 2: date and time */
				$line = sprintf( __( '%1$s on %2$s', 'elementor-application-vetting' ), $summary['status_label'], $when );
			}

			$decision = array(
				'summary' => $line,
				'subject' => (string) get_post_meta( $post->ID, '_eav_email_subject', true ),
			);
		}

		wp_send_json_success(
			array(
				'id'           => $post->ID,
				'name'         => $summary['name'],
				'email'        => $summary['email'],
				'status'       => $summary['status'],
				'status_label' => $summary['status_label'],
				'date_full'    => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) get_post_time( 'U', true, $post ) ),
				'fields'       => $fields,
				'compose'      => $compose,
				'decision'     => $decision,
			)
		);
	}

	/**
	 * Send the email, then record the decision. The status only changes
	 * after wp_mail() succeeds.
	 */
	public function handle_decide() {
		$this->guard();

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$id       = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$decision = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
		$subject  = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
		$body     = isset( $_POST['body'] ) ? wp_kses_post( wp_unslash( $_POST['body'] ) ) : '';
		$copy     = ! empty( $_POST['copy'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! in_array( $decision, array( EAV_Helpers::STATUS_APPROVED, EAV_Helpers::STATUS_REJECTED ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown decision.', 'elementor-application-vetting' ) ), 400 );
		}
		if ( '' === $subject ) {
			wp_send_json_error( array( 'message' => __( 'Add a subject before sending.', 'elementor-application-vetting' ) ), 400 );
		}
		if ( '' === trim( wp_strip_all_tags( $body ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Write a message before sending.', 'elementor-application-vetting' ) ), 400 );
		}

		$post = $this->get_application( $id );

		if ( EAV_Helpers::STATUS_PENDING !== get_post_meta( $post->ID, '_eav_status', true ) ) {
			wp_send_json_error( array( 'message' => __( 'This application has already been decided.', 'elementor-application-vetting' ) ), 409 );
		}

		// The recipient always comes from the stored record, never from the request.
		$to = (string) get_post_meta( $post->ID, '_eav_email', true );
		if ( ! is_email( $to ) ) {
			wp_send_json_error( array( 'message' => __( 'This application has no valid email address.', 'elementor-application-vetting' ) ), 400 );
		}

		$lock = 'eav_lock_' . $post->ID;
		if ( get_transient( $lock ) ) {
			wp_send_json_error( array( 'message' => __( 'This application is already being processed.', 'elementor-application-vetting' ) ), 409 );
		}
		set_transient( $lock, 1, MINUTE_IN_SECONDS );

		$result = EAV_Mailer::send( $to, $subject, $body, $copy );

		if ( is_wp_error( $result ) ) {
			delete_transient( $lock );
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
		}

		update_post_meta( $post->ID, '_eav_status', $decision );
		update_post_meta( $post->ID, '_eav_decided_at', time() );
		update_post_meta( $post->ID, '_eav_decided_by', get_current_user_id() );
		update_post_meta( $post->ID, '_eav_email_subject', wp_slash( $subject ) );
		update_post_meta( $post->ID, '_eav_email_body', wp_slash( $body ) );
		delete_transient( $lock );

		/**
		 * Fires after a decision email was sent and the status saved.
		 *
		 * @param int    $post_id  Application ID.
		 * @param string $decision approved or rejected.
		 * @param string $subject  Email subject.
		 * @param string $body     Email body HTML.
		 */
		do_action( 'eav_application_decided', $post->ID, $decision, $subject, $body );

		if ( EAV_Helpers::STATUS_APPROVED === $decision ) {
			/* translators: %s: applicant email address */
			$message = sprintf( __( 'Approved. Your email was sent to %s.', 'elementor-application-vetting' ), $to );
		} else {
			/* translators: %s: applicant email address */
			$message = sprintf( __( 'Rejected. Your email was sent to %s.', 'elementor-application-vetting' ), $to );
		}

		wp_send_json_success(
			array(
				'message' => $message,
				'counts'  => $this->counts(),
			)
		);
	}
}
