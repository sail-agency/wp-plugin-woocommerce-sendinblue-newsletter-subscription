<?php
/**
 *
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Settings
 *
 * Adds UX for adding/modifying customizations
 *
 * @since 2.0.0
 */
class WC_Sendinblue_Settings extends WC_Settings_Page {

    const TABS_WITHOUT_SAVE_BUTTON = ['', 'chat', 'statistics'];
    public static $wc_emails;

    public static $wc_emails_enabled;

    public $customizations;
    
    public $ma_status = 'disabled';
    
    /**
     * Add various admin hooks/filters
     *
     * @since 2.0.0
     */
    public function __construct() {

        $this->id    = 'sendinblue';
        $this->label = __( 'Sendinblue', 'wc_sendinblue' );

        // useable email list of WC : for now, 9 emails (New Order,Cancelled_Order,Processing_Order,Completed_Order,Refunded_Order,New_Account, On Hold Order, Failed Order, Customer Note)
        self::$wc_emails_enabled = array(
            'WC_Email_New_Order' => 'new_order',
            'WC_Email_Cancelled_Order' => 'cancelled_order',
            'WC_Email_Customer_Processing_Order' => 'customer_processing_order',
            'WC_Email_Customer_Completed_Order' => 'customer_completed_order',
            'WC_Email_Customer_Refunded_Order' => 'customer_refunded_order',
            'WC_Email_Customer_New_Account' => 'customer_new_account',
            'WC_Email_Customer_Note' => 'customer_note',
            'WC_Email_Customer_On_Hold_Order'  => 'customer_on_hold_order',
            'WC_Email_Failed_Order'  => 'failed_order'
        );
        update_option('wc_emails_enabled', self::$wc_emails_enabled);

        // add tab
        add_filter( 'woocommerce_settings_tabs_array', array( $this, 'add_settings_page' ), 20 );

        // show sections
        add_action( 'woocommerce_sections_' . $this->id, array( $this, 'output_sections' ) );

        // show settings
        add_action( 'woocommerce_settings_' . $this->id, array( $this, 'output' ) );

        // save settings
        add_action( 'woocommerce_settings_save_' . $this->id, array( $this, 'save' ), 1);

        $this->customizations = get_option( 'wc_sendinblue_settings', array() );

        // Custom action for statistics section
        add_action('woocommerce_settings_ws_statistics_after',  array($this, 'ws_statistics'));
        // Custom action for ws match attributes section
        add_action('woocommerce_admin_field_match', array($this, 'ws_match_attributes'));
        // Custom action for sms options section
        add_action('woocommerce_settings_ws_sms_notification_end',  array($this, 'ws_sms_notification'));
        add_action('woocommerce_settings_ws_sms_admin_notification_after',  array($this, 'ws_sms_admin_notification'));
        // Custom action for email section
        add_action('woocommerce_settings_ws_notification_activation_after',  array($this, 'ws_notification_activation'));
        add_action('woocommerce_settings_ws_sendinblue_templates_after',  array($this, 'ws_sendinblue_templates'));
        // Custom action for campaign section
        //add_action('woocommerce_settings_ws_email_campaign_follow_contacts_end',  array($this, 'ws_email_campaign_follow_contacts'));
        //add_action('woocommerce_settings_ws_email_campaign_send_after',  array($this, 'ws_email_campaign_send'));
        add_action('woocommerce_settings_ws_sms_campaign_send_end',  array($this, 'ws_sms_campaign_send'));
    
        // Custom action for marketing automation section // BG20190425
        add_action('woocommerce_settings_ws_marketingauto_enable_note_after',  array($this, 'ws_marketingauto_enable_note'));

    }

    /**
     * Get sections
     *
     * @return array
     */
    public function get_sections() {
        if ( isset(WC_Sendinblue::$access_key) && '' != WC_Sendinblue::$access_key ) {
            return array(
                '' => __('General', 'wc_sendinblue'),
                'subscribe' => __('Subscription Options', 'wc_sendinblue'),
                'email_options' => __('Email Options', 'wc_sendinblue'),
                'sms_options' => __('SMS Options', 'wc_sendinblue'),
                'campaigns' => __('SMS Campaign', 'wc_sendinblue'),
                'marketingauto' => __('Automation', 'wc_sendinblue'), // BG20190425 BG20190819
                'statistics' => __('Statistics', 'wc_sendinblue'),
                'chat' => __('Chat', 'wc_sendinblue'), // BG20190819,
            );
        }else{
            return null;
        }
    }

    /*
     * new_order, cancelled_order, customer_processing_order, customer_completed_order, customer_refunded_order

       customer_invoice, customer_note, customer_reset_password, customer_new_account
     * */

    /**
     * Render the settings for the current section
     *
     * @since 2.0.0
     */
    public function output() {
        $general_settings = get_option('ws_main_option');
        $access_key = isset($general_settings['access_key']) ? $general_settings['access_key'] : '';

        $settings = $this->get_settings();

        // inject the actual setting value before outputting the fields
        // ::output_fields() uses get_option() but customizations are stored
        // in a single option so this dynamically returns the correct value
        if (isset($_GET['section']) && 'email_options' == $_GET['section']) {
            // email options
            $wc_emails = (array)WC_Emails::instance()->emails;

            foreach(self::$wc_emails_enabled as $filed=>$id) {
                $email_enabled = (array)($wc_emails[$filed]);
                if ( 'no' == $email_enabled['enabled'] ) {
                    add_filter("pre_option_{$filed}", function () {  return 'no';  });
                } else {
                    add_filter("pre_option_{$filed}", function () {  return 'yes'; });
                }
            }
        }
        if (isset($_GET['section']) && 'sms_options' == $_GET['section']) {

        }

        foreach ( $this->customizations as $filter => $value ) {
            add_filter("pre_option_{$filter}", array($this, 'get_customization'));
        }

        WC_Admin_Settings::output_fields( $settings );

        /* user sync */
        // add user sync script
        ?>
        <!-- start Users Sync popup -->
        <?php add_thickbox(); ?>
        <?php if(!empty($access_key)) { ?> 
            <div id="ws-sib-sync-users" style="display:none;">
                <div id="ws-sib-sync-form">
                    <div id="ws-sync-failure" class="ws-sib_alert alert alert-danger" style="margin-bottom: 0; margin-top: 10px; display: none;"><p></p></div>

                    <div class="ws-row " style="">
                        <b><p><?php echo __('Sync Lists', 'wc_sendinblue');?></p></b>
                        <div class="ws-md-5" style="padding-left: 20px;">
                            <p style="margin-bottom: 0;"><?php echo __('Choose the Sendinblue lists in which you want to add your existing users:', 'wc_sendinblue'); ?></p>
                        </div>
                        <?php
                        $lists = WC_Sendinblue_API::get_list();

                        ?>
                        <div class="ws-md-5 ws-sync-list">
                            <select id="ws_sib_select_list" data-placeholder="Please select the list" class="" name="list_id" multiple="true">
                                <?php foreach ($lists as $id=>$list): ?>
                                    <option type="checkbox" value="<?php echo $id ?>" ><?php echo $list ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <?php

                    $admin_profile = new WC_Admin_Profile();
                    $customer_fileds =$admin_profile->get_customer_meta_fields();
                    // available sendinblue attributes
                    $allAttrs = WC_Sendinblue_API::get_attributes();
                    $attrs = $allAttrs['attributes']['normal_attributes'];
                    ?>
                    <div class="clear"></div>
                    <hr style="margin-top: 25px;">

                    <div class="ws-row" style="float:left;width:100%;">
                        <b><p><?php echo __('Match Attributes', 'wc_sendinblue');?></p></b>
                        <div class="ws-sync-attr-field ws-md-11" style="border-bottom: dotted 1px #dedede;">
                            <div class="ws-md-6">
                                <p><?php _e('Woocommerce Customers Attributes', 'wc_sendinblue'); ?></p>
                            </div>
                            <div class="ws-md-6">
                                <p><?php _e('Sendinblue Contact Attributes', 'wc_sendinblue'); ?></p>
                            </div>
                        </div>

                        <div class="ws-sync-attr-line">
                            <div class="ws-sync-attr ws-md-11" style="padding-top: 5px;border-bottom: dotted 1px #dedede;">
                                <div class="ws-md-5">
                                    <select class="ws-sync-wp-attr" name="" style="width: 100%;">
                                        <?php foreach ($customer_fileds['billing']['fields'] as $id=>$label): ?>
                                            <option value="<?php echo $id; ?>"><?php echo $label['label']; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="ws-md-1"><span class="dashicons dashicons-leftright"></span></div>
                                <div class="ws-md-5">
                                    <select class="ws-sync-sib-attr" name="" style="width: 100%;">
                                        <?php foreach ($attrs as $attr): ?>
                                            <option value="<?php echo $attr['name']; ?>"><?php echo $attr['name']; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="ws-md-1" style="padding-top: 3px;">
                                    <a href="javascript:void(0)" class="ws-sync-attr-dismiss"><span class="dashicons dashicons-dismiss"></span></a>
                                </div>
                                <input type="hidden" class="ws-sync-match" name="<?php echo $attrs[0]['name']?>" value="NAME">
                            </div>
                        </div>
                        <div class="ws-md-1" style="padding-top: 8px; width: 5%;">
                            <a href="javascript:void(0)" class="ws-sync-attr-plus"><span class="dashicons dashicons-plus-alt "></span></a>
                        </div>
                    </div>

                    <div style="position: absolute;text-align: right;bottom: 10px;right: 10px;">
                        <img id="ws_loading_sync_gif" src="<?php echo WC()->plugin_url().'/assets/images/select2-spinner.gif';?>" style="margin-right: 12px;vertical-align: middle;display: none;">
                        <button type="submit" id="ws_sync_users_btn" class="btn button-primary" style="min-width: 120px;"><span class="ws-sib-spin"><i class="fa fa-circle-o-notch fa-spin fa-lg"></i>&nbsp;&nbsp;</span><?php echo __('Apply','wc_sendinblue'); ?></button>
                    </div>
                </div>
            </div>
        <!-- end Users Sync popup -->
        <?php } ?>
        <?php

    }


    /**
     * Return the customization value for the given filter
     *
     * @since 2.0.0
     * @return string
     */
    public function get_customization() {

        $filter = str_replace( 'pre_option_', '', current_filter() );

        return isset($this->customizations[$filter]) ? $this->customizations[$filter] : '';


    }


    /**
     * Save the customizations
     *
     * @since 2.0.0
     */
    public function save() {

        $wc_plugin_id = 'woocommerce_';

        // Email options
        if ( 'email_options' == $_GET['section'] ) {
            $notification_activation = array();
            foreach (self::$wc_emails_enabled as $filed => $id) {

                $email_settings = get_option($wc_plugin_id . $id . '_settings', null);
                $email_settings['enabled'] = 'no';
                if (!empty($_POST[$filed])) {
                    $email_settings['enabled'] = 'yes';
                    array_push($notification_activation, str_replace('_', ' ', str_replace('Customer_', '', str_replace('WC_Email_', '', $filed))));
                }
                update_option($wc_plugin_id . $id . '_settings', $email_settings);
                //
                update_option('ws_notification_activation',$notification_activation);
            }

        }

        //////////// Default options ////////////
        foreach ( $this->get_settings() as $field ) {

            // skip titles, etc
            if ( ! isset( $field['id'] ) ) {
                continue;
            }

            if ( ! empty( $_POST[ $field['id'] ] ) ) {

                // active notification of sms credits
                if ( 'ws_sms_credits_notify' == $field['id'] && !isset($this->customizations[ $field['id'] ])){
                    // Create a schedule to send notify email for SMS credits limit
                    //if(isset($this->customizations['ws_sms_credits_notify']) && $this->customizations['ws_sms_credits_notify'] == 'no')
                    //wp_schedule_event(time() + 120, 'hourly', 'ws_sms_alert_event');
                    //wp_schedule_event(time(), 'daily', 'ws_sms_alert_event');
                    wp_schedule_event(time() + 10 , 'weekly', 'ws_sms_alert_event');

                }

                $this->customizations[ $field['id'] ] = wp_kses_post( stripslashes( $_POST[ $field['id'] ] ) );

                if ( 'checkbox' == $field['type'] || 'fake_checkbox' == $field['type'] ) {

                    $this->customizations[ $field['id'] ] = 'yes';

                }

            } else {

                if ( 'checkbox' == $field['type'] || 'fake_checkbox' == $field['type'] ) {

                    $this->customizations[ $field['id'] ] = 'no';

                } elseif ( 'match' == $field['type'] ) {
                    if ( isset($_POST['ws_enable_match_attributes']) && '1' == $_POST['ws_enable_match_attributes'] )
                    {
                        $matched_lists = array();
                        $ws_lists_data = $_POST['ws_matched_lists'];
                        foreach($ws_lists_data as $list)
                        {
                            $list_info = explode('||', $list);
                            $matched_lists = array_merge($matched_lists, array($list_info[0] => $list_info[1]));
                        }

                        $this->customizations['ws_matched_lists'] = $matched_lists;
                    }
                    else
                    {
                        unset($this->customizations['ws_matched_lists']);
                    }

                } elseif ( isset( $this->customizations[ $field['id'] ] )) {

                    unset($this->customizations[$field['id']]);

                }
                // inactive notification of sms credits
                if ( 'ws_sms_credits_notify' == $field['id'] ){
                    wp_clear_scheduled_hook('ws_sms_alert_event');
                    unset($this->customizations[$field['id']]);
                }
            }
        }
        update_option( 'wc_sendinblue_settings', $this->customizations );

        WC_Sendinblue::get_wc_templates();
    }


    /**
     * Return admin fields in proper format for outputting / saving
     *
     * @since 1.1
     * @return array
     */
    public function get_settings() {

        $account_user_name = WC_Sendinblue::$account_info['user_name'];
        $account_email = WC_Sendinblue::$account_info['email'];
        $account_email_credits = WC_Sendinblue::$account_info['email_credits'];
        $credit_type = array(
            'payAsYouGo'=>'Pay As You Go',
            'UNLIMITED'=>'Unlimited Plan',
            'SPECIAL_PLAN'=>'Special Plan',
            'SMS'=>'SMS Plan',
            'sms'=>'SMS',
            'FREE'=>'Free Plan',
            'free'=>'Free Plan',
            'IP'=>'IP Plan',
            'CREDIT_REC'=>'Subscription',
            'subscription'=>'Subscription',
            'SUPPORT'=>'Support'
        );
        $account_plan = isset($account_email_credits['plan_type']) ? $credit_type[$account_email_credits['plan_type']] : '';

        $account_sms_credits = WC_Sendinblue::$account_info['SMS_credits'];
        
        $account_sms_plan = isset($account_sms_credits['plan_type']) ? $credit_type[$account_sms_credits['plan_type']] : '';

        $settings_init = array(
            'general' =>

                array(

                    array(
                        'title' => __( 'WooCommerce Sendinblue Integration', 'wc_sendinblue' ),
                        'type'  => 'title',
                        'desc'  => __('Activate your account with your API v3 key', 'wc_sendinblue'),
                    ),

                    array(
                        'id'       => 'ws_api_key',
                        'title'    => __( 'API access Key', 'wc_sendinblue' ),
                        'class'    => 'input-text regular-input',
                        'desc_tip' => '',
                        'type'     => 'text',
                        'desc'     => '<br><p><b>'.__('Have an account', 'wc_sendinblue').'? </b>'.sprintf(__('Get your Sendinblue API key from %s', 'wc_sendinblue'),
                                " <a target='_blank' href='https://my.sendinblue.com/advanced/apikey/?utm_source=woocommerce_addon&utm_medium=plugin&utm_campaign=module_link'>".__('here.','wc_sendinblue')."</a> ").sprintf(__('Do not have a Sendinblue account? %s', 'wc_sendinblue'),
                                "<a target='_blank' href='https://app.sendinblue.com/account/register?utm_source=woocommerce_addon&utm_medium=plugin&utm_campaign=module_link'>".__('Sign up','wc_sendinblue')."</a>").'</p>',
                    ),

                    array( 'type' => 'sectionend' ),

                ),

        );
        //Users Sync part
        $users = count_users();
        if (!empty($users['avail_roles']['customer'])) {
            $currentUsers = $users['avail_roles']['customer'];
        }
        else {
            $currentUsers = 0;
        }

        $isSynced = get_option('ws_sync_users', '0');
        $disabled = '';
        if ( 0 == $currentUsers ){
            $desc = __('You have 0 existing customers.','wc_sendinblue');
            $disabled = 'disabled';
        }
        elseif ($isSynced != $currentUsers) {
            $desc = sprintf(__('You have %s existing customers. Do you want to add them to Sendinblue?', 'wc_sendinblue'), $currentUsers );
        }
        else{
            $desc = __('All your customers have been added to a Sendinblue list.','wc_sendinblue');
            $disabled = 'disabled';
        }
        $settings = array(
            'general' =>

                array(

                    array(
                        'title' => __( 'WooCommerce Sendinblue Integration', 'wc_sendinblue' ),
                        'type'  => 'title',
                        'desc'  => '
                        <div  style="/*background-color: #fff;border: 1px solid #ddd;padding: 12px 30px;*/">
                            <h4>'.__('You are currently logged in as:','wc_sendinblue').'</h4>
                            <div id="ws_info_wrap" style="padding-left: 24px;height:140px;">
                            <div style="float:left;width:440px;margin-top: -16px;border-right-style: dotted;border-right-width: thin;">
                            <p style="font-size: 14px;font-weight: 600;color: #0073aa;">'.$account_user_name.' -- '.$account_email.'</p>
                            <p style="font-size: 14px;font-weight: 600;color: #0073aa;">'.$account_plan.' -- '.$account_email_credits['credits'].' credits</p>
                            <p style="font-size: 14px;font-weight: 600;color: #0073aa;">'.$account_sms_plan.' -- '.$account_sms_credits['credits'].' credits</p><i>'.
                            sprintf(__('To buy more credits, please click %s.', 'wc_sendinblue').'</i>',
                                "<a target='_blank' href='https://www.sendinblue.com/pricing?utm_source=wordpress_plugin&utm_medium=plugin&utm_campaign=module_link' class='ws_refresh'>".__('here','wc_sendinblue')."</a>")
                            .'</div>
                            <div style="float:left;padding-left:24px;margin-top: -16px;">
                            <b><p>'.__('Customers Synchronisation','wc_sendinblue').'</p></b>
                            <p>'.$desc.'</p>
                            <a id="ws-sib-sync-btn" class="thickbox button-primary '.$disabled.'" name="'.__('Customers Synchronisation', 'wc_sendinblue').'" href="#TB_inline?width=600&height=300&inlineId=ws-sib-sync-users">'.__('Sync my users', 'wc_sendinblue').'</a>
                            </div>
                            </div></div>',
                    )
                ),
            'subscribe' =>

                array(

                    array(
                        'title' => __( 'Sendinblue Newsletter Subscription Options', 'wc_sendinblue' ),
                        'type'  => 'title',
                    ),

                    array(
                        'id'       => 'ws_subscription_enabled',
                        'title'    => __( 'Enable/Disable', 'wc_sendinblue' ),
                        'desc'     => __( 'Enable Subscription', 'wc_sendinblue' ),
                        'desc_tip' => __( 'If enabled, all customers will be added to a list after subscription event occurs.', 'wc_sendinblue' ),
                        'default'  => 'yes',
                        'type'     => 'checkbox',
                    ),

                    array(
                        'id'       => 'ws_order_event',
                        'title'    => __( 'Subscribe Event', 'wc_sendinblue' ),
                        'desc_tip' => '',
                        'desc' => '',
                        'css'      => 'min-width:300px;',
                        'default'  => 'on-hold',
                        'type'     => 'select',
                        'options'  => array(
                            'on-hold'  => __( 'Order Created', 'wc_sendinblue' ),
                            /*'processing'   => __( 'Order Processing', 'wc_sendinblue' ),*/
                            'completed' => __( 'Order Completed', 'wc_sendinblue' ),
                        ),

                    ),

                    array(
                        'id'       => 'ws_sendinblue_list',
                        'title'    => __( 'Lists', 'wc_sendinblue' ),
                        'desc_tip' => '',
                        'css'      => 'min-width:300px;',
                        'type'     => 'select',
                        'default'  => '',
                        'options'     => WC_Sendinblue::$lists,
                        'desc'     => '<p>'.__('All customers will be added to this list.', 'wc_sendinblue').'</p>'
                    ),
                    array(
                        'id'       => 'ws_enable_match_attributes',
                        'title'    => __('Match Attributes', 'wc_sendinblue'),
                        'desc'     => __( 'Enable Match Attributes', 'wc_sendinblue' ),
                        'desc_tip' => __( 'If enabled, customers attributes will be matched with sendinblue lists attributes.', 'wc_sendinblue' ),
                        'type'     => 'checkbox',
                        'default'  => 'no',
                    ),
                    array(
                        'id'       => 'ws_match_attributes',
                        'type'     => 'match',
                    ),

                    array(
                        'id'       => 'ws_dopt_enabled',
                        'title'    => __( 'Double Opt-In', 'wc_sendinblue' ),
                        'desc'     => __( 'Enable Double Opt-In', 'wc_sendinblue' ),
                        'desc_tip' => __( 'If enabled, customers will receive an email prompting them to confirm their subscription to the list above.', 'wc_sendinblue' ),
                        'type'     => 'checkbox',
                        'default'  => 'no',
                    ),
                    array(
                        'id'       => 'ws_dopt_templates',
                        'title'    => __( 'Double Opt-In Template', 'wc_sendinblue' ),
                        'desc' => '<p>'.__( 'To view or edit the template, login to your Sendinblue online dashboard and go to Campaigns > SMTP Templates.', 'wc_sendinblue' ).'</p>',
                        'css'      => 'min-width:300px;',
                        'type'     => 'select',
                        'default'  => '0',
                        'options'     => WC_Sendinblue::$dopt_templates,
                        'desc_tip'     => ''
                    ),
                    array(
                        'id'       => 'ws_opt_field',
                        'title'    => __( 'Display Opt-In Field', 'wc_sendinblue' ),
                        'desc' => __( 'Display an Opt-In Field at Checkout', 'wc_sendinblue' ),
                        'type'     => 'checkbox',
                        'default'  => 'no',
                        'desc_tip' => __( 'If enabled, customers will be presented with an "Opt-in" checkbox during checkout and will only be added to the list above if they opt-in.', 'wc_sendinblue' ),
                    ),
                    array(
                        'type' => 'sectionend',
                        /*'id' => 'ws_emails_custom'*/
                    ),
                    array(
                        'title' => '',
                        'type'  => 'title',
                    ),

                    array(
                        'id'       => 'ws_opt_field_label',
                        'title'    => __( 'Opt-In Field Label', 'wc_sendinblue' ),
                        'desc'    => '<p>'.__( 'Optional: customize the label displayed next to the opt-in checkbox.', 'wc_sendinblue' ).'</p>',
                        'css'      => 'min-width:300px;',
                        'default'  => 'Add me to the newsletter',
                        'type'     => 'text',
                    ),

                    array(
                        'id'       => 'ws_opt_default_status',
                        'title'    => __( 'Opt-In Checkbox Default Status', 'wc_sendinblue' ),
                        'desc'    => '<p>'.__( 'The default state for the opt-in checkbox.', 'wc_sendinblue' ).'</p>',
                        'css'      => 'min-width:300px;',
                        'default'  => 'Checked',
                        'type'     => 'select',
                        'options'  => array(
                            'checked'  => __( 'Checked', 'wc_sendinblue' ),
                            'unchecked'   => __( 'Unchecked', 'wc_sendinblue' ),
                        ),
                    ),
                    array(
                        'id'       => 'ws_opt_checkbox_location',
                        'title'    => __( 'Opt-In Checkbox Display Location', 'wc_sendinblue' ),
                        'desc'    => '<p>'.__( 'Where to display the opt-in checkbox on checkout page (under Billing info , Order info or Terms and Conditions).', 'wc_sendinblue' ).'</p>
                                      <p>'.__('To display the Opt-in checkbox under the "Terms & conditions" checkbox, you should set the "Terms & Conditions" parameter in the WooCommerce checkout settings.').'</p>',
                        'css'      => 'min-width:300px;',
                        'default'  => 'Billing',
                        'type'     => 'select',
                        'options'  => array(
                            'billing'  => __( 'Billing', 'wc_sendinblue' ),
                            'order'   => __( 'Order', 'wc_sendinblue' ),
                            'terms_condition' => __( 'Terms and Conditions' , 'wc_sendinblue')
                        ),
                    ),

                    array( 'type' => 'sectionend' ),

                ),
            'email_options' =>

                array(

                    array(
                        'title' => __( 'Sendinblue Email Sending Options', 'wc_sendinblue' ),
                        'type'  => 'title',
                        'id' => 'ws_group'
                    ),
                    array(
                        'title'    => __( 'Enable/Disable', 'wc_sendinblue' ),
                        'id'       => 'ws_smtp_enable',
                        'default'  => 'yes',
                        'type'     => 'checkbox',
                        'desc' =>  'Enable Sendinblue to send WooCommerce emails',
                        'desc_tip' => 'Check this box if you want your automatic emails to be sent with Sendinblue SMTP (for improved deliverability & to track statistics).'
                    ),

                    array(
                        'type' => 'sectionend',
                        'id' => 'ws_notification_activation'
                    ),
                    array(
                        'title' => '',
                        'type'  => 'title',
                    ),

                    array(
                        'title'    => __( 'Templates', 'wc_sendinblue' ),
                        'id'       => 'ws_email_templates_enable',
                        'default'  => 'no',
                        'class'         => 'ws_sms_send',
                        'type'     => 'radio',
                        'options'  => array(
                            'no'  => __( 'WooCommerce', 'wc_sendinblue' ),
                            'yes' => __( 'Sendinblue', 'wc_sendinblue' )
                        ),
                        'desc_tip' =>  __('Choose Sendinblue if you want to replace default Woocommerce emails by custom emails saved in Sendinblue.', 'wc_sendinblue'),
                        'autoload' => false
                    ),

                    array(
                        'id' => 'ws_new_order_template',
                        'type' => 'fake_select',
                    ),
                    array(
                        'id' => 'ws_processing_order_template',
                        'type' => 'fake_select',
                    ),
                    array(
                        'id' => 'ws_refunded_order_template',
                        'type' => 'fake_select',
                    ),
                    array(
                        'id' => 'ws_cancelled_order_template',
                        'type' => 'fake_select',
                    ),
                    array(
                        'id' => 'ws_completed_order_template',
                        'type' => 'fake_select',
                    ),
                    array(
                        'id' => 'ws_new_account_template',
                        'type' => 'fake_select',
                    ),
                    array(
                        'id' => 'ws_failed_order_template'  ,
                        'type' => 'fake_select',
                    ),
                    array(
                        'id' => 'ws_on_hold_order_template',
                        'type' => 'fake_select',
                    ),
                    array(
                        'id' => 'ws_customer_note_template',
                        'type' => 'fake_select',
                    ),
                    array(
                        'type' => 'sectionend',
                        'id' => 'ws_sendinblue_templates'
                    ),

                ),

            'sms_options' =>

                array(

                    array(
                        'title' => __( 'Sendinblue SMS Options', 'wc_sendinblue' ),
                        'type'  => 'title',
                        'id' => 'ws_group'
                    ),
                    array(
                        'id'       => 'ws_sms_enable',
                        'title'    => __( 'Enable/Disable', 'wc_sendinblue' ),
                        'type'     => 'checkbox',
                        'desc'     => __( 'Enable Sendinblue to send confirmation SMS', 'wc_sendinblue' ),
                        'default'  => 'no',
                        'desc_tip' => __( 'Check this box if you want to send SMS notifications through Sendinblue.', 'wc_sendinblue' ),
                    ),
                    array(
                        'id' => 'ws_sms_notification',
                        'type' => 'sectionend'
                    ),
                    //
                    array(
                        'id' => 'ws_sms_send_after',
                        'type' => 'fake_checkbox',
                    ),
                    array(
                        'id' => 'ws_sms_send_shipment',
                        'type' => 'fake_checkbox',
                    ),
                    //
                    array(
                        'title' => __( 'Send SMS after order confirmation', 'wc_sendinblue' ),
                        'type'  => 'title',
                    ),
                    array(
                        'id'       => 'ws_sms_sender_after',
                        'class'         => 'input-text regular-input ws_sms_sender',
                        'title'    => __( 'Sender', 'wc_sendinblue' ),
                        'desc_tip' => __( 'This field allows you to customize the SMS sender. The number of characters is limited to 11 alphanumeric characters. You can\'t configure your Sender with a phone number.', 'wc_sendinblue' ),
                        'type'     => 'text',
                        'desc'     => __('Number of characters left:&nbsp;','wc_sendinblue'),
                        'custom_attributes'=> array('maxlength'=> '11', 'required'=>'true'),
                    ),
                    array(
                        'id'       => 'ws_sms_send_msg_desc_after',
                        'class' => 'wide-input ws_sms_send_msg_desc',
                        'title'    => __( 'Message', 'wc_sendinblue' ),
                        'desc_tip' => __( ' Create the content of your SMS with the limit of 160-character.Beyond 160 characters, it will be counted as a second SMS. Thus, if you write  SMS of 240 characters, it will be recorded using two SMS.', 'wc_sendinblue' ),
                        'type'     => 'textarea',
                        'desc' => '<i>'.__( 'Number of SMS used: % &nbsp;&nbsp;&nbsp;Number of characters left: % &nbsp;&nbsp;&nbsp;Attention: a line break is counted as a single character.', 'wc_sendinblue').'</i>',
                        'custom_attributes'=> array('required'=>'true'),
                    ),
                    array(
                        'id'       => '',
                        'class'       => 'ws_sms_send_test',
                        'title'    => __( 'Send a test SMS', 'wc_sendinblue' ),
                        'desc_tip' => __( 'The phone number should be entered without spaces and include the country code prefix. In this example: 0033663309741, 0033 is the prefix for France and 06 63 30 97 41 is the mobile number.', 'wc_sendinblue' ),
                        'type'     => 'text',
                        'desc' => '<br><p>'.__( 'Sending a test SMS will be deducted from your SMS credits.', 'wc_sendinblue' ).'</p>',
                    ),
                    array( 'type' => 'sectionend' ),

                    //// Send a SMS after order shipment
                    array(
                        'title' => __( 'Send a SMS after order shipment', 'wc_sendinblue' ),
                        'type'  => 'title'
                    ),
                    array(
                        'id'       => 'ws_sms_sender_shipment',
                        'class'         => 'input-text regular-input ws_sms_sender',
                        'title'    => __( 'Sender', 'wc_sendinblue' ),
                        'desc_tip' => __( 'This field allows you to customize the SMS sender. The number of characters is limited to 11 alphanumeric characters. You can\'t configure your Sender with a phone number.', 'wc_sendinblue' ),
                        'type'     => 'text',
                        'desc'     => __('Number of characters left:&nbsp;','wc_sendinblue'),
                        'custom_attributes'=> array('maxlength'=> '11','required'=>'true'),
                    ),
                    array(
                        'id'       => 'ws_sms_send_msg_desc_shipment',
                        'class' => 'wide-input ws_sms_send_msg_desc',
                        'title'    => __( 'Message', 'wc_sendinblue' ),
                        'desc_tip' => __( ' Create the content of your SMS with the limit of 160-character.Beyond 160 characters, it will be counted as a second SMS. Thus, if you write  SMS of 240 characters, it will be recorded using two SMS.', 'wc_sendinblue' ),
                        'type'     => 'textarea',
                        'desc' => '<i>'.__( 'Number of SMS used: % &nbsp;&nbsp;&nbsp;Number of characters left: % &nbsp;&nbsp;&nbsp;Attention: a line break is counted as a single character.', 'wc_sendinblue').'</i>',
                        'custom_attributes'=> array('required'=>'true'),
                    ),
                    array(
                        'id'       => '',
                        'class'       => 'ws_sms_send_test',
                        'title'    => __( 'Send a test SMS', 'wc_sendinblue' ),
                        'desc_tip' => __( 'The phone number should be entered without spaces and include the country code prefix. In this example: 0033663309741, 0033 is the prefix for France and 06 63 30 97 41 is the mobile number.', 'wc_sendinblue' ),
                        'type'     => 'text',
                        'desc' => '<br><p>'.__( 'Sending a test SMS will be deducted from your SMS credits.', 'wc_sendinblue' ).'</p>',
                    ),
                    array(
                        'id' => 'ws_sms_admin_notification',
                        'type' => 'sectionend'
                    ),

                    array(
                        'id' => 'ws_sms_credits_notify',
                        'type' => 'fake_checkbox',
                    ),
                    // Notification
                    array(
                        'title' => __( 'You want to be notified by e-mail when you do not have enough SMS credits?', 'wc_sendinblue' ),
                        'type'  => 'title'
                    ),
                    array(
                        'id'       => 'ws_sms_credits_notify_email',
                        'class'         => 'input-text regular-input ws_sms_credits',
                        'title'    => __( 'Email', 'wc_sendinblue' ),
                        'type'     => 'email',
                        'custom_attributes'=> array('required'=>'true'),
                    ),
                    array(
                        'id'       => 'ws_sms_credits_limit',
                        'class'         => 'input-text regular-input ws_sms_credits',
                        'title'    => __( 'Limit', 'wc_sendinblue' ),
                        'desc_tip' => __( 'Alert threshold for remaining credits', 'wc_sendinblue' ),
                        'type'     => 'number',
                        'custom_attributes'=> array('required'=>'true'),
                    ),
                    array( 'type' => 'sectionend' ),

                ),

            'campaigns' =>

                array(
                    ////  Send a SMS campaign

                    array(
                        'title' => __( 'SMS Campaign', 'wc_sendinblue' ),
                        'type'  => 'title',
                    ),
                    array(
                        'title'    => __( 'To', 'wc_sendinblue' ),
                        'id'       => 'ws_sms_send_to',
                        'default'  => 'single',
                        'class'         => 'ws_sms_send_to',
                        'type'     => 'radio',
                        'options'  => array(
                            'single'       => __( 'A single contact', 'wc_sendinblue' ),
                            'all' => __( 'All of my WordPress customers', 'wc_sendinblue' ),
                            'only'       => __( 'Only subscribed customers', 'wc_sendinblue' ),
                        ),
                        'desc_tip' =>  true,
                        'autoload' => false
                    ),

                    array( 'type' => 'sectionend' ),
                    array(
                        'type'     => 'title',
                        'desc' => '',
                    ),
                    array(
                        'id'       => 'ws_sms_single_campaign',
                        'class'         => 'input-text regular-input ws_sms_single',
                        'title'    => __( 'Contact\'s Phone Number', 'wc_sendinblue' ),
                        'desc_tip' => __( 'The phone number should be entered without spaces and include the country code prefix. In this example: 0033663309741, 0033 is the prefix for France and 06 63 30 97 41 is the mobile number.', 'wc_sendinblue' ),
                        'type'     => 'text',
                        'custom_attributes'=> array('maxlength'=> '17',/*'required'=>'true'*/),
                    ),
                    array(
                        'id'       => 'ws_sms_sender_campaign',
                        'class'         => 'input-text regular-input ws_sms_sender',
                        'title'    => __( 'Sender', 'wc_sendinblue' ),
                        'desc_tip' => __( 'Use this field to customize your SMS sender. This field is limited to 11 alphanumeric characters and cannot contain a phone number. ', 'wc_sendinblue' ),
                        'type'     => 'text',
                        'desc'     => __('Number of characters left:&nbsp;','wc_sendinblue'),
                        'custom_attributes'=> array('maxlength'=> '11',/*'required'=>'true'*/),
                    ),
                    array(
                        'id'       => 'ws_sms_campaign_message',
                        'class' => 'wide-input ws_sms_send_msg_desc',
                        'title'    => __( 'Message', 'wc_sendinblue' ),
                        'desc_tip' => __( 'Write your SMS content with a 160 character limit. Additional charatcters will be counted as an additional SMS. For example, if you write a SMS containing 240 characters, it will use two SMS credits and be received as two messages.', 'wc_sendinblue' ),
                        'type'     => 'textarea',
                        'desc' => '<i>'.__( 'Number of SMS used: % &nbsp;&nbsp;&nbsp;Number of characters left: % &nbsp;&nbsp;&nbsp;Attention: a line break is counted as a single character.', 'wc_sendinblue').'</i>',
                        'custom_attributes'=> array('required'=>'true'),
                    ),
                    array(
                        'id'       => '',
                        'class'       => 'ws_sms_send_test',
                        'title'    => __( 'Send a test SMS', 'wc_sendinblue' ),
                        'desc_tip' => __( 'The phone number should be entered without spaces and include the country code prefix. In this example: 0033663309741, 0033 is the prefix for France and 06 63 30 97 41 is the mobile number.', 'wc_sendinblue' ),
                        'type'     => 'text',
                        'desc' => '<br><p>'.__( 'Sending a test SMS will be deducted from your SMS credits.', 'wc_sendinblue' ).'</p>',
                    ),
                    array(
                        'id' => 'ws_sms_campaign_send',
                        'type' => 'sectionend'
                    ),
                ),

        );

        // BG20190425 // BG20190819
        $this->ma_status = WC_Sendinblue_API::get_ma_status();
        $maArray = array();
        if ( 'enabled' == $this->ma_status ) {
            $tracker_help_link = '<a href="https://developers.sendinblue.com/docs/gettings-started-with-sendinblue-tracker?utm_source=woocommerce_plugin&utm_medium=plugin&utm_campaign=module_link" target="_blank">' .__('here', 'wc_sendinblue') . '</a>';
            $maArray = array(
                'id' => 'ws_marketingauto_enable',
                'title' => __( 'Marketing Automation', 'wc_sendinblue' ),
                'type' => 'checkbox',
                'desc' => __( 'Enable Sendinblue Marketing automation', 'wc_sendinblue' ),
                'default' => 'no',
                'desc_tip' => __( 'Check this box to insert the Sendinblue Tracker javascript snippet to your website code so you can track your website activity with Sendinblue Automation. Learn more about the Sendinblue Tracker ', 'wc_sendinblue' ). $tracker_help_link . '.',            
            );
        } else {
            $GLOBALS['hide_save_button'] = true;
        }
        $settings['marketingauto'] = array(
            array(
                'title' => __( 'Marketing Automation & Abandoned Cart', 'wc_sendinblue' ),
                'type'  => 'title',
            ),
            $maArray,
            array( 
                'id'       => 'ws_marketingauto_enable_note',
                'type' => 'sectionend' 
            ),
        );

		// BG20190819
		$apps_page_link = '<a href="https://app.sendinblue.com/account/apps/?utm_source=woocommerce_plugin&utm_medium=plugin&utm_campaign=module_link" target="_blank">' . __('Apps page', 'wc_sendinblue' ) . '</a>';
		$sendinblue_plans_link = '<a href="https://help.sendinblue.com/hc/en-us/articles/360001062420-How-to-setup-your-chat-widget" target="_blank">' . __('Here', 'wc_sendinblue' ) . '</a>';
		$settings['chat'] = array(
            array(
                'title' => __( 'Chat', 'wc_sendinblue' ),
                'type'  => 'title',
                'desc'  =>
                    sprintf(__( 'Chat feature is available for all sendinblue users. You can enable the feature on the %s of your Sendinblue account.', 'wc_sendinblue' ), $apps_page_link)
                    . "<br /> "
                    . sprintf(__( '%s are more info on how to set up chat widget.', 'wc_sendinblue' ), $sendinblue_plans_link)
            )
        );

        $settings['statistics'] = array(
            array(
                'id'   => 'ws_statistics',
                'type' => 'sectionend'
            ),
        );
        $current_section = isset( $GLOBALS['current_section'] ) ? $GLOBALS['current_section'] : 'general';

        $current_tab = $GLOBALS['_GET']['tab'];

        $GLOBALS['hide_save_button'] = false;
        if ($current_tab == $this->id) {
            $api_key_v3 = get_option(WC_Sendinblue::API_KEY_V3_OPTION_NAME);
            if ( !empty($api_key_v3) ) {
                if ( in_array($current_section, self::TABS_WITHOUT_SAVE_BUTTON)) {
                    $GLOBALS['hide_save_button'] = true;
                }
                return isset($settings[$current_section]) ? $settings[$current_section] : $settings['general'];
            } else {
                $GLOBALS['hide_save_button'] = true;
                return $settings_init['general'];
            }
        }else{
            return $settings_init['general'];
        }
    }

    private function wc_enqueue_js( $code ) {
        if ( function_exists( 'wc_enqueue_js' ) ) {
            wc_enqueue_js( $code );
        }
    }

	// BG20190819
    public function ws_marketingauto_enable_note() {
    	if ( 'disabled' == $this->ma_status) { 
			$GLOBALS['hide_save_button'] = true;
			?>
			<div class="sib-ma-alert sib-ma-disabled alert alert-danger" role="alert">
				<?php echo __('Activate Automation on the Apps page of your Sendinblue account, then click on the <strong>TRY FOR FREE</strong> button.', 'wc_sendinblue' ); ?>
			</div>
			<div class="sib-ma-alert sib-ma-disabled alert alert-danger" role="alert">&nbsp;</div>
			<div class="sib-ma-alert sib-ma-disabled alert alert-danger" role="alert">
				<a target="_blank" href="https://app.sendinblue.com/account/apps/?utm_source=woocommerce_plugin&utm_medium=plugin&utm_campaign=module_link"><?php echo __('Activate', 'wc_sendinblue' ); ?></a>
			</div>
		<?php } else {
			$desc = __( 'Recover lost sales by reminding your customers about the items they\'ve left in the cart.<br />Simply follow the steps of the pre-made "Abandoned Cart" workflow: ', 'wc_sendinblue' );
			$cart_info_link_text = __( 'Create in Sendinblue', 'wc_sendinblue' );
			$desc .= '<br /><a target="_blank" href="https://automation.sendinblue.com/?utm_source=woocommerce_plugin&utm_medium=plugin&utm_campaign=module_link">' . $cart_info_link_text . '</a>'; 
			$desc_tip = __( 'To know which WooCommerce variables can be used in your email template ', 'wc_sendinblue' );
			$desc_tip_link_text = __( 'click here', 'wc_sendinblue' );
			$desc_tip .= '<a target="_blank" href="https://help.sendinblue.com/hc/en-us/articles/360005797539/?utm_source=woocommerce_plugin&utm_medium=plugin&utm_campaign=module_link">' . $desc_tip_link_text . '</a>';
			?>
			<table style="display: none;" id="ws_marketingauto_enable_info" class="form-table">
				<tbody>
					<tr class="" valign="top">
						<th scope="row" class="titledesc"><?php echo __( 'Abandoned Cart email', 'wc_sendinblue' ); ?></th>
						<td class="forminp">
							<div class="sib-ma-alert sib-ma-disabled alert alert-danger" role="alert"><?php echo $desc; ?></div>
							<div class="sib-ma-alert sib-ma-disabled alert alert-danger" role="alert">&nbsp;</div>
							<div class="sib-ma-alert sib-ma-disabled alert alert-danger" role="alert"><?php echo $desc_tip; ?></div>
						</td>
					</tr>
				</tbody>
			</table>
		<?php }
	}

    /**
     * Statistics on general options
     */
    public function ws_statistics(){
        ?>
        <h3><?php _e('Statistics', 'wc_sendinblue'); ?></h3>
        <div class="ws_date" ><input id="ws_date_picker" name="ws_date_picker">
            <img id="ws_date_gif" src="<?php echo WC()->plugin_url()."/assets/images/select2-spinner.gif"; ?>" style="margin-right: 12px;vertical-align: middle;display: none;">
        </div>
        <table id="ws_statistics_table" class="wc_shipping widefat wp-list-table" cellspacing="0">
            <thead>
            <tr>
                <th class="sort">&nbsp;</th>
                <th class=""><?php _e('Email Templates','wc_sendinblue');?></th>
                <th class=""><?php _e('Sent','wc_sendinblue');?></th>
                <th class=""><?php _e('Deliverability Rate','wc_sendinblue');?></th>
                <th class=""><?php _e('Open Rate','wc_sendinblue');?></th>
                <th class=""><?php _e('Click Rate','wc_sendinblue');?></th>

            </tr>
            </thead>
            <tbody class="ui-sortable">
            <?php
            foreach(WC_Sendinblue_SMTP::get_statistics() as $statistic){ ?>
                <tr id="<?php echo str_replace(' ', '-', $statistic['name']);?>">
                    <td width="1%" class="sort ui-sortable-handle">
                        <input type="hidden" name="method_order[flat_rate]" value="">
                    </td>
                    <td class=""><?php echo $statistic['name'];?></td>
                    <td class=""><?php echo $statistic['sent'];?></td>
                    <td class=""><?php echo $statistic['delivered'];?></td>
                    <td class=""><?php echo $statistic['open_rate'];?></td>
                    <td class=""><?php echo $statistic['click_rate'];?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
        <?php

    }

    /**
     * Match Woocommerce customers attributes and Sendinblue lists
     */
    public function ws_match_attributes(){
        //get current matched lists
        $matched_lists = isset($this->customizations['ws_matched_lists']) ? $this->customizations['ws_matched_lists'] : array();
        // get woocommerce customer's attributes
        $admin_profile = new WC_Admin_Profile();
        $customer_fileds =$admin_profile->get_customer_meta_fields();
        // available sendinblue attributes
        $allAttrs = WC_Sendinblue_API::get_attributes();
        $attrs = $allAttrs['attributes']['normal_attributes'];
        ?>
        <tr valign="top">
                <th scope="row" class="titledesc">
                </th>
                <td class="ws-match-attr" style="padding-top: 0px;">
                    <table class="form-ws-table" id="ws-match-attribute-table" >

                        <tr>
                            <td>
                                <label for=""><?php _e('Woocommerce Customers Attributes', 'wc_sendinblue'); ?></label>
                            </td>
                            <td></td>
                            <td>
                                <label for=""><?php _e('Sendinblue Contact Attributes', 'wc_sendinblue' ) ?></label>
                            </td>
                            <td></td>
                        </tr>
                      <?php
                      if ( empty($matched_lists) ){ ?>
                        <tr class="ws-match-row">
                            <td>
                                <select class="ws-match-list-wp-attr" name="" style="width: 100%;">
                                    <?php foreach ($customer_fileds['billing']['fields'] as $id=>$label): ?>
                                        <option value="<?php echo $id; ?>"><?php echo $label['label']; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td style="text-align: center;">
                                <span class="dashicons dashicons-leftright"></span>
                            </td>
                            <td>
                                <select class="ws-match-list-sib-attr" name="" style="width: 100%;">
                                    <?php foreach ($attrs as $attr): ?>
                                        <option value="<?php echo $attr['name']; ?>"><?php echo $attr['name']; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                            </td>
                            <td style="border: none;">
                                <a href="javascript:void(0)" class="ws-match-list-plus" style="display: none;"><span class="dashicons dashicons-plus-alt "></span></a>
                            </td>
                            <input type="hidden" name="ws_matched_lists[]" class="ws-matched-lists">
                        </tr>
                       <?php
                      }
                      else
                      {
                          foreach($matched_lists as $key=>$val)
                          {
                              ?>
                              <tr class="ws-match-row">
                                  <td>
                                      <select class="ws-match-list-wp-attr" name="" style="width: 100%;">
                                          <?php foreach ($customer_fileds['billing']['fields'] as $id=>$label): ?>
                                              <option value="<?php echo $id; ?>" <?php if ( $id == $val ) { echo 'selected="selected"'; } ?>><?php echo $label['label']; ?></option>
                                          <?php endforeach; ?>
                                      </select>
                                  </td>
                                  <td style="text-align: center;">
                                      <span class="dashicons dashicons-leftright"></span>
                                  </td>
                                  <td>
                                      <select class="ws-match-list-sib-attr" name="" style="width: 100%;">
                                          <?php foreach ($attrs as $attr): ?>
                                              <option value="<?php echo $attr['name']; ?>" <?php if ( $attr['name'] == $key ) { echo 'selected="selected"'; }?>><?php echo $attr['name']; ?></option>
                                          <?php endforeach; ?>
                                      </select>
                                  </td>
                                  <td>
                                      <a href="javascript:void(0)" class="ws-match-list-dismiss"><span class="dashicons dashicons-dismiss"></span></a>
                                  </td>
                                  <td style="border: none;">
                                      <a href="javascript:void(0)" class="ws-match-list-plus" style="display: none;"><span class="dashicons dashicons-plus-alt "></span></a>
                                  </td>
                                  <input type="hidden" name="ws_matched_lists[]" class="ws-matched-lists">
                              </tr>
                              <?php
                          }
                      }

                      ?>
                    </table>
                    <label for=""><?php _e('Match the WooCommerce Customers attributes with your Sendinblue contacts attributes', 'wc_sendinblue') ?></label>
                </td>
            </tr>
        </tr>
        <?php
    }
    /**
     * Customer Notification settings on SMS options section
     */
    public function ws_sms_notification(){
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for=""><?php _e('Enable Customer Notifications', 'wc_sendinblue'); ?></label>
            </th>
            <td class="forminp">
                <table class="form-ws-table">
                    <tr>
                        <td style="width: 30%;"><label for="ws_sms_send_after"><input type="checkbox" name="ws_sms_send_after" id="ws_sms_send_after" value="1" <?php checked( WC_Admin_Settings::get_option('ws_sms_send_after','no'), 'yes'); ?>><?php _e('Order confirmation', 'wc_sendinblue'); ?></label></td>
                        <td><label for="ws_sms_send_shipment"><input type="checkbox" name="ws_sms_send_shipment" id="ws_sms_send_shipment" value="1" <?php checked( WC_Admin_Settings::get_option('ws_sms_send_shipment','no'), 'yes'); ?>><?php _e('Order shipment', 'wc_sendinblue'); ?></label></td>
                    </tr>
                </table>
            </td>
        </tr>

        <?php
    }
    /**
     * Admin Notification settings on SMS options section
     */
    public function ws_sms_admin_notification(){
        ?>
        <table class="form-table">
            <tbody><tr valign="top">
                <th scope="row" class="titledesc">
                    <label for=""><?php _e('Enable Admin Notifications', 'wc_sendinblue'); ?></label>
                </th>
                <td class="forminp">
                    <table class="form-ws-table">
                        <tr>
                            <td>
                                <label for="ws_sms_credits_notify"><input type="checkbox" name="ws_sms_credits_notify" id="ws_sms_credits_notify" value="1" <?php checked( WC_Admin_Settings::get_option('ws_sms_credits_notify','no'), 'yes'); ?>><?php _e('SMS credit shortage', 'wc_sendinblue'); ?></label>

                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
            </tbody>
        </table>
        <?php
    }
    /**
     * Notification settings on Email options section
     */
    public function ws_notification_activation(){
        ?>
        <table class="form-table">
            <tbody><tr valign="top">
                <th scope="row" class="titledesc">
                    <label for=""><?php _e('Notification Activation', 'wc_sendinblue'); ?></label>
                </th>
                <td class="forminp">
                    <table class="form-ws-table">
                        <tr>
                            <td><label for="WC_Email_New_Order"><input type="checkbox" name="WC_Email_New_Order" id="WC_Email_New_Order" value="1" <?php checked( WC_Admin_Settings::get_option('WC_Email_New_Order','no'), 'yes'); ?>><?php _e('New order', 'wc_sendinblue'); ?></label></td>
                            <td><label for="WC_Email_Customer_Processing_Order"><input type="checkbox" name="WC_Email_Customer_Processing_Order" id="WC_Email_Customer_Processing_Order" value="1" <?php checked( WC_Admin_Settings::get_option('WC_Email_Customer_Processing_Order','no'), 'yes'); ?>><?php _e('Processing order', 'wc_sendinblue'); ?></label></td>
                            <td><label for="WC_Email_Customer_Refunded_Order"><input type="checkbox" name="WC_Email_Customer_Refunded_Order" id="WC_Email_Customer_Refunded_Order" value="1" <?php checked( WC_Admin_Settings::get_option('WC_Email_Customer_Refunded_Order','no'), 'yes'); ?>><?php _e('Refunded order', 'wc_sendinblue'); ?></label></td>
                        </tr>
                        <tr>
                            <td><label for="WC_Email_Cancelled_Order"><input type="checkbox" name="WC_Email_Cancelled_Order" id="WC_Email_Cancelled_Order" value="1" <?php checked( WC_Admin_Settings::get_option('WC_Email_Cancelled_Order','no'), 'yes'); ?>><?php _e('Cancelled order', 'wc_sendinblue'); ?></label></td>
                            <td><label for="WC_Email_Customer_Completed_Order"><input type="checkbox" name="WC_Email_Customer_Completed_Order" id="WC_Email_Customer_Completed_Order" value="1" <?php checked( WC_Admin_Settings::get_option('WC_Email_Customer_Completed_Order','no'), 'yes'); ?>><?php _e('Completed order', 'wc_sendinblue'); ?></label></td>
                            <td><label for="WC_Email_Customer_New_Account"><input type="checkbox" name="WC_Email_Customer_New_Account" id="WC_Email_Customer_New_Account" value="1" <?php checked( WC_Admin_Settings::get_option('WC_Email_Customer_New_Account','no'), 'yes'); ?>><?php _e('New account', 'wc_sendinblue'); ?></label></td>
                        </tr>
                        <tr>
                            <td><label for="WC_Email_Customer_On_Hold_Order"><input type="checkbox" name="WC_Email_Customer_On_Hold_Order" id="WC_Email_Customer_On_Hold_Order" value="1" <?php checked( WC_Admin_Settings::get_option('WC_Email_Customer_On_Hold_Order','no'), 'yes'); ?>><?php _e('Order on-hold', 'wc_sendinblue'); ?></label></td>
                            <td><label for="WC_Email_Failed_Order"><input type="checkbox" name="WC_Email_Failed_Order" id="WC_Email_Failed_Order" value="1" <?php checked( WC_Admin_Settings::get_option('WC_Email_Failed_Order','no'), 'yes'); ?>><?php _e('Failed order', 'wc_sendinblue'); ?></label></td>
                            <td><label for="WC_Email_Customer_Note"><input type="checkbox" name="WC_Email_Customer_Note" id="WC_Email_Customer_Note" value="1" <?php checked( WC_Admin_Settings::get_option('WC_Email_Customer_Note','no'), 'yes'); ?>><?php _e('Customer note', 'wc_sendinblue'); ?></label></td>
                        </tr>
                    </table>
                </td>
            </tr>
            </tbody>
        </table>
        <?php
    }

    /**
     * Sendinblue templates on Email options section
     */
    public function ws_sendinblue_templates(){
        $email_templates = array(
            'ws_new_order_template' => '',
            'ws_processing_order_template' => '',
            'ws_refunded_order_template' => '',
            'ws_cancelled_order_template' => '',
            'ws_completed_order_template' => '',
            'ws_new_account_template' => '',
            'ws_failed_order_template' => '',
            'ws_on_hold_order_template' => '',
            'ws_customer_note_template'  => ''
        );
        $templates = WC_Sendinblue::$templates;
        foreach($email_templates as $key=>$content){
            $option_value = WC_Admin_Settings::get_option($key,'0');
            $email_templates[$key] = '<select name="'.$key.'" id="'.$key.'">';
            $email_templates[$key] .= '<option value="0" > - Choose Template - </option>';
            foreach($templates as $id=>$val){
                $email_templates[$key] .= '<option value="'.$id.'" '.selected($option_value, $id ,false).'>'.$val['name'].'</option>';
            }
            $email_templates[$key] .='</select>';
        }
        ?>

        <table class="form-table">
            <tbody><tr valign="top">
                <th scope="row" class="titledesc">
                    <label for=""><?php _e('Sendinblue Templates', 'wc_sendinblue'); ?></label>
                </th>
                <td class="forminp">
                    <table class="form-ws-table">
                        <tr>
                            <td><label for="ws_new_order_template"><?php _e('New order', 'wc_sendinblue'); ?></label></td><td><?php echo $email_templates['ws_new_order_template']; ?></td>
                            <td><label for="ws_processing_order_template"><?php _e('Processing order', 'wc_sendinblue'); ?></label></td><td><?php echo $email_templates['ws_processing_order_template']; ?></td>
                        </tr>
                        <tr>
                            <td><label for="ws_refunded_order_template"><?php _e('Refunded order', 'wc_sendinblue'); ?></label></td><td><?php echo $email_templates['ws_refunded_order_template']; ?></td>
                            <td><label for="ws_cancelled_order_template"><?php _e('Cancelled order', 'wc_sendinblue'); ?></label></td><td><?php echo $email_templates['ws_cancelled_order_template']; ?></td>
                        </tr>
                        <tr>
                            <td><label for="ws_completed_order_template"><?php _e('Completed order', 'wc_sendinblue'); ?></label></td><td><?php echo $email_templates['ws_completed_order_template']; ?></td>
                            <td><label for="ws_failed_order_template"><?php _e('Failed order', 'wc_sendinblue'); ?></label></td><td><?php echo $email_templates['ws_failed_order_template']; ?></td>
                        </tr>
                        <tr>
                            <td><label for="ws_on_hold_order_template"><?php _e('Order on-hold', 'wc_sendinblue'); ?></label></td><td><?php echo $email_templates['ws_on_hold_order_template']; ?></td>
                            <td><label for="ws_customer_note_template"><?php _e('Customer note', 'wc_sendinblue'); ?></label></td><td><?php echo $email_templates['ws_customer_note_template']; ?></td>
                        </tr>
                        <tr>
                            <td><label for="ws_new_account_template"><?php _e('New account', 'wc_sendinblue'); ?></label></td><td><?php echo $email_templates['ws_new_account_template']; ?></td>
                            <td></td>
                        </tr>
                    </table>
                    <p style="margin-top: 22px;"><i><?php _e('The default WooCommerce template will be used for notifications without an active Sendinblue template.', 'wc_sendinblue'); ?></i></p>
                </td>
            </tr>
            </tbody>
        </table>
        <?php
    }

    /**
     * Add following contacts part on campaign section
     */
    public function ws_email_campaign_follow_contacts(){
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
            </th>
            <td class="forminp" style="padding-top: 0px;">
                <textarea name="ws_email_campaign_following_contacts" id="ws_email_campaign_following_contacts" style="" class="ws_contact_textarea wide-input" placeholder="example1@email.com,example2@email.com, etc."></textarea>
                <p class="description"><?php echo sprintf(__('Please copy/paste comma-separated email addresses.%s If you want to send a campaign to more than 1000 email addresses, we advise you to send your campaign directly from %s.', 'wc_sendinblue'),
                        '<br>','<a href="">Sendinblue</a>');?></p>
            </td>
        </tr>

        <?php
    }
    /**
     * Add email send part on campaign section
     */
    public function ws_email_campaign_send(){
        ?>
        <table class="form-table">
            <tbody>
            <tr valign="top">
                <th scope="row" class="titledesc">
                    <label for=""><?php _e('Message', 'wc_sendinblue'); ?></label>
                </th>
                <td class="forminp">
                    <textarea name="ws_email_campaign_message" id="ws_email_campaign_message" style="" class="ws_textarea wide-input" placeholder=""></textarea>
                    <p class="description"><?php printf(__('> You can insert HTML or simple text in the text input.%s > You must have UNSUBSCRIBE tag wihin your html or a variable {} in your footer to create a valid campaign.%s It\'s important that the user is able to unsubscribe from your list.', 'wc_sendinblue'),'<br>','<br>');?></p>
                </td>
            </tr>
            <tr valign="top">
                <th scope="row" class="titledesc">
                    <label for=""><?php _e('Send a test email', 'wc_sendinblue'); ?></label>
                </th>
                <td class="forminp">
                    <table class="form-ws-table">
                        <tr>
                            <td><input type="email" name="" id="" class="input-text regular-input"/><a href="javascript:void(0);" class="button"><?php _e('Send', 'wc_sendinblue'); ?></a></td>
                        </tr>
                        <tr>
                            <td>
                                <img id="ws_login_gif_email" src="<?php echo WC()->plugin_url().'/assets/images/select2-spinner.gif';?>" style="margin-right: 12px;vertical-align: middle;display: none;">
                                <input name="" id="ws_email_send_campaign_btn" class="button-secondary" type="button" value="<?php _e('Send My Campaign', 'wc_sendinblue'); ?>">
                                <p class="description"><?php printf( __('You can follow sending progress and statistics from your %s account', 'wc_sendinblue'),'<a href="">Sendinblue</a>'); ?></p>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
            </tbody>
        </table>
        <?php
    }
    /**
     * Add SMS send part on campaign section
     */
    public function ws_sms_campaign_send(){

        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
            </th>
            <td class="forminp">
                <table class="form-ws-table">
                    <tr>
                        <td>
                            <img id="ws_login_gif_sms" src="<?php echo WC()->plugin_url().'/assets/images/select2-spinner.gif';?>" style="margin-right: 12px;vertical-align: middle;display: none;">
                            <input name="" id="ws_sms_send_campaign_btn" class="button-secondary" type="button" value="<?php _e('Send my campaign', 'wc_sendinblue'); ?>">
                            <p class="description"><?php printf( __('You can follow sending progress and statistics from your %s account', 'wc_sendinblue'),'<a href="">Sendinblue</a>'); ?></p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>

        <?php
    }
}

// setup settings
return new WC_Sendinblue_Settings();
