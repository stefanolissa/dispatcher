<?php
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

    /**
     * @param DispatcherAudienceParams $params
     */
    function form($params) {
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
    function get_target_description($params = null) {
        if (is_null($params)) {
            return '';
        }
        return implode(', ', $params->settings['roles']);
    }

    /**
     *
     * @global wpdb $wpdb
     * @param DispatcherAudienceParams $params
     * @return DispatcherContact[]
     */
    function get_contacts($params) {
        global $wpdb;
        $roles = $params->settings['roles'] ?? [];
        $contacts = [];
        $contact_id = $params->last_contact_id;
        while (count($contacts) < $params->max) {
            $db_users = $wpdb->get_results($wpdb->prepare("select ID from {$wpdb->users} where id > %d order by id limit %d", $contact_id, $params->max));
            if (empty($db_users)) {
                return $contacts;
            }

            $contact_id = $db_users[count($db_users) - 1]->ID;

            Dispatcher::log(__METHOD__, 'Extracted', count($db_users));

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
     * @param DispatcherAudienceParams $params
     */
    function form($params) {
        $roles = $params->settings['roles'] ?? [];
        foreach (wp_roles()->roles as $key => $wp_role) {
            echo '<label><input type="checkbox" name="audience_data[roles][]" value="', esc_attr($key), '" ',
            in_array($key, $roles) ? 'checked' : '',
            '> ', esc_html($wp_role['name']), '</label><br>';
        }
    }
}

class DispatcherAudienceWoocommerce {

    function get_id() {
        return 'dsp-wc';
    }

    function get_name() {
        return 'Woocommerce customers';
    }

    function get_target_description($params = null) {
        if (is_null($params)) {
            return '';
        }
        $days = $params->settings['days'] ?? 0;
        if ($days) {
            return 'Customers with an order in the last ' . $days . ' days';
        } else {
            return 'Customers with at least one order';
        }
    }

    function get_contacts($params) {
        global $wpdb;

        $contacts = [];
        $us = $wpdb->get_results($wpdb->prepare("select ID as id, user_email as email from {$wpdb->users} where id > %d order by id limit %d", $params->last_contact_id, $params->max));
        Dispatcher::log('Extracted', count($us), 'users');
        if (!function_exists('wc_get_orders')) {
            Dispatcher::log('wc_get_orders() does not exists');
            return [];
        }
        $start_time = 0;
        if (!empty($params->settings['days'])) {
            $start_time = time() - ((int) $this->settings['days'] * DAY_IN_SECONDS);
        }

        foreach ($us as $u) {
            Dispatcher::log('Processing user', $u->id);
            $orders = wc_get_orders([
                'customer' => $u->id,
                'date_created' => '>=' . $start_time,
                'status' => ['wc-completed', 'wc-processing'], // any
                'limit' => 1
            ]);

            if (empty($orders)) {
                Dispatcher::log('No orders found');
                continue;
            }

            $contacts[] = $u;
        }

        return $contacts;
    }

    /**
     * @param DispatcherAudienceParams $params
     */
    function form($params) {
        $days = $params->settings['days'] ?? 0;
        ?>
        With an order in the last<br>
        <select name="audience_data[days]">
            <option value="0" <?= $days == 0 ? 'selected' : '' ?>>No filter</option>
            <option value="30" <?= $days == 30 ? 'selected' : '' ?>>Last 30 days</option>
            <option value="60" <?= $days == 60 ? 'selected' : '' ?>>Last 60 days</option>
            <option value="90" <?= $days == 90 ? 'selected' : '' ?>>Last 90 days</option>
            <option value="180" <?= $days == 365 ? 'selected' : '' ?>>Last 180 days</option>
            <option value="365" <?= $days == 365 ? 'selected' : '' ?>>Last 365 days</option>
        </select>
        <?php
    }
}

add_action('dispatcher_audiences_init', function () {
    dispatcher_register_audience(new DispatcherAudienceAll());
    dispatcher_register_audience(new DispatcherAudienceRoles());
    dispatcher_register_audience(new DispatcherAudienceWoocommerce());
});
