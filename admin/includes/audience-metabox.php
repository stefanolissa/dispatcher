
<script>
    jQuery(function () {
        jQuery('select#audience_id').on('change', function () {
            jQuery('#audience-settings').html('Loading...');
            this.disabled = true;
            const data = {
                action: 'dsp_audience_settings',
                audience_nonce: jQuery('#audience_nonce').val(),
                audience_id: this.value,
                post_id: jQuery('#audience_post_id').val()
            };
            jQuery.post(ajaxurl, data, function (response) {
                jQuery('#audience-settings').html(response);
                jQuery('select#audience_id').prop('disabled', false);
            }).fail(function () {
                alert('Server error happened.');
            }).always(function () {
            });
        });

        jQuery('select#audience_id').trigger('change');

        jQuery('#audience_total_reload').on('click', function () {
            let data = jQuery("#dsp-audience-form :input").serializeArray();
            data.push({name: 'action', value: 'dsp_audience_total'});
            jQuery.post(ajaxurl, data, function (response) {
                jQuery('#audience_total').html(response);
            }).fail(function () {
                alert('Server error happened.');
            }).always(function () {
            });
        });
    });
</script>

<?php
/** @var WP_Post $post - Externally provided */
Dispatcher::init_audiences();

$audience_id = sanitize_key(Dispatcher::get_post_meta($post->ID, 'audience_id'));
$total = (int) Dispatcher::get_post_meta($post->ID, 'audience_total');
$status = Dispatcher::get_post_meta($post->ID, 'status');
?>
<div id="dsp-audience-form">
    <?php wp_nonce_field('save', 'audience_nonce'); ?>
    <input type="hidden" name="audience_post_id" id="audience_post_id" value="<?php echo ((int) $post->ID); ?>">
    <select name="audience_id" id="audience_id" <?php echo $status == 'sending'?'disabled':''; ?>>
        <?php
        foreach (Dispatcher::$audiences as $id => $audience) {
            echo '<option value="', esc_attr($id), '" ', ($audience_id == $id ? 'selected' : ''), '>', esc_html($audience->get_name()), '</option>';
        }
        ?>
    </select>
    <div style="margin-top: .5rem">Total: <span id="audience_total"><?php echo (int) $total; ?></span> <span id="audience_total_reload" style="cursor: pointer">↺</span></div>

    <div id="audience-settings" style="margin-top: 1rem"></div>
</div>

