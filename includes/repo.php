<?php

defined('ABSPATH') || exit;

add_filter('update_plugins_satollo-dispatcher', function ($update, $plugin_data, $plugin_file, $locales) {
    $data = get_opion('dispatcher_update_data');
    if ($data && $data->updated < time() - WEEK_IN_SECONDS || isset($_GET['force-check'])) {
        $data = false;
    }

    if (!$data) {
        $response = wp_remote_get('https://www.satollo.net/repo/dispatcher/plugin.json');
        $data = json_decode(wp_remote_retrieve_body($response));
        if (is_object($data)) {
            $data->updated = time();
            update_option('dispatcher_update_data', $data, false);
        }
    }

    if (isset($data->version)) {

        $update = [
            'version' => $data->version,
            'slug' => 'dispatcher',
            'url' => 'https://www.satollo.net/plugins/dispatcher',
            'package' => 'https://www.satollo.net/repo/dispatcher/dispatcher.zip',
            'banners' => [
                'low' => 'https://www.satollo.net/repo/dispatcher/banner.png',
                'high' => 'https://www.satollo.net/repo/dispatcher/banner.png'
            ],
            'icons' => [
                '1x' => 'https://www.satollo.net/repo/dispatcher/icon.png',
                '2x' => 'https://www.satollo.net/repo/dispatcher/icon.png'
            ]
        ];
        return $update;
    } else {
        return false;
    }
}, 0, 4);

function dispatcher_render_markdown($text) {
    $text = preg_replace('/^### (.*$)/m', '<h4>$1</h4>', $text);
    $text = preg_replace('/^## (.*$)/m', '<h3>$1</h3>', $text);
    $text = preg_replace('/^# (.*$)/m', '', $text);
    $text = preg_replace('/^- (.*$)/m', '- $1<br>', $text);
    $text = preg_replace('/\*\*(.*?)\*\*/m', '<strong>$1</strong>', $text);
    $text = preg_replace('/`(.*?)`/m', '<code>$1</code>', $text);
    $text = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2" target="_blank">$1</a>', $text);
    $text = wpautop($text, false);
    $text = wp_kses_post($text);
    return $text;
}

add_filter('plugins_api', function ($res, $action, $args) {
    if ($action !== 'plugin_information' || $args->slug !== 'dispatcher') {
        return $res;
    }

    $response = wp_remote_get('https://www.satollo.net/repo/dispatcher/CHANGELOG.md');
    $changelog = '';
    if (wp_remote_retrieve_response_code($response) == '200') {
        $changelog = wp_remote_retrieve_body($response);
        $changelog = dispatcher_render_markdown($changelog);
    }

    $response = wp_remote_get('https://www.satollo.net/repo/dispatcher/README.md');
    $readme = '';
    if (wp_remote_retrieve_response_code($response) == '200') {
        $readme = wp_remote_retrieve_body($response);
        $readme = dispatcher_render_markdown($readme);
    }

    $res = new stdClass();
    $res->name = 'Dispatcher';
    $res->slug = 'dispatcher';
    $res->version = DISPATCHER_VERSION;
    $res->author = '<a href="https://www.satollo.net">Stefano Lissa</a>';
    $res->homepage = 'https://www.satollo.net/plugins/dispatcher';
    $res->download_link = 'https://www.satollo.net/repo/dispatcher/dispatcher.zip';

    $res->sections = array(
        'description' => $readme,
        'changelog' => $changelog
    );

    $res->banners = [
        'low' => 'https://www.satollo.net/repo/dispatcher/banner.png',
        'high' => 'https://www.satollo.net/repo/dispatcher/banner.png'
    ];

    $res->icons = [
        '1x' => 'https://www.satollo.net/repo/dispatcher/icon.png',
        '2x' => 'https://www.satollo.net/repo/dispatcher/icon.png'
    ];

    return $res;
}, 20, 3);
