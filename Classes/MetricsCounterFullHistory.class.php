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
    private $chart_by_month_data = array();
    /**
     * @var array
     */
    private $chart_by_month_data_html = array();
    /**
     * @var array
     */
    private $chart_by_month_header = array();
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

        $this->ckanApiUrl = get_option('ckan_access_pt');
        if (!$this->ckanApiUrl) {
            $this->ckanApiUrl = '//catalog.data.gov/';
        }
        $this->ckanApiUrl = str_replace(array('http:', 'https:'), array('', ''), $this->ckanApiUrl);

        $this->curl = new CurlWrapper();
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
            $parent_nid = $this->create_metric_content(
                $RootOrganization->getTitle(),
                $solr_query
            );
        }

        $this->write_metrics_csv_and_xls();

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

        $this->chart_by_month_header[] = 'AGENCY';

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
                $this->chart_by_month_header[] = $date;
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
        $chart_data = $chart_data_html = array($title);

        $now = date('M Y');
        $date = '';

        $month = $this->first_month;
        $Year = $this->first_year;

        while ($date != $now) {
            $startDt = date('Y-m-d', mktime(0, 0, 0, $month, 1, $Year));
            $endDt = date('Y-m-t', mktime(0, 0, 0, $month, 1, $Year));

            $range = "[" . $startDt . "T00:00:00Z%20TO%20" . $endDt . "T23:59:59Z]";

            $url = $this->ckanApiUrl . "api/3/action/package_search?fq=({$organizations})+AND+dataset_type:dataset+AND+" . $this->date_field . ":{$range}&rows=0";
            $ui_url = $this->ckanApiUrl . 'dataset?q=(' . $organizations . ')+AND+dataset_type:dataset+AND+' . $this->date_field . ':' . $range;

            $this->debugStatsByMonth++;
            $response = $this->curl->get($url);
            $body = json_decode($response, true);

            $dataset_count = $body['result']['count'];
            $chart_data[] = $dataset_count;
            $chart_data_html[] = '<a href="' . $ui_url . '">' . $dataset_count . '</a>';

            $date = date('M Y', mktime(0, 0, 0, $month++, 1, $Year));
        }

        $this->chart_by_month_data[] = $chart_data;
        $this->chart_by_month_data_html[] = $chart_data_html;

        return 1;
    }

    /**
     *
     */
    private function write_metrics_csv_and_xls()
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

        fputcsv($fp_csv, $this->chart_by_month_header);

        foreach ($this->chart_by_month_data as $data) {
            fputcsv($fp_csv, $data);
        }
        fclose($fp_csv);

        @chmod($csvPath, 0666);

        if (!file_exists($csvPath)) {
            die('could not write ' . $csvPath);
        } else {
            echo '/federal-agency-participation-full-by-' . $this->date_field . '.csv done <br />';
        }


        $htmlPath = $upload_dir['basedir'] . '/federal-agency-participation-full-by-' . $this->date_field . '.html';
        @chmod($htmlPath, 0666);
        if (file_exists($htmlPath) && !is_writable($htmlPath)) {
            die('could not write ' . $htmlPath);
        }

        $html = '<tr><th>' . join('</th><th>', $this->chart_by_month_header) . '</th></tr>' . PHP_EOL;
        foreach ($this->chart_by_month_data_html as $row) {
            $html .= '  <tr><td>' . join('</td><td>', $row) . '</td></tr>' . PHP_EOL;
        }
        $html = <<<END
<html>
    <head>
        <style type="text/css">
            tbody tr:nth-child(odd) {
                background-color: #ddd;
            }
        </style>
    </head>
    <body>
        <table>
            $html
        </table>
    </body>
</html>
END;

        file_put_contents($htmlPath, $html);

        @chmod($htmlPath, 0666);

        if (!file_exists($htmlPath)) {
            die('could not write ' . $htmlPath);
        } else {
            echo '/federal-agency-participation-full-by-' . $this->date_field . '.html done <br />';
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