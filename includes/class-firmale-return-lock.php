<?php
if (!defined('ABSPATH')) { exit; }

/** The unique option_name index serializes operations, including with persistent caches. */
final class Firmale_Return_Lock
{
    private $name;
    private $owner;
    private $release_on_shutdown;

    public static function acquire($scope, $release_on_shutdown = true)
    {
        $lock = new self();
        $lock->name = 'firmale_lock_' . hash('sha256', $scope);
        $lock->owner = wp_generate_uuid4();
        $lock->release_on_shutdown = $release_on_shutdown;
        if (!add_option($lock->name, $lock->owner, '', false)) {
            return false;
        }
        if ($release_on_shutdown) {
            register_shutdown_function(array($lock, 'release'));
        }
        return $lock;
    }

    public function release()
    {
        global $wpdb;
        // Compare owner in SQL: a delayed worker must never remove a newer lock.
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
            $this->name, $this->owner
        ));
        wp_cache_delete($this->name, 'options');
        wp_cache_delete('notoptions', 'options');
    }

    public function owner() { return $this->owner; }

    public static function release_verified($scope, $owner)
    {
        global $wpdb;
        $name = 'firmale_lock_' . hash('sha256', $scope);
        $removed = $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $owner));
        wp_cache_delete($name, 'options');
        wp_cache_delete('notoptions', 'options');
        return $removed === 1;
    }
}
