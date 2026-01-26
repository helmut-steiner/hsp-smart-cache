<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HSP_Cache_Maintenance {
    public static function run_db_cleanup() {
        global $wpdb;

        // Delete revisions
        $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type = 'revision'" );
        // Delete auto-drafts
        $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_status = 'auto-draft'" );
        // Delete trashed posts
        $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_status = 'trash'" );
        // Delete spam/trashed comments
        $wpdb->query( "DELETE FROM {$wpdb->comments} WHERE comment_approved IN ('spam','trash')" );

        // Delete expired transients
        $now = time();
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_%' AND option_value < %d", $now ) );
        $wpdb->query(
            $wpdb->prepare(
                "DELETE o FROM {$wpdb->options} o LEFT JOIN {$wpdb->options} t ON o.option_name = REPLACE(t.option_name, '_timeout', '') WHERE t.option_name LIKE '_transient_timeout_%' AND t.option_value < %d",
                $now
            )
        );

        return true;
    }

    public static function optimize_tables() {
        global $wpdb;
        $tables = $wpdb->get_col( 'SHOW TABLES' );
        if ( empty( $tables ) ) {
            return false;
        }
        foreach ( $tables as $table ) {
            $wpdb->query( "OPTIMIZE TABLE {$table}" );
        }
        return true;
    }
}
