<?php
/*
If (!class_exists('Mailin_Woo'))
	require_once 'mailin.php';*/
class WC_Sendinblue {

	public static $customizations;
	/**
	 * Access key
	 */
	public static $access_key;
	/**
	 * Sendinblue lists
	 */
	public static $lists;
	/**
	 * Sendinblue templates
	 */
	public static $templates;
	/**
	 * Sendinblue Double opt-in templates
	 */
	public static $dopt_templates;
	/**
	 * Sendinblue account info
	 */
	public static $account_info;
	/**
	 * Error type
	 */
	public static $ws_error_type;
	/**
	 * WordPress senders
	 */
	public static $senders;
	/**
	 * Constant name for sendinblue api v3 key
	 */
	const API_KEY_V3_OPTION_NAME = 'sib_wc_api_key_v3';
	/**
	 * Constant name for sendinblue installation id
	 */
	const INSTALLATION_ID = 'sib_wc_installation_id';

	public function __construct() {

	}

	/**
	 * Initialize of setting values for admin user
	 */
	public static function init() {

		self::$customizations = get_option( 'wc_sendinblue_settings', array() );

		$general_settings = get_option( 'ws_main_option' );
		self::$access_key = isset( $general_settings['access_key'] ) ? $general_settings['access_key'] : '';

		$error_settings      = get_option( 'ws_error_type', array() );
		self::$ws_error_type = isset( $error_settings['error_type'] ) ? $error_settings['error_type'] : '';
		delete_option( 'ws_error_type' );

		// To connect and get account details and lists.
		if ( '' != self::$access_key ) {

			// Get lists.
			self::$lists = WC_Sendinblue_API::get_list();

			// Get templates.
			self::$templates = WC_Sendinblue_API::get_templates();

			self::$dopt_templates = get_transient( 'ws_dopt_' . md5( self::$access_key ) );
			if ( false == self::$dopt_templates || false === self::$dopt_templates ) {

				$dopt_template = array( '0' => 'Default' );
				// For double opt-in.
				foreach ( self::$templates as $id => $template ) {
					if ( false !== strpos( $template['content'], 'DOUBLEOPTIN' ) || false != strpos( $template['content'], 'doubleoptin' ) ) {
						$dopt_template[ $id ] = $template['name'];
					}
				}
				self::$dopt_templates = $dopt_template;
				if ( 0 < count( self::$dopt_templates ) ) {
					set_transient( 'ws_dopt_' . md5( self::$access_key ), self::$dopt_templates, 60 * 60 * 1 );
				}
			}

			// Get account's info.
			self::$account_info = WC_Sendinblue_API::get_account_info();

			// Get statistics.
			self::get_wc_templates(); // Option - ws_email_templates.
			self::getActivatedEmailList();

			// Get senders from wp.
			$blogusers = get_users( 'role=Administrator' );
			$senders   = array( '-1' => '- Select a sender -' );
			foreach ( $blogusers as $user ) {
				$senders[ $user->user_nicename ] = $user->user_email;
			}
			self::$senders = $senders;

		}
	}

	// When admin set up on WC email setting.
	public static function getActivatedEmailList() {
		$wc_plugin_id            = 'woocommerce_';
		$notification_activation = array();
		$wc_emails_enabled       = get_option( 'wc_emails_enabled' );
		foreach ( $wc_emails_enabled as $filed => $id ) {
			$email_settings = get_option( $wc_plugin_id . $id . '_settings', null );
			// Default emails (ex, New order is checked but value is empty) template don't have a value.
			if ( ! isset( $email_settings ) || 'yes' == $email_settings['enabled'] ) {
				array_push( $notification_activation, str_replace( '_', ' ', str_replace( 'Customer_', '', str_replace( 'WC_Email_', '', $filed ) ) ) );
			}
		}
		update_option( 'ws_notification_activation', $notification_activation );
	}

	/**
	 * Get current SMS credits
	 *
	 * @return credits
	 */
	public static function ws_get_credits() {

		$mailin   = new SibApiClient();
		$response = $mailin->getAccount();
		$credits  = null;

		if ( SibApiClient::RESPONSE_CODE_OK === $mailin->getLastResponseCode() ) {

			foreach ( $response['plan'] as $key => $value ) {
				if ( 'sms' == $value['type'] ) {
					$credits = $value['credits'];
				}
			}
		}

		return $credits;

	}

	/**
	 * Get Sendinblue email templates regarding to settings
	 */
	public static function get_wc_templates() {
		$customizations     = get_option( 'wc_sendinblue_settings', array() );
		$ws_email_templates = array(
			'New Order'        => isset( $customizations['ws_new_order_template'] ) ? $customizations['ws_new_order_template'] : '0', // template id
			'Processing Order' => isset( $customizations['ws_processing_order_template'] ) ? $customizations['ws_processing_order_template'] : '0',
			'Refunded Order'   => isset( $customizations['ws_refunded_order_template'] ) ? $customizations['ws_refunded_order_template'] : '0',
			'Cancelled Order'  => isset( $customizations['ws_cancelled_order_template'] ) ? $customizations['ws_cancelled_order_template'] : '0',
			'Completed Order'  => isset( $customizations['ws_completed_order_template'] ) ? $customizations['ws_completed_order_template'] : '0',
			'Failed Order'     => isset( $customizations['ws_failed_order_template'] ) ? $customizations['ws_failed_order_template'] : '0',
			'Order On-Hold'    => isset( $customizations['ws_on_hold_order_template'] ) ? $customizations['ws_on_hold_order_template'] : '0',
			'Customer Note'    => isset( $customizations['ws_customer_note_template'] ) ? $customizations['ws_customer_note_template'] : '0',
			'New Account'      => isset( $customizations['ws_new_account_template'] ) ? $customizations['ws_new_account_template'] : '0',
		);
		update_option( 'ws_email_templates', $ws_email_templates );
	}
	/**
	 * Get statistics regarding to order's status
	 */
	public static function get_statistics( $startDate, $endDate ) {

		$apiClient                  = new SibApiClient();
		$ws_notification_activation = get_option( 'ws_notification_activation', array() );
		$statistics                 = array();

		$customization = get_option( 'wc_sendinblue_settings', array() );
		if ( ! isset( $customization['ws_smtp_enable'] ) || 'yes' != $customization['ws_smtp_enable'] ) {
			return array();
		}

		foreach ( $ws_notification_activation as $template_name ) {

			$report    = $apiClient->getTransactionalEmailReports( $template_name, $startDate, $endDate );
			$sent      = isset( $report['requests'] ) ? $report['requests'] : 0;
			$delivered = isset( $report['delivered'] ) ? $report['delivered'] : 0;
			$opens     = isset( $report['opens'] ) ? $report['opens'] : 0;
			$clicks    = isset( $report['clicks'] ) ? $report['clicks'] : 0;

			$statistics[ $template_name ] = array(
				'name'       => $template_name,
				'sent'       => $sent,
				'delivered'  => 0 != $sent && 0 != $delivered ? round( $delivered / $sent * 100, 2 ) . '%' : '0%',
				'open_rate'  => 0 != $sent && 0 != $opens ? round( $opens / $sent * 100, 2 ) . '%' : '0%',
				'click_rate' => 0 != $sent && 0 != $clicks ? round( $clicks / $sent * 100, 2 ) . '%' : '0%',
			);
		}

		return $statistics;
	}
	/**
	 * Create_subscriber function.
	 * @param string $email
	 * @param string $list_id
	 * @param string $name
	 * @return void
	 */
	public function create_subscriber( $email, $list_id, $info ) {
		$general_settings = get_option( 'ws_main_option', array() );
		try {

			$mailin = new SibApiClient();

			$data = array(
				'email'            => $email,
				'attributes'       => $info,
				'emailBlacklisted' => false,
				'listIds'          => array( intval( $list_id ) ),
				'smsBlacklisted'   => false,
			);

			$mailin->getUser( $email );
			if ( SibApiClient::RESPONSE_CODE_OK === $mailin->getLastResponseCode() ) {
				unset( $data['email'] );
				$response = $mailin->updateUser( $email, $data );
			} else {
				$data['attributes']['internalUserHistory'] = array(
					array(
						'action' => 'SUBSCRIBE_BY_PLUGIN',
						'id'     => 1,
						'name'   => 'woocommerce',
					),
				);
				$response = $mailin->createUser( $data );
			}

			if ( in_array( $mailin->getLastResponseCode(), array( SibApiClient::RESPONSE_CODE_UPDATED, SibApiClient::RESPONSE_CODE_CREATED ) ) ) {
				return 'success';
			} else {
				return 'failure';
			}
		} catch ( Exception $e ) {
			return 'failure';
		}
	} // End create_subscriber().
	/**
	 * Check if the user is in list already
	 */
	public function check_subscriber( $email ) {
		$general_settings = get_option( 'ws_main_option', array() );
		$mailin           = new SibApiClient();
		$result           = $mailin->getUser( $email );

		if ( SibApiClient::RESPONSE_CODE_OK === $mailin->getLastResponseCode() ) {
			$is_dopt_checked = isset( $result['attributes']['DOUBLE_OPT-IN'] ) ? $result['attributes']['DOUBLE_OPT-IN'] : '1'; // 1 - yes, 2 - no.
			if ( '2' == $is_dopt_checked ) {
				return 'failure';
			} else {
				return 'success';
			}
		} else {
			return 'failure';
		}

	}

	/**
	 * Subscribe process for submit on confirmation email
	 */
	public static function subscribe() {
		$site_domain      = str_replace( 'https://', '', home_url() );
		$site_domain      = str_replace( 'http://', '', $site_domain );
		$general_settings = get_option( 'ws_main_option', array() );

		$mailin       = new SibApiClient();
		$code = ! empty( $_GET['code'] ) ? esc_attr( sanitize_text_field( $_GET['code'] ) ) : '';
		$list_id = ! empty( $_GET['li'] ) ? intval( $_GET['li'] ) : 0;
		$temp_dopt_id = ! empty( $_GET['temp_id'] ) ? intval( $_GET['temp_id'] ) : 0;

		$contact_info = SIB_Model_Contact::get_data_by_code( $code );

		if ( false != $contact_info ) {
			$email = $contact_info['email'];

			$attributes = maybe_unserialize( base64_decode( $contact_info['info'] ) );

			$data = array(
				'email'            => $email,
				'attributes'       => $attributes,
				'emailBlacklisted' => false,
				'listIds'          => array( intval( $list_id ) ),
				'smsBlacklisted'   => false,
			);

			$mailin->getUser( $email );
			if ( SibApiClient::RESPONSE_CODE_OK === $mailin->getLastResponseCode() ) {
				unset( $data['email'] );
				$data['unlinkListIds'] = array( intval( $temp_dopt_id ) );
				$response              = $mailin->updateUser( $email, $data );
			} else {
				$data['attributes']['internalUserHistory'] = array(
					array(
						'action' => 'SUBSCRIBE_BY_PLUGIN',
						'id'     => 1,
						'name'   => 'woocommerce',
					),
				);
				$response                                  = $mailin->createUser( $data );
			}
		}
		?>
		<body style="margin:0; padding:0;">
		<table style="background-color:#ffffff" cellpadding="0" cellspacing="0" border="0" width="100%">
			<tbody>
			<tr style="border-collapse:collapse;">
				<td style="border-collapse:collapse;" align="center">
					<table cellpadding="0" cellspacing="0" border="0" width="540">
						<tbody>
						<tr>
							<td style="line-height:0; font-size:0;" height="20"></td>
						</tr>
						</tbody>
					</table>
					<table cellpadding="0" cellspacing="0" border="0" width="540">
						<tbody>
						<tr>
							<td style="line-height:0; font-size:0;" height="20">
								<div
									style="font-family:arial,sans-serif; color:#61a6f3; font-size:20px; font-weight:bold; line-height:28px;">
									<?php esc_html_e( 'Thank you for subscribing', 'wc_sendinblue' ); ?></div>
							</td>
						</tr>
						</tbody>
					</table>
					<table cellpadding="0" cellspacing="0" border="0" width="540">
						<tbody>
						<tr>
							<td style="line-height:0; font-size:0;" height="20"></td>
						</tr>
						</tbody>
					</table>
					<table cellpadding="0" cellspacing="0" border="0" width="540">
						<tbody>
						<tr>
							<td align="left">

								<div
									style="font-family:arial,sans-serif; font-size:14px; margin:0; line-height:24px; color:#555555;">
									<br>
									<?php esc_html_e( 'You have just subscribed to the newsletter of ', 'wc_sendinblue' ); ?>
									<?php esc_html_e( $site_domain ); ?>
									<?php echo ' .'; ?>
									<br><br>
									<?php esc_html_e( '-Sendinblue', 'wc_sendinblue' ); ?></div>
							</td>
						</tr>
						</tbody>
					</table>
					<table cellpadding="0" cellspacing="0" border="0" width="540">
						<tbody>
						<tr>
							<td style="line-height:0; font-size:0;" height="20">
							</td>
						</tr>
						</tbody>
					</table>
				</td>
			</tr>
			</tbody>
		</table>
		</body>
		<?php
		exit;
	}

	/**
	 * Hook native wp_mail
	 * @return boolean
	 */
	public static function wp_mail_native( $to, $subject, $message, $headers = '', $attachments = array() ) {
		require plugin_dir_path( __FILE__ ) . 'function.wp_mail.php';
	}
	// Hook wp_mail.
	public static function sib_email( $to, $subject, $message, $headers = '', $attachments = array(), $tags = array(), $from_name = '', $from_email = '' ) {
		// From email and name.

		if ( '' == $from_email ) {
			$from_email = trim( get_bloginfo( 'admin_email' ) );
			$from_name  = trim( get_bloginfo( 'name' ) );
		}
		$from_email = apply_filters( 'wp_mail_from', $from_email );
		$from_name  = apply_filters( 'wp_mail_from_name', $from_name );

		// Headers.
		if ( empty( $headers ) ) {
			$headers = array();
			$reply   = array();
			$bcc     = array();
			$cc      = array();
		} else {
			if ( ! is_array( $headers ) ) {
				// Explode the headers out, so this function can take both.
				// String headers and an array of headers.
				$tempheaders = explode( "\n", str_replace( "\r\n", "\n", $headers ) );
			} else {
				$tempheaders = $headers;
			}
			$headers = array();
			$reply   = array();
			$bcc     = array();
			$cc      = array();

			// If it's actually got contents.
			if ( ! empty( $tempheaders ) ) {
				// Iterate through the raw headers.
				foreach ( (array) $tempheaders as $header ) {
					if ( false === strpos( $header, ':' ) ) {
						if ( false !== stripos( $header, 'boundary=' ) ) {
							$parts    = preg_split( '/boundary=/i', trim( $header ) );
							$boundary = trim( str_replace( array( "'", '"' ), '', $parts[1] ) );
						}
						continue;
					}
					// Explode them out.
					list( $name, $content ) = explode( ':', trim( $header ), 2 );

					// Cleanup crew.
					$name    = trim( $name );
					$content = trim( $content );

					switch ( strtolower( $name ) ) {
						case 'x-mailin-tag':
							$headers[ trim( $name ) ] = trim( $content );
							break;
						case 'from':
							if ( false !== strpos( $content, '<' ) ) {
								// So... making my life hard again?
								$from_name = substr( $content, 0, strpos( $content, '<' ) - 1 );
								$from_name = str_replace( '"', '', $from_name );
								$from_name = trim( $from_name );

								$from_email = substr( $content, strpos( $content, '<' ) + 1 );
								$from_email = str_replace( '>', '', $from_email );
								$from_email = trim( $from_email );
							} else {
								$from_name  = '';
								$from_email = trim( $content );
							}
							break;
						case 'bcc':
							$bcc[] = array( 'email' => trim( $content ) );
							break;
						case 'cc':
							$cc[] = array( 'email' => trim( $content ) );
							break;
						case 'reply-to':
							if ( false !== strpos( $content, '<' ) ) {
								// So... making my life hard again?.
								$reply_to       = substr( $content, strpos( $content, '<' ) + 1 );
								$reply_to       = str_replace( '>', '', $reply_to );
								$reply['email'] = trim( $reply_to );
							} else {
								$reply['email'] = trim( $content );
							}
							break;
						default:
							break;
					}
				}
			}
		}

		// Set destination addresses.
		if ( ! is_array( $to ) ) {
			$to = explode( ',', preg_replace( '/\s+/', '', $to ) ); // Strip all whitespace.
		}

		$processed_to = array();
		foreach ( $to as $email ) {
			if ( is_array( $email ) ) {
				foreach ( $email as $email_key => $email_val ) {
					$processed_to[] = array(
						'email' => $email_key,
						'name'  => $email_val
					);
					break;
				}	
			} else {
				$processed_to[] = array( 'email' => $email );
			}
		}
		$to = $processed_to;

		// Attachments.
		$attachment_content = array();
		if ( is_array( $attachments ) ) {
			foreach ( $attachments as $attachment ) {
				$content = self::getAttachmentStruct( $attachment );
				if ( ! is_wp_error( $content ) ) {
					$attachment_content = array_merge( $attachment_content, $content );
				}
			}
		}
		// Common transformations for the HTML part.
		// If it is text/plain, New line break found.
		if ( false === strpos( $message, '</table>' ) && false === strpos( $message, '</div>' ) ) {
			if ( false !== strpos( $message, "\n" ) ) {
				if ( is_array( $message ) ) {
					foreach ( $message as &$value ) {
						$value['content'] = preg_replace( '#<(https?://[^*]+)>#', '$1', $value['content'] );
						$value['content'] = nl2br( $value['content'] );
					}
				} else {
					$message = preg_replace( '#<(https?://[^*]+)>#', '$1', $message );
					$message = nl2br( $message );
				}
			}
		}

		// Sending.
		$general_settings = get_option( 'ws_main_option', array() );
		$mailin           = new SibApiClient();
		$data             = array(
			'to'          => $to,
			'sender'      => array(
				'email' => $from_email,
				'name'  => $from_name,
			),
			'subject'     => $subject,
			'htmlContent' => $message,
		);

		if ( ! empty( $reply ) ) {
			$data['replyTo'] = $reply;
		}
		if ( ! empty( $cc ) ) {
			$data['cc'] = $cc;
		}
		if ( ! empty( $bcc ) ) {
			$data['bcc'] = $bcc;
		}
		if ( ! empty( $attachment_content ) ) {
			$data['attachment'] = array( $attachment_content );
		}
		if ( ! empty( $headers ) ) {
			$data['headers'] = $headers;
		}

		try {
			$sent = $mailin->sendEmail( $data );
			return $sent;
		} catch ( Exception $e ) {
			return new WP_Error( $e->getMessage() );
		}
	}
	public static function getAttachmentStruct( $path ) {

		$struct = array();

		try {

			if ( ! @is_file( $path ) ) {
				throw new Exception( $path . ' is not a valid file.' );
			}

			$filename = basename( $path );

			if ( ! function_exists( 'get_magic_quotes' ) ) {
				function get_magic_quotes() {
					return false; }
			}
			if ( ! function_exists( 'set_magic_quotes' ) ) {
				function set_magic_quotes( $value ) {
					return true;}
			}

			$isMagicQuotesSupported = version_compare( PHP_VERSION, '5.3.0', '<' )
				&& function_exists( 'get_magic_quotes_runtime' )
				&& function_exists( 'set_magic_quotes_runtime' );

			if ( $isMagicQuotesSupported ) {
				// Escape linters check.
				$getMagicQuotesRuntimeFunc = 'get_magic_quotes_runtime';
				$setMagicQuotesRuntimeFunc = 'set_magic_quotes_runtime';

				// Save magic quotes value.
				$magicQuotes = $getMagicQuotesRuntimeFunc();
				$setMagicQuotesRuntimeFunc( 0 );
			}

			$file_buffer = file_get_contents( $path );
			$file_buffer = chunk_split( base64_encode( $file_buffer ), 76, "\n" );

			if ( $isMagicQuotesSupported ) {
				// Restore magic quotes value.
				$setMagicQuotesRuntimeFunc( $magicQuotes );
			}

			$struct['name']    = $filename;
			$struct['content'] = $file_buffer;

		} catch ( Exception $e ) {
			return new WP_Error( 'Error creating the attachment structure: ' . $e->getMessage() );
		}

		return $struct;
	}

	/**
	 * Logout process
	 *
	 * @return void
	 */
	public static function logout() {
		self::processInstallationInfo( 'logout' );
		$setting = array();
		update_option( 'ws_main_option', $setting );

		$home_settings = array(
			'activate_email' => 'no',
		);
		update_option( 'ws_home_option', $home_settings );
		update_option( 'wc_sendinblue_settings', $setting );
		update_option( 'ws_email_templates', $setting );
		delete_option( 'ws_credits_notice' );
		// Remove sync users option.
		delete_option( 'ws_sync_users' );
		// Remove transients.
		delete_transient( 'ws_credit_' . md5( self::$access_key ) );
		delete_transient( 'ws_temp_' . md5( self::$access_key ) );
		delete_transient( 'ws_list_' . md5( self::$access_key ) );
		delete_option( self::API_KEY_V3_OPTION_NAME );

		wp_redirect( add_query_arg( 'page', 'wc-settings&tab=sendinblue', admin_url( 'admin.php' ) ) );
		exit;
	}
	/**
	 * Ajax module for validation of API access key.
	 *
	 * @options :
	 *  ws_main_option
	 *  ws_token_store
	 *  ws_error_type
	 */
	public static function ajax_validation_process() {
		$access_key = ! empty( $_POST['access_key'] ) ? trim( sanitize_text_field( $_POST['access_key'] ) ) : '';

		try {
			update_option( self::API_KEY_V3_OPTION_NAME, $access_key );
			$apiClient = new SibApiClient();
			$data      = $apiClient->getAccount();

			if ( $apiClient->getLastResponseCode() === SibApiClient::RESPONSE_CODE_OK ) {

				$settings = array(
					'access_key' => $access_key,
				);
				update_option( 'ws_main_option', $settings );
				update_option( 'ws_account_data', $data );
				$customizations = get_option( 'wc_sendinblue_settings', array() );
				if ( empty( $customizations ) ) {
					$customizations = array(
						'ws_subscription_enabled'   => 'yes',
						'ws_order_event'            => 'on-hold',
						'ws_smtp_enable'            => 'yes',
						'ws_email_templates_enable' => 'no',
						'ws_sms_enable'             => 'no',
						'ws_marketingauto_enable'   => 'no', // BG20190425.
					);
					update_option( 'wc_sendinblue_settings', $customizations );
				}
				self::processInstallationInfo( 'login' );
				// Create or validate global, calculated, and transactional attributes.
				self::createListFolder();
				self::createAttributesName();
				wp_send_json( 'success' );
			} else {
				delete_option( self::API_KEY_V3_OPTION_NAME );
				$settings = array(
					'error_type' => __( 'Please input correct information.', 'wc_sendinblue' ),
				);
				update_option( 'ws_error_type', $settings );
			}
		} catch ( Exception $e ) {
				delete_option( self::API_KEY_V3_OPTION_NAME );
				$message = $e->getMessage();
				wp_send_json( 'fail' );
		}
	}

	public static function createListFolder() {
		$mailin = new SibApiClient();

		$folder_all = $mailin->getAllFolders();
		// Find folder id of 'form' folder if it exists.
		foreach ( $folder_all['folders'] as $key => $value ) {
			if ( 'form' == strtolower( $value['name'] ) ) {
				$form_folder_id = $value['id'];
				break;
			}
		}
		// Create folder 'form' and gets its id.
		if ( empty( $form_folder_id ) ) {
			$response = $mailin->createFolder( array( 'name' => 'FORM' ) );
			if ( SibApiClient::RESPONSE_CODE_CREATED === $mailin->getLastResponseCode() ) {
				$form_folder_id = $response['id'];
			}
		}
		// Check for Temp - DOUBLE OPTIN list if not then create.
		$create_list = true;

		if ( ! empty( $form_folder_id ) ) {
			$list_all = $mailin->getAllLists( $form_folder_id );
			foreach ( $list_all['lists'] as $value ) {
				if ( 'Temp - DOUBLE OPTIN' == $value['name'] ) {
					$create_list = false;
					break;
				}
			}
		}

		if ( ! empty( $form_folder_id ) && $create_list ) {
			$data = array(
				'name'     => 'Temp - DOUBLE OPTIN',
				'folderId' => $form_folder_id,
			);
			$mailin->createList( $data );
		}
	}

	public static function createAttributesName() {
		// List of the attributes should be created. Filtered by type of attribute.
		$required_attr['calculated']    = self::attrCalculated();
		$required_attr['global']        = self::attrGlobal();
		$required_attr['transactional'] = self::attrTransactional();

		// Create Sib client obj and get all the attributes which was already created.
		$mailin            = new SibApiClient();
		$attr_list         = $mailin->getAttributes();
		$attr_exist        = array();
		$double_optin_attr = false;
		// Rearrange the created attributes type wise.
		if ( isset( $attr_list['attributes'] ) ) {
			foreach ( $attr_list['attributes'] as $key => $value ) {
				// Block Start It is used for only DOUBLE_OPT-IN attribute check.
				if ( 'category' == $value['category'] && 'DOUBLE_OPT-IN' == $value['name'] && ! empty( $value['enumeration'] ) ) {
						$double_optin_attr = true;
				}
				// Block End.
				if ( ! isset( $attr_exist[ $value['category'] ] ) ) {
					$attr_exist[ $value['category'] ] = array();
				}
				$attr_exist[ $value['category'] ][] = $value;
			}
		}

		// To find which attribute is not created.
		foreach ( $required_attr as $key => $value ) {
			if ( isset( $attr_exist[ $key ] ) ) {
				$temp_name = array_column( $attr_exist[ $key ], 'name' );
				foreach ( $value as $key1 => $value1 ) {
					if ( in_array( $value1['name'], $temp_name ) ) {
						unset( $required_attr[ $key ][ $key1 ] );
					}
				}
			}
		}

		// To create transactional attributes.
		foreach ( $required_attr['transactional'] as $key => $value ) {
			$mailin->createAttribute( 'transactional', $value['name'], array( 'type' => $value['type'] ) );
		}

		// To create calculated attributes.
		foreach ( $required_attr['calculated'] as $key => $value ) {
			$mailin->createAttribute( 'calculated', $value['name'], array( 'value' => $value['value'] ) );
		}

		// To create global attributes.
		foreach ( $required_attr['global'] as $key => $value ) {
			$mailin->createAttribute( 'global', $value['name'], array( 'value' => $value['value'] ) );
		}

		// To create double opt in attribute.
		if ( ! $double_optin_attr ) {
			$data = array(
				'type'        => 'category',
				'enumeration' => array(
					array(
						'value' => 1,
						'label' => 'Yes',
					),
					array(
						'value' => 2,
						'label' => 'No',
					),
				),
			);
			$mailin->createAttribute( 'category', 'DOUBLE_OPT-IN', $data );
		}
	}

	public static function attrCalculated() {
		$calcAttr = array(
			array(
				'name'     => 'WC_LAST_30_DAYS_CA',
				'category' => 'calculated',
				'value'    => 'SUM[ORDER_PRICE,ORDER_DATE,>,NOW(-30)]',
			),
			array(
				'name'     => 'WC_CA_USER',
				'category' => 'calculated',
				'value'    => 'SUM[ORDER_PRICE]',
			),
			array(
				'name'     => 'WC_ORDER_TOTAL',
				'category' => 'calculated',
				'value'    => 'COUNT[ORDER_ID]',
			),
		);
		return $calcAttr;
	}

	public static function attrGlobal() {
		$globalAttr = array(
			array(
				'name'     => 'WC_CA_LAST_30DAYS',
				'category' => 'global',
				'value'    => 'SUM[WC_LAST_30_DAYS_CA]',
			),
			array(
				'name'     => 'WC_CA_TOTAL',
				'category' => 'global',
				'value'    => 'SUM[WC_CA_USER]',
			),
			array(
				'name'     => 'WC_ORDERS_COUNT',
				'category' => 'global',
				'value'    => 'SUM[WC_ORDER_TOTAL]',
			),
		);
		return $globalAttr;
	}

	public static function attrTransactional() {
		$transactionalAttributes = array(
			array(
				'name'     => 'ORDER_ID',
				'category' => 'transactional',
				'type'     => 'id',
			),
			array(
				'name'     => 'ORDER_DATE',
				'category' => 'transactional',
				'type'     => 'date',
			),
			array(
				'name'     => 'ORDER_PRICE',
				'category' => 'transactional',
				'type'     => 'float',
			),
		);
		return $transactionalAttributes;
	}

	public static function processInstallationInfo( $action ) {
		global $wp_version;

		if ( 'login' == $action ) {
			$apiClient = new SibApiClient();

			$params['partnerName']    = 'WOOCOMMERCE';
			$params['active']         = true;
			$params['plugin_version'] = SibApiClient::PLUGIN_VERSION;
			if ( ! empty( $wp_version ) ) {
				$params['shop_version'] = $wp_version;
			}
			$params['shop_url']     = get_home_url();
			$params['created_at']   = gmdate( 'Y-m-d\TH:i:s\Z' );
			$params['activated_at'] = gmdate( 'Y-m-d\TH:i:s\Z' );
			$params['type']         = 'sib';
			$response               = $apiClient->createInstallationInfo( $params );
			if ( $apiClient->getLastResponseCode() === SibApiClient::RESPONSE_CODE_CREATED ) {
				if ( ! empty( $response['id'] ) ) {
					update_option( self::INSTALLATION_ID, $response['id'] );
				}
			}
		} elseif ( 'logout' == $action ) {
			$installationId = get_option( self::INSTALLATION_ID );
			if ( ! empty( $installationId ) ) {
				$apiClient                = new SibApiClient();
				$params['active']         = false;
				$params['deactivated_at'] = gmdate( 'Y-m-d\TH:i:s\Z' );
				$apiClient->updateInstallationInfo( $installationId, $params );
			}
		}
	}
}
