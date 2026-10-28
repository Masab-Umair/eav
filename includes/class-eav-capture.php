<?php
/**
 * Captures Elementor Pro form submissions.
 *
 * This only listens to elementor_pro/forms/new_record and never alters the
 * record, never adds errors and never stops execution, so the form's own
 * actions (admin email notification, etc.) run exactly as before. Everything is
 * wrapped in try/catch so a failure here can never break the submission.
 *
 * @package ElementorApplicationVetting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EAV_Capture {

	/**
	 * Field types that are not applicant data.
	 *
	 * @var string[]
	 */
	private $skip_types = array( 'recaptcha', 'recaptcha_v3', 'hcaptcha', 'turnstile', 'honeypot', 'step', 'html', 'password' );

	public function init() {
		add_action( 'elementor_pro/forms/new_record', array( $this, 'handle' ), 20, 2 );
	}

	/**
	 * Hook callback.
	 *
	 * @param object $record       ElementorPro\Modules\Forms\Classes\Form_Record.
	 * @param object $ajax_handler ElementorPro\Modules\Forms\Classes\Ajax_Handler.
	 */
	public function handle( $record, $ajax_handler = null ) {
		try {
			$this->process( $record );
		} catch ( Throwable $e ) {
			EAV_Helpers::log( 'Capture failed: ' . $e->getMessage(), true );
		}
	}

	/**
	 * Values that can identify the form (Form Name, CSS ID, widget ID).
	 *
	 * @param object $record Form record.
	 * @return string[] Lowercase identifiers.
	 */
	private function form_identifiers( $record ) {
		$found = array();
		foreach ( array( 'form_name', '_element_id', 'id', 'form_id' ) as $key ) {
			$value = $record->get_form_settings( $key );
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				$found[] = strtolower( trim( (string) $value ) );
			}
		}
		return array_values( array_unique( $found ) );
	}

	/**
	 * Does this record belong to a configured form?
	 * An empty "Form identifiers" setting means every Elementor form.
	 *
	 * @param string[] $identifiers Identifiers of the submitted form.
	 * @return bool
	 */
	private function is_target_form( array $identifiers ) {
		$configured = strtolower( (string) EAV_Helpers::opt( 'form_ids' ) );
		$wanted     = array_filter( array_map( 'trim', explode( ',', $configured ) ) );

		if ( empty( $wanted ) ) {
			return true;
		}
		return (bool) array_intersect( $wanted, $identifiers );
	}

	/**
	 * Store the submission.
	 *
	 * @param object $record Form record.
	 */
	private function process( $record ) {
		if ( ! is_object( $record ) || ! method_exists( $record, 'get' ) || ! method_exists( $record, 'get_form_settings' ) ) {
			return;
		}

		$identifiers = $this->form_identifiers( $record );

		if ( ! $this->is_target_form( $identifiers ) ) {
			if ( EAV_Helpers::opt( 'log_unmatched' ) ) {
				EAV_Helpers::log( 'Ignored an Elementor form. Identifiers seen: ' . implode( ', ', $identifiers ), true );
			}
			return;
		}

		$fields = $record->get( 'fields' );
		if ( ! is_array( $fields ) || empty( $fields ) ) {
			return;
		}

		$rows = array();
		foreach ( $fields as $id => $field ) {
			$type = isset( $field['type'] ) ? (string) $field['type'] : 'text';
			if ( in_array( $type, $this->skip_types, true ) ) {
				continue;
			}

			$value = isset( $field['value'] ) ? $field['value'] : '';
			if ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'strval', $value ) );
			}

			$label = ( isset( $field['title'] ) && '' !== trim( (string) $field['title'] ) )
				? (string) $field['title']
				: ucwords( str_replace( array( '_', '-' ), ' ', (string) $id ) );

			$rows[] = array(
				'id'    => sanitize_text_field( (string) $id ),
				'label' => sanitize_text_field( $label ),
				'type'  => sanitize_key( $type ),
				'value' => sanitize_textarea_field( (string) $value ),
			);
		}

		if ( empty( $rows ) ) {
			return;
		}

		$identity = EAV_Helpers::detect_identity( $rows );
		$form_id  = ! empty( $identifiers ) ? $identifiers[0] : '';

		$post_id = wp_insert_post(
			array(
				'post_type'   => EAV_CPT,
				'post_status' => 'publish',
				'post_title'  => $identity['name'],
			),
			true
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			EAV_Helpers::log( 'Could not create application post.', true );
			return;
		}

		// update_post_meta() unslashes its input, so slash first to keep backslashes intact.
		update_post_meta( $post_id, '_eav_status', EAV_Helpers::STATUS_PENDING );
		update_post_meta( $post_id, '_eav_name', wp_slash( $identity['name'] ) );
		update_post_meta( $post_id, '_eav_first_name', wp_slash( $identity['first'] ) );
		update_post_meta( $post_id, '_eav_last_name', wp_slash( $identity['last'] ) );
		update_post_meta( $post_id, '_eav_email', wp_slash( $identity['email'] ) );
		update_post_meta( $post_id, '_eav_form', wp_slash( $form_id ) );
		update_post_meta( $post_id, '_eav_fields', wp_slash( $rows ) );

		/**
		 * Fires after an application has been stored.
		 *
		 * @param int   $post_id  Application post ID.
		 * @param array $rows     Captured field rows.
		 * @param array $identity Detected name and email.
		 */
		do_action( 'eav_application_captured', $post_id, $rows, $identity );
		do_action( 'mav_application_captured', $post_id, $rows, $identity );
	}
}
