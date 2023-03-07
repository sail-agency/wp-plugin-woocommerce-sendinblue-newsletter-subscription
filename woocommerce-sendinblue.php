<?php
/**
 * Plugin Name: Sendinblue - WooCommerce Email Marketing
 * Description: Allow users to subscribe to your newsletter via the checkout page and a client to send SMS campaign.
 * Author: Sendinblue
 * Author URI: https://www.sendinblue.com/?r=wporg
 * Version: 999
 * Requires at least: 4.3
 * Tested up to: 5.7
 *
 * WC requires at least: 2.1
 * WC tested up to: 5.1.0
 * License: GPLv2 or later
 */
/*
This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.
This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.
You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Check if WooCommerce is active.
if ( ! function_exists( 'is_plugin_active' ) ) {
	require_once ABSPATH . '/wp-admin/includes/plugin.php';
}

if ( ! is_plugin_active( 'woocommerce/woocommerce.php' ) ) {
	return;
}

// WC version check.
if ( version_compare( get_option( 'woocommerce_db_version' ), '2.1', '<' ) ) {

	function woocommerce_sendinblue_outdated_version_notice() {

		$message = sprintf(
			/* translators: %s: search term */
			__( '%1$sWooCommerce Sendinblue is inactive.%2$s This version requires WooCommerce 2.1 or newer. Please %3$supdate WooCommerce to version 2.1 or newer%4$s', 'wc_sendinblue' ),
			'<strong>',
			'</strong>',
			'<a href="' . admin_url( 'plugins.php' ) . '">',
			'&nbsp;&raquo;</a>'
		);

		echo sprintf( '<div class="error"><p>%s</p></div>', $message );
	}

	add_action( 'admin_notices', 'woocommerce_sendinblue_outdated_version_notice' );

	return;
}

if ( ! class_exists( 'WC_Sendinblue_Integration' ) ) {

	register_deactivation_hook( __FILE__, array( 'WC_Sendinblue_Integration', 'deactivate' ) );
	register_activation_hook( __FILE__, array( 'WC_Sendinblue_Integration', 'activate' ) );
	register_uninstall_hook( __FILE__, array( 'WC_Sendinblue_Integration', 'uninstall' ) );

	if ( ! class_exists( 'SIB_Model_Contact' ) ) {
		require_once 'model/model-contacts.php';
	}
	if ( ! class_exists( 'Mailin_Woo' ) ) {
		require_once 'includes/mailin.php';
	}
	if ( ! class_exists( 'SibApiClient' ) ) {
		require_once 'includes/SibApiClient.php';
	}
	require_once 'model/model-country.php';
	require_once 'includes/wc-sendinblue.php';
	require_once 'includes/wc-sendinblue-sms.php';
	require_once 'includes/wc-sendinblue-smtp.php';
	require_once 'includes/wc-sendinblue-api.php';

	/**
	 * Sendinblue Integration class.
	 */
	class WC_Sendinblue_Integration {

		/** 
		 * Settings variable
		 * 
		 * @var \WC_Sendinblue_Integration_Settings instance
		 * */
		public $settings;

		/** Var array the active filters. */
		public $filters;

		/** Sendinblue SMTP is enabled to send all WC emails*/
		public static $ws_smtp_enabled;

		/** Check if wp_mail is declared.  */
		public static $wp_mail_conflict;

		/** Order id when get started sending email. */
		public $order_id;

		/**
		 * Id of customer
		 * 
		 * @var int $customer_id - customer id.
		 */
		public $customer_id;

		/**
		 * Data of customer
		 * 
		 * @var array $customer_data  - customer data.
		 */
		public $customer_data;

		/** Email template of sendinblue when send order email. */
		public static $order_template_sib;

		/**
		 * Initializes the plugin.
		 */
		public function __construct() {
			// Notify the sms limit.
			add_action( 'ws_sms_alert_event', array( $this, 'do_sms_limit_notify' ) );
			// Load translation.
			add_action( 'init', array( $this, 'init' ) );

			$this->customizations = get_option( 'wc_sendinblue_settings', array() );

			// Admin.
			if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {

				// Load settings page.
				add_filter( 'woocommerce_get_settings_pages', array( $this, 'add_settings_page' ) );

				// Add a 'Configure' link to the plugin action links.
				// Add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_plugin_action_links'));.

				// Add a new interval of a week.
				add_filter( 'cron_schedules', array( $this, 'ws_add_weekly_cron_schedule' ) );

			}

			// Init variables.
			$this->ws_subscribe_enable = isset( $this->customizations['ws_subscription_enabled'] ) ? $this->customizations['ws_subscription_enabled'] : 'yes';
			$this->ws_smtp_enable      = isset( $this->customizations['ws_smtp_enable'] ) ? $this->customizations['ws_smtp_enable'] : 'yes';
			self::$ws_smtp_enabled = $this->ws_smtp_enable;
			$this->ws_template_enable  = isset( $this->customizations['ws_email_templates_enable'] ) ? $this->customizations['ws_email_templates_enable'] : 'no';
			$this->ws_sms_enable       = isset( $this->customizations['ws_sms_enable'] ) ? $this->customizations['ws_sms_enable'] : 'no';

			self::$wp_mail_conflict = false;

			add_action( 'woocommerce_init', array( $this, 'load_customizations' ) );

			// Register style sheet.
			add_action( 'admin_enqueue_scripts', array( $this, 'register_plugin_scripts' ) );

			add_action( 'admin_print_scripts', array( $this, 'admin_inline_js' ) );

			// Get customer id when create customer.
			add_action( 'woocommerce_created_customer', array( $this, 'get_new_customer_id' ), 10, 2 );

			// Maybe add an "opt-in" field to the checkout.
			add_action( 'woocommerce_checkout_after_terms_and_conditions', array( $this, 'maybe_add_checkout_fields_terms' ) );
			add_filter( 'woocommerce_checkout_fields', array( $this, 'maybe_add_checkout_fields' ) );

			// Hook into woocommerce order status changed hook to handle the desired subscription event trigger.
			add_action( 'woocommerce_order_status_changed', array( $this, 'ws_order_status_changed' ), 10, 3 );

			// Maybe save the "opt-in" field on the checkout.
			add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'maybe_save_checkout_fields' ) );

			// Alert credit is not enough.
			add_action( 'admin_notices', array( $this, 'ws_admin_credits_notice' ) );

			// Ajax.
			add_action( 'wp_ajax_ws_validation_process', array( 'WC_Sendinblue', 'ajax_validation_process' ) );
			add_action( 'wp_ajax_ws_sms_test_send', array( 'WC_Sendinblue_SMS', 'ajax_sms_send' ) );
			add_action( 'wp_ajax_ws_sms_refresh', array( 'WC_Sendinblue_SMS', 'ajax_sms_refresh' ) );
			add_action( 'wp_ajax_ws_sms_campaign_send', array( 'WC_Sendinblue_SMS', 'ajax_sms_campaign_send' ) );
			add_action( 'wp_ajax_ws_get_daterange', array( 'WC_Sendinblue_SMTP', 'ajax_get_daterange' ) );
			add_action( 'wp_ajax_ws_email_campaign_send', array( 'WC_Sendinblue_SMTP', 'ajax_email_campaign_send' ) );
			add_action( 'wp_ajax_ws_dismiss_alert', array( $this, 'ajax_dismiss_alert' ) );
			add_action( 'wp_ajax_ws_transient_refresh', array( $this, 'ajax_transient_refresh' ) );
			/* To sync all customers to contact list. */
			add_action( 'wp_ajax_ws_sync_users', array( $this, 'ajax_sync_users' ) );

			// Hook to send woocommerce email.
			add_filter( 'wc_get_template', array( $this, 'ws_get_template_type' ), 10, 2 );
			add_filter( 'woocommerce_email_headers', array( $this, 'woocommerce_mail_header' ), 15 );
			add_filter( 'woocommerce_mail_content', array( $this, 'woocommerce_mail_content' ), 20 );
			add_filter( 'woocommerce_email_styles', array( $this, 'ws_get_email_style' ) );

			// Get order info from $arg when get started sending woo emails.
			add_action( 'woocommerce_before_template_part', array( $this, 'ws_before_template_part' ), 10, 4 );// $template_name, $template_path, $located, $args );)

			// Marketing automation events hooks start BG20190425.
			add_action( 'wp_head', array( $this, 'install_ma_and_chat_script' ) ); // fn will determine if script should be loaded
			$ma_enabled = isset( $this->customizations['ws_marketingauto_enable'] ) ? $this->customizations['ws_marketingauto_enable'] : '';
			if ( 'yes' == $ma_enabled ) {
				// Identification for logged in user.
				add_action( 'wp_login', array( $this, 'wp_login_action' ), 11, 2 );
				// Add marketing automation event listeners.
				add_action( 'wp_head', array( $this, 'install_ma_event_listeners' ) );
				// Cart changed data update.
				add_action( 'wp_footer', array( $this, 'ws_cart_custom_fragment_load' ) );
				add_filter( 'woocommerce_add_to_cart_fragments', array( $this, 'ws_cart_custom_fragment' ), 10, 1 );
				// Order completed.
				add_action( 'woocommerce_thankyou', array( $this, 'ws_checkout_completed' ) );
			}
			// Marketing automation events hooks end.

			/**
			 * Hook wp_mail to send transactional emails.
			 */

			// Check if wp_mail function is already declared by others.
			if ( function_exists( 'wp_mail' ) ) {
				self::$wp_mail_conflict = true;
			}
			$home_settings   = get_option( 'sib_home_option', array() );
			$nf_sib_settings = get_option( 'ninja_forms_settings', array() );

			if ( 'yes' == $this->ws_smtp_enable && false == self::$wp_mail_conflict ) {
				function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {

					try {
						$sent = WC_Sendinblue::sib_email( $to, $subject, $message, $headers, $attachments );
						if ( is_wp_error( $sent ) || ! isset( $sent['code'] ) || 'success' != $sent['code'] ) {
							try {
								return true;
							} catch ( Exception $e ) {
								return false;
							}
						}
						return true;
					} catch ( Exception $e ) {
						return false;
					}
				}
			} elseif ( 'yes' == $this->ws_smtp_enable && true == self::$wp_mail_conflict ) {
				if ( ( !isset( $nf_sib_settings['nf_sib_smtp_enable'] ) || 'yes' != $nf_sib_settings['nf_sib_smtp_enable'] ) && ( !isset( $home_settings['activate_email'] ) || 'yes' != $home_settings['activate_email'] ) ) {
					add_action( 'admin_notices', array( &$this, 'wpMailNotices' ) );
					return;
				}
			}
			SIB_Model_Contact::add_prefix();
			SIB_Model_Country::add_prefix();

		}

		public function ws_before_template_part( $template_name, $template_path, $located, $args ) {
			if ( isset( $args['order'] ) && is_object( $args['order'] ) ) {
				if ( version_compare( get_option( 'woocommerce_db_version' ), '3.0', '>=' ) ) {
					$this->order_id = $args['order']->get_id();
				} else {
					$this->order_id = $args['order']->id;
				}
			}
		}

		/**
		 * Inline scripts
		 */
		public function admin_inline_js() {
			// Login and logout button.
			$api_key_v3 = get_option( WC_Sendinblue::API_KEY_V3_OPTION_NAME );
			if ( ! empty( $api_key_v3 ) ) {
				$logBtn = '<div style="padding-left: 24px;"><a href="' . esc_url( add_query_arg( 'ws_action', 'logout' ) ) . '" class = "button-primary" style="padding: 0 20px; ">' . __( 'Logout', 'wc_sendinblue' ) . '</a></div>';// sprintf( __('If you want logout, please click %s. ', 'wc_sendinblue'),'<a href="'. esc_url(add_query_arg('sib_action', 'logout')).'">'. __('Logout','wc_sendinblue') .'</a>');
			} else {
				// Login.
				$logBtn = '<div><img id="ws_login_gif" src="' . WC()->plugin_url() . '/assets/images/select2-spinner.gif' .
					'" style="margin-right: 12px;vertical-align: middle;display:none;"><a href="javascript:void(0);" class = "ws_api_key_active button-primary" style="padding: 0 20px; margin-top: 24px;">' . __( 'Login', 'wc_sendinblue' ) . '</a></div>';
			}

			$loading_gif = '<img class="ws_loading_gif" src="' . WC()->plugin_url() . '/assets/images/select2-spinner.gif' .
				'" style="margin-right: 12px;vertical-align: middle;display:none;">';
			// Variable in send SMS page.

			echo "<script type='text/javascript'>\n";

			if ( ( isset( $_GET['section'] ) && 'sms_options' == $_GET['section'] ) || ( isset( $_GET['section'] ) && 'campaigns' == $_GET['section'] ) ) {
				/* translators: %s: search term */
				echo 'var VAR_SMS_MSG_DESC = "' . sprintf( __( 'If you want to personalize the SMS, you can use the variables below:%1$s - For first name use {first_name}%2$s - For last name use {last_name}%3$s - For order price use {order_price}%4$s - For order date use {order_date}', 'wc_sendinblue' ), '<br>', '<br>', '<br>', '<br>' ) . '";';
			}
			echo 'var ws_tab ="' . ( isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : '' ) . '";';
			echo 'var ws_section ="' . ( isset( $_GET['section'] ) ? sanitize_text_field( $_GET['section'] ) : '' ) . '";';
			echo 'var SEND_BTN ="' . __( 'Send', 'wc_sendinblue' ) . '";';
			echo 'var SENDING_BTN ="' . __( 'Sending', 'wc_sendinblue' ) . '";';
			echo 'var SEND_CAMP_BTN ="' . __( 'Send the campaign', 'wc_sendinblue' ) . '";';
			echo 'var ws_alert_msg_failed ="' . __( 'Message has not been sent successfully.', 'wc_sendinblue' ) . '";';
			echo 'var ws_alert_msg_contact_failed ="' . __( 'Message has not been sent successfully. Please check format of contacts', 'wc_sendinblue' ) . '";';
			echo 'var ws_alert_msg_success ="' . __( 'Message has been sent successfully.', 'wc_sendinblue' ) . '";';
			echo "var Loading_Gif ='" . $loading_gif . "';";
			echo "var LOG_BTN ='" . $logBtn . "';";

			echo "\n</script>";

		}

		/**
		 * Load scripts.
		 */
		public function register_plugin_scripts( $hook ) {
			if ( ! isset( $_GET['tab'] ) ) {
				return;
			}

			if ( 'woocommerce_page_wc-settings' == $hook && 'sendinblue' == $_GET['tab'] ) {
				wp_enqueue_script( 'wc_sendinblue_js', plugin_dir_url( __FILE__ ) . 'assets/js/sendinblue_admin.js', array(), SibApiClient::PLUGIN_VERSION );
				wp_enqueue_script( 'ws-ui-js', plugin_dir_url( __FILE__ ) . '/assets/js/jquery-ui.js', array(), SibApiClient::PLUGIN_VERSION );
				wp_enqueue_script( 'ws-moment-js', plugin_dir_url( __FILE__ ) . '/assets/js/moment.js', array(), SibApiClient::PLUGIN_VERSION );
				wp_enqueue_script( 'wc-date-js', plugin_dir_url( __FILE__ ) . '/assets/js/jquery.comiseo.daterangepicker.js', array(), SibApiClient::PLUGIN_VERSION );
				wp_localize_script( 'wc_sendinblue_js', 'ajax_object', array( 'ajax_url' => admin_url( 'admin-ajax.php' ) ) );

				wp_enqueue_style( 'wc_sendinblue_css', plugin_dir_url( __FILE__ ) . '/assets/css/sendinblue_admin.css', array(), SibApiClient::PLUGIN_VERSION );
				wp_enqueue_style( 'ws-ui-css', plugin_dir_url( __FILE__ ) . '/assets/css/jquery-ui.css', array(), SibApiClient::PLUGIN_VERSION );
				wp_enqueue_style( 'wc-date-css', plugin_dir_url( __FILE__ ) . '/assets/css/jquery.comiseo.daterangepicker.css', array(), SibApiClient::PLUGIN_VERSION );
				wp_enqueue_script( 'wc-chosen-js', plugin_dir_url( __FILE__ ) . '/assets/js/chosen.jquery.min.js', array( 'jquery' ), SibApiClient::PLUGIN_VERSION );
				wp_enqueue_style( 'wc-chosen-css', plugin_dir_url( __FILE__ ) . '/assets/css/chosen.min.css', array(), SibApiClient::PLUGIN_VERSION );

			}
			return;
		}

		/**
		 * Add settings page
		 *
		 * @param array $settings
		 * @return array
		 */
		public function add_settings_page( $settings ) {
			$settings[] = require_once 'includes/wc-sendinblue-settings.php';
			return $settings;
		}

		/**
		 * Load customizations after WC is loaded
		 */
		public function load_customizations() {
			if ( ! isset( $_GET['tab'] ) || 'sendinblue' != $_GET['tab'] ) {
				return;
			}
						add_action( 'admin_notices', array( $this, 'ws_api_check' ) );

			WC_Sendinblue::init();

		}

		/**
		 * Initialize method.
		 */
		public function init() {
			// Redirect after activate plugin.
			if ( get_option( 'ws_do_activation_redirect', false ) ) {
				delete_option( 'ws_do_activation_redirect' );
				if ( ! isset( $_GET['activate-multi'] ) ) {
					wp_redirect( add_query_arg( 'page', 'wc-settings&tab=sendinblue', admin_url( 'admin.php' ) ) );
				}
			}

			// Localization in the init action for WPML support.
			load_plugin_textdomain( 'wc_sendinblue', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

			// Subscribe.
			if ( isset( $_GET['ws_action'] ) && ( 'subscribe' == $_GET['ws_action'] ) ) {
				WC_Sendinblue::subscribe();
				exit;
			}
			if ( ( isset( $_GET['ws_action'] ) ) && ( 'logout' == $_GET['ws_action'] ) ) {
				WC_Sendinblue::logout();
			}

		}

		/** Lifecycle methods. */
		/**
		 * Run every time.  Used since the activation hook is not executed when updating a plugin
		 */
		private function install() {

		}

		/**
		 * Check if an api key is correct
		 **/
		public function ws_api_check() {
			// Check required fields.
			if ( '' == WC_Sendinblue::$access_key && '' != WC_Sendinblue::$ws_error_type ) {
				// Show notice.
				echo $this->get_message( __( 'Sendinblue error: ', 'wc_sendinblue' ) . WC_Sendinblue::$ws_error_type );
			}
		}

		/**
		 * Get message
		 *
		 * @return string Error
		 */
		private function get_message( $message, $type = 'error' ) {
			ob_start();

			?>
			<div class="<?php echo esc_attr( $type ); ?>">
				<p><?php echo esc_html( $message ); ?></p>
			</div>
			<?php
			return ob_get_clean();
		}

		/**
		 * Display alert when you don't have enough credits
		 */
		public function ws_admin_credits_notice() {
			if ( 2 > WC_Sendinblue::$account_info['email_credits']['credits'] && 'closed' != get_option( 'ws_credits_notice' ) && null != get_option( 'ws_credits_notice' ) ) {
				$class   = 'error notice is-dismissible ws_credits_notice';
				$message = __( 'You don\'t have enough credits to send email through <b>Sendinblue SMTP</b>. ', 'wc_sendinblue' );
				$url     = '<i>' . sprintf(
					/* translators: %s: search term */
					__( 'To buy more credits, please click %s.', 'wc_sendinblue' ) . '</i>',
					"<a target='_blank' href='https://www.sendinblue.com/pricing?utm_source=wordpress_plugin&utm_medium=plugin&utm_campaign=module_link' class='ws_refresh'>" . __( 'here', 'wc_sendinblue' ) . '</a>'
				);
				$button  = '<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>';
				echo "<div class=\"$class\"> <p>$message$url</p>$button</div>";
			}
		}

		/**
		 * WooCommerce 2.2 support for wc_get_order
		 *
		 * @param int $order_id
		 * @return void
		 */
		private function wc_get_order( $order_id ) {
			if ( function_exists( 'wc_get_order' ) ) {
				return wc_get_order( $order_id );
			} else {
				return new WC_Order( $order_id );
			}
		}

		/**
		 * By Subscription and SMS Options
		 * order_status_changed function.
		 *
		 * @return void
		 */
		public function ws_order_status_changed( $id, $status = 'pending', $new_status = 'on-hold' ) {
			self::$order_template_sib = $new_status;
			// Current status of an order ($new_status) will be "on-hold" or "processing " after created.
			if ( 'on-hold' == $this->customizations['ws_order_event'] ) {
				$compareStatus = 'on-hold|processing';
			} else {
				$compareStatus = $this->customizations['ws_order_event'];
			}

			// Get WC order.
			$order = $this->wc_get_order( $id );

			// Customer will be added to a list after subscribe event occur.
			if ( 'yes' == $this->ws_subscribe_enable && false !== strpos( $compareStatus, $new_status ) ) {

				$ws_dopt_enabled = isset( $this->customizations['ws_dopt_enabled'] ) ? $this->customizations['ws_dopt_enabled'] : 'no';
				// Get the ws_opt_in value from the post meta. (it is coming form front).
				$ws_opt_in = get_post_meta( $id, 'ws_opt_in', true ); // Yes or no.
				$info      = array();
				if ( isset( $this->customizations['ws_matched_lists'] ) ) {
					foreach ( $this->customizations['ws_matched_lists'] as $key => $attr ) {
						if ( version_compare( get_option( 'woocommerce_db_version' ), '3.0', '>=' ) ) {
							$billing_data = $order->get_data();
							$info[ $key ] = $billing_data['billing'][ str_replace( 'billing_', '', $attr ) ];
						} else {
							$info[ $key ] = $order->$attr;
						}
						if ( ! empty( $info['SMS'] ) ) {
							$iso_code    = SIB_Model_Country::get_prefix( $billing_data['billing']['country'] );
							$info['SMS'] = self::checkMobileNumber( $info['SMS'], $iso_code );
						}
					}
				}
				$date_format = 'Y-m-d';
				if ( version_compare( get_option( 'woocommerce_db_version' ), '3.0', '>=' ) ) {
					$info            = array_merge(
						$info,
						array(
							/* Woocommerce attrs */
							'ORDER_ID'    => $id,
							'ORDER_DATE'  => date( $date_format, strtotime( $order->get_date_created() ) ),
							'ORDER_PRICE' => $order->get_total(),
						)
					);
					$subscribe_email = $order->get_billing_email();
				} else {
					$info            = array_merge(
						$info,
						array(
							/* woocommerce attrs */
							'ORDER_ID'    => $id,
							'ORDER_DATE'  => date( $date_format, strtotime( $order->order_date ) ),
							'ORDER_PRICE' => $order->order_total,
						)
					);
					$subscribe_email = $order->billing_email;
				}

				// To check if the email is in list already.
				$wc_sib     = new WC_Sendinblue();
				if ( 'yes' == $ws_dopt_enabled && 'yes' == $ws_opt_in ) {
					$wc_sib_smtp      = new WC_Sendinblue_SMTP();
					$dopt_template_id = ! isset( $this->customizations['ws_dopt_templates'] ) ? 0 : $this->customizations['ws_dopt_templates'];

					$temp_list_id = $this->customizations['ws_dopt_list_id']; // Temp - DOUBLE OPTIN.
					$wc_sib_smtp->double_optin_signup( $subscribe_email, $this->customizations['ws_sendinblue_list'], $info, $dopt_template_id, $temp_list_id );
				} elseif ( 'yes' == $ws_opt_in ) {
					$wc_sib->create_subscriber( $subscribe_email, $this->customizations['ws_sendinblue_list'], $info );
				}
			}

			// Send confirmation SMS.
			if ( 'yes' == $this->ws_sms_enable ) {

				$wc_sib_sms           = new WC_Sendinblue_SMS();
				$ws_sms_send_after    = isset( $this->customizations['ws_sms_send_after'] ) ? $this->customizations['ws_sms_send_after'] : 'no';
				$ws_sms_send_shipment = isset( $this->customizations['ws_sms_send_shipment'] ) ? $this->customizations['ws_sms_send_shipment'] : 'no';

				// Send a SMS confirmation for order confirmation.
				if ( 'yes' == $ws_sms_send_after && false !== strpos( $compareStatus, $new_status ) ) {
					$from = $this->customizations['ws_sms_sender_after'];
					$text = $this->customizations['ws_sms_send_msg_desc_after'];
					$wc_sib_sms->ws_send_confirmation_sms( $order, $from, $text );
				}
				// Send a SMS confirmation for the shipment of the order.
				if ( 'yes' == $ws_sms_send_shipment && 'completed' == $new_status ) {
					$from = $this->customizations['ws_sms_sender_shipment'];
					$text = $this->customizations['ws_sms_send_msg_desc_shipment'];
					$wc_sib_sms->ws_send_confirmation_sms( $order, $from, $text );
				}
			}
		}

		/**
		 * Adding function to make Mobible Number Validate according to the API format
		 */
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
			} else {
				return '00' . $call_prefix . $number;
			}
		}

		/**
		 * Add the opt-in checkbox to the checkout fields (to be displayed on checkout).
		 */
		public function maybe_add_checkout_fields_terms( $checkout_fields ) {
			$ws_opt_field     = isset( $this->customizations['ws_opt_field'] ) ? $this->customizations['ws_opt_field'] : 'no';
			$display_location = isset( $this->customizations['ws_opt_checkbox_location'] ) ? $this->customizations['ws_opt_checkbox_location'] : '';
			if ( 'yes' == $ws_opt_field && 'terms_condition' == $display_location ) {
				?>
				<p class="form-row terms woocommerce-validated" id="ws_opt_in_field" style="clear:both;">
					<label class="checkbox">
						<input type="checkbox" class="input-checkbox" name="ws_opt_in" <?php echo ( 'checked' == $this->customizations['ws_opt_default_status'] ? 'checked' : '' ); ?>>
						<?php echo esc_attr( $this->customizations['ws_opt_field_label'] ); ?>
					</label>
				</p>
				<?php

			}
		}

		public function maybe_add_checkout_fields( $checkout_fields ) {
			$display_location = isset( $this->customizations['ws_opt_checkbox_location'] ) ? $this->customizations['ws_opt_checkbox_location'] : '';

			if ( empty( $display_location ) ) {
				$display_location = 'billing';
			}
			$ws_opt_field = isset( $this->customizations['ws_opt_field'] ) ? $this->customizations['ws_opt_field'] : 'no';
			if ( 'yes' == $ws_opt_field ) {
				$checkout_fields[ $display_location ]['ws_opt_in'] = array(
					'type'    => 'checkbox',
					'label'   => esc_attr( $this->customizations['ws_opt_field_label'] ),
					'default' => 'checked' == $this->customizations['ws_opt_default_status'] ? 1 : 0,
				);
			}

			return $checkout_fields;
		}

		/**
		 * When the checkout form is submitted, save opt-in value.
		 */
		public function maybe_save_checkout_fields( $order_id ) {
			$ws_opt_enable = isset( $this->customizations['ws_opt_field'] ) ? $this->customizations['ws_opt_field'] : 'no';
			if ( 'yes' == $ws_opt_enable ) {
				$opt_in = isset( $_POST['ws_opt_in'] ) ? 'yes' : 'no';
				update_post_meta( $order_id, 'ws_opt_in', $opt_in );
			} else {
				// Customer will be added to a list.
				update_post_meta( $order_id, 'ws_opt_in', 'yes' );
			}
		}

		/**
		 * When Sendinblue is enabled to send Woocommerce emails
		 * replace email template with one of Sendinblue instead of Woo template
		 */
		public function ws_get_template_type( $path, $file ) {
			$files = explode( '/', $file );
			if ( ! is_array( $files ) || ! isset( $files[1] ) ) {
				return $path;
			}
			$files = explode( '.', $files[1] );
			if ( ! is_array( $files ) ) {
				return $path;
			}
			$type = $files[0];
			// Ex, admin-new-order.php to admin-new-order.
			$email_type = array(
				'admin-new-order'           => 'New Order',
				'admin-cancelled-order'     => 'Cancelled Order',
				'customer-completed-order'  => 'Completed Order',
				'customer-new-account'      => 'New Account',
				'customer-processing-order' => 'Processing Order',
				'customer-refunded-order'   => 'Refunded Order',
				'customer-note'             => 'Customer Note',
				'customer-on-hold-order'    => 'Order On-Hold',
				'admin-failed-order'        => 'Failed Order',
			);
			if ( array_key_exists( $type, $email_type ) ) {
				$template_type = $email_type[ $type ]; // Ex, New order.
				$template_ids  = get_option( 'ws_email_templates', array() );
				$template_id   = $template_ids[ $template_type ];

				$template                 = array(
					'id'   => $template_id,
					'type' => $template_type,
				);
				self::$order_template_sib = $template;
			}
			return $path;
		}

		/**
		 * Get new customer id
		 *
		 * @param int $customer_id - new customer id.
		 */
		public function get_new_customer_id( $customer_id, $customer_data ) {
			$this->customer_id   = $customer_id;
			$this->customer_data = $customer_data;
		}

		// Add template tags in email header.
		public function woocommerce_mail_header( $header ) {

			$template = self::$order_template_sib;
			$header  .= 'X-Mailin-Tag: ' . ( isset( $template['type'] ) ? $template['type'] : '' ) . "\r\n";
			// From name.
			$from_name    = WC_Emails::instance()->get_from_name();
			$from_address = WC_Emails::instance()->get_from_address();
			$header      .= 'From: ' . $from_name . ' <' . $from_address . '>' . "\r\n";

			return $header;
		}
		// Replace email template with one of Sendinblue.
		public function woocommerce_mail_content( $message ) {
			if ( 'yes' == $this->ws_template_enable ) {

				$template = self::$order_template_sib;
				if ( empty( $template ) || '0' == $template['id'] ) {
					return $message;
				}

				$sib_templates = WC_Sendinblue_API::get_templates();
				$sib_template  = $sib_templates[ $template['id'] ];
				// Replace a transactional attributes (ORDER_ID,ORDER_DATE,ORDER_PRICE,...).
				$order = $this->wc_get_order( $this->order_id );
				if ( null != $order ) {
					$items              = $order->get_items();
					$show_download_link = $order->is_download_permitted();
					$refunded_orders    = $order->get_refunds();
					$refunded_amount    = 0;
					if ( ! empty( $refunded_orders ) ) {
						foreach ( $refunded_orders as $refunded_order ) {
							$refunded_amount += $refunded_order->get_amount();
						}
					}
					// Get download product link.
					ob_start();
					if ( $show_download_link ) {
						foreach ( $items as $item_id => $item ) {
							if ( version_compare( get_option( 'woocommerce_db_version' ), '3.0', '>=' ) ) {
								wc_display_item_downloads( $item );
							} else {
								$order->display_item_downloads( $item );
							}
						}
					}
					$order_download_link = ob_get_contents();
					ob_clean();

					// Get order product details.
					$order_detail = '<table style="padding-left: 0px;width: 100%;text-align: left;"><tr><th>' . __( 'Products', 'wc_sendinblue' ) . '</th><th>' . __( 'Quantity', 'wc_sendinblue' ) . '</th><th>' . __( 'Price', 'wc_sendinblue' ) . '</th></tr>';
					foreach ( $items as $item ) {
						if ( isset( $item['variation_id'] ) && '' != $item['variation_id'] ) {
							$product = new WC_Product_Variation( $item['variation_id'] );
						} else {
							$product = new WC_Product( $item['product_id'] );
						}
						$product_name     = $item['name'];
						$product_quantity = $item['qty'];
						$sub_total        = $product->get_price() * $product_quantity;
						if ( version_compare( get_option( 'woocommerce_db_version' ), '3.0', '>=' ) ) {
							$product_price = wc_price( $sub_total, array( 'currency' => $order->get_currency() ) );
						} else {
							$product_price = wc_price( $sub_total, array( 'currency' => $order->order_currency ) );
						}
						$order_detail .= '<tr><td>' . $product_name . '</td><td>' . $product_quantity . '</td><td>' . $product_price . '</td></tr>';
					}
					$order_detail .= '</table>';
					if ( version_compare( get_option( 'woocommerce_db_version' ), '3.0', '>=' ) ) {
						// check for tracking
						$tracking_provider = $order->get_meta('_wc_shipment_tracking_items')[0]["tracking_provider"] ? $order->get_meta('_wc_shipment_tracking_items')[0]["tracking_provider"] : $order->get_meta('_wc_shipment_tracking_items')[0]["custom_tracking_provider"];
							
							$tracking_text = 'Your order has been shipped. You will receive another email with your tracking information shortly.';
							// send shipping details if exist
							if ($tracking_provider) {
								$tracking_number = $order->get_meta('_wc_shipment_tracking_items')[0]["tracking_number"];
								$tracking_url = $order->get_meta('_wc_shipment_tracking_items')[0]["custom_tracking_link"];
								if($tracking_url) {
								    $tracking_text = "Your order has been shipped with the tracking number <a href='".$tracking_url."' style='color:#477c93;'>". $tracking_number."</a> with ". $tracking_provider.".";
								}
								else {
								    $tracking_text = "Your order has been shipped with the tracking number  $tracking_number with $tracking_provider.";
								}
							}
							// check for hands free
// 							$cups_preorder_text = "";
// 							// Loop through the order items
// 							foreach ( $order->get_items() as $item ) {
// 								// Get the product ID
// 								$product_id = $item->get_product_id();
// 								// Check if the product ID matches the one you're looking for
// 								if ( $product_id == 207132 || $product_id == 207131 || $product_id == 207084 ) {
// 									// Cups pre order text
// 									$cups_preorder_text = "Thanks for pre-ordering the Handy Handsfree Cup! <p>The estimated shipping date for your order will be as follows: For EU and UK customers: 30th of Jan 2023; For all other customers: 16th of Jan 2023. </p><p>If your order consists of other products, they will be shipped out together with the Handsfree Cup on the estimated shipping dates as given above. If you want to make any changes to your order, please send an email to contact@thehandy.com with your order number and changes.</p>";
// 									break;
// 								}
// 							}
							
						$orders = array(
							'{ORDER_ID}'              => $order->get_id(),
							'{BILLING_FIRST_NAME}'    => $order->get_billing_first_name(),
							'{BILLING_LAST_NAME}'     => $order->get_billing_last_name(),
							'{BILLING_COMPANY}'       => $order->get_billing_company(),
							'{BILLING_ADDRESS_1}'     => $order->get_billing_address_1(),
							'{BILLING_ADDRESS_2}'     => $order->get_billing_address_2(),
							'{BILLING_CITY}'          => $order->get_billing_city(),
							'{BILLING_STATE}'         => $order->get_billing_state(),
							'{BILLING_POSTCODE}'      => $order->get_billing_postcode(),
							'{BILLING_COUNTRY}'       => $order->get_billing_country(),
							'{BILLING_PHONE}'         => $order->get_billing_phone(),
							'{BILLING_EMAIL}'         => $order->get_billing_email(),
							'{SHIPPING_FIRST_NAME}'   => $order->get_shipping_first_name(),
							'{SHIPPING_LAST_NAME}'    => $order->get_shipping_last_name(),
							'{SHIPPING_COMPANY}'      => $order->get_shipping_company(),
							'{SHIPPING_ADDRESS_1}'    => $order->get_shipping_address_1(),
							'{SHIPPING_ADDRESS_2}'    => $order->get_shipping_address_2(),
							'{SHIPPING_CITY}'         => $order->get_shipping_city(),
							'{SHIPPING_STATE}'        => $order->get_shipping_state(),
							'{SHIPPING_POSTCODE}'     => $order->get_shipping_postcode(),
							'{SHIPPING_COUNTRY}'      => $order->get_shipping_country(),
							'{CART_DISCOUNT}'         => $order->get_discount_total(),
							'{CART_DISCOUNT_TAX}'     => $order->get_discount_tax(),
							'{SHIPPING_METHOD_TITLE}' => $order->get_shipping_method(),
							'{CUSTOMER_USER}'         => $order->get_customer_user_agent(),
							'{ORDER_KEY}'             => $order->get_order_key(),
							'{ORDER_DISCOUNT}'        => wc_price( $order->get_discount_total(), array( 'currency' => $order->get_currency() ) ),
							'{ORDER_TAX}'             => wc_price( $order->get_total_tax(), array( 'currency' => $order->get_currency() ) ),
							'{ORDER_SHIPPING_TAX}'    => wc_price( $order->get_shipping_tax(), array( 'currency' => $order->get_currency() ) ),
							'{ORDER_SHIPPING}'        => wc_price( $order->get_shipping_total(), array( 'currency' => $order->get_currency() ) ),
							'{ORDER_PRICE}'           => wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ),
							'{ORDER_DATE}'            => gmdate( 'd-m-Y', strtotime( $order->get_date_created() ) ),
							'{ORDER_SUBTOTAL}'        => wc_price( $order->get_subtotal(), array( 'currency' => $order->get_currency() ) ),
							'{ORDER_DOWNLOAD_LINK}'   => $order_download_link,
							'{ORDER_PRODUCTS}'        => $order_detail,
							'{PAYMENT_METHOD}'        => $order->get_payment_method(),
							'{PAYMENT_METHOD_TITLE}'  => $order->get_payment_method_title(),
							'{CUSTOMER_IP_ADDRESS}'   => $order->get_customer_ip_address(),
							'{CUSTOMER_USER_AGENT}'   => $order->get_customer_user_agent(),
							'{REFUNDED_AMOUNT}'       => wc_price( $refunded_amount, array( 'currency' => $order->get_currency() ) ),
							'{TRACKING_TEXT}'      => $tracking_text,		
// 							'{PREORDER_TEXT}'		  => $cups_preorder_text,
							// For admin.
							'[ORDER_LINK]'            => admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' ),
						);
					} else {
						$orders = array(
							'{ORDER_ID}'              => $order->id,
							'{BILLING_FIRST_NAME}'    => $order->billing_first_name,
							'{BILLING_LAST_NAME}'     => $order->billing_last_name,
							'{BILLING_COMPANY}'       => $order->billing_company,
							'{BILLING_ADDRESS_1}'     => $order->billing_address_1,
							'{BILLING_ADDRESS_2}'     => $order->billing_address_2,
							'{BILLING_CITY}'          => $order->billing_city,
							'{BILLING_STATE}'         => $order->billing_state,
							'{BILLING_POSTCODE}'      => $order->billing_postcode,
							'{BILLING_COUNTRY}'       => $order->billing_country,
							'{BILLING_PHONE}'         => $order->billing_phone,
							'{BILLING_EMAIL}'         => $order->billing_email,
							'{SHIPPING_FIRST_NAME}'   => $order->shipping_first_name,
							'{SHIPPING_LAST_NAME}'    => $order->shipping_last_name,
							'{SHIPPING_COMPANY}'      => $order->shipping_company,
							'{SHIPPING_ADDRESS_1}'    => $order->shipping_address_1,
							'{SHIPPING_ADDRESS_2}'    => $order->shipping_address_2,
							'{SHIPPING_CITY}'         => $order->shipping_city,
							'{SHIPPING_STATE}'        => $order->shipping_state,
							'{SHIPPING_POSTCODE}'     => $order->shipping_postcode,
							'{SHIPPING_COUNTRY}'      => $order->shipping_country,
							'{CART_DISCOUNT}'         => $order->cart_discount,
							'{CART_DISCOUNT_TAX}'     => $order->cart_discount_tax,
							'{SHIPPING_METHOD_TITLE}' => $order->shipping_method_title,
							'{CUSTOMER_USER}'         => $order->customer_user,
							'{ORDER_KEY}'             => $order->order_key,
							'{ORDER_DISCOUNT}'        => wc_price( $order->order_discount, array( 'currency' => $order->order_currency ) ),
							'{ORDER_TAX}'             => wc_price( $order->order_tax, array( 'currency' => $order->order_currency ) ),
							'{ORDER_SHIPPING_TAX}'    => wc_price( $order->order_shipping_tax, array( 'currency' => $order->order_currency ) ),
							'{ORDER_SHIPPING}'        => wc_price( $order->order_shipping, array( 'currency' => $order->order_currency ) ),
							'{ORDER_PRICE}'           => wc_price( $order->order_total, array( 'currency' => $order->order_currency ) ),
							'{ORDER_DATE}'            => $order->order_date,
							'{ORDER_SUBTOTAL}'        => wc_price( $order->order_total - $order->order_shipping, array( 'currency' => $order->order_currency ) ),
							'{ORDER_DOWNLOAD_LINK}'   => $order_download_link,
							'{ORDER_PRODUCTS}'        => $order_detail,
							'{PAYMENT_METHOD}'        => $order->payment_method,
							'{PAYMENT_METHOD_TITLE}'  => $order->payment_method_title,
							'{CUSTOMER_IP_ADDRESS}'   => $order->customer_ip_address,
							'{CUSTOMER_USER_AGENT}'   => $order->customer_user_agent,
							'{REFUNDED_AMOUNT}'       => wc_price( $refunded_amount, array( 'currency' => $order->order_currency ) ),
							// For admin.
							'[ORDER_LINK]'            => admin_url( 'post.php?post=' . $order->id . '&action=edit' ),
						);
					}
				} else { // For new account email.
					$customer_id   = $this->customer_id;
					$customer_data = $this->customer_data;
					if ( $customer_id ) {
						$orders = array(
							'{USER_LOGIN}'    => $customer_data['user_login'],
							'{USER_PASSWORD}' => $customer_data['user_pass'],
						);
					} else {
						$orders = array();
					}
				}

				foreach ( $orders as $tag => $value ) {
					$sib_template['content'] = str_replace( $tag, $value, $sib_template['content'] );
				}
				return $sib_template['content'];
			}
			return $message;
		}
		// Replace css of email template.
		public function ws_get_email_style( $css ) {
			return $css;
		}
		/* End of replace email template */

		/**
		 * Send notify email for limit of sms credits
		 */
		public function do_sms_limit_notify() {
			// Do something every day.
			$sms_limit       = isset( $this->customizations['ws_sms_credits_limit'] ) ? $this->customizations['ws_sms_credits_limit'] : 0;
			$sms_limit_email = isset( $this->customizations['ws_sms_credits_notify_email'] ) ? $this->customizations['ws_sms_credits_notify_email'] : '';
			$notify_status   = isset( $this->customizations['ws_sms_credits_notify'] ) ? $this->customizations['ws_sms_credits_notify'] : 'no';
			$current_sms_num = WC_Sendinblue::ws_get_credits();

			if ( 'yes' == $notify_status && '' != $sms_limit_email && 0 != $sms_limit && $current_sms_num < $sms_limit ) {
				$subject = __( 'Notification of your credits', 'wc_sendinblue' );
				WC_Sendinblue_SMTP::send_email( 'notify', $sms_limit_email, $subject, $current_sms_num );
			}
		}

		// Custom schedule.
		public function ws_add_weekly_cron_schedule( $schedules ) {
			$schedules['weekly'] = array(
				'interval' => 604800, // 1 week in seconds.
				'display'  => __( 'Once Weekly' ),
			);
			return $schedules;
		}
		/**
		 * Uninstall method is called once uninstall this plugin
		 * delete tables, options that used in plugin
		 */
		public static function uninstall() {
			$setting = array();
			update_option( 'ws_main_option', $setting );
			update_option( 'wc_sendinblue_settings', $setting );
			update_option( 'ws_email_templates', $setting );
		}

		/**
		 * Deactivate method is called once deactivate this plugin
		 */
		public static function deactivate() {
			SIB_Model_Country::remove_table();

			wp_clear_scheduled_hook( 'ws_hourly_event' );

			// Remove sync users option.
			delete_option( 'ws_sync_users' );

			// Remove transients.
			WC_Sendinblue_API::remove_transients();
		}

		/**
		 * Install method is called once install this plugin.
		 * Create tables, default option ...
		 */
		public static function activate() {
			SIB_Model_Contact::create_table();

			// Get the country code data.
			SIB_Model_Country::create_table();

			$file         = fopen( plugin_dir_path( __FILE__ ) . '/model/country_code.csv', 'r' );
			$country_code = array();
			while ( ! feof( $file ) ) {
				$code                     = fgetcsv( $file );
				$country_code[ $code[0] ] = $code[1];
			}
			fclose( $file );

			SIB_Model_Country::Initialize( $country_code );

			// Redirect option.
			update_option( 'ws_do_activation_redirect', true );
		}



		/* Ajax module for dismiss alert */
		public function ajax_dismiss_alert() {
			update_option( 'ws_credits_notice', 'closed' );
			wp_send_json( 'success' );
		}

		/* Ajax module for initialize transients */
		public function ajax_transient_refresh() {
			wp_send_json( 'success' );
		}

		/* Ajax module for sync customers to contact list */
		public function ajax_sync_users() {
			$postData = ! empty( $_POST['data'] ) ? esc_attr( sanitize_text_field( $_POST['data'] ) ) : '';
			if ( isset( $postData['errAttr'] ) ) {
				$post_errorattr = ! empty( $_POST['data']['errAttr'] ) ? esc_attr( sanitize_text_field( $_POST['data']['errAttr'] ) ) : '';
				wp_send_json(
					array(
						'code'    => 'attr_duplicated',
						'message' => sprintf(
							/* translators: %s: search term */
							__(
								'The attribute %s is duplicated. You can select one at a time.',
								'wc_sendinblue'
							),
							'<b>' . $post_errorattr . '</b>'
						),
					)
				);}

			$listIDs = (array) $postData['list_id'];
			unset( $postData['list_id'] );

			$usersData = 'EMAIL';
			foreach ( $postData as $attrSibName => $attrWP ) {
				$usersData .= ';' . $attrSibName;
			}

			// Sync users to sendinblue.
			// Create body data like csv.
			// NAME;SURNAME;EMAIL\n Name1;Surname1;example1@example.net\nName2;Surname2;example2@example.net.
			$contentData         = '';
				$users           = get_users( array( 'role' => 'customer' ) );
				$count_customers = count( $users ); // Count_users();
			if ( ! empty( $users ) ) {
				foreach ( $users as $user ) {
					$userId    = $user->ID;
					$user_info = get_userdata( $userId );
					$userData  = $user_info->user_email;
					foreach ( $postData as $attrSibName => $attrWP ) {
						$userData .= ';' . $user_info->$attrWP;
					}
					$contentData .= "\n" . $userData;
				}
			}
			if ( '' == $contentData ) {
				wp_send_json(
					array(
						'code'    => 'empty_users',
						'message' => __(
							'There is not any user in the roles.',
							'wc_sendinblue'
						),
					)
				); }

			$usersData .= $contentData;
			$result     = WC_Sendinblue_API::sync_users( $usersData, $listIDs );
			if ( 'success' == $result['code'] ) {
				update_option( 'ws_sync_users', $count_customers );
			}
			wp_send_json( $result );
		}
		/**
		 * Notice wp_mail is not possible
		 */
		public static function wpMailNotices() {
			if ( self::$wp_mail_conflict ) {
				echo '<div class="error"><p>' . esc_html__( 'You cannot to use Sendinblue SMTP now because wp_mail has been declared by another process or plugin. ', 'ninja_forms_sib' ) . '</p></div>';
			}
		}



		// Marketing automation fns start.
		/**
		 * Install marketing automation script in header
		 */
		public function install_ma_and_chat_script() {
			if ( true == $this->checkWpPlugin() ) {
				return; }

			$general_settings = get_option( 'ws_main_option' );
			if ( isset( $general_settings['ma_key'] ) ) {
				 $ma_key = $general_settings['ma_key'];
				if ( '' != $ma_key ) {
					$ma_enabled = isset( $this->customizations['ws_marketingauto_enable'] ) ? $this->customizations['ws_marketingauto_enable'] : '';
					if ( 'yes' == $ma_enabled ) {

						$output      = '<!-- Sendinblue Marketing automation WooCommerce integration and Chat: start -->';
							$output .= '
                            <script type="text/javascript">
                                (function() {window.sib ={equeue:[],client_key:"' . $ma_key . '"};';
								$found_email_id = $this->get_email_id();
						if ( $found_email_id ) {
							$output .= 'window.sib.email_id = "' . $found_email_id . '";';
						}

								$output .= 'window.sendinblue = {}; for (var j = [\'track\', \'identify\', \'trackLink\', \'page\'], i = 0; i < j.length; i++) { (function(k) { window.sendinblue[k] = function() { var arg = Array.prototype.slice.call(arguments); (window.sib[k] || function() { var t = {}; t[k] = arg; window.sib.equeue.push(t);})(arg[0], arg[1], arg[2]);};})(j[i]);}var n = document.createElement("script"),i = document.getElementsByTagName("script")[0]; n.type = "text/javascript", n.id = "sendinblue-js", n.async = !0, n.src = "https://sibautomation.com/sa.js?key=" + window.sib.client_key, i.parentNode.insertBefore(n, i), window.sendinblue.page();})();
                            </script>';
						$output         .= '<!-- Sendinblue Marketing automation WooCommerce integration and Chat: end -->';
						echo $output;
					}
				}
			}
		}
		/**
		 * Helper fn for install_ma_and_chat_script (Install marketing automation script in header)
		 */
		public function checkWpPlugin() {
			$wp_plugin_options = get_option( 'sib_home_option' );
			if ( isset( $wp_plugin_options ) && is_array( $wp_plugin_options ) ) {
				if ( isset( $wp_plugin_options['activate_ma'] ) ) {
					if ( 'yes' == $wp_plugin_options['activate_ma'] ) {
						return true; }
				}
			}
			return false;
		}
		/**
		 * Add marketing automation event listeners and submiting JS fns
		 */
		public function install_ma_event_listeners() {
			$code = <<<'EOD'
            /** Sendinblue Marketing automation WooCommerce integration events: start */
                var tracking_event_type = '';
                var tracking_event_data = '';
                var ws_disable_next_event_cart_deleted = 0;

                function ws_ma_submit_event(submit_tracking_event_type, submit_tracking_event_data) {
                    sendinblue.track(submit_tracking_event_type, {}, JSON.parse(submit_tracking_event_data));
                }
                
                jQuery(function($) { 
                    $(document.body) 
                        .on("added_to_cart", function (event, fragments, hash, button) { 
                            ws_ma_submit_event(fragments.tracking_event_type, fragments.tracking_event_data);
                        })
                        .on("updated_checkout", function (event, args) { 
                            $( document.body ).trigger( 'wc_fragment_refresh' );
                        })
                        .on("removed_from_cart", function (event, fragments, hash, button) { 
                            ws_ma_submit_event(fragments.tracking_event_type, fragments.tracking_event_data);
                        });
                });
                jQuery(function($) { 
                    $(document.body).on("wc_fragments_refreshed", function () {
                        if (1 == ws_disable_next_event_cart_deleted) {
                            // cart is deleted because order is completed
                            ws_disable_next_event_cart_deleted = 0;
                        } else {
                            tracking_event_type = $("#ws_ma_event_type").attr("data-ws_ma_event_type_data");
                            tracking_event_data = $("#ws_ma_event_data").attr("data-ws_ma_event_data_data");
                            ws_ma_submit_event(tracking_event_type, tracking_event_data);
                        }
                    });
                });
            /** Sendinblue Marketing automation WooCommerce integration events: end */
EOD;
			$this->wc_enqueue_js_internal( $code );
		}

		/**
		 * Cart fragments helping fn
		 */
		public function ws_cart_custom_fragment_load() {
			echo "<input id='ws_ma_event_type' type='hidden' style='display: none' />";
			echo "<input id='ws_ma_event_data' type='hidden' style='display: none' />";
		}
		/**
		 * Cart fragments helping fn
		 */
		public function ws_cart_custom_fragment( $cart_fragments ) {
			$data     = new stdClass();
			$data->id = $this->get_wc_cart_id();

			// If cart is empty.
			if ( empty( WC()->cart->cart_contents ) && ! empty( WC()->cart->removed_cart_contents ) ) {
				$tracking_event_type = 'cart_deleted';
			} elseif ( ! empty( WC()->cart->cart_contents ) ) { // If cart is not empty.
				$tracking_event_type = 'cart_updated';
				$data->data          = $this->get_tracking_data_cart();
			} else {
				return $cart_fragments;
			}
			$tracking_event_data = json_encode( $data, JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_SLASHES );
			$tracking_event_data = str_replace( "\u0022", "\u2033", $tracking_event_data );

			// For events without fragments in parmeters - fragments transfered over html.
			$cart_fragments['#ws_ma_event_type'] = "<input id='ws_ma_event_type' type='hidden' style='display: none' data-ws_ma_event_type_data='" . $tracking_event_type . "' />";
			$cart_fragments['#ws_ma_event_data'] = "<input id='ws_ma_event_data' type='hidden' style='display: none' data-ws_ma_event_data_data='" . $tracking_event_data . "' />";
			// For events with fragments in parmeters.
			$cart_fragments['tracking_event_type'] = $tracking_event_type;
			$cart_fragments['tracking_event_data'] = $tracking_event_data;
			return $cart_fragments;
		}
		/**
		 * Event checkout completed.
		 */
		public function ws_checkout_completed( $order_id ) {
			$email_id = $this->get_email_id();

			// Allow code execution only once.
			if ( ! get_post_meta( $order_id, '_thankyou_action_done', true ) ) {
				$order = wc_get_order( $order_id );

				// Flag the action as done (to avoid repetitions on reload).
				$order->update_meta_data( '_thankyou_action_done', true, $order_id );
				$order->save();
				$tracking_event_type = 'order_completed';
				$data                = $this->get_tracking_data_order( $order_id );
			}

			// If this is a guest user (not logged in) or user is LoggedIn (LoggedIn as administrator) then here we can take his email from order data.
			if ( ! $this->is_user_logged_in() || $this->is_administrator() ) {
				$email_id = $data->data->billing_address->email;
			}

			if ( '' != $email_id ) {
				$this->set_email_id_cookie( $email_id ); }

			$tracking_event_data = json_encode( $data, JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_SLASHES );
			$tracking_event_data = str_replace( "\u0022", "\u2033", $tracking_event_data );

			$code = $this->build_event_code( 'track', $tracking_event_type, $email_id, $tracking_event_data, 'ws_disable_next_event_cart_deleted = 1;' );
			$this->wc_enqueue_js_internal( $code );
		}

		private function wc_enqueue_js_internal( $code ) {
			if ( empty( $code ) ) {
				return;
			}
			if ( function_exists( 'wp_add_inline_script' ) ) {
				$script = '(function ($) { ' . $code . ' }(jQuery))';
				wp_add_inline_script( 'woocommerce', $script, 'after' );
			} else {
				wc_enqueue_js( $code );
			}
		}

		/**
		 * Get specific data from cart formated according to trackin needs
		 */
		public function get_tracking_data_cart() {
			$data                   = new stdClass();
			$cartitems              = WC()->cart->get_cart();
			$totals                 = WC()->cart->get_totals();
			$data->affiliation      = get_bloginfo( 'name' );
			$data->subtotal         = ! empty( $totals['subtotal'] ) ? $totals['subtotal'] : 0;
			$data->discount         = ! empty( $totals['discount_total'] ) ? $totals['discount_total'] : 0;
			$data->shipping         = ! empty( $totals['shipping_total'] ) ? $totals['shipping_total'] : 0;
			$data->total_before_tax = ! empty( $totals['cart_contents_total'] ) ? $totals['cart_contents_total'] : 0;
			$data->tax              = ! empty( $totals['total_tax'] ) ? $totals['total_tax'] : 0;
			$data->total            = ! empty( $totals['total'] ) ? $totals['total'] : 0;
			$data->currency         = get_woocommerce_currency();
			$data->url              = wc_get_cart_url();
			$data->checkouturl      = wc_get_checkout_url();

			$data->items = array();
			foreach ( $cartitems as $key => $cartitem ) {
				// $cartitem => array, $cartitem['data'] => class [ (WC_Product_Simple extends WC_Product) OR (WC_Product_Variation extends WC_Product_Simple)].
				$item                      = new stdClass();
				$item->name                = $cartitem['data']->get_title();
				$item->sku                 = $cartitem['data']->get_sku();
				$item->id                  = ! empty( $cartitem['product_id'] ) ? $cartitem['product_id'] : '';
				$cats_array                = wp_get_post_terms( $item->id, 'product_cat', array( 'fields' => 'names' ) );
				$item->category            = implode( ',', $cats_array );
				$item->variant_id          = ! empty( $cartitem['variation_id'] ) ? $cartitem['variation_id'] : '';
				$variation                 = new WC_Product_Variation( $item->variant_id );
				$cartitem['variation_sku'] = $variation->get_sku();
				$item->variant_sku         = ! empty( $cartitem['variation_sku'] ) ? $cartitem['variation_sku'] : '';
				$item->variant_name        = implode( ',', $cartitem['variation'] );
				$item->quantity            = ! empty( $cartitem['quantity'] ) ? $cartitem['quantity'] : 0;
				$unit_price                = $cartitem['data']->is_on_sale() ? $cartitem['data']->get_sale_price() : $cartitem['data']->get_regular_price();
				$item->price               = round( (float) $unit_price * (float) $item->quantity, 2 );
				$image_full                = $cartitem['data']->get_image( 'woocommerce_single' );
				$dom                       = new DOMDocument();
				@$dom->loadHTML( $image_full );
				$nodelist = $dom->getElementsByTagName( 'img' );
				foreach ( $nodelist as $node ) {
					$item->image = $node->getAttribute( 'src' ); }
				$item->url = $cartitem['data']->get_permalink();
				array_push( $data->items, $item );
			}
			return $data;
		}
		/**
		 * Get specific data from order formated according to tracking needs
		 */
		public function get_tracking_data_order( $order_id ) {
			$order = wc_get_order( $order_id );

			$data     = new stdClass();
			$data->id = $this->get_wc_cart_id();

			$data->data                   = new stdClass();
			$data->data->id               = $order->get_order_number();
			$data->data->affiliation      = get_bloginfo( 'name' ); // site name is ok?
			$data->data->date             = $order->get_date_created()->date( DATE_ATOM );
			$data->data->subtotal         = (float) $order->get_subtotal();
			$data->data->discount         = $order->get_total_discount();
			$data->data->shipping         = (float) $order->get_shipping_total();
			$data->data->total_before_tax = (float) ( $data->data->subtotal - $data->data->discount );
			$data->data->tax              = (float) $order->get_total_tax();
			$data->data->revenue          = (float) $order->get_total();
			$data->data->currency         = $order->get_currency();
			$data->data->url              = $order->get_checkout_order_received_url();

			$data->data->shipping_address            = new stdClass();
			$data->data->shipping_address->firstname = $order->get_shipping_first_name();
			$data->data->shipping_address->lastname  = $order->get_shipping_last_name();
			$data->data->shipping_address->company   = $order->get_shipping_company();
			$data->data->shipping_address->phone     = ''; // does not exist, so just empty value
			$data->data->shipping_address->address1  = $order->get_shipping_address_1();
			$data->data->shipping_address->address2  = $order->get_shipping_address_2();
			$data->data->shipping_address->city      = $order->get_shipping_city();
			$data->data->shipping_address->country   = $order->get_shipping_country();
			$data->data->shipping_address->state     = $order->get_shipping_state();
			$data->data->shipping_address->zipcode   = $order->get_shipping_postcode();

			$data->data->billing_address            = new stdClass();
			$data->data->billing_address->firstname = $order->get_billing_first_name();
			$data->data->billing_address->lastname  = $order->get_billing_last_name();
			$data->data->billing_address->company   = $order->get_billing_company();
			$data->data->billing_address->phone     = $order->get_billing_phone();
			$data->data->billing_address->address1  = $order->get_billing_address_1();
			$data->data->billing_address->address2  = $order->get_billing_address_2();
			$data->data->billing_address->city      = $order->get_billing_city();
			$data->data->billing_address->country   = $order->get_billing_country();
			$data->data->billing_address->state     = $order->get_billing_state();
			$data->data->billing_address->zipcode   = $order->get_billing_postcode();
			$data->data->billing_address->email     = $order->get_billing_email();

			$data->data->items = array();
			foreach ( $order->get_items() as $item_key => $orderitem ) {
				// $orderitem => class WC_Order_Item_Product extends WC_Order_Item.
				$product = wc_get_product( $orderitem->get_product_id() ); // class WC_Product

				$item       = new stdClass();
				$item->name = $orderitem->get_name(); // woo automatically add variants.
				$item->sku  = $product->get_sku();
				// $item->id = $orderitem->get_id(); //  id of the item in order.
				$item->id           = $orderitem->get_product_id();
				$cats_array         = wp_get_post_terms( $item->id, 'product_cat', array( 'fields' => 'names' ) );
				$item->category     = implode( ',', $cats_array );
				$item->variant_id   = $orderitem->get_variation_id();
				$variation          = new WC_Product_Variation( $item->variant_id );
				$item->variant_sku  = $variation->get_sku();
				$attributes         = $variation->get_attributes();
				$item->variant_name = implode( ',', $attributes );
				$item->price        = round( $orderitem->get_total(), 2 );
				$item->quantity     = (int) $orderitem->get_quantity();
				$image_full         = $product->get_image( 'woocommerce_single' );
				$dom                = new DOMDocument();
				@$dom->loadHTML( $image_full );
				$nodelist = $dom->getElementsByTagName( 'img' );
				foreach ( $nodelist as $node ) {
					$item->image = $node->getAttribute( 'src' ); }
				$item->url = $product->get_permalink();
				array_push( $data->data->items, $item );
			}
			return $data;
		}
		/**
		 * Helper fn to bulid tracking event code
		 */
		public function build_event_code( $method, $event, $email_id, $data, $prefix = '' ) {
			return sprintf( $prefix . 'sendinblue.%s("%s", JSON.parse(\'{"email":"' . $email_id . '"}\'), JSON.parse(\'%s\'));', $method, $event, $data );
		}
		/**
		 * Actions performed after user login.
		 */
		public function wp_login_action( $username, $obj_WP_User ) {
			if ( ! empty( trim( $obj_WP_User->data->user_email ) ) && ! $this->is_administrator( $obj_WP_User ) ) {
				$this->set_email_id_cookie( trim( $obj_WP_User->data->user_email ) );
			}
		}
		/**
		 * Helper fn for seeting email_id cookie.
		 */
		public function set_email_id_cookie( $email = '' ) {
			if ( '' == $email ) { // Try to find from logged user.
				$current_user = wp_get_current_user();
				if ( 0 != $current_user->ID && ! $this->is_administrator( $current_user ) ) {
					$email = $current_user->user_email;
				}
			}
			if ( '' != $email ) {
				setcookie( 'email_id', $_COOKIE['email_id'] = $email, time() + 86400, '/' ); }
		}
		/**
		 * Helper fn to get email_id from currently logged user or cookie
		 */
		public function get_email_id() {
			$found_email_id = '';
			$current_user   = wp_get_current_user();
			if ( $this->is_administrator( $current_user ) ) {
				// Try to find in cookies and also cookie email_id should not be equal to admin email.
				if ( isset( $_COOKIE['email_id'] ) && '' != $_COOKIE['email_id'] && $current_user->user_email != $_COOKIE['email_id'] ) {
					$found_email_id = ! empty( $_COOKIE['email_id'] ) ? esc_attr( sanitize_text_field( $_COOKIE['email_id'] ) ) : '';
				}
			} elseif ( 0 == $current_user->ID ) {
				// Try to find in cookies.
				if ( isset( $_COOKIE['email_id'] ) && '' != $_COOKIE['email_id'] ) {
					$found_email_id = ! empty( $_COOKIE['email_id'] ) ? esc_attr( sanitize_text_field( $_COOKIE['email_id'] ) ) : '';
				}
			} else {
				$found_email_id = $current_user->user_email;
			}
			return $found_email_id;
		}
		/**
		 * Helper dn to get Woocommerce cart id from wc session cookie
		 */
		public function get_wc_cart_id() {
			$cookie_id = 'wp_woocommerce_session_';
			$cart_id   = '';
			foreach ( $_COOKIE as $key => $val ) {
				if ( false !== strpos( $key, $cookie_id ) ) {
					$cart_id = $key; }
			}
			return $cart_id;
		}

		// Marketing automation fns end.

		// Check if logged in user is administrator.
		public function is_administrator( $wp_user = null ) {
			if ( ! $wp_user ) {
				$wp_user = wp_get_current_user();
			}
			return ! empty( $wp_user->roles ) && in_array( 'administrator', $wp_user->roles );
		}
		 // Check if any user is logged in.
		public function is_user_logged_in() {
			$current_user = wp_get_current_user();
			return $current_user->ID;
		}
	}

	/**
	 * The WC_Sendinblue_Integration global object
	 *
	 * @name $WC_Sendinblue_Integration
	 * @global WC_Sendinblue_Integration $GLOBALS ['WC_Sendinblue_Integration']
	 */
	$GLOBALS['WC_Sendinblue_Integration'] = new WC_Sendinblue_Integration();
}
