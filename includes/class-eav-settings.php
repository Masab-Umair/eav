<?php
/**
 * Settings > Application Vetting.
 *
 * @package ElementorApplicationVetting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EAV_Settings {

	const PAGE = 'eav-settings';

	public function init() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_notices', array( $this, 'elementor_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( EAV_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Field definitions, grouped by section.
	 *
	 * @return array
	 */
	private function sections() {
		$td = 'elementor-application-vetting';

		return array(
			'capture'   => array(
				'title'  => __( 'Form capture', $td ),
				'fields' => array(
					'form_ids'         => array(
						'label' => __( 'Form identifiers', $td ),
						'type'  => 'text',
						'desc'  => __( 'Comma-separated. A submission is saved when its Form Name, CSS ID or widget ID matches one of these. Leave empty to capture every Elementor form.', $td ),
					),
					'first_name_field' => array(
						'label' => __( 'First name field ID', $td ),
						'type'  => 'text',
						'desc'  => __( 'Optional. Leave empty to auto-detect.', $td ),
					),
					'last_name_field'  => array(
						'label' => __( 'Last name field ID', $td ),
						'type'  => 'text',
						'desc'  => __( 'Optional. Leave empty to auto-detect.', $td ),
					),
					'email_field'      => array(
						'label' => __( 'Email field ID', $td ),
						'type'  => 'text',
						'desc'  => __( 'Optional. Leave empty to use the first email-type field that is not a confirmation field.', $td ),
					),
					'log_unmatched'    => array(
						'label' => __( 'Log unmatched forms', $td ),
						'type'  => 'checkbox',
						'desc'  => __( 'Writes the identifiers of ignored Elementor forms to the PHP error log. Turn on while setting up, then off.', $td ),
					),
				),
			),
			'email'     => array(
				'title'  => __( 'Decision emails', $td ),
				'fields' => array(
					'from_name'       => array(
						'label' => __( 'From name', $td ),
						'type'  => 'text',
						'desc'  => __( 'Optional. Defaults to your site mail settings.', $td ),
					),
					'from_email'      => array(
						'label' => __( 'From email', $td ),
						'type'  => 'email',
						'desc'  => __( 'Optional. Use an address on your own domain so the mail passes SPF and DKIM.', $td ),
					),
					'reply_to'        => array(
						'label' => __( 'Reply-to email', $td ),
						'type'  => 'email',
						'desc'  => __( 'Optional. Where applicant replies go.', $td ),
					),
					'approve_subject' => array(
						'label' => __( 'Approval subject', $td ),
						'type'  => 'text',
						'desc'  => __( 'Pre-filled in the composer and editable there. Placeholders: {first_name}, {last_name}, {full_name}, {site_name}.', $td ),
					),
					'approve_body'    => array(
						'label' => __( 'Approval starter text', $td ),
						'type'  => 'textarea',
						'desc'  => __( 'Pre-filled in the composer and editable there.', $td ),
					),
					'reject_subject'  => array(
						'label' => __( 'Rejection subject', $td ),
						'type'  => 'text',
						'desc'  => '',
					),
					'reject_body'     => array(
						'label' => __( 'Rejection starter text', $td ),
						'type'  => 'textarea',
						'desc'  => '',
					),
				),
			),
			'brand'     => array(
				'title'  => __( 'Brand colors', $td ),
				'fields' => array(
					'color_primary'  => array(
						'label' => __( 'Deep purple', $td ),
						'type'  => 'color',
						'desc'  => __( 'Headings, links, focus and primary buttons.', $td ),
					),
					'color_burgundy' => array(
						'label' => __( 'Burgundy', $td ),
						'type'  => 'color',
						'desc'  => __( 'Reject button and rejected status.', $td ),
					),
					'color_teal'     => array(
						'label' => __( 'Teal', $td ),
						'type'  => 'color',
						'desc'  => __( 'Approve button and approved status.', $td ),
					),
					'color_orange'   => array(
						'label' => __( 'Orange', $td ),
						'type'  => 'color',
						'desc'  => __( 'Pending status and keyboard focus ring.', $td ),
					),
				),
			),
			'icons'     => array(
				'title'  => __( 'Icons (optional)', $td ),
				'fields' => array(
					'icon_close' => array(
						'label' => __( 'Close icon class', $td ),
						'type'  => 'iconclass',
						'desc'  => __( 'CSS class of a Skyboot icon, for example the class shown for the icon in your Skyboot icon list. Leave empty for a text-only button.', $td ),
					),
					'icon_send'  => array(
						'label' => __( 'Send icon class', $td ),
						'type'  => 'iconclass',
						'desc'  => __( 'Same as above, shown on the Send button. Leave empty for no icon.', $td ),
					),
				),
			),
			'data'      => array(
				'title'  => __( 'Data', $td ),
				'fields' => array(
					'delete_on_uninstall' => array(
						'label' => __( 'Delete data on uninstall', $td ),
						'type'  => 'checkbox',
						'desc'  => __( 'When you delete this plugin, permanently remove all stored applications and settings. Off by default.', $td ),
					),
				),
			),
		);
	}

	public function add_page() {
		add_options_page(
			__( 'Application Vetting', 'elementor-application-vetting' ),
			__( 'Application Vetting', 'elementor-application-vetting' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	public function register() {
		register_setting(
			'eav_settings_group',
			EAV_Helpers::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => EAV_Helpers::defaults(),
			)
		);

		foreach ( $this->sections() as $section_id => $section ) {
			add_settings_section( 'eav_' . $section_id, $section['title'], '__return_false', self::PAGE );
			foreach ( $section['fields'] as $key => $field ) {
				add_settings_field(
					$key,
					$field['label'],
					array( $this, 'render_field' ),
					self::PAGE,
					'eav_' . $section_id,
					array_merge( $field, array( 'key' => $key, 'label_for' => 'eav-' . $key ) )
				);
			}
		}
	}

	/**
	 * Sanitise submitted settings. The Settings API has already unslashed the input.
	 *
	 * @param mixed $input Submitted values.
	 * @return array
	 */
	public function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$defaults = EAV_Helpers::defaults();
		$out      = array();

		foreach ( $this->sections() as $section ) {
			foreach ( $section['fields'] as $key => $field ) {
				$raw = isset( $input[ $key ] ) ? (string) $input[ $key ] : '';

				switch ( $field['type'] ) {
					case 'checkbox':
						$out[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
						break;
					case 'color':
						$color       = sanitize_hex_color( $raw );
						$out[ $key ] = $color ? $color : $defaults[ $key ];
						break;
					case 'email':
						$out[ $key ] = sanitize_email( $raw );
						break;
					case 'textarea':
						$out[ $key ] = wp_kses_post( $raw );
						break;
					case 'iconclass':
						$out[ $key ] = trim( preg_replace( '/[^A-Za-z0-9 _-]/', '', $raw ) );
						break;
					default:
						$out[ $key ] = sanitize_text_field( $raw );
				}
			}
		}
		return $out;
	}

	/**
	 * Render one field.
	 *
	 * @param array $args Field args.
	 */
	public function render_field( $args ) {
		$key   = $args['key'];
		$value = EAV_Helpers::opt( $key );
		$name  = EAV_Helpers::OPTION . '[' . $key . ']';
		$id    = 'eav-' . $key;

		switch ( $args['type'] ) {
			case 'checkbox':
				printf( '<label><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s /> %4$s</label>', esc_attr( $id ), esc_attr( $name ), checked( ! empty( $value ), true, false ), esc_html( $args['desc'] ) );
				return;
			case 'textarea':
				printf( '<textarea id="%1$s" name="%2$s" rows="6" class="large-text">%3$s</textarea>', esc_attr( $id ), esc_attr( $name ), esc_textarea( $value ) );
				break;
			case 'color':
				printf( '<input type="text" id="%1$s" name="%2$s" value="%3$s" class="eav-color" data-default-color="%3$s" />', esc_attr( $id ), esc_attr( $name ), esc_attr( $value ) );
				break;
			default:
				printf( '<input type="%1$s" id="%2$s" name="%3$s" value="%4$s" class="regular-text" />', esc_attr( 'email' === $args['type'] ? 'email' : 'text' ), esc_attr( $id ), esc_attr( $name ), esc_attr( $value ) );
		}

		if ( ! empty( $args['desc'] ) ) {
			echo '<p class="description">' . esc_html( $args['desc'] ) . '</p>';
		}
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Application Vetting', 'elementor-application-vetting' ); ?></h1>
			<p>
				<?php esc_html_e( 'Add this shortcode to a page to show the dashboard:', 'elementor-application-vetting' ); ?>
				<code>[<?php echo esc_html( EAV_Dashboard::SHORTCODE ); ?>]</code>
			</p>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'eav_settings_group' );
				do_settings_sections( self::PAGE );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Colour pickers on the settings screen only.
	 *
	 * @param string $hook Admin page hook.
	 */
	public function assets( $hook ) {
		if ( 'settings_page_' . self::PAGE !== $hook ) {
			return;
		}
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		wp_add_inline_script( 'wp-color-picker', 'jQuery(function($){$(".eav-color").wpColorPicker();});' );
	}

	/**
	 * Warn when Elementor Pro is missing.
	 */
	public function elementor_notice() {
		if ( ! current_user_can( 'manage_options' ) || class_exists( '\ElementorPro\Plugin' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'settings_page_' . self::PAGE !== $screen->id ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Elementor Pro is not active. Applications are only captured from Elementor Pro forms.', 'elementor-application-vetting' ) . '</p></div>';
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Settings', 'elementor-application-vetting' ) . '</a>' );
		return $links;
	}
}
