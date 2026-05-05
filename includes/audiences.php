<?php

namespace Satollo\Dispatcher;

defined('ABSPATH') || exit;

/**
 * Only for code completion. Do not use.
 */
class DispatcherAudienceParams {

    var $last_contact_id;
    var $max;

    /** @var array */
    var $settings;
}

/**
 * Only for code completion. Do not use.
 */
class DispatcherContact {

    var $id;
    var $email;
}

class DispatcherAudienceAll {

    function get_id() {
        return 'dsp-all';
    }

    function get_name() {
        return 'All WP users';
    }

    function get_contacts($params) {
        global $wpdb;
        $contacts = [];
        $users = $wpdb->get_results($wpdb->prepare("select ID, user_email from {$wpdb->users} where id > %d order by id limit %d", $params->last_contact_id, $params->max));
        foreach ($users as $user) {
            $contact = new \stdClass();
            $contact->id = $user->ID;
            $contact->email = $user->user_email;
            $contacts[] = $contact;
        }
        return $contacts;
    }

    function get_total($params) {
        global $wpdb;
        return $wpdb->get_var("select count(*) from {$wpdb->users}");
    }

    /**
     * @param DispatcherAudienceParams $params
     */
    function form($params) {
        echo 'No settings needed';
    }

    function settings_form($controls) {
        echo 'No settings needed';
    }
}

class DispatcherAudienceRoles {

    function get_id() {
        return 'dsp-roles';
    }

    function get_name() {
        return 'WP users by roles';
    }

    /**
     *
     * @param DispatcherAudienceParams $params
     * @return string
     */
    function get_contacts_description($audience = null) {
        if (is_null($audience->settings)) {
            return '';
        }
        return implode(', ', $audience->settings['roles']);
    }

    /**
     *
     * @global wpdb $wpdb
     * @param DispatcherAudienceParams $params
     * @return DispatcherContact[]
     */
    function get_contacts($audience, $last_contact_id, $max) {
        global $wpdb;

        Logger::log('audience', Logger::DEBUG, __METHOD__, $audience, 'Last contact ID:', $last_contact_id, 'Max:', $max);

        $roles = $audience->settings['roles'] ?? [];
        $contacts = [];
        $contact_id = $last_contact_id;
        while (count($contacts) < $max) {
            $db_users = $wpdb->get_results($wpdb->prepare("select ID from {$wpdb->users} where id > %d order by id limit %d", $contact_id, $max));
            if (empty($db_users)) {
                break;
            }

            $contact_id = $db_users[count($db_users) - 1]->ID;

            //Logger::log('audience', Logger::DEBUG, __METHOD__, 'Extracted:', count($db_users));

            foreach ($db_users as $db_user) {

                $user = new \WP_User($db_user->ID);
                //Logger::log('audience', Logger::DEBUG, $db_user->ID, $user->roles);
                if (empty(array_intersect($user->roles, $roles))) {
                    continue;
                }
                $contact = new \stdClass();
                $contact->id = $user->ID;
                $contact->email = $user->user_email;
                $contacts[] = $contact;
            }
        }
        Logger::log('audience', Logger::DEBUG, __METHOD__, 'Contacts:', count($contacts));
        return $contacts;
    }

    function get_settings_schema() {

    }

    function settings_form($controls) {
        $roles = $controls->settings['roles'] ?? [];
        foreach (wp_roles()->roles as $key => $wp_role) {
            echo '<label><input type="checkbox" name="settings[roles][]" value="', esc_attr($key), '" ',
            in_array($key, $roles) ? 'checked' : '',
            '> ', esc_html($wp_role['name']), '</label><br>';
        }
    }
}

add_action('dispatcher_audiences_init', function () {
    dispatcher_register_audience_type(new DispatcherAudienceAll());
    dispatcher_register_audience_type(new DispatcherAudienceRoles());
});
