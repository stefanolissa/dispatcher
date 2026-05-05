<?php

namespace Satollo\Dispatcher;

/**
 * Description of Engine
 *
 * @author satol
 */
class Engine {

    /**
     *
     * @global wpdb $wpdb
     */
    static function run() {
        global $wpdb;

        Logger::log('engine', Logger::INFO, 'Start');

        if (!self::set_lock()) {
            Logger::log('engine', Logger::INFO, 'Lock active,. End.');
            return;
        }

        // Extract the emails to be sent
        // any – retrieves any status except for ‘inherit’, ‘trash’ and ‘auto-draft’.
        // Custom post statuses with ‘exclude_from_search’ set to true are also excluded.
        $emails = get_posts(['post_type' => POST_TYPE, 'post_status' => 'any', 'posts_per_page' => 1,
            'meta_query' => [
                [
                    'key' => 'dispatcher_status',
                    'value' => 'sending',
                    'compare' => '=’',
                ]],
            'meta_key' => 'dispatcher_send_time', 'orderby' => 'meta_value_num', 'order' => 'ASC']);

        if (!$emails) {
            self::reset_lock();
            Logger::log('engine', Logger::INFO, 'No emails to send. End.');
            return;
        }

        $speed = self::$settings['speed'] ?? 100;
        $max = intdiv($speed, 4);
        $delay = self::$settings['delay'] ?? 0;
        $dry_run = isset(Dispatcher::$settings['dry_run']);

        Logger::log('engine', Logger::DEBUG, 'Speed:', $speed, 'Delay:', $delay, 'Max per run:', $max, 'Dry run:', $dry_run);
        // TODO: select the older or order the previous query by a meta value "dispatcher_send_time"
        $email = $emails[0];

        Logger::log('engine', Logger::DEBUG, 'Processing:', $email->post_title);

        $count = intval(Dispatcher::get_post_meta($email->ID, 'sent'));

        $audience = Dispatcher::get_email_audience($email);
        if (!$audience) {
            self::reset_lock();
            Dispatcher::update_post_meta($email->ID, 'status', 'sent');
            Logger::log('engine', Logger::INFO, __METHOD__, 'Missing audience for email', $email->ID);
            Logger::log('engine', Logger::INFO, __METHOD__, 'Set completed');
            Logger::log('engine', Logger::INFO, __METHOD__, 'End');
            return;
        }

        $audience_type = Dispatcher::get_audience_type($audience->type);

        if (!$audience_type) {
            self::reset_lock();
            Logger::log('engine', Logger::ERROR, 'No audience type for email', $email->ID);
            Dispatcher::update_post_meta($email->ID, 'status', 'sent');
            Logger::log('engine', Logger::INFO, 'Sending completed');
            Logger::log('engine', Logger::INFO, 'End');
            return;
        }

        $last_contact_id = sanitize_key(Dispatcher::get_post_meta($email->ID, 'last_contact_id'));

//        $audience_settings = self::get_post_meta($email->ID, 'audience_settings');
//        if (!is_array($audience_settings)) {
//            $audience_settings = [];
//        }

        Logger::log('engine', Logger::DEBUG, 'Last contact ID:', $last_contact_id);

        $contacts = $audience_type->get_contacts($audience, $last_contact_id, $max);

        Logger::log('engine', Logger::DEBUG, $contacts);

        if (!$contacts) {
            self::reset_lock();
            Dispatcher::update_post_meta($email->ID, 'status', 'sent');
            Logger::log('engine', Logger::INFO, 'No more contacts. Sending completed. End.');
            return;
        }

        Logger::log('engine', Logger::INFO, 'Contacts:', count($contacts));

        $headers = ['Content-Type: text/html'];
        if (Dispatcher::$settings['from_email'] ?? '') {
            $headers[] = 'From: ' . Dispatcher::$settings['from_email'];
        }

        $message = Dispatcher::render_message($email);

        foreach ($contacts as $contact) {
            self::update_lock();
            Dispatcher::update_post_meta($email->ID, 'last_contact_id', $contact->id);

            Logger::log('engine', Logger::DEBUG, 'Sending to', $contact->email);

//            if (Dispatcher::$settings['redirect_email'] ?? '') {
//                self::log(__METHOD__, 'Redirecting to ', self::$settings['redirect_email']);
//                $to = self::$settings['redirect_email'];
//            } else {
            $to = $contact->email;
//            }

            if ($dry_run) {
                //file_put_contents(__DIR__ . '/' . get_option('dispatcher_secret') . '.txt', $contact->email . ' [#' . $contact->id . ']' . "\n", FILE_APPEND);
                $result = true;
            } else {
                $result = wp_mail($to, $email->post_title, $message, $headers);
            }
            $count++;
            Dispatcher::update_post_meta($email->ID, 'sent', $count);

            if ($result) {
                Logger::log('engine', Logger::DEBUG, 'Sent to:', $contact->email);
            } else {
                Logger::log('engine', Logger::ERROR, 'Sending error:', $contact->email);
            }

            if ($delay) {
                usleep($delay * 1000);
            }
        }

//        if (isset(self::$settings['dry_run'])) {
//            file_put_contents(__DIR__ . '/' . get_option('dispatcher_secret') . '.txt', 'End batch' . "\n", FILE_APPEND);
//        }
        self::reset_lock();

        Logger::log('engine', Logger::INFO, 'End');
    }

    static function set_lock() {
        $lock = (int) get_option('dispatcher_lock', 0);
        if ($lock && time() - $lock < HOUR_IN_SECONDS) {
            return false;
        }
        update_option('dispatcher_lock', time(), false);
        return true;
    }

    static function update_lock() {
        update_option('dispatcher_lock', time());
    }

    static function reset_lock() {
        update_option('dispatcher_lock', 0, false);
    }
}
