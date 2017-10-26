<?php

require_once __DIR__ . '/MetricsCounterFed.class.php';
require_once __DIR__ . '/MetricsCounterNonFed.class.php';

/**
 * Class MetricsDaily
 */
class MetricsDaily
{
  /**
   *
   */
  const LOCK_TITLE = 'metrics_cron_lock';
  /**
   * @var WP_DB
   */
  private $wpdb;

  /**
   *
   */
  function __construct()
  {
    global $wpdb;
    $this->wpdb = $wpdb;
  }

  /**
   * @return bool
   */
  public function run() {
    if (!$this->start()) {
      return false;
    }

    $MetricsCounterFed = new MetricsCounterFed();
    $MetricsCounterNonFed = new MetricsCounterNonFed();
    $MetricsCounterFed->updateMetrics();
    $MetricsCounterNonFed->updateMetrics();

    $this->finish();
  }

  /**
   * @return bool
   */
  private function start() {
    if (!$this->checkLock()) {
      echo "Locked: another instance of metrics script is already running. Please try again later or clean lock: ". self:: LOCK_TITLE;

      return false;
    }

    echo PHP_EOL . date("(Y-m-d H:i:s)") . '(metrics-cron) Started' . PHP_EOL;
    set_time_limit(60 * 60 * 5);  //  5 hours

    $this->cleaner();

    return true;
  }

  /**
   *
   */
  private function finish() {
    //        Publish new metrics
    $this->publishNewMetrics();

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
   *  Clean trash records, if previous cron script failed
   */
  private function cleaner()
  {
    $this->wpdb->query("DELETE FROM wp_posts WHERE post_type='metric_new'");
    $this->wpdb->query("DELETE FROM wp_postmeta WHERE post_id NOT IN (SELECT ID FROM wp_posts)");
  }

  /**
   *  Replace previous data with latest metrics
   */
  private function publishNewMetrics()
  {
    $this->wpdb->query("DELETE FROM wp_posts WHERE post_type='metric_organization'");
    $this->wpdb->query(
      "UPDATE wp_posts SET post_type='metric_organization' WHERE post_type='metric_new'"
    );
    $this->wpdb->query("DELETE FROM wp_postmeta WHERE post_id NOT IN (SELECT ID from wp_posts)");

    update_option('metrics_updated_gmt', gmdate("m/d/Y h:i A", time()) . ' GMT');
  }

  /**
   *  Unlock the system for next cron run
   */
  private function unlock()
  {
    delete_option(self::LOCK_TITLE);
  }
}
