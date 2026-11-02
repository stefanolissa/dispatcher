<?php

namespace Satollo\Dispatcher;

defined('ABSPATH') || exit;

/** @var \wpdb $wpdb */
global $wpdb;

$audience = Dispatcher::get_audience((int) $_GET['id']);

if (!$audience) {
    die('Audience not found');
}

$audience_type = Dispatcher::get_audience_type($audience->type);
if (!$audience_type) {
    die('Audience type not registered');
}

class xList_Table extends \WP_List_Table {

    public function __construct(public $audience_type, public $audience) {
        parent::__construct([
            'singular' => 'Contact',
            'plural' => 'Contacts',
            'ajax' => false,
        ]);
    }

    public function get_columns() {
        $columns = [
            'id' => '#',
            'email' => 'Email',
            'created' => 'Created',
        ];
        return $columns;
    }

    public function column_default($item, $column_name) {
        switch ($column_name) {
            case 'id':
                return $item->id;
            case 'email':
                return esc_html($item->email);
                case 'created':
                return esc_html($item->created->format('Y-m-d') ?? '');
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

        $total_items = 30;

        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page' => $per_page,
        ]);

        $this->items = $this->audience_type->get_contacts($this->audience, 0, 30);

    }
}

$table = new xList_Table($audience_type, $audience);

$table->prepare_items();

?>
<style>
    .column-id {
        width: 3rem;
    }
    .column-email {
        width: 5rem;
    }
</style>
<div class="wrap">
    <h2>Contacts</h2>

    <form method="post">

        <?php $table->display(); ?>
    </form>


</div>