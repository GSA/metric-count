<?php

require_once 'MetricsTaxonomy.class.php';
require_once 'MetricsTaxonomiesTree.class.php';

/** Include PHPExcel */
require_once 'Classes/PHPExcel.php';
require_once 'Classes/PHPExcel/IOFactory.php';


/**
 * Class MetricsCounter
 */
class MetricsCounter
{
    /**
     * cURL handler
     * @var resource
     */
    private $ch;

    /**
     * cURL headers
     * @var array
     */
    private $ch_headers;

    /**
     * @var string
     */
    private $ckan_no_cache_ip = '';

    /**
     * @var string
     */
    private $idm_json_url = '';

    /**
     * @var mixed|string
     */
    private $ckanApiUrl = '';

    /**
     * @var int
     */
    private $stats = 0;
    /**
     * @var int
     */
    private $statsByMonth = 0;

    /**
     * @var array
     */
    private $results = array();

    /**
     *
     */
    function __construct()
    {
        $this->idm_json_url = get_option('org_server');
        if (!$this->idm_json_url) {
            $this->idm_json_url = 'http://idm.data.gov/fed_agency.json';
        }

        $this->ckanApiUrl = get_option('ckan_access_pt');
        if (!$this->ckanApiUrl) {
            $this->ckanApiUrl = '//catalog.data.gov/';
        }
        $this->ckanApiUrl = str_replace(array('http:', 'https:'), array('', ''), $this->ckanApiUrl);

        $this->ckan_no_cache_ip = get_option('ckan_no_cache_ip') ? get_option('ckan_no_cache_ip') : '216.128.241.210';

        // Create cURL object.
        $this->ch = curl_init();
        // Follow any Location: headers that the server sends.
        curl_setopt($this->ch, CURLOPT_FOLLOWLOCATION, true);
        // However, don't follow more than five Location: headers.
        curl_setopt($this->ch, CURLOPT_MAXREDIRS, 5);
        // Automatically set the Referrer: field in requests
        // following a Location: redirect.
        curl_setopt($this->ch, CURLOPT_AUTOREFERER, true);
        // Return the transfer as a string instead of dumping to screen.
        curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
        // If it takes more than 45 seconds, fail
        curl_setopt($this->ch, CURLOPT_TIMEOUT, 45);
        // We don't want the header (use curl_getinfo())
        curl_setopt($this->ch, CURLOPT_HEADER, false);
        // Track the handle's request string
        curl_setopt($this->ch, CURLINFO_HEADER_OUT, true);
        // Attempt to retrieve the modification date of the remote document.
        curl_setopt($this->ch, CURLOPT_FILETIME, true);

        // Initialize cURL headers
        $this->set_headers();
    }

    /**
     * Sets the custom cURL headers.
     * @access    private
     * @return    void
     * @since     Version 0.1.0
     */
    private function set_headers()
    {
        $date             = new DateTime(null, new DateTimeZone('UTC'));
        $this->ch_headers = array(
            'Date: ' . $date->format('D, d M Y H:i:s') . ' GMT', // RFC 1123
            'Accept-Charset: utf-8',
            'Accept-Encoding: gzip'
        );
    }

    private function cleaner()
    {
        global $wpdb;
        $wpdb->query("DELETE FROM wp_posts WHERE post_type='metric_organization'");
        $wpdb->query("DELETE FROM wp_postmeta WHERE post_id NOT IN (SELECT ID from wp_posts)");
    }

    /**
     *
     */
    public function updateMetrics()
    {
        if (defined('METRICS_CLEANER') && METRICS_CLEANER) {
            $this->cleaner();
        }

//    Get latest taxonomies from http://idm.data.gov/fed_agency.json
        $taxonomies = $this->ckan_metric_get_taxonomies();

//    Create taxonomy families, with parent taxonomy and sub-taxonomies (children)
        $TaxonomiesTree = $this->ckan_metric_convert_structure($taxonomies);

        $FederalOrganizationTree = $TaxonomiesTree->getVocabularyTree('Federal Organization');

        /** @var MetricsTaxonomy $RootOrganization */
        foreach ($FederalOrganizationTree as $RootOrganization) {
//        skip broken structures
            if (!$RootOrganization->getTerm()) {
                continue;
            }

            $solr_terms = join('+OR+', $RootOrganization->getTerms());
            $solr_query = "organization:({$solr_terms})";

//        wtf?
            $parent_nid = $this->create_metric_content(
                $RootOrganization->getIsCfo(),
                $RootOrganization->getTitle(),
                $RootOrganization->getTerm(),
                $solr_query
            );

            $this->create_metric_content(
                $RootOrganization->getIsCfo(),
                $RootOrganization->getTitle(),
                $RootOrganization->getTerm(),
                $solr_query,
                $parent_nid,
                1,
                '',
                0
            );

            $this->create_metric_content_by_publishers(
                $RootOrganization,
                $parent_nid
            );

//        wtf ?
            $this->create_metric_content(
                $RootOrganization->getIsCfo(),
                'Department/Agency Level',
                $RootOrganization->getTerm(),
                'organization:' . urlencode($RootOrganization->getTerm()),
                $parent_nid,
                0,
                $RootOrganization->getTitle(),
                0,
                1
            );

//        wtf children?
            $SubOrganizations = $RootOrganization->getChildren();
            if ($SubOrganizations) {
                /** @var MetricsTaxonomy $Organization */
                foreach ($SubOrganizations as $Organization) {
                    $this->create_metric_content(
                        $Organization->getIsCfo(),
                        $Organization->getTitle(),
                        $Organization->getTerm(),
                        'organization:' . urlencode($Organization->getTerm()),
                        $parent_nid,
                        0,
                        $RootOrganization->getTitle(),
                        1,
                        1
                    );
                }
            }

        }

//        $this->write_metrics_csv_and_xls();

        echo 'get count: ' . $this->stats . ' times<br />';
        echo 'get count by month: ' . $this->statsByMonth . ' times<br />';
    }

    /**
     * @return mixed
     */
    private function ckan_metric_get_taxonomies()
    {
        $response   = $this->curl_get($this->idm_json_url);
        $body       = json_decode($response, true);
        $taxonomies = $body['taxonomies'];

        return $taxonomies;
    }

    /**
     * @param $url
     *
     * @return mixed
     */
    private function curl_get(
        $url
    ) {
        if ('http' != substr($url, 0, 4)) {
            $url = 'http:' . $url;
        }

        $url = str_replace('catalog.data.gov', $this->ckan_no_cache_ip, $url);

        return $this->curl_make_request('GET', $url);
    }

    /**
     * @param string $method // HTTP method (GET, POST)
     * @param string $uri    // URI fragment to CKAN resource
     * @param string $data   // Optional. String in JSON-format that will be in request body
     *
     * @return mixed    // If success, either an array or object. Otherwise FALSE.
     * @throws Exception
     */
    private function curl_make_request(
        $method,
        $uri,
        $data = null
    ) {
        $method = strtoupper($method);
        if (!in_array($method, array('GET', 'POST'))) {
            throw new Exception('Method ' . $method . ' is not supported');
        }
        // Set cURL URI.
        curl_setopt($this->ch, CURLOPT_URL, $uri);
        if ($method === 'POST') {
            if ($data) {
                curl_setopt($this->ch, CURLOPT_POSTFIELDS, urlencode($data));
            } else {
                $method = 'GET';
            }
        }

        // Set cURL method.
        curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, $method);

        // Set headers.
        curl_setopt($this->ch, CURLOPT_HTTPHEADER, $this->ch_headers);
        // Execute request and get response headers.
        $response = curl_exec($this->ch);
        $info     = curl_getinfo($this->ch);
        // Check HTTP response code
        if ($info['http_code'] !== 200) {
            switch ($info['http_code']) {
                case 404:
                    throw new Exception($data);
                    break;
                default:
                    throw new Exception(
                        $info['http_code'] . ': ' .
                        $this->http_status_codes[$info['http_code']] . PHP_EOL . $data . PHP_EOL
                    );
            }
        }

        return $response;
    }

    /**
     * @param $taxonomies
     *
     * @return MetricsTaxonomiesTree
     */
    private function ckan_metric_convert_structure($taxonomies)
    {
        $Taxonomies = new MetricsTaxonomiesTree();

        foreach ($taxonomies as $taxonomy) {
            $taxonomy = $taxonomy['taxonomy'];

//        ignore bad ones
            if (strlen($taxonomy['unique id']) == 0) {
                continue;
            }

//        ignore 3rd level ones
            if ($taxonomy['unique id'] != $taxonomy['term']) {
                continue;
            }

//        Empty Federal Agency = illegal
            if (!$taxonomy['Federal Agency']) {
                continue;
            }

//        Make sure we got $return[$sector], ex. $return['Federal Organization']
            if (!isset($return[$taxonomy['vocabulary']])) {
                $return[$taxonomy['vocabulary']] = array();
            }

            $RootAgency = $Taxonomies->getRootAgency($taxonomy['Federal Agency'], $taxonomy['vocabulary']);

            if (!($RootAgency)) {
//            create root agency if doesn't exist yet
                $RootAgency = new MetricsTaxonomy($taxonomy['Federal Agency']);
                $RootAgency->setIsRoot(true);
            }

            if (strlen($taxonomy['Sub Agency']) != 0) {
//        This is sub-agency
                $Agency = new MetricsTaxonomy($taxonomy['Sub Agency']);
                $Agency->setTerm($taxonomy['unique id']);
                $Agency->setIsCfo($taxonomy['is_cfo']);
                $RootAgency->addChild($Agency);
            } else {
//        ELSE this is ROOT agency
                $RootAgency->setTerm($taxonomy['unique id']);
                $RootAgency->setIsCfo($taxonomy['is_cfo']);
            }

//        updating the tree
            $Taxonomies->updateRootAgency($RootAgency, $taxonomy['vocabulary']);
        }

//    $return = $Taxonomies->toArray();
//    return $return;

        return $Taxonomies;
    }

    /**
     * @param        $cfo
     * @param        $title
     * @param        $ckan_id
     * @param        $organizations
     * @param int    $parent_node
     * @param int    $agency_level
     * @param string $parent_name
     * @param int    $sub_agency
     * @param int    $export
     *
     * @return mixed
     */
    private function create_metric_content(
        $cfo,
        $title,
        $ckan_id,
        $organizations,
        $parent_node = 0,
        $agency_level = 0,
        $parent_name = '',
        $sub_agency = 0,
        $export = 0
    ) {
        if (strlen($ckan_id) != 0) {
            $url = $this->ckanApiUrl . "api/3/action/package_search?fq=($organizations)+AND+dataset_type:dataset&rows=1&sort=metadata_modified+desc";

            $this->stats++;

            $response = $this->curl_get($url);
            $body     = json_decode($response, true);

            $count = $body['result']['count'];

            if ($count) {
                $last_entry = $body['result']['results'][0]['metadata_modified'];
//        2013-12-12T07:39:40.341322

//            echo '---'.PHP_EOL;
//            echo $url.PHP_EOL.PHP_EOL;
//            echo 'metadata_modified '.$last_entry.PHP_EOL;

                $last_entry = substr($last_entry, 0, 10);
//        2013-12-12

            } else {
                $last_entry = '1970-01-01';
            }
        } else {
            $count = 0;
        }

        $metric_sync_timestamp = time();

        if (!$sub_agency && $cfo == 'Y' && $title != 'Department/Agency Level') {
            //get list of last 12 months
            $month = date('m');

            $startDate = mktime(0, 0, 0, $month - 11, 1, date('Y'));
            $endDate   = mktime(0, 0, 0, $month, date('t'), date('Y'));

            $tmp = date('mY', $endDate);

            $oneYearAgo = date('Y-m-d', $startDate);

            while (true) {
                $months[] = array(
                    'month' => date('m', $startDate),
                    'year'  => date('Y', $startDate)
                );

                if ($tmp == date('mY', $startDate)) {
                    break;
                }

                $startDate = mktime(0, 0, 0, date('m', $startDate) + 1, 15, date('Y', $startDate));
            }

            $dataset_count = array();
            $dataset_range = array();

            $i = 1;

            /**
             * Get metrics by current $organizations for each of latest 12 months
             */
            foreach ($months as $date_arr) {
                $startDt = date('Y-m-d', mktime(0, 0, 0, $date_arr['month'], 1, $date_arr['year']));
                $endDt   = date('Y-m-t', mktime(0, 0, 0, $date_arr['month'], 1, $date_arr['year']));

                $range = "[" . $startDt . "T00:00:00Z%20TO%20" . $endDt . "T23:59:59Z]";

                $url = $this->ckanApiUrl . "api/3/action/package_search?fq=($organizations)+AND+dataset_type:dataset+AND+metadata_created:$range&rows=0";
                $this->statsByMonth++;
                $response = $this->curl_get($url);
                $body     = json_decode($response, true);

                $dataset_count[$i] = $body['result']['count'];
                $dataset_range[$i] = $range;
                $i++;
            }

            /**
             * Get metrics by current $organizations for latest 12 months TOTAL
             */

            $range = "[" . $oneYearAgo . "T00:00:00Z%20TO%20NOW]";

            $url = $this->ckanApiUrl . "api/3/action/package_search?fq=($organizations)+AND+dataset_type:dataset+AND+metadata_created:$range&rows=0";

            $this->statsByMonth++;
            $response = $this->curl_get($url);
            $body     = json_decode($response, true);

            $lastYearCount = $body['result']['count'];
            $lastYearRange = $range;
        }

        $content_id = get_page_by_title($title, OBJECT, 'metric_organization')->ID;

        if ($sub_agency) {
            global $wpdb;
            $myrows     = $wpdb->get_var(
                "SELECT id FROM `wp_posts` p
                                   INNER JOIN wp_postmeta pm ON pm.post_id = p.id
                                                           WHERE post_title = '" . $title . "' AND post_type = 'metric_organization'
                   AND meta_key = 'ckan_unique_id' AND meta_value = '" . $ckan_id . "'"
            );
            $content_id = $myrows;
        }

        if ($title == 'Department/Agency Level') {
            global $wpdb;
            $myrows = $wpdb->get_var(
                "SELECT id FROM `wp_posts` p
                                   INNER JOIN wp_postmeta pm ON pm.post_id = p.id
                                   WHERE post_title = 'Department/Agency Level' AND post_type = 'metric_organization'
                                   AND meta_key = 'parent_organization' AND meta_value = " . $parent_node
            );

            $content_id = $myrows;
        }

        list($Y, $m, $d) = explode('-', $last_entry);
        $last_entry = "$m/$d/$Y";

        if (sizeof($content_id) == 0) {

            $my_post = array(
                'post_title'  => $title,
                'post_status' => 'publish',
                'post_type'   => 'metric_organization'
            );

            $new_post_id = wp_insert_post($my_post);

            $this->update_post_meta($new_post_id, 'metric_count', $count);


            if (!$sub_agency && $cfo == 'Y' && $title != 'Department/Agency Level') {
                for ($i = 1; $i < 13; $i++) {
                    $this->update_post_meta($new_post_id, 'month_' . $i . '_dataset_count', $dataset_count[$i]);
                }

                $this->update_post_meta($new_post_id, 'last_year_dataset_count', $lastYearCount);

                for ($i = 1; $i < 13; $i++) {
                    $this->update_post_meta(
                        $new_post_id,
                        'month_' . $i . '_dataset_url',
                        $this->ckanApiUrl . 'dataset?q=(' . $organizations . ')+AND+dataset_type:dataset+AND+metadata_created:' . $dataset_range[$i]
                    );
                }

                $this->update_post_meta(
                    $new_post_id,
                    'last_year_dataset_url',
                    $this->ckanApiUrl . 'dataset?q=(' . $organizations . ')+AND+dataset_type:dataset+AND+metadata_created:' . $lastYearRange
                );

            }

            if ($cfo == 'Y') {
                $this->update_post_meta($new_post_id, 'metric_sector', 'Federal');
            } else {
                $this->update_post_meta($new_post_id, 'metric_sector', 'Other');
            }

            $this->update_post_meta($new_post_id, 'ckan_unique_id', $ckan_id);
            $this->update_post_meta($new_post_id, 'metric_last_entry', $last_entry);
            $this->update_post_meta($new_post_id, 'metric_sync_timestamp', $metric_sync_timestamp);

            $this->update_post_meta(
                $new_post_id,
                'metric_url',
                $this->ckanApiUrl . 'dataset?q=' . $organizations
            );


            if ($parent_node != 0) {
                $this->update_post_meta($new_post_id, 'parent_organization', $parent_node);
            }

            if ($agency_level != 0) {
                $this->update_post_meta($new_post_id, 'parent_agency', 1);
            }

            $flag = false;
            if ($count > 0) {
                if ($export != 0) {
                    $this->results[] = array($parent_name, $title, $count, $last_entry);
                }

                if ($parent_node == 0 && $flag == false) {
                    $parent_name = $title;
                    $title       = '';

                    $this->results[] = array($parent_name, $title, $count, $last_entry);
                }
            }
        } else {
            $new_post_id = $content_id;
            $my_post     = array(
                'ID'          => $new_post_id,
                'post_status' => 'publish',
            );

            wp_update_post($my_post);
            $this->update_post_meta($new_post_id, 'metric_count', $count);
            $this->update_post_meta($new_post_id, 'ckan_unique_id', $ckan_id);

            if (!$sub_agency && $cfo == 'Y' && $title != 'Department/Agency Level') {
                for ($i = 1; $i < 13; $i++) {
                    $this->update_post_meta($new_post_id, 'month_' . $i . '_dataset_count', $dataset_count[$i]);
                }

                $this->update_post_meta($new_post_id, 'last_year_dataset_count', $lastYearCount);

                for ($i = 1; $i < 13; $i++) {
                    $this->update_post_meta(
                        $new_post_id,
                        'month_' . $i . '_dataset_url',
                        $this->ckanApiUrl . 'dataset?q=(' . $organizations . ')+AND+dataset_type:dataset+AND+metadata_created:' . $dataset_range[$i]
                    );
                }

                $this->update_post_meta(
                    $new_post_id,
                    'last_year_dataset_url',
                    $this->ckanApiUrl . 'dataset?q=(' . $organizations . ')+AND+dataset_type:dataset+AND+metadata_created:' . $lastYearRange
                );
            }

            if ($cfo == 'Y') {
                $this->update_post_meta($new_post_id, 'metric_sector', 'Federal');
            } else {
                $this->update_post_meta($new_post_id, 'metric_sector', 'Other');
            }

            $this->update_post_meta($new_post_id, 'metric_last_entry', $last_entry);
            $this->update_post_meta($new_post_id, 'metric_sync_timestamp', $metric_sync_timestamp);
            $this->update_post_meta(
                $new_post_id,
                'metric_url',
                $this->ckanApiUrl . 'dataset?q=' . $organizations
            );

            if ($parent_node != 0) {
                $this->update_post_meta($new_post_id, 'parent_organization', $parent_node);
            }

            if ($agency_level != 0) {
                $this->update_post_meta($new_post_id, 'parent_agency', 1);
            }

            $flag = false;
            if ($count > 0) {
                if ($export != 0) {
                    $this->results[] = array($parent_name, $title, $count, $last_entry);
                }

                if ($parent_node == 0 && $flag == false) {
                    $parent_name = $title;
                    $title       = '';

                    $this->results[] = array($parent_name, $title, $count, $last_entry);
                }
            }
        }

        return $new_post_id;
    }

    /**
     * Temporary to remove all duplicate meta
     * Removes ONLY with manual launch
     *
     * @param $post_id
     * @param $meta_key
     * @param $meta_value
     */
    private function update_post_meta($post_id, $meta_key, $meta_value)
    {
        if (defined('DELETE_DUPLICATE_META') && DELETE_DUPLICATE_META) {
            delete_post_meta($post_id, $meta_key);
        }
        update_post_meta($post_id, $meta_key, $meta_value);
    }

    /**
     * @param MetricsTaxonomy $RootOrganization
     * @param                 $parent_nid
     *
     * @return int
     */
    private function create_metric_content_by_publishers($RootOrganization, $parent_nid)
    {
//        http://catalog.data.gov/api/action/package_search?q=organization:treasury-gov+AND+type:dataset&rows=0&facet.field=publisher
        $ckan_organization = 'organization:' . urlencode($RootOrganization->getTerm());
        $url               = $this->ckanApiUrl . "api/3/action/package_search?q={$ckan_organization}+AND+type:dataset&rows=0&facet.field=publisher";

        $this->stats++;

        $response = $this->curl_get($url);
        $body     = json_decode($response, true);

        if (!isset($body['result']['facets']['publisher'])) {
            return;
        }

        $publishers = $body['result']['facets']['publisher'];
        if (!sizeof($publishers)) {
            return;
        }

        global $wpdb;

        foreach ($publishers as $publisherTitle => $count) {
            $content_id = $wpdb->get_var(
                "SELECT id FROM `wp_posts` p
                                   INNER JOIN wp_postmeta pm ON pm.post_id = p.id
                                   WHERE post_title = '" . $publisherTitle . "' AND post_type = 'metric_organization'
                   AND meta_key = 'metric_publisher' AND meta_value = '$parent_nid'"
            );

            if (!$content_id) {

                $my_post = array(
                    'post_title'  => $publisherTitle,
                    'post_status' => 'publish',
                    'post_type'   => 'metric_organization'
                );

                $content_id = wp_insert_post($my_post);
            }

            $this->update_post_meta($content_id, 'metric_count', $count);

            $this->update_post_meta($content_id, 'metric_publisher', $parent_nid);


//            http://dev-ckan-fe-data.reisys.com/dataset?publisher=United+States+Mint.+Sales+and+Marketing+%28SAM%29+Department
            $this->update_post_meta(
                $content_id,
                'metric_url',
                $this->ckanApiUrl . 'dataset?publisher=' . urlencode($publisherTitle)
            );

            if ('Y' == $RootOrganization->getIsCfo()) {
                $this->update_post_meta($content_id, 'metric_sector', 'Federal');
            } else {
                $this->update_post_meta($content_id, 'metric_sector', 'Other');
            }

            $this->update_post_meta($content_id, 'parent_organization', $parent_nid);

            $this->results[] = array($RootOrganization->getTitle(), $publisherTitle, $count, '-');
        }

        return;


        if (false) {


            if ($cfo == 'Y') {
                $this->update_post_meta($new_post_id, 'metric_sector', 'Federal');
            } else {
                $this->update_post_meta($new_post_id, 'metric_sector', 'Other');
            }

            $this->update_post_meta($new_post_id, 'ckan_unique_id', $ckan_id);
            $this->update_post_meta($new_post_id, 'metric_last_entry', $last_entry);
            $this->update_post_meta($new_post_id, 'metric_sync_timestamp', $metric_sync_timestamp);

            $this->update_post_meta(
                $new_post_id,
                'metric_url',
                $this->ckanApiUrl . 'dataset?q=' . $organizations
            );


            if ($parent_node != 0) {
                $this->update_post_meta($new_post_id, 'parent_organization', $parent_node);
            }

            if ($agency_level != 0) {
                $this->update_post_meta($new_post_id, 'parent_agency', 1);
            }

            $flag = false;
            if ($count > 0) {
                if ($export != 0) {
                    $this->results[] = array($parent_name, $title, $count, $last_entry);
                }

                if ($parent_node == 0 && $flag == false) {
                    $parent_name = $title;
                    $title       = '';

                    $this->results[] = array($parent_name, $title, $count, $last_entry);
                }
            }
        } else {
            $new_post_id = $content_id;
            $my_post     = array(
                'ID'          => $new_post_id,
                'post_status' => 'publish',
            );

            wp_update_post($my_post);
            $this->update_post_meta($new_post_id, 'metric_count', $count);
            $this->update_post_meta($new_post_id, 'ckan_unique_id', $ckan_id);

            if (!$sub_agency && $cfo == 'Y' && $title != 'Department/Agency Level') {
                for ($i = 1; $i < 13; $i++) {
                    $this->update_post_meta($new_post_id, 'month_' . $i . '_dataset_count', $dataset_count[$i]);
                }

                $this->update_post_meta($new_post_id, 'last_year_dataset_count', $lastYearCount);

                for ($i = 1; $i < 13; $i++) {
                    $this->update_post_meta(
                        $new_post_id,
                        'month_' . $i . '_dataset_url',
                        $this->ckanApiUrl . 'dataset?q=(' . $organizations . ')+AND+dataset_type:dataset+AND+metadata_created:' . $dataset_range[$i]
                    );
                }

                $this->update_post_meta(
                    $new_post_id,
                    'last_year_dataset_url',
                    $this->ckanApiUrl . 'dataset?q=(' . $organizations . ')+AND+dataset_type:dataset+AND+metadata_created:' . $lastYearRange
                );
            }

            if ($cfo == 'Y') {
                $this->update_post_meta($new_post_id, 'metric_sector', 'Federal');
            } else {
                $this->update_post_meta($new_post_id, 'metric_sector', 'Other');
            }

            $this->update_post_meta($new_post_id, 'metric_last_entry', $last_entry);
            $this->update_post_meta($new_post_id, 'metric_sync_timestamp', $metric_sync_timestamp);
            $this->update_post_meta(
                $new_post_id,
                'metric_url',
                $this->ckanApiUrl . 'dataset?q=' . $organizations
            );

            if ($parent_node != 0) {
                $this->update_post_meta($new_post_id, 'parent_organization', $parent_node);
            }

            if ($agency_level != 0) {
                $this->update_post_meta($new_post_id, 'parent_agency', 1);
            }

            $flag = false;
            if ($count > 0) {
                if ($export != 0) {
                    $this->results[] = array($parent_name, $title, $count, $last_entry);
                }

                if ($parent_node == 0 && $flag == false) {
                    $parent_name = $title;
                    $title       = '';

                    $this->results[] = array($parent_name, $title, $count, $last_entry);
                }
            }
        }

        return $new_post_id;
    }

    /**
     *
     */
    private function write_metrics_csv_and_xls()
    {
        asort($this->results);
//    chdir(ABSPATH.'media/');

        $upload_dir = wp_upload_dir();

//    Write CSV result file
        $fp_csv = fopen($upload_dir['basedir'] . '/federal-agency-participation.csv', 'w');

        if ($fp_csv == false) {
            die("unable to create file");
        }

        fputcsv($fp_csv, array('Agency Name', 'Sub-Agency Name', 'Datasets', 'Last Entry'));

        foreach ($this->results as $record) {
            fputcsv($fp_csv, $record);
        }
        fclose($fp_csv);

        // Instantiate a new PHPExcel object
        $objPHPExcel = new PHPExcel();
        // Set the active Excel worksheet to sheet 0
        $objPHPExcel->setActiveSheetIndex(0);
        // Initialise the Excel row number
        $row = 1;

        $objPHPExcel->getActiveSheet()->SetCellValue('A' . $row, 'Agency Name');
        $objPHPExcel->getActiveSheet()->SetCellValue('B' . $row, 'Sub-Agency Name');
        $objPHPExcel->getActiveSheet()->SetCellValue('C' . $row, 'Datasets');
        $objPHPExcel->getActiveSheet()->SetCellValue('D' . $row, 'Last Entry');
        $row++;

        foreach ($this->results as $record) {
            if ($record) {
                $objPHPExcel->getActiveSheet()->SetCellValue('A' . $row, $record[0]);
                $objPHPExcel->getActiveSheet()->SetCellValue('B' . $row, $record[1]);
                $objPHPExcel->getActiveSheet()->SetCellValue('C' . $row, $record[2]);
                $objPHPExcel->getActiveSheet()->SetCellValue('D' . $row, $record[3]);
                $row++;
            }
        }

        // Instantiate a Writer to create an OfficeOpenXML Excel .xlsx file
        $objWriter = new PHPExcel_Writer_Excel2007($objPHPExcel);
        // Write the Excel file to filename some_excel_file.xlsx in the current directory
        $objWriter->save($upload_dir['basedir'] . '/federal-agency-participation.xls');
    }
}