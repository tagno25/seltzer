<?php

/*
    Copyright 2009-2014 Edward L. Platt <ed@elplatt.com>
    
    This file is part of the Seltzer CRM Project
    billing.inc.php - Billing module

    Seltzer is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    any later version.

    Seltzer is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with Seltzer.  If not, see <http://www.gnu.org/licenses/>.
*/

/**
 * @return This module's revision number.  Each new release should increment
 * this number.
 */
function billing_revision () {
    return 1;
}

/**
 * @return An array of the permissions provided by this module.
 */
function billing_permissions () {
    return array();
}

/**
 * Install or upgrade this module.
 * @param $old_revision The last installed revision of this module, or 0 if the
 *   module has never been installed.
 */
function billing_install($old_revision = 0) {
    // Create initial database table
    if ($old_revision < 1) {
        // TODO
    }
}

// DB to Object mapping ////////////////////////////////////////////////////////

// Table data structures ///////////////////////////////////////////////////////

// Forms ///////////////////////////////////////////////////////////////////////

/**
 * Form for initiating membership billings.
 * @return The form structure.
 */
function billing_form () {
    
    $bill_date = variable_get('billing_last_date', '');
    $bill_label = empty($bill_date) ? 'never' : $bill_date;
    
    // Create form structure
    $form = array(
        'type' => 'form'
        , 'method' => 'post'
        , 'command' => 'billing'
        , 'fields' => array(
            array(
                'type' => 'fieldset'
                , 'label' => 'Process Billings'
                , 'fields' => array(
                    array(
                        'type' => 'message',
                        'value' => 'This will generate billing entries for any members with active memberships.  <strong>Important:</strong> make sure the membership data is up to date before running billing.'
                    ),
                    array(
                        'type' => 'readonly',
                        'class' => 'date',
                        'label' => 'Last Billed',
                        'name' => 'last_billed',
                        'value' => $bill_label
                    ),
                    array(
                        'type' => 'submit'
                        , 'value' => 'Process'
                    )
                )
            )
        )
    );
    
    return $form;
}

/**
 * Form for initiating membership billing emails.
 * @return The form structure.
 */
function email_bills_form () {
    
    $email_date = variable_get('billing_last_email', '');
    $from_label = empty($email_date) ? 'never' : $email_date;
    
    // Create form structure
    $form = array(
        'type' => 'form'
        , 'method' => 'post'
        , 'command' => 'billing_email'
        , 'fields' => array(
            array(
                'type' => 'fieldset'
                , 'label' => 'Send Billing Emails'
                , 'fields' => array(
                    array(
                        'type' => 'message',
                        'value' => 'This will send an email with a payment button to anyone who has a nonzero account balance.'
                        ),
                    array(
                        'type' => 'readonly',
                        'class' => 'date',
                        'label' => 'Last Emailed',
                        'name' => 'last_emailed',
                        'value' => $from_label
                    ),
                    array(
                        'type' => 'submit'
                        , 'value' => 'Send Emails'
                    )
                )
            )
        )
    );
    
    return $form;
}

// Command handlers ////////////////////////////////////////////////////////////

/**
 * Run billings
 */
function command_billing () {
    // Get current date and last bill date
    $today = date('Y-m-d');
    $last_billed = variable_get('billing_last_date', '');
    // Find memberships that start before today and end after the last bill date
    $filter = array();
    if (!empty($last_billed)) {
        $filter['ends_after'] = $last_billed;
    }
    $membership_data = crm_get_data('member_membership', array('filter' => $filter));
    // Bill each membership
    foreach ($membership_data as $membership) {
        if (!empty($membership['end']) && strtotime($membership['end']) < strtotime($today)) {
            // Bill until end of membership
            billing_bill_membership($membership, $membership['end'], $last_billed);
        } else {
            // Bill until today
            billing_bill_membership($membership, $today, $last_billed);
        }
    }
    // Set last billed date to today
    variable_set('billing_last_date', $today);
    $begin = empty($last_billed) ? 'the beginning of time' : $last_billed;
    message_register("Billings processed from $begin through $today.");
    return crm_url('payments');
}

/**
 * Send emails to any members with a positive balance.
 */
function command_billing_email () {
    global $config_email_from;
    global $config_site_title;
    // Get balances and contacts
    $cids = payment_contact_filter(array('balance_due'=>true));
    $balances = payment_accounts(array('cid'=>$cids));
    $contacts = crm_get_data('contact', array('cid'=>$cids));
    $cidToContact = crm_map($contacts, 'cid');
    // Email each contact with a balance
    foreach ($balances as $cid => $balance) {
        // Construct button
        $params = array(
            'referenceId' => $cid
            , 'amount' => $balance['code'] . ' ' . payment_format_currency($balance, false) 
            , 'amountPayPal' => payment_format_currency($balance, false)
            , 'description' => 'Membership Dues Payment'
        );
        $amount = payment_format_currency($balance);
        // Send email
        $to = $cidToContact[$cid]['email'];
        $subject = "[$config_site_title] Payment Due";
        $from = $config_email_from;
        $headers = "Content-type: text/html\r\nFrom: $from\r\n";
        $message = "<p>Hello,<br/><br/>Your current account balance is $amount.  To pay this balance using </p>";
        if (function_exists('amazon_payment_revision')) {
            global $config_amazon_payment_access_key_id;
            global $config_amazon_payment_secret;
            if(!empty($config_amazon_payment_access_key_id)&&!empty($config_amazon_payment_secret)) {
                $button1 = theme('amazon_payment_button', $cid, $params);
                $message .= "<p>Amazon Payments, please click the button below.</p>$button1";
            }
        }
        if (function_exists('paypal_payment_revision')) {
            global $config_paypal_email;
            if(!empty($config_paypal_email)){
                $button2 = theme('paypal_payment_button', $cid, $params);
                $message .= "<p>Paypal, please click the button below.</p>$button2";
            }
        }
        $res = mail($to, $subject, $message, $headers);
    }
    message_register('E-mails have been sent');
    variable_set('billing_last_email', date('Y-m-d'));
    return crm_url('payments', array('query'=>array('tab'=>'billing')));
}

// Pages ///////////////////////////////////////////////////////////////////////

/**
 * @return An array of pages provided by this module.
 */
function billing_page_list () {
    $pages = array();
    return $pages;
}

/**
 * Page hook.  Adds module content to a page before it is rendered.
 *
 * @param &$page_data Reference to data about the page being rendered.
 * @param $page_name The name of the page being rendered.
 * @param $options The array of options passed to theme('page').
*/
function billing_page (&$page_data, $page_name, $options) {
    switch ($page_name) {
        case 'payments':
            // Add view and add tabs
            if (user_access('payment_edit')) {
                page_add_content_top($page_data, theme('form', crm_get_form('billing')), 'Billing');
                page_add_content_top($page_data, theme('form', crm_get_form('email_bills')), 'Billing');
            }
            break;
        case 'contact':
            if (user_access('payment_view') || $_GET['cid'] == user_id()) {
                page_add_content_bottom($page_data, theme('billing_account_info', $_GET['cid']), 'Account');
            }
            if (user_access('payment_view') || $_GET['cid'] == user_id()) {
                page_add_content_bottom($page_data, theme('billing_first_month', $_GET['cid']), 'Plan');
            }
            break;
    }
}

// Utility functions ///////////////////////////////////////////////////////////

/**
 * Run billing for a single membership.
 * @param $membership The membership to bill.
 * @param $until Bill up to and including this day.
 * @param $after Start billing after this day or beginning of time if not given.
 lling_bill_membership*/
function billing_bill_membership ($membership, $until, $after = '') {
    $price = payment_parse_currency($membership['plan']['price']);
    $price['value'] *= -1;
    $months = $membership['plan']['months'];

    $until_date = strtotime($until);
    $membership_start = strtotime($membership['start']);
    // Find first unbilled day
    if (empty($after) || $membership_start > strtotime($after)) {
        // Membership started on or after first billable day
        // Start billing on membership start date
        $period_start = $membership_start;
    } else {
        // Membership started before first billable date
        // Find first billing period starting after $after
        $begin = strtotime($after . ' +1 day');
        $period_start = billing_period_start($membership, getdate($begin))['0'];
        $period_start = strtotime("+".$months." months", $period_start);
    }
    // Check for partial month and bill prorated
    $period_info = getdate($period_start);
    if (billing_days_remaining($membership, $period_info) != billing_days_in_period ($membership, $period_info)) {
        if($membership['plan']['prorate']==1){
            // Parital month, prorate $membership, $date_info, $price
            $prorated = billing_prorate($membership, $period_info, $price);
        } else {
            // Parital month, no prorate
            $prorated = $price;
        }
        $payment = array(
            'date' => date('Y-m-d', $period_start)
            , 'description' => 'Dues: ' . $membership['plan']['name']
            , 'code' => $prorated['code']
            , 'value' => $prorated['value']
            , 'credit_cid' => $membership['cid']
            , 'method' => 'cash'
        );
        payment_save($payment);
        // Advance to beginning of first full period
        $days = billing_days_remaining($membership, $period_info);
        $period_start = strtotime("+$days days", $period_start);
    }
    // Bill each full billing period
    while ($period_start < $until_date) {
        $payment = array(
            'date' => date('Y-m-d', $period_start)
            , 'description' => 'Dues: ' . $membership['plan']['name']
            , 'code' => $price['code']
            , 'value' => $price['value']
            , 'credit_cid' => $membership['cid']
            , 'method' => 'cash'
        );
        payment_save($payment);
        // Advance to next billing period
        $period_start = strtotime("+$months month", $period_start);

    }
}

/**
 * Find the start of the current billing period.
 * $membership The membership info for the user.
 * $date_info A date, as returned by getdate().
 */
function billing_period_start ($membership, $date_info) {
    $months = $membership['plan']['months'];
    $date1 = new DateTime();
    $date2 = new DateTime();
    if($membership['plan']['baseday']=="0000-00-00"){
        $baseday=$membership['start'];
    } else {
        $baseday=$membership['plan']['baseday'];
    }
    $date1 -> setTimestamp(strtotime($baseday));
    $date2 -> setTimestamp($date_info['0']);
    $interval = date_diff($date1, $date2);
    $periods=(int)((($interval->y*12)+$interval->m)/$months);
    if ($periods==0) {
        $period_start=strtotime($baseday);
    } else {
        $period_start=strtotime("+".($periods*$months)." month", strtotime($baseday));
    }
    return getdate($period_start);
}

/**
 * Find the number of days left in a billing period.
 * $membership The membership info for the user.
 * $date_info A date, as returned by getdate().
 * $return The number of days remaining after $date_info.
 */
function billing_days_remaining ($membership, $date_info) {
    $months = $membership['plan']['months'];
    if($membership['plan']['baseday']=="0000-00-00"){
        //if baseday is 0000-00-00, set days remaining to the entire period
        $days = billing_days_in_period ($membership, $date_info);
    } else {
        $period_start = billing_period_start($membership, $date_info);
        $date1 = new DateTime();
        $date2 = new DateTime();
        $date1 -> setTimestamp($period_start['0']);
        $date2 -> setTimestamp($date_info['0']);
        $interval = date_diff($date1, $date2);
        $days=billing_days_in_period ($membership, $date_info)-$interval->format('%a');
    }
    if ($days < 0) {
        $days += billing_days_in_period ($membership, $date_info);
    }
    return $days;
}

/**
 * Return the number of days in the billing period.
 * $date_info A date, as returned by getdate().
 * $return The number of days in the same billing period as $date_info
 */
function billing_days_in_period ($membership, $date_info) {
    $days = 0;
    $months = $membership['plan']['months'];
    $period_start = billing_period_start($membership, $date_info);
    $period_end = strtotime("+".$months." month", $period_start['0']);
    $date1 = new DateTime();
    $date2 = new DateTime();
    $date1 -> setTimestamp($period_start['0']);
    $date2 -> setTimestamp($period_end);
    $interval = date_diff($date1, $date2);
    $days=$interval->format('%a');
    return $days;
}

/**
 * Calculate prorated billing amount.
error_log("fix all @param");
 * @param $period_info Billing start date (as returned by getdate()).
 * @param $day_of_period The day of month billing periods start on.
 * @param $price Price array representing a full billing period.
 */
function billing_prorate ($membership, $date_info, $price) {
    $due = $price['value'];
    $period = billing_days_in_period($membership, $date_info);
    if($membership['plan']['prorate']==1 && $membership['plan']['baseday']!="0000-00-00"){
        $fraction = (billing_days_remaining($membership, $date_info) + 1.0) / $period;
    } else {
        $fraction = 1;
    }
    $prorated = array(
        'code' => $price['code']
        , 'value' => ceil($due * $fraction)
    );
    return $prorated;
}

// Themes //////////////////////////////////////////////////////////////////////

/**
 * Return themed html for first month button.
 */
function theme_billing_first_month ($cid) {
    $contact = crm_get_one('contact', array('cid'=>$cid));
    // Calculate fraction of the billing period
    $mship = end($contact['member']['membership']);
    $date = getdate(strtotime($mship['start']));
    $period = billing_days_in_period($mship, $date);
    if($mship['plan']['prorate']==1 && $mship['plan']['baseday']!="0000-00-00"){
        $fraction = (billing_days_remaining($mship, $date) + 1.0) / $period;
        $html = "<fieldset><legend>First period's prorated dues</legend>";
    } else {
        $fraction = 1;
        $html = "<fieldset><legend>First period's dues</legend>";
    }
    // Get payment amount
    $due = payment_parse_currency($mship['plan']['price']);
    $due['value'] = ceil($due['value'] * $fraction);
    $params = array(
        'referenceId' => $cid
        , 'amount' => $due['code'] . ' ' . payment_format_currency($due, false)
        , 'amountPayPal' => payment_format_currency($due, false)
        , 'description' => 'Membership Dues Payment'
    );
    $amount = payment_format_currency($due);
    $html .= "<p><strong>First period's dues:</strong> $amount</p>";
    if ($due['value'] > 0) {
        if (function_exists('amazon_payment_revision')) {
            global $config_amazon_payment_access_key_id;
            global $config_amazon_payment_secret;
            if(!empty($config_amazon_payment_access_key_id)&&!empty($config_amazon_payment_secret)) {
                $html .= theme('amazon_payment_button', $cid, $params);
            }
        }
        if (function_exists('paypal_payment_revision')) {
            global $config_paypal_email;
            if(!empty($config_paypal_email)){
                $html .= theme('paypal_payment_button', $cid, $params);
            }
        }
    }
    $html .= '</fieldset>';
    return $html;
}

/**
 * Return an account summary and payment button.
 * @param $cid The cid of the contact to create a form for.
 * @return An html string for the summary and button.
 */
function theme_billing_account_info ($cid) {
    $balances = payment_accounts(array('cid'=>$cid));
    $balance = $balances[$cid];
    $params = array(
        'referenceId' => $cid
        , 'amount' => $balance['code'] . ' ' . payment_format_currency($balance, false) 
        , 'amountPayPal' => payment_format_currency($balance, false)
        , 'description' => 'Membership Dues Payment'
    );
    $output = '<div>';
    $amount = payment_format_currency($balance);
    if ($balance['value'] > 0) {
        $output .= "<p><strong>Outstanding balance:</strong> $amount</p>";
        if (function_exists('amazon_payment_revision')) {
            global $config_amazon_payment_access_key_id;
            global $config_amazon_payment_secret;
            if(!empty($config_amazon_payment_access_key_id)&&!empty($config_amazon_payment_secret)) {
                $output .= theme('amazon_payment_button', $cid, $params);
            }
        }
        if (function_exists('paypal_payment_revision')) {
            global $config_paypal_email;
            if(!empty($config_paypal_email)){
                $output .= theme('paypal_payment_button', $cid, $params);
            }
        }
    } else {
        $balance['value'] = -1*$balance['value'];
        $amount = payment_format_currency($balance);
        $output .= "<p><strong>No balance owed.  Account credit:</strong> $amount</p>";
    }
    $output .= '</div>';
    return $output;
}
