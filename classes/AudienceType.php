<?php
namespace Satollo\Dispatcher;

defined('ABSPATH') || exit;

/**
 * A type of audience. Method level compatibile objects can be provided by
 * third-parties using the registration procedure.
 */
class AudienceType {

    /**
     * Unique identifier, use prefixed string. Will be sanitized with sanitize_key().
     */
    public function get_id(): string {

    }

    public function get_name(): string {

    }

//    public function get_settings_schema(): array {
//
//    }

    public function get_contacts($audience): array {

    }

    /**
     * Optional.
     * @param type $audience
     */
    public function get_total($audience): int {

    }

    /**
     * Optional.
     * @param Controls $controls
     */
    public function settings_form($controls) {
        $controls->settings; // The audience settings
    }
}