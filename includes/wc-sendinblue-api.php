<?php
class WC_Sendinblue_API {

	/** Transient delay time. */
	const DELAYTIME = HOUR_IN_SECONDS;

	// Get list.
	public static function get_list() {
		// Get lists.
		$general_settings = get_option( 'ws_main_option' );
		$access_key       = isset( $general_settings['access_key'] ) ? $general_settings['access_key'] : '';
		$lists            = get_transient( 'ws_list_' . md5( $access_key ) );
		if ( false == $lists || false === $lists ) {

			$data = array();

			$account = new SibApiClient();

			$list_check = $account->getLists( $data );

			if ( ! empty( $list_check ) ) {
				   $lists = $account->getAllLists();
			}

			// Update with default list id.
			if ( ! isset( WC_Sendinblue::$customizations['ws_sendinblue_list'] ) ) {
				WC_Sendinblue::$customizations['ws_sendinblue_list'] = $lists['lists'][ $lists['count'] - 1 ]['id'];
				update_option( 'wc_sendinblue_settings', WC_Sendinblue::$customizations );
			}
			$list_data = array();

			if ( SibApiClient::RESPONSE_CODE_OK === $account->getLastResponseCode() ) {

				if ( ! empty( $lists['lists'] ) ) {

					foreach ( $lists['lists'] as $list ) {
						if ( 'Temp - DOUBLE OPTIN' == $list['name'] ) {
							WC_Sendinblue::$customizations['ws_dopt_list_id'] = $list['id'];
							update_option( 'wc_sendinblue_settings', WC_Sendinblue::$customizations );
							continue;
						}
						$list_data[ $list['id'] ] = $list['name'];
					}
				}
			}

			$lists = $list_data;

			if ( 0 < count( $lists ) ) {
				set_transient( 'ws_list_' . md5( $access_key ), $lists, self::DELAYTIME );
			}
		}
		return $lists;
	}

	public static function get_templates() {
		$general_settings = get_option( 'ws_main_option' );
		$access_key       = isset( $general_settings['access_key'] ) ? $general_settings['access_key'] : '';
		// Get templates.
		$templates = get_transient( 'ws_temp_' . md5( $access_key ) );
		if ( false == $templates || false === $templates ) {
			$mailin = new SibApiClient();

			$data = array(
				'templateStatus' => 'true',
			);

			$templates_check = $mailin->getEmailTemplates( $data );

			if ( ! empty( $templates_check ) ) {
				$templates = $mailin->getAllEmailTemplates();
			}

			$template_data = array();

			if ( SibApiClient::RESPONSE_CODE_OK === $mailin->getLastResponseCode() ) {

				if ( ! empty( $templates['templates'] ) ) {

					foreach ( $templates['templates'] as $template ) {
						$template_data[ $template['id'] ] = array(
							'name'    => $template['name'],
							'content' => $template['htmlContent'],
							'subject' => $template['subject'],
						);

					}
				}
			}

			$templates = $template_data;

			if ( 0 < count( $templates ) ) {
				set_transient( 'ws_temp_' . md5( $access_key ), $templates, self::DELAYTIME );
			}
		}
		return $templates;
	}

	public static function get_account_info() {
		$general_settings = get_option( 'ws_main_option' );
		$access_key       = isset( $general_settings['access_key'] ) ? $general_settings['access_key'] : '';

		$account_info = get_transient( 'ws_credit_' . md5( $access_key ) );
		if ( false == $account_info || false === $account_info ) {
			$mailin       = new SibApiClient();
			$account_info = array();

			$response = $mailin->getAccount();
			if ( SibApiClient::RESPONSE_CODE_OK === $mailin->getLastResponseCode() ) {

				$account_info['email']     = $response['email'];
				$account_info['user_name'] = $response['firstName'] . ' ' . $response['lastName'];

				$account_data = array();
				foreach ( $response['plan'] as $key => $value ) {
					if ( isset( $value['type'] ) && isset( $value['credits'] ) ) {
						$account_data[ $key ]['plan_type'] = $value['type'];
						$account_data[ $key ]['credits']   = $value['credits'];
					}
				}
				if ( isset( $account_data[1] ) ) {
					$account_info['SMS_credits'] = $account_data[1];
				}
				if ( isset( $account_data[0] ) ) {
					$account_info['email_credits'] = $account_data[0];
				}

				$settings = array(
					'access_key'    => $access_key,
					'account_email' => $account_info['email'],
				);
				update_option( 'ws_main_option', $settings );
				set_transient( 'ws_credit_' . md5( $access_key ), $account_info, self::DELAYTIME );
			}
		}
		return $account_info;
	}

	/** Get all attributes */
	public static function get_attributes() {
		$general_settings = get_option( 'ws_main_option' );
		$access_key       = isset( $general_settings['access_key'] ) ? $general_settings['access_key'] : '';
		// Get attributes.
		$attrs = get_transient( 'ws_attrs_' . md5( $access_key ) );

		if ( false == $attrs || false === $attrs ) {
			$mailin   = new SibApiClient();
			$response = $mailin->getAttributes();

			if ( empty( $response['attributes'] ) ) {
				$attributes = array(
					'normal_attributes'   => array(),
					'category_attributes' => array(),
				);
			} else {
				$attributes = array();
				foreach ( $response['attributes'] as $key => $value ) {
					if ( ! isset( $attributes[ $value['category'] . '_attributes' ] ) ) {
						$attributes[ $value['category'] . '_attributes' ] = array();
					}
					$attributes[ trim( $value['category'] ) . '_attributes' ][] = $value;
				}
			}
			$attrs = array( 'attributes' => $attributes );
			if ( 0 < count( $attributes ) ) {
				set_transient( 'ws_attrs_' . md5( $access_key ), $attrs, self::DELAYTIME );
			}
		}

		return $attrs;

	}


	// BG20190425.
	/** Get smtp status with MA */
	public static function get_ma_status() {
		$mailin   = new SibApiClient();
		$response = $mailin->getAccount(); // this fn returns MA status together with SMTP
		$status   = 'disabled';
		if ( SibApiClient::RESPONSE_CODE_OK === $mailin->getLastResponseCode() ) {
			// Get Marketing Automation API key.
			if ( isset( $response['marketingAutomation'] ) && true == $response['marketingAutomation']['enabled'] ) {
				$ma_key = $response['marketingAutomation']['key'];

			} else {
				$ma_key = '';
			}
			$general_settings           = get_option( 'ws_main_option', array() );
			$general_settings['ma_key'] = $ma_key;
			update_option( 'ws_main_option', $general_settings );
			if ( '' != $ma_key ) {
				$status = 'enabled'; }
		}
		return $status;
	}


	/**
	 * Sync wp users to contact list.
	 *
	 * @param $info - user's attributes
	 * @param $list_ids - array : desired list
	 * @return string - success or failure
	 */
	public static function sync_users( $users_info, $list_ids ) {
		$mailin   = new SibApiClient();
			$data = array(
				'fileBody' => $users_info,
				'listIds'  => $list_ids,
			);
			$mailin->importContacts( $data );
			if ( SibApiClient::RESPONSE_CODE_ACCEPTED === $mailin->getLastResponseCode() ) {
				$response = array(
					'code'    => 'success',
					'message' => __( 'Contact synchronization has started.', 'sib_lang' ),
				);
			} else {
				$response = array(
					'code'    => 'failed',
					'message' => __( 'Something went wrong. PLease try again.', 'sib_lang' ),
				);
			}
			return $response;
	}
	/* Remove all transients */
	public static function remove_transients() {
		// Remove transients.
		$general_settings = get_option( 'ws_main_option' );
		$access_key       = isset( $general_settings['access_key'] ) ? $general_settings['access_key'] : '';
		delete_transient( 'ws_credit_' . md5( $access_key ) );
		delete_transient( 'ws_temp_' . md5( $access_key ) );
		delete_transient( 'ws_list_' . md5( $access_key ) );
		delete_transient( 'ws_dopt_' . md5( $access_key ) );
		delete_transient( 'ws_attrs_' . md5( $access_key ) );
	}

	public static function get_date_config() {
		$date_format = 'd-m-Y';
		$account     = get_option( 'ws_account_data' );

		if ( ! isset( $account['address']['country'] ) ) {
			$mailin  = new SibApiClient();
			$account = $mailin->getAccount();
			update_option( 'ws_account_data', $account );
		}

		if ( isset( $account['address']['country'] ) && 'france' == strtolower( $account['address']['country'] ) ) {
			$date_format = 'm-d-Y';
		}

		return $date_format;
	}

}
