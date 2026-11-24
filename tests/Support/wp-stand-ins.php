<?php

/**
 * Empty stand-ins for the WordPress core classes the plugin names in signatures and
 * instanceof checks, for the Unit suite only. Enough for type hints, no behavior.
 * The Integration suite uses the real classes.
 */

declare(strict_types=1);

if (! class_exists('WP_Post')) {
    final class WP_Post
    {
        public int $ID = 0;

        public string $post_mime_type = '';

        public function __construct(object $post)
        {
            foreach (get_object_vars($post) as $key => $value) {
                $this->$key = $value;
            }
        }
    }
}

if (! class_exists('WP_Filesystem_Base')) {
    class WP_Filesystem_Base {}
}

if (! class_exists('wpdb')) {
    class wpdb
    {
        public string $options = 'wp_options';
    }
}
