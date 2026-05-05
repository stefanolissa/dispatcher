<?php

namespace Satollo\Dispatcher;

defined('ABSPATH') || exit;

/** @var \wpdb $wpdb */
global $wpdb;

$post = wp_unslash($_POST);
$type = sanitize_key($post['type'] ?? '');

// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- not necessary
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    check_admin_referer('dispatcher-action', '_dispatchernonce');

    if (isset($_POST['clean'])) {
        $wpdb->query("truncate table {$wpdb->prefix}dispatcher_logs");
    }
}

class List_Table extends \WP_List_Table {

    var $type;

    public function __construct($type) {
        $this->type = $type;
        parent::__construct([
            'singular' => 'Audience',
            'plural' => 'Audiences',
            'ajax' => false,
        ]);
    }

    public function get_columns() {
        $columns = [
            'cb' => '<input type="checkbox">',
            'id' => '#',
            'type' => 'Type',
            'created' => 'Created',
            'message' => 'Message'
        ];
        return $columns;
    }

    public function column_default($item, $column_name) {
        switch ($column_name) {
            case 'id':
                return $item->id;
            case 'created':
                return esc_html($item->created);
            case 'type':
                return esc_html($item->type);
            case 'message':
                return esc_html($item->message);
            default:
                return '?';
        }
    }

    public function column_cb($item) {
        return '<input type="checkbox" name="log_ids[]" value="' . esc_attr($item->id) . '">';
    }

    public function get_bulk_actions() {
        return [
            'delete' => 'Delete',
        ];
    }

    public function process_bulk_action() {
        global $wpdb;
        // Handle bulk actions
        if ('delete' === $this->current_action() && !empty($_POST['log_ids'])) {
            check_admin_referer('dispatcher-action', '_dispatchernonce');
            global $wpdb;

            $ids = wp_parse_id_list($_POST['log_ids'] ?? []);
            $ids_placeholders = implode(',', array_fill(0, count($ids), '%d'));

            $wpdb->query($wpdb->prepare(
                            "DELETE FROM {$wpdb->prefix}dispatcher_logs WHERE id IN ($ids_placeholders)",
                            ...$ids
                    ));

//            wp_safe_remote_post(admin_url('admin-ajax.php'), [
//                'blocking' => false,
//            ]);
        }
    }

    function extra_tablenav( $which ) {
        if ( $which == "top" ) {
            ?>
            <div class="alignleft actions">
                <button type="submit" name="clean" class="button button-secondary">Clean</button>
            </div>
            <?php
        }
    }

    public function prepare_items() {
        global $wpdb;

        $columns = $this->get_columns();
        $hidden = [];
        $sortable = [];
        $this->_column_headers = [$columns, $hidden, $sortable];

        $per_page = 30;
        $current_page = $this->get_pagenum();
        if ($this->type) {
            $total_items = (int) $wpdb->get_var($wpdb->prepare("select count(*) from {$wpdb->prefix}dispatcher_logs where type=%s", $type));
        } else {
            $total_items = (int) $wpdb->get_var("select count(*) from {$wpdb->prefix}dispatcher_logs");
        }

        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page' => $per_page,
        ]);

        if ($this->type) {
            $this->items = $wpdb->get_results($wpdb->prepare("select * from {$wpdb->prefix}dispatcher_logs where type=%s order by id desc limit %d offset %d",
                            $this->type, $per_page, ($current_page - 1) * $per_page));
        } else {
            $this->items = $wpdb->get_results($wpdb->prepare("select * from {$wpdb->prefix}dispatcher_logs order by id desc limit %d offset %d",
                            $per_page, ($current_page - 1) * $per_page));
        }
    }
}

$table = new List_Table($type);
$table->process_bulk_action();
$table->prepare_items();

$log_types = ['engine' => 'Engine'];
?>
<style>
    .column-id {
        width: 3rem;
    }
    .column-type {
        width: 5rem;
    }
    .column-created {
        width: 12rem;
    }
</style>
<div class="wrap">
    <h2>Logs</h2>


    <form method="post">
        <?php wp_nonce_field('dispatcher-action', '_dispatchernonce'); ?>
        <select name="type" onchange="this.form.submit()">
            <option value="">All</option>
            <?php foreach ($log_types as $id => $name) { ?>
                <option value="<?= esc_attr($id) ?>" <?= $id == $type ? 'selected' : '' ?>><?= esc_html($name) ?></option>
            <?php } ?>
        </select>

        <?php $table->display(); ?>
    </form>


</div>