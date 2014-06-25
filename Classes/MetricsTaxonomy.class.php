<?php

/**
 * Class MetricsTaxonomy
 */
class MetricsTaxonomy
{
    /**
     * @var
     */
    private $title = '';
    /**
     * @var
     */
    private $isRoot = false;
    /**
     * @var
     */
    private $isCfo = 'N';
    /**
     * @var string
     */
    private $term = '';
    /**
     * @var
     */
    private $terms = array();
    /**
     * @var
     */
    private $children = array();

    /**
     * @param $title
     */
    function __construct($title)
    {
        $this->title    = $title;
        $this->children = array();
        $this->terms    = array();
    }

    /**
     * @param MetricsTaxonomy $taxonomy
     *
     * @return $this
     */
    public function addChild(MetricsTaxonomy $taxonomy)
    {
        $this->terms[]    = '('.$taxonomy->getTerm().')';
        $this->children[$taxonomy->getTitle()] = $taxonomy;

        return $this;
    }

    /**
     * @return mixed
     */
    public function getChildren()
    {
        return $this->children;
    }

    /**
     * @return array
     */
    public function toArray()
    {
        $return = array(
            'title'  => $this->getTitle(),
            'isRoot' => (bool)$this->getIsRoot(),
            'isCfo'  => $this->getIsCfo(),
            'terms'  => $this->getTerms(),
        );
        if (sizeof($this->children)) {
            $return['children'] = array();
            /** @var self $subAgency */
            foreach ($this->children as $subAgency) {
                $return['children'][$subAgency->getTitle()] = $subAgency->toArray();
            }
        }

        return $return;
    }

    /**
     * @return mixed
     */
    public function getTitle()
    {
        return $this->title;
    }

    /**
     * @return mixed
     */
    public function getIsRoot()
    {
        return $this->isRoot;
    }

    /**
     * @param mixed $isRoot
     */
    public function setIsRoot($isRoot)
    {
        $this->isRoot = $isRoot;
    }

    /**
     * @return mixed
     */
    public function getIsCfo()
    {
        return $this->isCfo;
    }

    /**
     * @param mixed $isCfo
     */
    public function setIsCfo($isCfo)
    {
        $this->isCfo = $isCfo;
    }

    /**
     * @return mixed
     */
    public function getTerms()
    {
        return $this->terms;
    }

    /**
     * @return string
     */
    public function getTerm()
    {
        return $this->term;
    }

    /**
     * @param string $term
     */
    public function setTerm($term)
    {
        $this->term = $term;
        array_unshift($this->terms, "($term)");
    }
}