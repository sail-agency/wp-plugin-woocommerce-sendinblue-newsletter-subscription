<?php

class WC_Sendinblue_SMTP {

	const STATISTICS_DATE_FORMAT = 'Y-m-d';

	/**
	 * Smtp details.
	 */
	public static $smtp_details;

	public function __construct() {

	}

	/** Update smtp details. */
	public static function update_smtp_details() {
		self::$smtp_details = get_option( 'ws_smtp_detail', null );
		if ( null == self::$smtp_details ) {
			$mailin   = new SibApiClient();
			$response = $mailin->getAccount();
			if ( SibApiClient::RESPONSE_CODE_OK === $mailin->getLastResponseCode() ) {
				if ( true == $response['relay']['enabled'] ) {
					self::$smtp_details = $response['relay']['data'];
					update_option( 'ws_smtp_detail', self::$smtp_details );
					return true;
				} else {
					self::$smtp_details = array(
						'relay' => false,
					);
					update_option( 'ws_smtp_detail', self::$smtp_details );
					return false;
				}
			}
		}
		return false;
	}
	/**
	 * Send email campaign.
	 */
	public function getContacts( $type ) {
	}
	public function sendEmailCampaign( $info ) {
		$type     = ! empty( $info['type'] ) ? $info['type'] : '';
		$contacts = $this->getContacts( $type );
		foreach ( $contacts as $contact ) {
			// Return bool.
			if ( ! self::send_email( 'woo-campaign', $contact, $info['subject'], $info['from_name'], $info['sender'] ) ) {
				return false;
			}
		}
		return true;
	}
	/* End of send email campaign */

	/**
	 * Send mail
	 *
	 * @params (type, to_email, subject, to_info, list_id)
	 */
	public static function send_email( $type = 'double-optin', $to_email, $subject, $code = '', $list_id = '', $template_id = 0, $attributes = null, $temp_dopt_id = '' ) {
		$customizations   = get_option( 'wc_sendinblue_settings', array() );
		$general_settings = get_option( 'ws_main_option', array() );

		$mailin = new SibApiClient();

		// Get sender info.
		$sender_email = trim( get_bloginfo( 'admin_email' ) );
		$sender_name  = trim( get_bloginfo( 'name' ) );

		// Send mail.
		$to   = array( array( 'email' => $to_email ) );
		$from = array(
			'email' => $sender_email,
			'name'  => $sender_name,
		);

		$null_array  = array();
		$site_domain = str_replace( 'https://', '', home_url() );
		$site_domain = str_replace( 'http://', '', $site_domain );

		if ( 'woo-campaign' == $type ) {
			$html_content = isset( $customizations['ws_email_campaign_message'] ) ? $customizations['ws_email_campaign_message'] : '';
		} else {
			if ( 0 == $template_id ) {
				// Default template.
				$template_contents = self::get_email_template( $type );
				$html_content      = $template_contents['html_content'];
			} else {
				$search_value = '({{\s*doubleoptin\s*}})';
				$templates    = WC_Sendinblue_API::get_templates();
				$template     = $templates[ $template_id ];
				$html_content = $template['content'];
				$text_content = $template['content'];
				$html_content = str_replace( 'https://[DOUBLEOPTIN]', '{subscribe_url}', $html_content );
				$html_content = str_replace( 'http://[DOUBLEOPTIN]', '{subscribe_url}', $html_content );
				$html_content = str_replace( 'https://{{doubleoptin}}', '{subscribe_url}', $html_content );
				$html_content = str_replace( 'http://{{doubleoptin}}', '{subscribe_url}', $html_content );
				$html_content = str_replace( 'https://{{ doubleoptin }}', '{subscribe_url}', $html_content );
				$html_content = str_replace( 'http://{{ doubleoptin }}', '{subscribe_url}', $html_content );
				$html_content = str_replace( '[DOUBLEOPTIN]', '{subscribe_url}', $html_content );
				$html_content = preg_replace( $search_value, '{subscribe_url}', $html_content );
			}
		}
		$html_content = str_replace( '{title}', $subject, $html_content );
		$html_content = str_replace( '{site_domain}', $site_domain, $html_content );
		$html_content = str_replace(
			'{subscribe_url}',
			add_query_arg(
				array(
					'ws_action' => 'subscribe',
					'code'      => $code,
					'li'        => $list_id,
					'temp_id'   => $temp_dopt_id,
				),
				home_url()
			),
			$html_content
		);
		if ( 'notify' == $type ) {
			// Code is current number of sms credits.
			$html_content = str_replace( '{present_credit}', $code, $html_content );
		}

		self::update_smtp_details();
		// All emails are sent using Sendinblue API.
		if ( false != self::$smtp_details['relay'] ) {
			$headers = array(
				'Content-Type' => 'text/html; charset=iso-8859-1',
				'X-Mailin-Tag' => 'Woocommerce Sendinblue',
			);
			$data    = array(
				'to'          => $to,
				'sender'      => $from,
				'subject'     => $subject,
				'htmlContent' => $html_content,
				'headers'     => $headers,
			);
			$result  = $mailin->sendEmail( $data );
			$result  = ( SibApiClient::RESPONSE_CODE_CREATED === $mailin->getLastResponseCode() ) ? true : false;
		} else {
			$headers[] = 'Content-Type: text/html; charset=UTF-8';
			$headers[] = "From: $sender_name <$sender_email>";
			$result    = @wp_mail( $to_email, $subject, $html_content, $headers );
		}
		return $result;
	}
	/**
	 * Get email template by type (test, confirmation, double-optin)
	 * Return @values : array ( 'html_content' => '...', 'text_content' => '...' );
	 */
	private static function get_email_template( $type = 'test' ) {
		$lang = get_bloginfo( 'language' );
		if ( 'fr-FR' == $lang ) {
			$file = 'temp_fr-FR';
		} else {
			$file = 'temp';
		}

		$file_path = plugin_dir_url( __FILE__ ) . 'templates/' . $type . '/';

		// Get html content.
		$html_content = file_get_contents( $file_path . $file . '.html' );

		// Get text content.
		if ( 'notify' != $type ) {
			$text_content = file_get_contents( $file_path . $file . '.txt' );
		} else {
			$text_content = 'This is a notify message.';
		}

		$templates = array(
			'html_content' => $html_content,
			'text_content' => $text_content,
		);

		return $templates;
	}
	/**
	 * Send double optin email
	 */
	public function double_optin_signup( $email, $list_id, $info, $template_id = 0, $temp_dopt_id ) {
		// Db store.
		$data = SIB_Model_Contact::get_data_by_email( $email );
		if ( false == $data ) {
			$uniqid = uniqid();
			$info   = array( 'DOUBLE_OPT-IN' => '1' ); // Yes.
			$data   = array(
				'email'       => $email,
				'info'        => base64_encode( maybe_serialize( $info ) ),
				'code'        => $uniqid,
				'is_activate' => 0,
				'extra'       => 0,
			);
			SIB_Model_Contact::add_record( $data );
		} else {
			$uniqid = $data['code'];
		}

		// Send double optin email.
		$subject = __( 'Please confirm subscription', 'wc_sendinblue' );
		if ( ! self::send_email( 'double-optin', $email, $subject, $uniqid, $list_id, $template_id, $info, $temp_dopt_id ) ) {
			return 'fail';
		}

		return 'success';
	}
	/**
	 * Validation email.
	 */
	public function validation_email( $email, $list_id ) {
		$general_settings = get_option( 'ws_main_option', array() );

		$mailin   = new SibApiClient();
		$response = $mailin->getUser( $email );
		if ( SibApiClient::RESPONSE_CODE_OK != $mailin->getLastResponseCode() ) {
			$ret = array(
				'code'   => 'success',
				'listid' => array(),
			);
			return $ret;
		}

		$listid = $response['listIds'];
		if ( ! isset( $listid ) || ! is_array( $listid ) ) {
			$listid = array();
		}
		if ( true === $response['emailBlacklisted'] ) {
			$ret = array(
				'code'   => 'update',
				'listid' => $listid,
			);
		} else {
			if ( ! in_array( $list_id, $listid ) ) {
				$ret = array(
					'code'   => 'success',
					'listid' => $listid,
				);
			} else {
				$ret = array(
					'code'   => 'already_exist',
					'listid' => $listid,
				);
			}
		}
		return $ret;
	}

	/** Ajax module for get statistics regarding date range.  */
	public static function ajax_get_daterange() {
		wp_send_json( self::get_statistics() );
	}

	public static function get_statistics() {
		$today = gmdate( 'Y-m-d' );
		$begin = '';
		$end   = '';
		if ( ! empty( $_POST['begin'] ) ) {
			$begin = ( new DateTime( $_POST['begin'] ) )->format( self::STATISTICS_DATE_FORMAT );
		}
		if ( empty( $begin ) || $begin > $today ) {
			$begin = $today;
		}
		if ( ! empty( $_POST['end'] ) ) {
			$end = ( new DateTime( $_POST['end'] ) )->format( self::STATISTICS_DATE_FORMAT );
		}
		if ( empty( $end ) || $end > $today ) {
			$end = $today;
		}
		return WC_Sendinblue::get_statistics( $begin, $end );
	}

	/** Ajax module for send email campaign */
	public function ajax_email_campaign_send() {
		$campaign_type = isset( $_POST['campaign_type'] ) ? $_POST['campaign_type'] : 'all';

		if ( 'some' == $campaign_type ) {
			$to = ! empty( $_POST['contacts'] ) ? $_POST['contacts'] : '';
		}

		$info = array(
			'to'      => $to,
			'from'    => ! empty( $_POST['sender'] ) ? $_POST['sender'] : '',
			'text'    => ! empty( $_POST['msg'] ) ? $_POST['msg'] : '',
			'subject' => ! empty( $_POST['subject'] ) ? $_POST['subject'] : '',
			'sender'  => ! empty( $_POST['sender'] ) ? $_POST['sender'] : '',
			'title'   => ! empty( $_POST['title'] ) ? $_POST['title'] : '',
		);

		$result = $this->sendEmailCampaign( $info );
		wp_send_json( $result ); // Result = true or false.
	}
}
