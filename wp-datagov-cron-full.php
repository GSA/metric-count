<?php
error_reporting(E_ALL);
ini_set('display_errors', true);
require_once ('../../../wp/wp-load.php');
require_once ('../../../wp/wp-blog-header.php');

if (current_user_can( 'manage_options' )) {
    ignore_user_abort(true);

    error_reporting(E_ALL & ~E_NOTICE);
    ini_set('display_errors', true);

//    define('DELETE_DUPLICATE_META', true);

    get_ckan_metric_info_full_history();
} else {
    echo 'Permission denied';
}