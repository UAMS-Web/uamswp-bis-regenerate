<?php

/**
 * The plugin inside a real WordPress: the database, the upload folder and the hooks.
 *
 * What the Unit suite's mocks cannot see: that the LIKE patterns match the rows MySQL
 * holds (and only those), that delete_transient() removes the value and its timeout,
 * that the folder really goes, and that the hooks fire. Runs single site and, with
 * WP_MULTISITE=1, on a network, where each site has its own options table and uploads.
 *
 * @see includes/class-uamswp-bis-regenerate.php
 */

declare(strict_types=1);

namespace UamswpBisRegenerate\Tests\Integration;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use UAMSWP_BIS_Regenerate;
use UamswpBisRegenerate\Tests\Support\IntegrationTestCase;
use WP_Hook;

final class RegenerateTest extends IntegrationTestCase
{
    private UAMSWP_BIS_Regenerate $plugin;

    /** @var list<string> Folders a test made, removed in tear_down(). */
    private array $made = [];

    public function set_up(): void
    {
        parent::set_up();
        $this->plugin = new UAMSWP_BIS_Regenerate;
    }

    public function tear_down(): void
    {
        foreach ($this->made as $dir) {
            $this->remove_tree($dir);
        }

        $this->made = [];
        parent::tear_down();
    }

    public function test_the_plugin_registers_its_hooks_on_plugins_loaded(): void
    {
        $this->assertSame(10, has_action('plugins_loaded', [UAMSWP_BIS_Regenerate::class, 'init']));
        $this->assertGreaterThan(0, did_action('plugins_loaded'));

        // init() registered a fresh instance; find it through one of its hooks.
        $this->assertTrue(has_action('delete_attachment'));
        $this->assertTrue(has_action('admin_post_'.UAMSWP_BIS_Regenerate::ACTION));
    }

    public function test_the_hooks_belong_to_the_instance_init_made_and_can_be_removed(): void
    {
        global $wp_filter;
        $this->assertIsArray($wp_filter);
        $hook = $wp_filter['delete_attachment'] ?? null;
        $this->assertInstanceOf(WP_Hook::class, $hook);

        $at_ten = $hook->callbacks[10] ?? [];
        $this->assertIsArray($at_ten);

        $registered = null;
        foreach ($at_ten as $callback) {
            $function = is_array($callback) ? ($callback['function'] ?? null) : null;
            if (is_array($function) && ($function[0] ?? null) instanceof UAMSWP_BIS_Regenerate && ($function[1] ?? null) === 'on_delete_attachment') {
                $registered = $function[0];
            }
        }

        $this->assertInstanceOf(UAMSWP_BIS_Regenerate::class, $registered);

        // WordPress tells instances apart, so a second instance holds no hook.
        $this->assertFalse(has_action('delete_attachment', [new UAMSWP_BIS_Regenerate, 'on_delete_attachment']));

        // The array callable is what lets another plugin unhook it (WP_UnitTestCase restores hooks after the test).
        $this->assertTrue(remove_action('delete_attachment', [$registered, 'on_delete_attachment']));
        $this->assertFalse(has_action('delete_attachment', [$registered, 'on_delete_attachment']));
    }

    public function test_regenerate_deletes_the_folder_and_only_that_images_transients(): void
    {
        $id = $this->image();
        $other = $id + 1000;
        $prefix = $id * 10;

        $folder = $this->bis_folder($id, ['photo-300x200-c.jpg', 'photo-600x400-c.jpg']);
        $other_folder = $this->bis_folder($other, ['other-300x200-c.jpg']);

        set_transient('image_sizer_mine1', $this->bis_url($id, 'photo-300x200-c.jpg'), DAY_IN_SECONDS);
        set_transient('image_sizer_mine2', $this->bis_url($id, 'photo-600x400-c.jpg'), DAY_IN_SECONDS);
        set_transient('image_sizer_other', $this->bis_url($other, 'other-300x200-c.jpg'), DAY_IN_SECONDS);
        // An ID that starts with this one's digits must not match: /bis-images/{id}0/.
        set_transient('image_sizer_prefix', $this->bis_url($prefix, 'x-300x200-c.jpg'), DAY_IN_SECONDS);
        // Another plugin's transient holding the same URL is not ours to delete.
        set_transient('someone_else', $this->bis_url($id, 'photo-300x200-c.jpg'), DAY_IN_SECONDS);

        $this->assertSame(['folder' => true, 'transients' => 2], $this->plugin->regenerate($id));

        $this->assertDirectoryDoesNotExist($folder);
        $this->assertDirectoryExists($other_folder);
        $this->assertFalse(get_transient('image_sizer_mine1'));
        $this->assertFalse(get_transient('image_sizer_mine2'));
        $this->assertFalse(get_option('_transient_timeout_image_sizer_mine1'));
        $this->assertNotFalse(get_transient('image_sizer_other'));
        $this->assertNotFalse(get_transient('image_sizer_prefix'));
        $this->assertNotFalse(get_transient('someone_else'));
    }

    public function test_regenerate_without_a_folder_still_clears_the_transients(): void
    {
        $id = $this->image();
        set_transient('image_sizer_only', $this->bis_url($id, 'photo-300x200-c.jpg'), DAY_IN_SECONDS);

        $this->assertSame(['folder' => false, 'transients' => 1], $this->plugin->regenerate($id));
        $this->assertFalse(get_transient('image_sizer_only'));
    }

    public function test_regenerate_refuses_what_is_not_an_image_attachment(): void
    {
        $pdf = $this->id(self::factory()->attachment->create_object(['file' => 'report.pdf', 'post_mime_type' => 'application/pdf']));
        $page = $this->id(self::factory()->post->create(['post_type' => 'page']));
        set_transient('image_sizer_pdf', $this->bis_url($pdf, 'report.pdf'), DAY_IN_SECONDS);
        set_transient('image_sizer_page', $this->bis_url($page, 'x.jpg'), DAY_IN_SECONDS);

        $this->assertNull($this->plugin->regenerate($pdf));
        $this->assertNull($this->plugin->regenerate($page));
        $this->assertNull($this->plugin->regenerate(PHP_INT_MAX));
        $this->assertNotFalse(get_transient('image_sizer_pdf'));
        $this->assertNotFalse(get_transient('image_sizer_page'));
    }

    public function test_the_bis_dir_path_filter_moves_the_folder_it_reads(): void
    {
        $id = $this->image();
        $uploads = wp_upload_dir();
        $root = $uploads['basedir'].'/resized-elsewhere';
        $callback = static fn (): string => $root;
        add_filter('bis_dir_path', $callback);

        try {
            $this->made[] = $root;
            $this->assertTrue(wp_mkdir_p($root.'/'.$id), 'wp_mkdir_p() refused '.$root.'/'.$id);
            file_put_contents($root.'/'.$id.'/a.jpg', 'x');
            set_transient('image_sizer_moved', $uploads['baseurl'].'/resized-elsewhere/'.$id.'/a.jpg', DAY_IN_SECONDS);

            $this->assertSame(['folder' => true, 'transients' => 1], $this->plugin->regenerate($id));
            $this->assertDirectoryDoesNotExist($root.'/'.$id);
        } finally {
            remove_filter('bis_dir_path', $callback);
        }
    }

    public function test_clearing_failed_resizes_deletes_only_empty_image_sizer_transients(): void
    {
        $id = $this->image();
        set_transient('image_sizer_failed1', '', DAY_IN_SECONDS);
        set_transient('image_sizer_failed2', null, DAY_IN_SECONDS);
        set_transient('image_sizer_good', $this->bis_url($id, 'photo-300x200-c.jpg'), DAY_IN_SECONDS);
        set_transient('someone_else_empty', '', DAY_IN_SECONDS);

        $this->assertSame(2, $this->plugin->delete_empty_transients());

        $this->assertFalse(get_option('_transient_image_sizer_failed1'));
        $this->assertFalse(get_option('_transient_image_sizer_failed2'));
        $this->assertNotFalse(get_transient('image_sizer_good'));
        $this->assertSame('', get_option('_transient_someone_else_empty'));
    }

    public function test_deleting_the_attachment_clears_its_transients(): void
    {
        $id = $this->image();
        set_transient('image_sizer_gone', $this->bis_url($id, 'photo-300x200-c.jpg'), DAY_IN_SECONDS);

        wp_delete_attachment($id, true);

        $this->assertFalse(get_transient('image_sizer_gone'));
    }

    public function test_the_bis_regenerate_link_clears_the_transients_bis_leaves_behind(): void
    {
        $id = $this->image();
        set_transient('image_sizer_linked', $this->bis_url($id, 'photo-300x200-c.jpg'), DAY_IN_SECONDS);
        wp_set_current_user($this->id(self::factory()->user->create(['role' => 'administrator'])));

        $_GET = ['delete-bis-image' => '', 'ids' => (string) $id, 'bis_nonce' => 'not-a-nonce'];
        $this->plugin->mirror_bis_regenerate_link();
        $this->assertNotFalse(get_transient('image_sizer_linked'), 'A bad nonce changes nothing.');

        $_GET['bis_nonce'] = wp_create_nonce('delete_bis_image');
        $this->plugin->mirror_bis_regenerate_link();
        $this->assertFalse(get_transient('image_sizer_linked'));

        $_GET = [];
    }

    public function test_the_bulk_action_regenerates_the_selected_images_and_reports_back(): void
    {
        $id = $this->image();
        set_transient('image_sizer_bulk', $this->bis_url($id, 'photo-300x200-c.jpg'), DAY_IN_SECONDS);
        wp_set_current_user($this->id(self::factory()->user->create(['role' => 'administrator'])));

        $url = $this->plugin->handle_bulk_action('https://example.org/wp-admin/upload.php', UAMSWP_BIS_Regenerate::ACTION, [(string) $id]);

        $this->assertFalse(get_transient('image_sizer_bulk'));
        $query_string = wp_parse_url($url, PHP_URL_QUERY);
        $this->assertIsString($query_string);
        parse_str($query_string, $query);
        $raw = $query[UAMSWP_BIS_Regenerate::NOTICE_ARG] ?? '';
        $this->assertIsString($raw);
        $this->assertSame(
            ['done' => [$id], 'skipped' => [], 'folders' => 0, 'transients' => 1],
            json_decode(rawurldecode($raw), true)
        );
    }

    public function test_each_site_on_a_network_keeps_its_own_folder_and_transients(): void
    {
        if (! is_multisite()) {
            $this->markTestSkipped('Runs with WP_MULTISITE=1.');
        }

        $main_id = $this->image();
        set_transient('image_sizer_main', $this->bis_url($main_id, 'a.jpg'), DAY_IN_SECONDS);

        $site = $this->id(self::factory()->blog->create());
        switch_to_blog($site);

        try {
            $id = $this->image();
            $folder = $this->bis_folder($id, ['b.jpg']);
            set_transient('image_sizer_sub', $this->bis_url($id, 'b.jpg'), DAY_IN_SECONDS);
            // The main site's URL for the same ID lives in a different options table.
            $this->assertStringContainsString('/sites/'.$site.'/', $folder);

            $this->assertSame(['folder' => true, 'transients' => 1], (new UAMSWP_BIS_Regenerate)->regenerate($id));
            $this->assertFalse(get_transient('image_sizer_sub'));
        } finally {
            restore_current_blog();
        }

        $this->assertNotFalse(get_transient('image_sizer_main'));
    }

    /**
     * Creates a JPEG attachment and returns its ID.
     */
    private function image(): int
    {
        return $this->id(self::factory()->attachment->create_object([
            'file' => 'photo.jpg',
            'post_mime_type' => 'image/jpeg',
        ]));
    }

    /**
     * A factory's result as an ID, failing the test on a WP_Error.
     *
     * @param  mixed  $result  What a factory create call returned
     */
    private function id(mixed $result): int
    {
        $this->assertIsInt($result);

        return $result;
    }

    /**
     * Makes bis-images/{ID}/ with the given files and returns its path.
     *
     * @param  int  $id  Attachment ID
     * @param  list<string>  $files  File names
     */
    private function bis_folder(int $id, array $files): string
    {
        $dir = $this->plugin->bis_root().'/'.$id;
        $this->assertTrue(wp_mkdir_p($dir), 'wp_mkdir_p() refused '.$dir);
        foreach ($files as $file) {
            file_put_contents($dir.'/'.$file, 'x');
        }

        $this->made[] = $dir;

        return $dir;
    }

    /**
     * The URL BIS would cache for a file in bis-images/{ID}/.
     *
     * @param  int  $id  Attachment ID
     * @param  string  $file  File name
     */
    private function bis_url(int $id, string $file): string
    {
        return wp_upload_dir()['baseurl'].'/bis-images/'.$id.'/'.$file;
    }

    /**
     * Removes a folder a test made, whether or not the plugin already did.
     *
     * @param  string  $dir  Folder path
     */
    private function remove_tree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if (! $item instanceof SplFileInfo) {
                continue;
            }

            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
