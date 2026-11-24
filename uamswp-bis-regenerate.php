<?php

/**
 * Plugin Name: UAMSWP BIS Regenerate
 * Plugin URI: https://github.com/UAMS-Web/uamswp-bis-regenerate
 * Description: Clears the Better Image Sizes files and the uams-2020 theme's cached image_sizer transients for an image, so the image goes back into the resize queue.
 * Version: 1.0.0
 * Requires at least: 6.8
 * Requires PHP: 8.2
 * Requires Plugins: better-image-sizes
 * Author: UAMS Web
 * License: GPL-2.0-or-later
 * Text Domain: uamswp-bis-regenerate
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

require_once __DIR__.'/includes/class-uamswp-bis-regenerate.php';

add_action('plugins_loaded', [UAMSWP_BIS_Regenerate::class, 'init']);
