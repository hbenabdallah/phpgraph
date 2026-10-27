<?php

// A global variable has the class its `@global` tag gives, in the function that documents it.

namespace {
    class wpdb { public function query(): void {} }
    class WP_Query { public function query(): void {} }

    /**
     * @global wpdb     $wpdb     WordPress database abstraction object.
     * @global WP_Query $wp_query WordPress Query object.
     */
    function documented(): void
    {
        global $wpdb, $wp_query;
        $wpdb->query(); // @phpgraph-expect calls wpdb::query INFERRED
        $wp_query->query(); // @phpgraph-expect calls WP_Query::query INFERRED
    }

    // Without the tag, the global could hold anything: two classes declare query(), nothing is guessed.
    function undocumented(): void
    {
        global $wpdb;
        $wpdb->query();
    }

    /**
     * @global string $wp_version
     * @global wpdb   $wpdb
     */
    function reassigned(): void
    {
        global $wp_version, $wpdb;
        $wpdb = new WP_Query();
        $wpdb->query(); // @phpgraph-expect calls WP_Query::query INFERRED
    }

    class Installer
    {
        /**
         * @global wpdb $wpdb
         */
        public function install(): void
        {
            global $wpdb;
            $wpdb->query(); // @phpgraph-expect calls wpdb::query INFERRED
        }

        public function other(): void
        {
            global $wpdb;
            $wpdb->query();
        }
    }
}
