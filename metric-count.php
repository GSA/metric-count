<?php
/*
Plugin Name: Metric Count
Description: This plugin makes API call to Ckan AND stores dataset count for each organization.
*/

add_action('admin_menu', 'metric_configuration');

/**
 *
 */
function metric_configuration()
{
    add_menu_page(
        'Metric Count Settings',
        'Metric Count Settings',
        'administrator',
        'metric_config',
        'metric_count_settings'
    );
}

/**
 *
 */
function metric_count_settings()
{
    $ckan_access_pt = (get_option('ckan_access_pt')) ? get_option('ckan_access_pt') : '//catalog.data.gov/';
    $org_server     = (get_option('org_server')) ? get_option(
        'org_server'
    ) : 'http://idm.data.gov/fed_agency.json';

    $html = '<form action="options.php" method="post" name="options">
			<h2>Metric Count Settings</h2>' . wp_nonce_field('update-options');

    $html .= '<table class="form-table" width="100%" cellpadding="10">
				<tbody>
					<tr>
						<td scope="row" aligni="left">
							<label>CKAN Metadata Access Point</label>
							<input type="text" name="ckan_access_pt" size="60" value="' . $ckan_access_pt . '">
						</td>
					</tr>
					<tr>
						<td scope="row" aligni="left">
						   <label>Organization Server Address</label>
						   <input type="text" name="org_server" size="60" value="' . $org_server . '">
						</td>
					</tr>
				</tbody>
 			</table>';

    $html .= '<input type="hidden" name="action" value="update" />
			<input type="hidden" name="page_options" value="ckan_access_pt,org_server" />
			<input type="submit" name="Submit" value="Update" />
			</form>';

    echo $html;
}

/**
 *  Main Cron Script - collecting metrics from catalog API
 */
function get_ckan_metric_info()
{
    ignore_user_abort(true);

    require_once 'Classes/MetricsCounter.class.php';

    $MetricsCounter = new MetricsCounter();
    $MetricsCounter->updateMetrics();
}

register_activation_hook(__FILE__, 'my_activation');
add_action('metrics_daily_update', 'get_ckan_metric_info');

/**
 *
 */
function my_activation()
{
    wp_schedule_event(time(), 'daily', 'metrics_daily_update');
}

register_deactivation_hook(__FILE__, 'my_deactivation');

/**
 *
 */
function my_deactivation()
{
    wp_clear_scheduled_hook('metrics_daily_update');
}

