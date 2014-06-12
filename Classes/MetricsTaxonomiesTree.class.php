<?php

/**
 * Class Metrics
 */
class MetricsTaxonomiesTree
{
    /**
     * @var
     */
    private $tree = array();

    /**
     *
     */
    function __construct()
    {
        $this->tree = array();
    }

    /**
     * @param MetricsTaxonomy $taxonomy
     * @param                 $vocabulary
     */
    public function updateRootAgency(MetricsTaxonomy $taxonomy, $vocabulary)
    {
        if (!isset($this->tree[$vocabulary])) {
            $this->tree[$vocabulary] = array();
        }
        $this->tree[$vocabulary][$taxonomy->getTitle()] = $taxonomy;
    }

    /**
     * @param $title
     * @param $vocabulary
     *
     * @return bool|MetricsTaxonomy
     */
    public function getRootAgency($title, $vocabulary)
    {
        $taxonomy = false;
        if (isset($this->tree[$vocabulary][$title])) {
            $taxonomy = $this->tree[$vocabulary][$title];
        }

        return $taxonomy;
    }

    /**
     * @return array
     */
    public function toArray()
    {
        $return = array();
        foreach ($this->tree as $vocabulary => $taxonomies) {
            $return[$vocabulary] = array();
            /** @var MetricsTaxonomy $rootTaxonomy */
            foreach ($taxonomies as $rootTaxonomy) {
                $return[$vocabulary][$rootTaxonomy->getTitle()] = $rootTaxonomy->toArray();
            }
        }

        return $return;
    }

    /**
     * @param $vocabulary
     *
     * @return array
     */
    public function getVocabularyTree($vocabulary)
    {
        $tree = array();
        if (isset($this->tree[$vocabulary])) {
            $tree = $this->tree[$vocabulary];
        }
        return $tree;
    }
}