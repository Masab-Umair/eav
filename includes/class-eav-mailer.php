<?php
/**
 * Sends the decision email.
 *
 * @package ElementorApplicationVetting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EAV_Mailer {

	/**
	 * Send an HTML email with a plain-text alternative.
	 *
	 * @param string $to            Recipient (always the stored applicant address).
	 * @param string $subject       Subject line, single line.
	 * @param string $body_html     Sanitised HTML body.
	 * @param bool   $copy_to_admin BCC the administrator who is sending.
	 * @return true|WP_Error
	 */
	public static function send( $to, $subject, $body_html, $copy_to_admin = false ) {
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		$from_email = sanitize_email( EAV_Helpers::opt( 'from_email' ) );
		if ( is_email( $from_email ) ) {
			$headers[] = 'From: ' . self::format_address( EAV_Helpers::opt( 'from_name' ), $from_email );
		}

		$reply_to = sanitize_email( EAV_Helpers::opt( 'reply_to' ) );
		if ( is_email( $reply_to ) ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}

		if ( $copy_to_admin ) {
			$user = wp_get_current_user();
			if ( $user && is_email( $user->user_email ) ) {
				$headers[] = 'Bcc: ' . $user->user_email;
			}
		}

		$html = apply_filters( 'eav_email_html', apply_filters( 'mav_email_html', self::wrap( $body_html ), $body_html, $subject ), $body_html, $subject );
		$plain = self::to_plain_text( $body_html );

		$failure = null;
		$capture = function ( $error ) use ( &$failure ) {
			$failure = $error;
		};
		$alt     = function ( $phpmailer ) use ( $plain ) {
			$phpmailer->AltBody = $plain; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		};

		add_action( 'wp_mail_failed', $capture );
		add_action( 'phpmailer_init', $alt );

		$sent = wp_mail( $to, $subject, $html, $headers );

		remove_action( 'wp_mail_failed', $capture );
		remove_action( 'phpmailer_init', $alt );

		if ( $sent ) {
			return true;
		}

		$message = ( $failure instanceof WP_Error )
			? $failure->get_error_message()
			: __( 'The email could not be sent. Check the site\'s outgoing mail (SMTP) settings and try again.', 'elementor-application-vetting' );

		return new WP_Error( 'eav_mail_failed', $message );
	}

	/**
	 * Build a safe "Name <email>" header value.
	 *
	 * @param string $name  Display name.
	 * @param string $email Address.
	 * @return string
	 */
	private static function format_address( $name, $email ) {
		$name = trim( str_replace( array( '"', '<', '>', "\r", "\n" ), '', (string) $name ) );
		return '' === $name ? $email : sprintf( '%s <%s>', $name, $email );
	}

	/**
	 * Minimal responsive wrapper around the body.
	 *
	 * @param string $body_html Body HTML.
	 * @return string
	 */
	private static function wrap( $body_html ) {
		$site = esc_html( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		$url  = esc_url( home_url( '/' ) );

		return '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#f4f1f7;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f1f7;"><tr><td align="center" style="padding:24px 12px;">'
			. '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:8px;">'
			. '<tr><td style="padding:32px;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:1.6;color:#241b2e;">'
			. $body_html
			. '</td></tr>'
			. '<tr><td style="padding:16px 32px 28px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#6a6076;border-top:1px solid #e4dcec;">'
			. '<a href="' . $url . '" style="color:#6a6076;">' . $site . '</a>'
			. '</td></tr></table></td></tr></table></body></html>';
	}

	/**
	 * Plain-text version of the body.
	 *
	 * @param string $html Body HTML.
	 * @return string
	 */
	private static function to_plain_text( $html ) {
		$text = preg_replace( '#<br\s*/?>|</p>|</div>|</li>#i', "\n", $html );
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );
		return trim( $text );
	}
}
