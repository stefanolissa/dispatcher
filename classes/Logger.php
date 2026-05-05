<?php

namespace Satollo\Dispatcher;

class Logger {

    const DEBUG = 0;
    const INFO = 1;
    const ERROR = 2;

    static $level = 0;

    static function log($type, $level, ...$parts) {
        global $wpdb;
        $message = implode(' ',
                array_map(function ($item) {
                    if (!is_scalar($item)) {
                        return json_encode($item);
                    } else {
                        if (is_bool($item)) {
                            return $item ? 'true' : 'false';
                        }
                        return $item;
                    }
                }, $parts));

        $wpdb->insert($wpdb->prefix . 'dispatcher_logs', ['type' => $type, 'level' => $level, 'message' => $message]);
    }
}
