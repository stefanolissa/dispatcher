<?php

namespace Satollo\Dispatcher;

defined('ABSPATH') || exit;

class WPUsersAudience {

    function get_id() {
        return 'dsp-wp-users';
    }

    function get_name() {
        return 'WP Users';
    }

    /**
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
     * @return Contact[]
     */
    function get_contacts($audience, $last_contact_id, $max) {
        global $wpdb;

        Logger::log('audience', Logger::DEBUG, __METHOD__, $audience, 'Last contact ID:', $last_contact_id, 'Max:', $max);

        $roles = $audience->settings['roles'] ?? [];
        $contacts = [];
        $contact_id = $last_contact_id;
        $date_start = empty($audience->settings['date_start']) ? false : new \DateTime($audience->settings['date_start']);
        $date_end = empty($audience->settings['date_end']) ? false : new \DateTime($audience->settings['date_end']);
        error_log(print_r($date_start, true));
        while (count($contacts) < $max) {
            $db_users = $wpdb->get_results($wpdb->prepare("select ID from {$wpdb->users} where id > %d order by id limit %d", $contact_id, $max));
            error_log(count($db_users));
            if (empty($db_users)) {
                break;
            }

            $contact_id = $db_users[count($db_users) - 1]->ID;

            //Logger::log('audience', Logger::DEBUG, __METHOD__, 'Extracted:', count($db_users));

            foreach ($db_users as $db_user) {

                $user = new \WP_User($db_user->ID);
                //Logger::log('audience', Logger::DEBUG, $db_user->ID, $user->roles);
                if (!empty($roles) && empty(array_intersect($user->roles, $roles))) {
                    continue;
                }

                $registered = new \DateTime($user->user_registered);
                error_log(print_r($registered, true));

                if ($date_start && $registered < $date_start) {
                    continue;
                }

                if ($date_end && $registered > $date_end) {
                    continue;
                }

                $contact = new \stdClass();
                $contact->id = $user->ID;
                $contact->email = $user->user_email;
                $contact->created = $registered;
                $contacts[] = $contact;
            }
        }
        Logger::log('audience', Logger::DEBUG, __METHOD__, 'Contacts:', count($contacts));
        return $contacts;
    }

    function get_settings_schema(): array {
        return [];
    }

    function settings_form($controls) {
        echo '<p>Empty fields are not used to filter users</p>';
        echo '<table class="form-table">';

        echo '<tr><th>Registered</th><td>';
        echo '<input type="date" name="settings[date_start]" value="', esc_attr($controls->settings['date_start'] ?? ''), '">';
        echo ' - ';
        echo '<input type="date" name="settings[date_end]" value="', esc_attr($controls->settings['date_end'] ?? ''), '">';
        echo '</td></tr>';
        echo '<tr><th>Roles</th><td>';
        $roles = $controls->settings['roles'] ?? [];
        foreach (wp_roles()->roles as $key => $wp_role) {
            echo '<label><input type="checkbox" name="settings[roles][]" value="', esc_attr($key), '" ',
            in_array($key, $roles) ? 'checked' : '',
            '> ', esc_html($wp_role['name']), '</label><br>';
        }
        echo '</td></tr>';
        echo '</table>';
    }
}

add_action('dispatcher_audiences_init', function () {
    dispatcher_register_audience_type(new WPUsersAudience());
});
