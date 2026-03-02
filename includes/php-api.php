<?php

// No namespace, here, never!

defined('ABSPATH') || exit;

function dispatcher_register_audience($audience) {
    return Dispatcher::register_audience($audience);
}
