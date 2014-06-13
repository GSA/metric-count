<?php
error_reporting(E_ALL);
ini_set('display_errors', true);
require_once ('../../../wp/wp-load.php');
require_once ('../../../wp/wp-blog-header.php');

if (current_user_can( 'manage_options' )) {
    ignore_user_abort(true);
    define('DELETE_DUPLICATE_META', true);

    if (isset($_GET['cleaner'])) {
        define('METRICS_CLEANER', true);
    }

    get_ckan_metric_info();
}
?>done