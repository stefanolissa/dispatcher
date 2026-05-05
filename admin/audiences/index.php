<?php

namespace Satollo\Dispatcher;

defined('ABSPATH') || exit;

/** @var \wpdb $wpdb */
global $wpdb;

$subpage = $_GET['subpage'] ?? '';

switch ($subpage) {
    case 'edit':
        include __DIR__ . '/edit.php';
        return;
    case 'view':
        include __DIR__ . '/view.php';
        return;
}

$post = wp_unslash($_POST);
$type = sanitize_key($post['type'] ?? '');

// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- not necessary
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    check_admin_referer('dispatcher-action');

    if (isset($_POST['new'])) {
        if (empty($type)) {
            die('Audience type not selected');
        }
        $wpdb->insert($wpdb->prefix . 'dispatcher_audiences', ['type' => $type, 'name' => 'New Audience of type ' . $type, 'settings' => serialize([])]);
    }
}

$action = sanitize_key($_GET['action'] ?? '');

if ($action === 'trash') {
    check_admin_referer('trash');
    //$wpdb->delete($wpdb->prefix . 'audiences', ['id' => (int)$_GET['id']]);
    $wpdb->update($wpdb->prefix . 'dispatcher_audiences', ['deleted' => 1], ['id' => (int) $_GET['id']]);
    echo '<script>location.href="?page=dispatcher-audiences";</script>';
    return;
}

if ($action === 'delete') {
    check_admin_referer('delete');
    $wpdb->delete($wpdb->prefix . 'dispatcher_audiences', ['deleted' => 1, 'id' => (int) $_GET['id']]);
    echo '<script>location.href="?page=dispatcher-audiences";</script>';
    return;
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
            'id' => '#',
            'type' => 'Type',
            'name' => 'Name',
            'actions' => 'Actions'
        ];
        return $columns;
    }

        public function column_default($item, $column_name) {
        switch ($column_name) {
            case 'id':
                return $item->id;
            case 'name':
                return '<a href="admin.php?page=dispatcher-audiences&subpage=edit&type=' . esc_attr($item->type) . '&id=' . $item->id . '">' . esc_html($item->name) . '</a>';
            case 'type':
                return esc_html($item->type);
            case 'actions':
                if ($item->deleted) {
                    return '<a href="' . wp_nonce_url('admin.php?page=dispatcher-audiences&action=delete&id=' . $item->id, 'delete') . '">Delete</a>';
                } else {
                    return '<a href="' . wp_nonce_url('admin.php?page=dispatcher-audiences&action=trash&id=' . $item->id, 'trash') . '">Trash</a>';
                }
            default:
                return '?';
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
            $total_items = (int) $wpdb->get_var($wpdb->prepare("select count(*) from {$wpdb->prefix}dispatcher_audiences where deleted=0 and type=%s", $type));
        } else {
            $total_items = (int) $wpdb->get_var("select count(*) from {$wpdb->prefix}dispatcher_audiences where deleted=0");
        }

        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page' => $per_page,
        ]);

        if ($this->type) {
            $this->items = $wpdb->get_results($wpdb->prepare("select * from {$wpdb->prefix}dispatcher_audiences where deleted=0 and type=%s order by id desc limit %d offset %d",
                            $this->type, $per_page, ($current_page - 1) * $per_page));
        } else {
            $this->items = $wpdb->get_results($wpdb->prepare("select * from {$wpdb->prefix}dispatcher_audiences where deleted=0 order by id desc limit %d offset %d",
                            $per_page, ($current_page - 1) * $per_page));
        }
    }


}

$table = new List_Table($type);
$table->prepare_items();

$audience_types = Dispatcher::get_audience_types();
?>
<style>
    .column-id {
        width: 3rem;
    }
    .column-type {
        width: 5rem;
    }
</style>
<div class="wrap">
    <h2>Audiences</h2>
    <?php //include __DIR__ . '/nav.php';   ?>


    <form method="post">
        <?php wp_nonce_field('dispatcher-action'); ?>
        <select name="type" onchange="this.form.submit()">
            <option value="">All</option>
            <?php foreach($audience_types as $id => $at) { ?>
            <option value="<?= esc_attr($id) ?>" <?= $id == $type ? 'selected' : '' ?>><?= esc_html($at->get_name()) ?></option>
            <?php } ?>
        </select>
        <?php if ($type) { ?>
            <button name="new" class="button button-secondary">Add new</button>
        <?php } ?>

    </form>

    <?php $table->display(); ?>
</div>