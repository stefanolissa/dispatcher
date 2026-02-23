<?php

class DispatcherAudience {

    var $id;
    var $name;
    var $settings = [];

    function __construct($id, $name, $settings = []) {
        $this->id = $id;
        $this->name = $name;
        $this->settings = $settings;
    }

    function get_contacts($data) {

    }

    function get_total($data) {

    }

    function form($params) {
        echo 'No settings needed';
    }
}

class DispactcheAudienceRoles extends DispatcherAudience {

    function form($params) {
        $roles = $params->settings['roles'] ?? [];
        foreach (wp_roles()->roles as $key => $wp_role) {
            echo '<label><input type="checkbox" name="audience_data[roles][]" value="', esc_attr($key), '" ',
            in_array($key, $roles) ? 'checked' : '',
            '> ', esc_html($wp_role['name']), '</label><br>';
        }
    }
}

add_action('dispatcher_audiences_init', function () {
    dispatcher_register_audience(new DispatcherAudience('dsp-all', 'All WP users'));
    dispatcher_register_audience(new DispactcheAudienceRoles('dsp-roles', 'WP users by roles'));
});
