<?php

class WC_Sendinblue_SMS {

	/**
	 * Send confirmation SMS
	 */
	public function ws_send_confirmation_sms( $order, $from, $text ) {

		if ( version_compare( get_option( 'woocommerce_db_version' ), '3.0', '<' ) ) {
			$first_name    = $order->billing_first_name;
			$last_name     = $order->billing_last_name;
			$total_pay     = $order->order_total;
			$ord_date      = $order->order_date;
			$order_country = $order->billing_country;
			$order_mobile  = $order->billing_phone;
		} else {
			$first_name    = $order->get_billing_first_name();
			$last_name     = $order->get_billing_last_name();
			$total_pay     = $order->get_total();
			$ord_date      = $order->get_date_created();
			$order_country = $order->get_billing_country();
			$order_mobile  = $order->get_billing_phone();
		}
		$iso_code      = SIB_Model_Country::get_prefix( $order_country );
		$mobile_number = $this->checkMobileNumber( $order_mobile, $iso_code );
		$text          = str_replace( '{first_name}', $first_name, $text );
		$text          = str_replace( '{last_name}', $last_name, $text );
		$text          = str_replace( '{order_price}', $total_pay, $text );
		$text          = str_replace( '{order_date}', $ord_date, $text );
		$data          = array(
			'recipient' => $mobile_number,
			'sender'    => $from,
			'content'   => $text,
		);
		$result        = self::ws_sms_send( $data );
	}

	/**
	 * Send SMS
	 */
	public static function ws_sms_send( $data ) {
		$general_settings = get_option( 'ws_main_option', array() );

		$mailin         = new SibApiClient();
		$data['source'] = 'api';
		$data['plugin'] = 'sendinblue-woocommerce-plugin';
		$result         = $mailin->sendSms( $data );

		delete_transient( 'ws_credit_' . md5( $general_settings['access_key'] ) );
		if ( SibApiClient::RESPONSE_CODE_CREATED === $mailin->getLastResponseCode() ) {
			return 'success';
		} else {
			return 'failure';
		}

	}
	/**
	 * This method is called when the user sets the Campaign single Choice Campaign and hits the submit button.
	 */
	public static function singleChoiceCampaign( $info ) {
		$sender_campaign_number  = $info['to'];
		$sender_campaign         = $info['from'];
		$sender_campaign_message = $info['text'];

		$personal_info = self::getCustomers( $sender_campaign_number );
		if ( 'false' != $personal_info ) {
			$sender_campaign_message = str_replace( '{first_name}', $personal_info['firstname'], $sender_campaign_message );
			$sender_campaign_message = str_replace( '{last_name}', $personal_info['lastname'], $sender_campaign_message );
		}

		$data = array(
			'recipient' => $sender_campaign_number,
			'sender'    => $sender_campaign,
			'content'   => $sender_campaign_message,
		);

		$result = self::ws_sms_send( $data );

		return $result;
	}

	/**
	 * This method is called when the user send the campaign to all WordPress customers
	 */

	public static function multipleChoiceCampaign( $info ) {
		$sender_campaign         = $info['from'];
		$sender_campaign_message = $info['text'];

		$data           = array();
		$final_result   = array(
			'success' => 0,
			'failure' => 0,
		);
		$data['sender'] = $sender_campaign;

		$response = self::getCustomers();
		foreach ( $response as $userId => $value ) {
			if ( isset( $value['phone_mobile'] ) && ! empty( $value['phone_mobile'] ) ) {
				$number = self::checkMobileNumber( $value['phone_mobile'], ( ! empty( $value['iso_code'] ) ? $value['iso_code'] : '' ) );

				$first_name = ( isset( $value['firstname'] ) ) ? $value['firstname'] : '';
				$last_name  = ( isset( $value['lastname'] ) ) ? $value['lastname'] : '';

				$fname = str_replace( '{first_name}', $first_name, $sender_campaign_message );
				$lname = str_replace( '{last_name}', $last_name, $fname );

				$data['content']   = $lname;
				$data['recipient'] = $number;

				$result = self::ws_sms_send( $data );

				if ( 'success' == $result ) {
					$final_result['success']++;
				} else {
					$final_result['failure']++;
				}
			}
		}

		if ( $final_result['failure'] > 0 ) {
			return 'failure';
		}

		return 'success';
	}

	/**
	 * This method is called when the user send the campaign to only subscribed customers
	 */
	public static function multipleChoiceSubCampaign( $info ) {
		$general_settings        = get_option( 'ws_main_option', array() );
		$sender_campaign         = $info['from'];
		$sender_campaign_message = $info['text'];

		// Create a campaign.
		$camp_name = 'SMS_' . gmdate( 'Ymd' );

		$first_name = '{NAME}';
		$last_name  = '{SURNAME}';

		$fname   = str_replace( '{first_name}', $first_name, $sender_campaign_message );
		$content = str_replace( '{last_name}', $last_name, $fname );

		$listid = array_keys( WC_Sendinblue_API::get_list() );

		$data = array(
			'name'        => $camp_name,
			'sender'      => $sender_campaign,
			'content'     => $content,
			'recipients'  => array( 'listIds' => $listid ),
			'scheduledAt' => gmdate( 'Y-m-d\TH:i:s.000+00:00', current_time( 'timestamp' ) + 60 ),
		);

		$mailin = new SibApiClient();

		$result = $mailin->createSmsCampaign( $data );

		delete_transient( 'ws_credit_' . md5( $general_settings['access_key'] ) );

		return isset( $result['id'] ) ? 'success' : 'failure';
	}
	/**
	 * This method is used to fetch all users from the default customer table to list
	 * them in the Sendinblue PS plugin.
	 */
	public static function getCustomers( $phone_number = null ) {
		$customer_data       = get_users( array( 'role' => 'customer' ) );
		$address_mobilephone = array();
		foreach ( $customer_data as $customer_detail ) {
			$iso_code = SIB_Model_Country::get_prefix( $customer_detail->billing_country );
			if ( 0 < count( $customer_detail ) ) {
				$address_mobilephone[ $customer_detail->ID ] = array(
					'firstname'    => $customer_detail->billing_first_name,
					'lastname'     => $customer_detail->billing_last_name,
					'phone_mobile' => $customer_detail->billing_phone,
					'iso_code'     => $iso_code,
				);
			}
			if ( null != $phone_number ) {
				$number = self::checkMobileNumber( $customer_detail->billing_phone, $iso_code );
				if ( $phone_number == $number ) {
					return $address_mobilephone[ $customer_detail->ID ];
				}
			}
		}

		if ( null != $phone_number ) {
			return 'false';
		}

		return $address_mobilephone;
	}

	public static function checkMobileNumber( $number, $call_prefix ) {
		$number  = preg_replace( '/\s+/', '', $number );
		$charone = substr( $number, 0, 1 );
		$chartwo = substr( $number, 0, 2 );

		if ( preg_match( '/^' . $call_prefix . '/', $number ) ) {
			return '00' . $number;
		} elseif ( '0' == $charone && '00' != $chartwo ) {
			if ( preg_match( '/^0' . $call_prefix . '/', $number ) ) {
				return '00' . substr( $number, 1 );
			} else {
				return '00' . $call_prefix . substr( $number, 1 );
			}
		} elseif ( '00' == $chartwo ) {
			if ( preg_match( '/^00' . $call_prefix . '/', $number ) ) {
				return $number;
			} else {
				return '00' . $call_prefix . substr( $number, 2 );
			}
		} elseif ( '+' == $charone ) {
			if ( preg_match( '/^\+' . $call_prefix . '/', $number ) ) {
				return '00' . substr( $number, 1 );
			} else {
				return '00' . $call_prefix . substr( $number, 1 );
			}
		} elseif ( '0' != $charone ) {
			return '00' . $call_prefix . $number;
		}
	}


	/** Ajax module for send test sms. */
	public static function ajax_sms_send() {
		$to      = ! empty( $_POST['sms'] ) ? $_POST['sms'] : '';
		$content_post = ! empty( $_POST['content'] ) ? $_POST['content'] : '';
		$content = '' != $content_post ? $content_post : __( 'Hello! This message has been sent using Sendinblue', 'wc_sendinblue' );
		$data    = array(
			'recipient' => $to,
			'sender'    => 'Sendinblue',
			'content'   => $content,
		);

		$result = self::ws_sms_send( $data );

		wp_send_json( $result );
	}

	/** Ajax module for send campaign sms. */
	public static function ajax_sms_campaign_send() {
		$campaign_type = isset( $_POST['campaign_type'] ) ? $_POST['campaign_type'] : 'all';
		$info          = array(
			'to'   => ! empty( $_POST['sms'] ) ? $_POST['sms'] : '',
			'from' => ! empty( $_POST['sender'] ) ? $_POST['sender'] : '',
			'text' => ! empty( $_POST['msg'] ) ? $_POST['msg'] : '',
		);

		if ( 'single' == $campaign_type ) {
			$result = self::singleChoiceCampaign( $info );
		} elseif ( 'all' == $campaign_type ) {
			$result = self::multipleChoiceCampaign( $info );
		} else {
			$result = self::multipleChoiceSubCampaign( $info );
		}

		wp_send_json( $result );
	}

	/* Ajax module for refresh sms credits */
	public static function ajax_sms_refresh() {
		delete_transient( 'ws_credit_' . md5( self::$access_key ) );
		wp_send_json( 'success' );
	}
}
