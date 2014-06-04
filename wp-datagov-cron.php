<?php
error_reporting(E_ALL);
ini_set('display_errors', true);
require_once ('../../../wp/wp-load.php');
require_once ('../../../wp/wp-blog-header.php');

if (current_user_can( 'manage_options' )) {
    ignore_user_abort(true);
    get_ckan_metric_info();
}
?>done