<?php

/*
    Copyright 2009-2014 Edward L. Platt <ed@elplatt.com>
    
    This file is part of the Seltzer CRM Project
    radius.inc.php - Radius module

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
function freeradius_revision () {
    return 1;
}

/**
 * @return An array of the permissions provided by this module.
 */
function freeradius_permissions () {
    return array();
}

/**
 * Install or upgrade this module.
 * @param $old_revision The last installed revision of this module, or 0 if the
 *   module has never been installed.
 */
function freeradius_install($old_revision = 0) {
    // Create initial database table
    if ($old_revision < 1) {
        // TODO
    }
}

// DB to Object mapping ////////////////////////////////////////////////////////

// Table data structures ///////////////////////////////////////////////////////

// Forms ///////////////////////////////////////////////////////////////////////

/**
 * Form for initiating membership radiuss.
 * @return The form structure.
 */
function freeradius_form () {

    $freeradius_date = variable_get('freeradius_last_date', '');
    $freeradius_label = empty($freeradius_date) ? 'never' : $freeradius_date;
    
    // Create form structure
    $form = array(
        'type' => 'form'
        , 'method' => 'post'
        , 'command' => 'freeradius'
        , 'fields' => array(
            array(
                'type' => 'fieldset'
                , 'label' => 'Update Freeradius Users'
                , 'fields' => array(
                    array(
                        'type' => 'message',
                        'value' => 'This will generate Freeradius user entries for any members with active memberships and remove entries for users without active membership.'
                    ),
                    array(
                        'type' => 'readonly',
                        'class' => 'date',
                        'label' => 'Last Updated',
                        'name' => 'last_updated',
                        'value' => $freeradius_label
                    ),
                    array(
                        'type' => 'submit'
                        , 'value' => 'Update Users'
                    )
                )
            )
        )
    );
    
    return $form;
}

// Command handlers ////////////////////////////////////////////////////////////

/**
 * Run radiuss
 */
function command_freeradius () {
    // Get current date
    $today = date('Y-m-d');
    $filter = array();
    $user_data = crm_get_data('user', array('filter' => $filter));
    foreach ($user_data as $user) {
        $membership = crm_get_data('member_membership', array('cid' => $user['cid']));
        if (!empty($user['hash'])) {
            if (!isset($membership['0'])) {
                freeradius_del_member($user);
            } elseif (!empty($membership['end']) && strtotime($membership['end']) > strtotime($today)) {
                freeradius_del_member($user);
            } else {
                freeradius_add_member($user);
            }
        } else {
            freeradius_del_member($user);
        }
    }
    variable_set('freeradius_last_date', $today);
    message_register("Freeradius users updated.");
    return crm_url('members');
}

// Pages ///////////////////////////////////////////////////////////////////////

/**
 * @return An array of pages provided by this module.
 */
function freeradius_page_list () {
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
function freeradius_page (&$page_data, $page_name, $options) {
    switch ($page_name) {
        case 'members':
            // Add view and add tabs
            if (user_access('user_edit')) {
                page_add_content_top($page_data, theme('form', crm_get_form('freeradius')), 'Freeradius');
            }
            break;
    }
}

// Utility functions ///////////////////////////////////////////////////////////

/**
 * Run radius for a single membership.
 * @param $membership The membership to bill.
 lling_bill_membership*/
function freeradius_add_member ($user) {
    global $config_password_hash_save;
    global $config_radius_host;
    global $config_radius_user;
    global $config_radius_password;
    global $config_radius_db;

    try {
        $db = new PDO('mysql:dbname='.$config_radius_db.';host='.$config_radius_host, $config_radius_user, $config_radius_password);
    } catch (PDOException $ex) {
        die(mysql_error());
    }

// http://www.linuxlasse.net/linux/howtos/Freeradius_and_MySQL
    switch ($user['hashtype']) {
        case "MD5":
            $hashtype = "MD5-Password";
            break;
        case "SMD5":
            $hashtype = "SMD5-Password";
            break;
        case "SHA":
            $hashtype = "SHA-Password";
            break;
        case "SSHA":
            $hashtype = "SSHA-Password";
            break;
        default:
            freeradius_del_member($user);
            return(1);
    }

    $esc_cid = mysql_real_escape_string($user['cid']);
    $esc_user = mysql_real_escape_string($user['username']);
    $esc_hash = mysql_real_escape_string($user['hash']);
    $esc_hashtype = mysql_real_escape_string($hashtype);

    try {
        $stmt = $db->prepare("INSERT INTO radcheck (id, username, attribute, op, value)
            VALUES (:id, :username, :attribute, ':=', :value)
            ON DUPLICATE KEY UPDATE `username`=VALUES(`username`),
            `attribute`=VALUES(`attribute`), `op`=VALUES(`op`), `value`=VALUES(`value`)");
        $stmt->bindParam(':id', $esc_cid);
        $stmt->bindParam(':username', $esc_user);
        $stmt->bindParam(':attribute', $esc_hashtype);
        $stmt->bindParam(':value', $esc_hash);
        $stmt->execute();
    } catch (PDOException $ex) {
        die(mysql_error());
    }
}

function freeradius_del_member ($user) {
    global $config_radius_host;
    global $config_radius_user;
    global $config_radius_password;
    global $config_radius_db;

    try {
        $db = new PDO('mysql:dbname='.$config_radius_db.';host='.$config_radius_host, $config_radius_user, $config_radius_password);
    } catch (PDOException $ex) {
        die(mysql_error());
    }

    $esc_cid = mysql_real_escape_string($user['cid']);
    try {
        $stmt = $db->prepare("DELETE FROM `radcheck` WHERE `id`=:id");
        $stmt->bindParam(':id', $esc_cid);
        $stmt->execute();
    } catch (PDOException $ex) {
        die(mysql_error());
    }
}

// Themes //////////////////////////////////////////////////////////////////////

/**
 * Return themed html for first month button.
 */
function theme_freeradius_first_month ($cid) {
    $contact = crm_get_one('contact', array('cid'=>$cid));
    // Calculate fraction of the radius period
    $mship = end($contact['member']['membership']);
    $date = getdate(strtotime($mship['start']));
    if($mship['plan']['prorate']==1 && $mship['plan']['baseday']!="0000-00-00"){
        $html = "<fieldset><legend>First period's prorated dues</legend>";
    } else {
        $html = "<fieldset><legend>First period's dues</legend>";
    }
    // Get payment amount
    $html .= '</fieldset>';
    return $html;
}

/**
 * Return an account summary and payment button.
 * @param $cid The cid of the contact to create a form for.
 * @return An html string for the summary and button.
 */
function theme_radius_account_info ($cid) {
    $balances = payment_accounts(array('cid'=>$cid));
    $balance = $balances[$cid];
    $output = '<div>';
    $output .= "<p><strong>No balance owed.  Account credit:</strong></p>";
    $output .= '</div>';
    return $output;
}
