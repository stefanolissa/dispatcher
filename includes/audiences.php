<?php

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
        $users = $wpdb->get_results($wpdb->prepare("select ID, user_email from {$wpdb->users} where id > %d limit %d", 0, $params->max));
        foreach ($users as $user) {
            $contact = new stdClass();
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

    function form($params) {
        echo 'No settings needed';
    }
}

class DispactcheAudienceRoles {

    function get_id() {
        return 'dsp-roles';
    }

    function get_name() {
        return 'WP users by roles';
    }

    function get_contacts($params) {
        global $wpdb;
        $roles = $params->settings['roles'] ?? [];
        $contacts = [];
        while (count($contacts) < $params->max) {
            $db_users = $wpdb->get_results($wpdb->prepare("select ID from {$wpdb->users} where id > %d limit %d", $params->last_contact_id, $params->max));
            if (empty($db_users)) {
                return $contacts;
            }
            foreach ($db_users as $db_user) {

                $user = new WP_User($db_user->ID);
                if (empty(array_intersect($user->roles, $roles))) {
                    continue;
                }
                $contact = new stdClass();
                $contact->id = $user->ID;
                $contact->email = $user->user_email;
                $contacts[] = $contact;
            }
        }
        return $contacts;
    }

    /**
     * Only a per-excess estimate
     *
     * @global wpdb $wpdb
     * @param stdClass $params
     * @return int
     */
    function get_total($params) {
        global $wpdb;
        return $wpdb->get_var("select count(*) from {$wpdb->users}");
    }

    function form($params) {
        var_dump($params);
        $roles = $params->settings['roles'] ?? [];
        foreach (wp_roles()->roles as $key => $wp_role) {
            echo '<label><input type="checkbox" name="audience_data[roles][]" value="', esc_attr($key), '" ',
            in_array($key, $roles) ? 'checked' : '',
            '> ', esc_html($wp_role['name']), '</label><br>';
        }
    }
}

add_action('dispatcher_audiences_init', function () {
    dispatcher_register_audience(new DispatcherAudienceAll());
    dispatcher_register_audience(new DispactcheAudienceRoles());
});
