/* sendinblue_admin */

jQuery(document).ready(function(){

    /**
     *  Initialize when page is loaded
     *  begin
     */
    jQuery('#ws_dopt_templates').closest( 'tr' ).css('display', 'none');
    jQuery('#ws_opt_field_label').closest( 'table' ).css('display', 'none');
    
    // hide remove icon when matched attributes list is one in subscription option section
    if(jQuery('.ws-match-row').length == 1 )
    {
        jQuery('#ws-match-attribute-table').find('tr:eq(1)').find('td:eq(3)').html('');
    }
    jQuery('.ws-match-row').last().find('.ws-match-list-plus').show();
    jQuery('.ws-matched-lists').each(function(){
        jQuery(this).getMatchedLists();
    });

    /**
     * end initialize
     */

    // login and logout button
    if(typeof LOG_BTN != 'undefined'){
        jQuery('#ws_api_key').after(LOG_BTN);
        jQuery('#ws_info_wrap').after( LOG_BTN );
    }
    // Subscribe options
    jQuery('#ws_dopt_enabled').on('change', function(){
        jQuery( this ).closest( 'tr' ).next( 'tr').hide('fast');

        if ( jQuery(this).prop('checked') == true ) {
            jQuery( this ).closest( 'tr' ).next( 'tr').show('fast');
        }
        else {
            jQuery( this ).closest( 'tr' ).next( 'tr').hide('fast');
        }

    }).change();
    jQuery('#ws_opt_field').on('change', function(){
        jQuery( this ).closest( 'table' ).nextAll( 'table').hide('fast');

        if ( jQuery(this).prop('checked') == true ) {
            jQuery( this ).closest( 'table' ).nextAll( 'table').show('fast');
        }
        else {
            jQuery( this ).closest( 'table' ).nextAll( 'table').hide('fast');
        }

    }).change();
    jQuery('#ws_enable_match_attributes').on('change', function(){
        if ( jQuery(this).prop('checked') == true ) {
            jQuery('.ws-match-attr').show();
        }
        else {
            jQuery('.ws-match-attr').hide();
        }
    }).change();
    //refresh credit info - ws_sms_refresh
    jQuery('.ws_refresh').on('click', function(){

        var data = {
            action: 'ws_sms_refresh'
        }
        jQuery.post(ajax_object.ajax_url, data,function(respond) {
            if(respond == 'success') {
                // refresh for transient data
            }
        });
    });
    /*
    * SMS options
    */
    if( ws_section == 'sms_options' || ws_section == 'campaigns') {

        jQuery('.ws_sms_send_msg_desc').after('<p><i>' + VAR_SMS_MSG_DESC + '</i></p>');
        jQuery('.ws_sms_send_test').after('<a href="javascript:void(0);" class = "ws_sms_send_test_btn button">'+ SEND_BTN +'</a>' + Loading_Gif+"<span></span>");
        jQuery('.ws_sms_send_test').closest('td').find('span').after('<div class="ws_sms_alert" style="display: none;"><p></p></div>');
        var desc = jQuery('.ws_sms_send_msg_desc:eq(0)').closest('td').find('i:eq(0)').text();
        var desc_arr = desc.split('%');
        var i = 0;
        jQuery('.ws_sms_send_msg_desc').each(function(){
            var num_chr = 160 - jQuery('.ws_sms_send_msg_desc:eq('+i+')').val().length;
            var flag = num_chr != 160 ? '1' : '0';
            jQuery('.ws_sms_send_msg_desc:eq('+i+')').closest('td').find('i:eq(0)').text(desc_arr[0] + flag + desc_arr[1] + num_chr + desc_arr[2]);
            desc = jQuery('.ws_sms_sender:eq('+i+')').closest('td').find('span').text();
            num_chr = 11 - jQuery('.ws_sms_sender:eq('+i+')').val().length;
            jQuery('.ws_sms_sender:eq('+i+')').closest('td').find('span').text(desc + num_chr);
            i++;
        });

    }
    jQuery('#ws_sms_send_after').click(function(){
        jQuery(this).closest('form').find('table:eq(2)').toggle(500,function(){ validProp(); });
        // h2 is used instead of h3 from high version of Woo
        jQuery(this).closest('form').find('h3:eq(1),h2:eq(1)').toggle();
    });
    jQuery('#ws_sms_send_shipment').click(function(){
        jQuery(this).closest('form').find('table:eq(3)').toggle(500,function(){ validProp(); });
        jQuery(this).closest('form').find('h3:eq(2),h2:eq(2)').toggle();
    });

    jQuery('input[name="ws_sms_send_after"]').each(function(){
        if(!jQuery(this).is(':checked')){
            jQuery(this).closest('form').find('table:eq(2)').hide();
            jQuery(this).closest('form').find('h3:eq(1),h2:eq(1)').hide();
        }
    });
    jQuery('input[name="ws_sms_send_shipment"]').each(function(){
        if(!jQuery(this).is(':checked')){
            jQuery(this).closest('form').find('table:eq(3)').hide();
            jQuery(this).closest('form').find('h3:eq(2),h2:eq(2)').hide();
        }
    });

    jQuery('#ws_sms_credits_notify').click(function(){
        jQuery(this).closest('form').find('table:eq(6)').toggle(500,function(){ validProp(); });
        jQuery(this).closest('form').find('h3:eq(3),h2:eq(3)').toggle();
    });
    jQuery('input[name="ws_sms_credits_notify"]').each(function(){
        if(!jQuery(this).is(':checked')){
            jQuery(this).closest('form').find('table:eq(6)').hide();
            jQuery(this).closest('form').find('h3:eq(3),h2:eq(3)').hide();
        }
    });
    function validProp(){
        jQuery('input[type=text],input[type=email],input[type=number],textarea').each(function(){
            jQuery(this).prop('required',false);
            if(jQuery(this).is(":visible") && jQuery(this).attr('class') != 'ws_sms_send_test'){
                jQuery(this).prop('required',true);
            }
        });
    }
    // change message info
    jQuery( '.ws_sms_send_msg_desc').bind('input propertychange', function(){
        var sms_num = Math.ceil( jQuery(this).val().length == 0 ? 1 : jQuery(this).val().length  / 160 );
        var num_chr = 160*sms_num - jQuery(this).val().length;
        jQuery(this).closest('td').find('i:eq(0)').text(desc_arr[0]+sms_num+desc_arr[1]+num_chr+desc_arr[2]);
    });
    // change sender info
    jQuery( '.ws_sms_sender').bind('input propertychange', function(){
        var num_chr = 11 - jQuery(this).val().length;
        jQuery(this).closest('td').find('span').text(desc+num_chr);
        // validation
        var sms_sender_val = jQuery(this).val();
        sms_sender_val = sms_sender_val.replace(/[^a-z0-9]/gi, '');
        jQuery(this).val(sms_sender_val);
    });
    if( ws_section == 'sms_options' && ws_section != '') {
        validProp();
    }
    /*-- SMS options end --*/

    //jQuery('th').css('width','220px').css('text-align','right');

    jQuery('input[name="ws_sms_send_to"]').click(function(){
        if(jQuery(this).val() != 'single'){
            jQuery(this).closest( 'table' ).next('table').find('tr:eq(0)').hide('fast');
            jQuery('#ws_sms_single_campaign').prop('required',false);
        }else{
            jQuery(this).closest( 'table' ).next('table').find('tr:eq(0)').show('fast');
            jQuery('#ws_sms_single_campaign').prop('required', true);
        }
    });
    jQuery('input[name="ws_sms_send_to"]').each(function(){
        if(jQuery(this).is(':checked') && jQuery( this).val() != 'single'){
            jQuery('#ws_sms_single_campaign').closest('tr').hide('fast');
            jQuery('#ws_sms_single_campaign').prop('required',false);
        }else if(jQuery(this).is(':checked') && jQuery( this).val() == 'single'){
            jQuery('#ws_sms_single_campaign').closest('tr').show('fast');
            jQuery('#ws_sms_single_campaign').prop('required',true);
        }
    });

    /*
     * Email options
     */
    jQuery('input[name="ws_email_templates_enable"]').click(function(){
        if(jQuery(this).is(':checked') && jQuery( this).val() != 'yes'){
            jQuery(this).closest( 'table' ).next('table').hide('fast');
        }else{
            jQuery(this).closest( 'table' ).next('table').show('fast');
        }
    });
    jQuery('input[name="ws_email_templates_enable"]').each(function(){
        if(jQuery(this).is(':checked') && jQuery( this).val() != 'yes'){
            jQuery(this).closest( 'table' ).next('table').hide('fast');
        }else if(jQuery(this).is(':checked') && jQuery( this).val() == 'no'){
            jQuery(this).closest( 'table' ).next('table').show('fast');
        }
    });

    /**
    * Send test SMS
    */
    jQuery('.ws_sms_send_test_btn').on('click', function () {

        var sms_to = jQuery(this).closest('td').find('.ws_sms_send_test').val();
        var content = jQuery(this).closest('table').find('.ws_sms_send_msg_desc').val();
        var alert_element = jQuery(this).closest('td').find('.ws_sms_alert');
        var remove_alert = function(){
            alert_element.find('p').text('');
            if(alert_element.hasClass('failure'))
            {
                alert_element.removeClass('failure');
            }
            if(alert_element.hasClass('success'))
            {
                alert_element.removeClass('success');
            }
            alert_element.hide('slow');

        };
        if(sms_to == '' || isValidSMS(sms_to) != true) {
            jQuery('.ws_sms_send_test').focus();
            alert_element.addClass('failure');
            alert_element.find('p').text(ws_alert_msg_failed);
            alert_element.show('slow');
            setTimeout(remove_alert, 3000);
            return false;
        }
        jQuery(this).attr('disabled', 'true');
        jQuery(this).text(SENDING_BTN);
        jQuery(this).closest('td').find('.ws_loading_gif').show();

        var data = {
            action : 'ws_sms_test_send',
            sms    : sms_to,
            content: content
        }

        jQuery('.sib-spin').show();
        jQuery.post(ajax_object.ajax_url, data,function(respond) {
            jQuery('.sib-spin').hide();
            jQuery('.ws_sms_send_test_btn').removeAttr('disabled');
            jQuery('.ws_sms_send_test_btn').text(SEND_BTN);
            jQuery('.ws_loading_gif').hide();

            if(respond != 'success') {
                alert_element.addClass('failure');
                alert_element.find('p').text(ws_alert_msg_failed);
                alert_element.show('slow');
                setTimeout(remove_alert, 3000);
            } else {
                alert_element.addClass('success');
                alert_element.find('p').text(ws_alert_msg_success);
                alert_element.show('slow');
                setTimeout(remove_alert, 3000);
            }
        });
    });

    /**
     * Send the SMS campaign
     */
    jQuery('#ws_sms_send_campaign_btn').on('click', function (){

        var sms_single = '0033663309741';
        if(jQuery( 'input[name="ws_sms_send_to"]:checked' ).val() == 'single') {
            sms_single = jQuery('#ws_sms_single_campaign').val();
        }
        var sms_sender = jQuery('#ws_sms_sender_campaign').val();
        var sms_send_msg = jQuery('#ws_sms_campaign_message').val();

        var campaign_type = jQuery('input[name=ws_sms_send_to]:checked').val();

        if( sms_single == '' || isValidSMS(sms_single) != true ) {
            jQuery('#ws_sms_single_campaign').focus();
            alert(ws_alert_msg_failed);
            return false;
        }
        jQuery('#ws_sms_send_msg_desc_campaign,#ws_sms_sender_campaign').each(function(){

            if(jQuery(this).val() == ''){
                jQuery(this).focus();
                alert(ws_alert_msg_failed);return false;
            }

        });
        //
        jQuery(this).attr('disabled', 'true');

        var data = {
            action       : 'ws_sms_campaign_send',
            campaign_type: campaign_type,
            sms          : sms_single,
            sender       : sms_sender,
            msg          : sms_send_msg
        }

        jQuery('#ws_login_gif_sms').show();
        jQuery.post(ajax_object.ajax_url, data,function(respond) {
            jQuery('#ws_login_gif_sms').hide();
            jQuery('#ws_sms_send_campaign_btn').removeAttr('disabled');
            if(respond != 'success') {
                alert(ws_alert_msg_failed);
            } else {
                alert(ws_alert_msg_success);
            }
        });
    });
    /**
     * Send the email campaign
     */
    jQuery('#ws_email_campaign_sender').change(function(){
        jQuery('#ws_email_campaign_from_name').val(jQuery('#ws_email_campaign_sender option:selected').val());
    });
    jQuery('.ws_email_send_campaign_btn').on('click', function (){

        var follow_contacts = {};
        if(jQuery( 'input[name="ws_email_campaign_to"]:checked' ).val() == 'some') {
            follow_contacts = jQuery('#ws_email_campaign_following_contacts').val().split(',');
        }
        var campaign_name = jQuery('#ws_email_campaign_name').val();
        var campaign_from_name = jQuery('#ws_email_campaign_from_name').val();
        var campaign_sender = jQuery('#ws_email_campaign_sender option:selected').text();
        var campaign_subject = jQuery('#ws_email_campaign_subject').val();
        var campaign_msg = jQuery('#ws_email_campaign_message').val();

        var campaign_type = jQuery('input[name=ws_email_campaign_to]:checked').val();

        if( campaign_type == 'some') {
            // send to following contacts
            if(!isValidContacts(follow_contacts)){
                jQuery('#ws_email_campaign_following_contacts').focus();
                alert(ws_alert_msg_contact_failed);
                return false;
            }
        }
        jQuery('#ws_email_campaign_name,#ws_email_campaign_from_name,#ws_email_campaign_sender,#ws_email_campaign_subject,#ws_email_campaign_message').each(function(){

            if(jQuery(this).val() == '' || jQuery(this).val() == '-1'){
                jQuery(this).focus();
                alert(ws_alert_msg_failed);
                return false;
            }

        });
        //
        jQuery(this).attr('disabled', 'true');

        var data = {
            action       : 'ws_email_campaign_send',
            campaign_type: campaign_type,
            contacts     : follow_contacts,
            title        : campaign_name,
            sender       : campaign_sender,
            subject      : campaign_subject,
            msg          : campaign_msg
        };

        jQuery('#ws_login_gif_email').show();
        jQuery.post(ajax_object.ajax_url, data,function(respond) {
            jQuery('#ws_login_gif_email').hide();
            jQuery('#ws_email_send_campaign_btn').removeAttr('disabled');
            if(respond != 'success') {
                alert(ws_alert_msg_failed);
            } else {
                alert(ws_alert_msg_success);
            }
        });
    });

    function isValidSMS(sms){
        var charone = sms.substring(0, 1);
        var chartwo = sms.substring(0, 2);
        if ( (charone == '0' && chartwo == '00') || (charone == '+' && !isNaN(chartwo))  || (!isNaN(charone) && !isNaN(chartwo)) )
            return true;
        return false;
    }

    function isValidContacts(emails){
        var email_check = /^[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,6}jQuery/i;
        jQuery.each(emails, function(key, email){
            if(!email_check.test(email)){
                return false;
            }
        });
        return true;
    }

    /**
     * Marketing automation 
     */
    // BG20190819
    jQuery('#ws_marketingauto_enable').on('change', function(){
        jQuery('#ws_marketingauto_enable_info').hide('fast');
        if ( jQuery(this).prop('checked') == true ) {
            jQuery('#ws_marketingauto_enable_info').show('fast');
        }
        else {
            jQuery('#ws_marketingauto_enable_info').hide('fast');
        }
    }).change();

    /**
     * Validate API key
     */
    jQuery('.ws_api_key_active').on('click', function () {

        var key = jQuery('#ws_api_key').val();
        if(key == '') {
            jQuery('#ws_api_key').focus();
            return false;
        }
        jQuery(this).attr('disabled', 'true');
        jQuery('#ws_login_gif').show();
        var data = {
            action: 'ws_validation_process',
            access_key   : key
        }

        jQuery.post(ajax_object.ajax_url, data,function(respond) {
            location.reload();
        });
    });

    /*
     * Dismiss alert
     */
    jQuery('.ws_credits_notice .notice-dismiss').on('click', function () {
        var alert_type = 'credit';
        var data = {
            action: 'ws_dismiss_alert',
            type: alert_type
        }
        jQuery.post(ajax_object.ajax_url, data,function(respond) {
            if(respond == 'success') {
                //
            }
        });
    });

    if(jQuery("#ws_date_picker").length) {
        jQuery("#ws_date_picker").daterangepicker({
            initialText : 'Today...',
            presetRanges: [{
                text: 'Today',
                dateStart: function () {
                    return moment()
                },
                dateEnd: function () {
                    return moment()
                }
            }, {
                text: 'Yesterday',
                dateStart: function () {
                    return moment().subtract(1, 'days')
                },
                dateEnd: function () {
                    return moment().subtract(1, 'days')
                }
            }, {
                text: 'Current Week',
                dateStart: function () {
                    return moment().startOf('week')
                },
                dateEnd: function () {
                    return moment().endOf('week')
                }
            }, {
                text: 'Last Week',
                dateStart: function () {
                    return moment().add('weeks', -1).startOf('week')
                },
                dateEnd: function () {
                    return moment().add('weeks', -1).endOf('week')
                }
            }, {
                text: 'Last Month',
                dateStart: function () {
                    return moment().add('months', -1).startOf('month')
                },
                dateEnd: function () {
                    return moment().add('months', -1).endOf('month')
                }
            }],

            datepickerOptions: {
                numberOfMonths: 2
                //initialText: 'Select period...'
            },
            onChange: function () {
                var date_range = JSON.stringify(jQuery("#ws_date_picker").daterangepicker("getRange"));
                jQuery('.ws_date_picker button').addClass('ui-selected');
                var data = {
                    action: 'ws_get_daterange',
                    begin: JSON.parse(date_range).start,
                    end: JSON.parse(date_range).end
                }
                jQuery('#ws_date_gif').show();
                jQuery('#ws_statistics_table').css('opacity',0.5);
                jQuery.post(ajax_object.ajax_url, data,function(respond) {
                    jQuery('#ws_date_gif').hide();
                    jQuery('#ws_statistics_table').css('opacity',1);
                    jQuery.map(respond, function(val, key) {
                        key = key.replace(' ', '-');
                        jQuery('#'+key).find('td:eq(2)').html(val.sent);
                        jQuery('#'+key).find('td:eq(3)').html(val.delivered);
                        jQuery('#'+key).find('td:eq(4)').html(val.open_rate);
                        jQuery('#'+key).find('td:eq(5)').html(val.click_rate);
                    });

                });

            }
        });
    }
    // Initialize for transients when user return after visit other page
    jQuery(window).focus(function() {

        var data = {
            action: 'ws_transient_refresh'
        }
        jQuery.post(ajax_object.ajax_url, data,function(respond) {
            if(respond == 'success') {
                //
            }
        });
    });

    /* sync customers to sendinblue contact list */
    // sync popup
    jQuery('#ws-sib-sync-btn').on('click', function() {

        jQuery('#ws-sync-failure').hide();

        // add to multilist field
        var list = jQuery('#ws_sib_select_list');
        list[0].selectedIndex = 0;
        list.chosen({width:"100%"});
        if(jQuery('.ws-sync-attr').length == 1)
        {
            jQuery('.ws-sync-attr-dismiss').hide();
        }
    });

    var attrFieldLine = jQuery('.ws-sync-attr-line').html();
    // sync add attr line filed
    jQuery('#ws-sib-sync-form').on('click', '.ws-sync-attr-plus', function(){

        var cur_height_thickbox = jQuery('#TB_window').height() + 40;
        var cur_height_thickbox_content = jQuery('#TB_ajaxContent').height() + 40;
        jQuery('.ws-sync-attr-line').append(attrFieldLine);
        jQuery('.ws-sync-attr-dismiss').show();
        jQuery('#TB_window').height(cur_height_thickbox);
        jQuery('#TB_ajaxContent').height(cur_height_thickbox_content);
        set_thickbox_height(cur_height_thickbox);
    });
    // sync dismiss attr line filedw
    jQuery('#ws-sib-sync-form').on('click', '.ws-sync-attr-dismiss', function(){
        jQuery(this).closest('.ws-sync-attr').remove();
        var attrCount = jQuery('.ws-sync-attr').length;
        var cur_height_thickbox = jQuery('#TB_window').height() - 40;
        var cur_height_thickbox_content = jQuery('#TB_ajaxContent').height() - 40;

        jQuery('#TB_window').height(cur_height_thickbox);
        jQuery('#TB_ajaxContent').height(cur_height_thickbox_content);
        if(attrCount == 1) jQuery('.ws-sync-attr-dismiss').hide();
        set_thickbox_height(cur_height_thickbox);
    });

    // set attribute matching
    jQuery('#ws-sib-sync-form').on('change', 'select', function () {
        if(jQuery(this).attr("class") == 'ws-sync-wp-attr'){
            jQuery(this).closest('.ws-sync-attr').find('.ws-sync-match').val(jQuery(this).val());
        }else{
            jQuery(this).closest('.ws-sync-attr').find('.ws-sync-match').attr('name',jQuery(this).val());
        }
    });

    // Sync customers to Sendinblue contact list
    jQuery('#ws_sync_users_btn').on('click', function(){
        jQuery(this).attr('disabled', 'true');
        jQuery('#ws_loading_sync_gif').show();
        var postData = jQuery('#ws-sib-sync-form').getAllValues();
        jQuery('#ws-sib-sync-form').find('input[type=hidden]').each(function (index, value) {
            var attrName = jQuery(this).attr('name');
            if(jQuery('input[name='+attrName+']').length > 1){
                // the attribute is duplicated !
                postData['errAttr'] = attrName;
            }
        });

        var data = {
            action:'ws_sync_users',
            data: postData,
        };

        jQuery.post(ajax_object.ajax_url, data,function(respond) {
            jQuery('#ws_loading_sync_gif').hide();
            jQuery('#ws_sync_users_btn').removeAttr('disabled');
            if(respond.code != 'success') {
                jQuery('#ws-sync-failure').show().html('<p>' + respond.message + '</p>');
            } else {
                // success to sync wp users
                jQuery('.tb-close-icon').click();
                window.location.reload();
            }
        });
    });

    // remove all transients
    jQuery('#ws-remove-cache').on('click',function(){
        var data = {
            action:'ws_remove_cache'
        };
        jQuery.post(ajax_object.ajax_url, data,function(res) {
            window.location.reload();
        });

    });

    // set thickbox height
    function set_thickbox_height(height)
    {
        var href = '#TB_inline?width=600&height=' + height + '&inlineId=ws-sib-sync-users';
        jQuery('#ws-sib-sync-btn').attr('href', href);
    }

    /**
     * match sib attr and woo attr
     */

    jQuery('.ws-enable-match-attr').on('change', function(){
        if(jQuery(this).is(':checked'))
        {
            jQuery('#ws-match-attribute-table').show();
        }
        else
        {
            jQuery('#ws-match-attribute-table').hide();
        }
    });
    var attrMatchLine = jQuery('.ws-match-row').html();
    // sync add attr line filed
    jQuery('#ws-match-attribute-table').on('click', '.ws-match-list-plus', function(){
        jQuery('#ws-match-attribute-table tbody').append('<tr class="ws-match-row">' + attrMatchLine + '</tr>');
        jQuery(this).hide();
        jQuery('.ws-match-row').find('td:eq(3)').html('<a href="javascript:void(0)" class="ws-match-list-dismiss"><span class="dashicons dashicons-dismiss"></span></a>');
        jQuery('.ws-match-row').last().find('.ws-match-list-plus').show();
    });
    // sync dismiss attr line filedw
    jQuery('#ws-match-attribute-table').on('click', '.ws-match-list-dismiss', function(){
        jQuery(this).closest('.ws-match-row').remove();
        jQuery('.ws-match-row').last().find('.ws-match-list-plus').show();
        var attrCount = jQuery('.ws-match-row').length;
        if(attrCount == 1) jQuery('.ws-match-list-dismiss').remove();
    });
    jQuery('#ws-match-attribute-table').on('change', 'select', function () {
        jQuery(this).closest('tr').find('.ws-matched-lists').getMatchedLists();
    });

});
// get serialized data form sync users inputs inside div tag
jQuery.fn.getAllValues = function()
{
    var o = {};
    o['buffer'] = '';
    jQuery(this).find('select').each(function() {
        if(this.name != '')
        {
            o[this.name] = jQuery(this).val();
        }
        else
        {
            if(o['buffer'] == '')
            {
                o['buffer'] = this.value;
            }
            else
            {
                o[this.value] = o['buffer'];
                o['buffer'] = '';
            }
        }
    });
    delete o['buffer'];
    return o;
};

// get matched lists -- sib_attr and woo_attr
jQuery.fn.getMatchedLists = function()
{
    var sib_attr = jQuery(this).closest('tr').find('.ws-match-list-sib-attr').val();
    var woo_attr = jQuery(this).closest('tr').find('.ws-match-list-wp-attr').val();
    jQuery(this).val(sib_attr + '||' + woo_attr);
};
