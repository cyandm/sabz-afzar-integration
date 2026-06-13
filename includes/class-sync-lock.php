<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Transient lock to prevent concurrent product sync runs (WP Cron + cron-sync.php).
 */
class SAI_Sync_Lock
{
    private const LOCK_KEY = 'sai_product_sync_running';

    /** @var int seconds — safety TTL if a run crashes without releasing */
    private const LOCK_TTL = 7200;

    public static function acquire(): bool
    {
        if (get_transient(self::LOCK_KEY)) {
            return false;
        }

        set_transient(self::LOCK_KEY, time(), self::LOCK_TTL);

        return true;
    }

    public static function release(): void
    {
        delete_transient(self::LOCK_KEY);
    }

    public static function is_locked(): bool
    {
        return (bool) get_transient(self::LOCK_KEY);
    }
}
