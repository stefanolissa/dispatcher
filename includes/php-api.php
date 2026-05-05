<?php

// No namespace, here, never!

defined('ABSPATH') || exit;

function dispatcher_register_audience_type($audience_type) {
    return \Satollo\Dispatcher\Dispatcher::register_audience_type($audience_type);
}
