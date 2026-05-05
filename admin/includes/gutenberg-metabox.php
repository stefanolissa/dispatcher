<?php

namespace Satollo\Dispatcher;

defined('ABSPATH') || exit;
?>
<script>
    function fn_dispatcher_action(act) {
        if (!confirm('Proceed?')) {
            return false;
        }
        const data = {
            action: 'dispatcher_gutenberg',
            _dispatchernonce: '<?= wp_create_nonce('save') ?>',
            act: act,
            post_id: wp.data.select('core/editor').getCurrentPostId()
        };

        jQuery.post(ajaxurl, data, function (response) {
            if (act === 'test') {
                alert(response);
            } else {
                jQuery('#dsp-status-meta-box').html(response);
            }
        }).fail(function () {
            alert('Server error happened.');
        }).always(function () {
        });
    }
</script>
<?php
wp_nonce_field('save', '_dispatchernonce');
echo '<div id="dsp-audience-meta-box" style="margin-bottom: 1rem">';
require_once __DIR__ . '/audience-metabox.php';
echo '</div>';

echo '<div id="dsp-status-meta-box">';
require_once __DIR__ . '/status-metabox.php';
echo '</div>';


