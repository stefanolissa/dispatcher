<?php

namespace Satollo\Dispatcher;

defined('ABSPATH') || exit;

/** @var WP_Post $post - Externally provided */
$audience_id = sanitize_key(Dispatcher::get_post_meta($post->ID, 'audience_id'));
$status = Dispatcher::get_post_meta($post->ID, 'status');
$audiences = Dispatcher::get_audiences();
?>
<div id="dispatcher-audience-form">
    <select name="audience_id" id="audience_id" <?php echo $status === 'sending'?'disabled':''; ?>>
        <?php
        foreach ($audiences as $audience) {
            echo '<option value="', esc_attr($audience->id), '" ', ($audience_id == $audience->id ? 'selected' : ''), '>', esc_html($audience->name), '</option>';
        }
        ?>
    </select>

</div>

