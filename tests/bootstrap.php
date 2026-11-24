<?php

/**
 * Unit bootstrap.
 *
 * The Unit suite runs with no WordPress: WordPress functions are mocked per
 * test with Brain Monkey. The Integration suite does not come through here; it
 * has its own Composer setup and bootstrap under tests/Integration.
 */

declare(strict_types=1);

require_once __DIR__.'/../vendor/autoload.php';

// Patchwork first, so a test can redefine functions declared in files loaded after it.
require_once __DIR__.'/../vendor/antecedent/patchwork/Patchwork.php';

// Plugin files open with `defined('ABSPATH') || exit;`. Point it at a directory that is
// not a WordPress install, so code that requires a core file fails loudly here.
if (! defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir().'/uamswp-bis-regenerate-tests-no-wordpress/');
}

// Stand-ins for the core classes the plugin's signatures name.
require_once __DIR__.'/Support/wp-stand-ins.php';

// The file that DEFINES the class, never the main plugin file, which registers a hook.
require_once __DIR__.'/../includes/class-uamswp-bis-regenerate.php';
