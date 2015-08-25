<?php

require_once __DIR__ . '/CurlWrapper.class.php';
require_once __DIR__ . '/MetricsTaxonomy.class.php';
require_once __DIR__ . '/MetricsTaxonomiesTree.class.php';

/**
 * Class MetricsCounter
 */
class MetricsCounterFullHistory
{
    /**
     *
     */
    const LOCK_TITLE = 'metrics_cron_full_lock';
    /**
     * cURL handler
     * @var CurlWrapper
     */
    private $curl;
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
    private $debugStats = 0;
    /**
     * @var int
     */
    private $debugStatsByMonth = 0;
    /**
     * @var array
     */
    private $data_tree;
    /**
     * @var int
     */
    private $first_month;
    /**
     * @var int
     */
    private $first_year;

    /**
     * @var string  'metadata_modified' or 'metadata_created'
     */
    private $date_field;

    /**
     * @param string $date_field 'metadata_modified' or 'metadata_created'
     */
    function __construct($date_field = 'metadata_created')
    {
        $this->date_field = $date_field;
        $this->idm_json_url = get_option('org_server');
        if (!$this->idm_json_url) {
            $this->idm_json_url = 'http://data.gov/app/themes/roots-nextdatagov/assets/Json/fed_agency.json';
        }

        $this->data_tree = array(
            'total' => 0,
            'updated_at' => date(DATE_RFC2822),
            'total_by_month' => array(),
            'organizations' => array()
        );

        $this->ckanApiUrl = get_option('ckan_access_pt');
        if (!$this->ckanApiUrl) {
            $this->ckanApiUrl = '//catalog.data.gov/';
        }
        $this->ckanApiUrl = str_replace(array('http:', 'https:'), array('', ''), $this->ckanApiUrl);

        $this->curl = new CurlWrapper();
    }

    /**
     * @return array|bool|mixed|string
     */
    public static function get_metrics_per_month_full()
    {
        $upload_dir = wp_upload_dir();

        $jsonPath = $upload_dir['basedir'] . '/federal-agency-participation-full-by-metadata_created.json';

        if (!is_file($jsonPath) or !is_readable($jsonPath)) {
            return false;
        }

        $metrics = file_get_contents($jsonPath);
        if (!$metrics) {
            return false;
        }

        $metrics = json_decode($metrics, true);
        if (!$metrics) {
            return false;
        }

        return $metrics;
    }

    /**
     *
     */
    public function generate_reports()
    {
        echo PHP_EOL . date("(Y-m-d H:i:s)") . '(metrics-cron-full) Started' . PHP_EOL;
        if (!$this->checkLock()) {
            echo "Locked: another instance of metrics script is already running. Please try again later";

            return;
        }

        set_time_limit(30 * 60 * 5);  // 30 minutes must be more than enough

        $this->generate_header();

//    Get latest taxonomies from http://idm.data.gov/fed_agency.json
        $taxonomies = $this->ckan_metric_get_taxonomies();

//    Create taxonomy families, with parent taxonomy and sub-taxonomies (children)
        $TaxonomiesTree = $this->ckan_metric_convert_structure($taxonomies);

        $FederalOrganizationTree = $TaxonomiesTree->getVocabularyTree('Federal Organization');

        /** @var MetricsTaxonomy $RootOrganization */
        foreach ($FederalOrganizationTree as $RootOrganization) {
//        skip broken structures
            if (!$RootOrganization->getTerm()) {
                /**
                 * Ugly TEMPORARY hack for missing
                 * Executive Office of the President [eop-gov]
                 */
                try {
                    $children = $RootOrganization->getTerms();
                    $firstChildTerm = trim($children[0], '(")');
                    list (, $fed, $gov) = explode('-', $firstChildTerm);
                    if (!$fed || !$gov) {
                        continue;
                    }
                    $RootOrganization->setTerm("$fed-$gov");
//                    echo "uglyfix: $fed-$gov<br />" . PHP_EOL;
                } catch (Exception $ex) {
//                    didn't help. Skip
                    continue;
                }
            }

            $solr_terms = join('+OR+', $RootOrganization->getTerms());
            $solr_query = "organization:({$solr_terms})";

            /**
             * Collect statistics and create data for ROOT organization
             */
            $this->create_metric_content(
                $RootOrganization->getTitle(),
                $solr_query
            );
        }

        $this->write_metrics_files();

        echo '<hr />get count: ' . $this->debugStats . ' times<br />';
        echo 'get count by month: ' . $this->debugStatsByMonth . ' times<br />';

        $this->unlock();
    }

    /**
     * @return bool
     * unlocked automatically after 30 minutes, if script died
     */
    private function checkLock()
    {
        $lock = get_option(self::LOCK_TITLE);

        if ($lock) {
            $now = time();
            $diff = $now - $lock;

//            30 minutes lock
            if ($diff < (30 * 60)) {
                return false;
            }
        }

        $this->lock();

        return true;
    }

    /**
     *  Lock the system to avoid simultaneous cron runs
     */
    private function lock()
    {
        update_option(self::LOCK_TITLE, time());
    }

    /**
     *
     */
    private function generate_header()
    {
        $url = $this->ckanApiUrl . "api/3/action/package_search?fq=dataset_type:dataset&rows=1&sort=" . $this->date_field . "+asc";

        $response = $this->curl->get($url);
        $body = json_decode($response, true);

        $count = $body['result']['count'];

        if ($count) {
            $earliest_dataset_date = $body['result']['results'][0][$this->date_field];
//        2013-12-12T07:39:40.341322

            $earliest_dataset_date = substr($earliest_dataset_date, 0, 10);
//        2013-12-12
            list($Year, $month,) = explode('-', $earliest_dataset_date);
            $this->first_month = $month;
            $this->first_year = $Year;

            $now = date('M Y');
            $date = '';
            while ($date != $now) {
                $date = date('M Y', mktime(0, 0, 0, $month++, 1, $Year));
            }
        } else {
            die($url . ' did not return `count` field');
        }
    }

    /**
     * @return mixed
     */
    private function ckan_metric_get_taxonomies()
    {
        $response = $this->curl->get($this->idm_json_url);
        $body = json_decode($response, true);
        $taxonomies = $body['taxonomies'];

        return $taxonomies;
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

//        Empty Federal Agency = illegal
            if (!$taxonomy['Federal Agency']) {
                continue;
            }

//        ignore 3rd level ones
            if ($taxonomy['unique id'] != $taxonomy['term']) {
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

        return $Taxonomies;
    }

    /**
     * @param        $title
     * @param        $organizations
     *
     * @return mixed
     */
    private function create_metric_content(
        $title,
        $organizations
    )
    {
        $organization = array(
            'title' => $title,
            'total' => 0,
            'web_url' => '',
            'api_url' => '',
            'metrics' => array()
        );

        $now = date('M Y');
        $date = '';

        $month = $this->first_month;
        $Year = $this->first_year;

        while ($date != $now) {
            $startDt = date('Y-m-d', mktime(0, 0, 0, $month, 1, $Year)) . 'T00:00:00Z';
            $endDt = date('Y-m-t', mktime(0, 0, 0, $month, 1, $Year)) . 'T23:59:59Z';

            $range = "[" . $startDt . "%20TO%20" . $endDt . "]";

            $api_url = $this->ckanApiUrl . "api/3/action/package_search?fq=({$organizations})+AND+dataset_type:dataset+AND+" . $this->date_field . ":{$range}";
            $web_url = $this->ckanApiUrl . 'dataset?q=(' . $organizations . ')+AND+dataset_type:dataset+AND+' . $this->date_field . ':' . $range;

            $this->debugStatsByMonth++;
            $response = $this->curl->get($api_url . '&rows=0');
            $body = json_decode($response, true);

            $dataset_count = $body['result']['count'];

            $date = date('M Y', mktime(0, 0, 0, $month, 1, $Year));

            $organization['metrics'][] = array(
                'title' => $date,
                'from_date' => $startDt,
                'till_date' => $endDt,
                'api_url' => $api_url,
                'web_url' => $web_url,
                'count' => $dataset_count
            );

            $organization['total'] += $dataset_count;

            if (isset($this->data_tree['total_by_month'][$date])) {
                $this->data_tree['total_by_month'][$date] += $dataset_count;
            } else {
                $this->data_tree['total_by_month'][$date] = $dataset_count;
            }

            $this->data_tree['total'] += $dataset_count;

            $month++;
        }

        $api_url = $this->ckanApiUrl . "api/3/action/package_search?fq=({$organizations})+AND+dataset_type:dataset";
        $web_url = $this->ckanApiUrl . 'dataset?q=(' . $organizations . ')+AND+dataset_type:dataset';

        $organization['api_url'] = $api_url;
        $organization['web_url'] = $web_url;

//        Skipping empty organizations
        if ($organization['total']) {
            $this->data_tree['organizations'][] = $organization;
        }

        return 1;
    }

    /**
     *
     */
    private function write_metrics_files()
    {
        $this->write_metrics_csv();
        $this->write_metrics_json();
    }

    /**
     *
     */
    private function write_metrics_csv()
    {
        $upload_dir = wp_upload_dir();

        $csvPath = $upload_dir['basedir'] . '/federal-agency-participation-full-by-' . $this->date_field . '.csv';
        @chmod($csvPath, 0666);
        if (file_exists($csvPath) && !is_writable($csvPath)) {
            die('could not write ' . $csvPath);
        }

//    Write CSV result file
        $fp_csv = fopen($csvPath, 'w');

        if ($fp_csv == false) {
            die("unable to create file");
        }

        $header = array_merge(
            array('Agency'),
            array_keys($this->data_tree['total_by_month']),
            array('Total')
        );
        fputcsv($fp_csv, $header);

        foreach ($this->data_tree['organizations'] as $organization) {
            if (!$organization['total']) {
                continue;
            }
            $line = array($organization['title']);
            foreach ($organization['metrics'] as $month) {
                $line[] = $month['count'];
            }
            $line[] = $organization['total'];
            fputcsv($fp_csv, $line);
        }

        fclose($fp_csv);

        @chmod($csvPath, 0666);

        if (!file_exists($csvPath)) {
            die('could not write ' . $csvPath);
        } else {
            echo '/federal-agency-participation-full-by-' . $this->date_field . '.csv done <br />';
        }
    }

    /**
     *
     */
    private function write_metrics_json()
    {
        $upload_dir = wp_upload_dir();

        $jsonPath = $upload_dir['basedir'] . '/federal-agency-participation-full-by-' . $this->date_field . '.json';
        @chmod($jsonPath, 0666);
        if (file_exists($jsonPath) && !is_writable($jsonPath)) {
            die('could not write ' . $jsonPath);
        }

//    Write JSON result file
        file_put_contents($jsonPath, json_encode($this->data_tree, JSON_PRETTY_PRINT));

        @chmod($jsonPath, 0666);

        if (!file_exists($jsonPath)) {
            die('could not write ' . $jsonPath);
        } else {
            echo '/federal-agency-participation-full-by-' . $this->date_field . '.json done <br />';
        }
    }

    /**
     *  Unlock the system for next cron run
     */
    private function unlock()
    {
        delete_option(self::LOCK_TITLE);
    }
}