<?php

/**
 * Hook registration, with array callables so remove_action() can find them.
 *
 * Brain Monkey keeps actions and filters apart (WordPress does not), so each hook is
 * checked with the function that matches how it was added. Brain Monkey also identifies
 * an object callback by class and method, not by instance, so which instance holds a
 * hook is tested in the Integration suite.
 */

declare(strict_types=1);

it('registers every action on its own instance', function (string $hook, string $method): void {
    $plugin = new UAMSWP_BIS_Regenerate;
    $plugin->register_hooks();

    expect(has_action($hook, [$plugin, $method]))->not->toBeFalse();
})->with([
    ['admin_menu', 'add_tools_page'],
    ['admin_post_uamswp_bis_regenerate', 'handle_request'],
    ['admin_notices', 'render_notice'],
    ['admin_init', 'mirror_bis_regenerate_link'],
    ['delete_attachment', 'on_delete_attachment'],
]);

it('registers every filter on its own instance', function (string $hook, string $method): void {
    $plugin = new UAMSWP_BIS_Regenerate;
    $plugin->register_hooks();

    expect(has_filter($hook, [$plugin, $method]))->not->toBeFalse();
})->with([
    ['media_row_actions', 'add_row_action'],
    ['attachment_fields_to_edit', 'add_attachment_field'],
    ['bulk_actions-upload', 'add_bulk_action'],
    ['handle_bulk_actions-upload', 'handle_bulk_action'],
]);
