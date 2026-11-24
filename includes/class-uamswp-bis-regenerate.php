<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Regenerates Better Image Sizes output for single attachments.
 *
 * Better Image Sizes (BIS) writes resized copies to uploads/[sites/N/]bis-images/{ID}/,
 * and the uams-2020 theme's image_sizer() caches each resized URL in a transient named
 * image_sizer_{md5}. Deleting both sends the image back into the theme's resize queue
 * the next time a page that uses it is viewed.
 */
final class UAMSWP_BIS_Regenerate
{
    /** Admin page slug under Tools. */
    public const PAGE_SLUG = 'uamswp-bis-regenerate';

    /** The admin-post action, the nonce action and the bulk action. */
    public const ACTION = 'uamswp_bis_regenerate';

    /** Transient prefix the uams-2020 theme's image_sizer() uses. */
    public const TRANSIENT_PREFIX = 'image_sizer_';

    /** Query arg that carries the result notice back after a redirect. */
    public const NOTICE_ARG = 'bis_regenerated';

    /**
     * Creates the instance and registers its hooks.
     */
    public static function init(): void
    {
        (new self)->register_hooks();
    }

    /**
     * Registers every hook the plugin uses.
     */
    public function register_hooks(): void
    {
        add_action('admin_menu', [$this, 'add_tools_page']);
        add_action('admin_post_'.self::ACTION, [$this, 'handle_request']);
        add_action('admin_notices', [$this, 'render_notice']);
        add_filter('media_row_actions', [$this, 'add_row_action'], 10, 2);
        add_filter('attachment_fields_to_edit', [$this, 'add_attachment_field'], 10, 2);
        add_filter('bulk_actions-upload', [$this, 'add_bulk_action']);
        add_filter('handle_bulk_actions-upload', [$this, 'handle_bulk_action'], 10, 3);

        // Keep the transients in step when BIS's own regenerate link or an attachment delete removes the folder.
        add_action('admin_init', [$this, 'mirror_bis_regenerate_link']);
        add_action('delete_attachment', [$this, 'on_delete_attachment']);
    }

    /**
     * The global $wpdb, typed.
     *
     * @throws LogicException When WordPress has not set it up
     */
    private function wpdb(): wpdb
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            throw new LogicException('UAMSWP BIS Regenerate needs $wpdb, which WordPress has not set up.');
        }

        return $wpdb;
    }

    /**
     * Clears an attachment's transients as WordPress deletes it (delete_attachment).
     *
     * @param  int  $id  Attachment ID
     */
    public function on_delete_attachment(int $id): void
    {
        $this->delete_transients_for($id);
    }

    /**
     * Whether the current user may regenerate images (the same rule BIS uses).
     */
    public function user_can(): bool
    {
        return current_user_can('manage_options') || current_user_can('regenerate_images');
    }

    /**
     * Parses a list of attachment IDs separated by commas or whitespace.
     *
     * @param  string  $raw  User input such as "12, 34 56"
     * @return list<int> Unique positive IDs, in the order given
     */
    public static function parse_ids(string $raw): array
    {
        $ids = [];
        foreach (preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            // A part that is not wholly digits ("12abc", "-5") is not an ID.
            if (preg_match('/^\d+$/', $part) !== 1) {
                continue;
            }

            $id = (int) $part;
            if ($id > 0 && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Regenerates one attachment: deletes its BIS folder and its image_sizer transients.
     *
     * @param  int  $id  Attachment ID
     * @return array{folder: bool, transients: int}|null Null when the ID is not an image attachment
     */
    public function regenerate(int $id): ?array
    {
        if ($id < 1 || get_post_type($id) !== 'attachment' || ! wp_attachment_is_image($id)) {
            return null;
        }

        return [
            'folder' => $this->delete_folder($id),
            'transients' => $this->delete_transients_for($id),
        ];
    }

    /**
     * Regenerates several attachments and totals the result.
     *
     * @param  array<int, int>  $ids  Attachment IDs
     * @return array{done: list<int>, skipped: list<int>, folders: int, transients: int}
     */
    public function regenerate_many(array $ids): array
    {
        $summary = ['done' => [], 'skipped' => [], 'folders' => 0, 'transients' => 0];
        foreach ($ids as $id) {
            $result = $this->regenerate($id);
            if ($result === null) {
                $summary['skipped'][] = $id;

                continue;
            }

            $summary['done'][] = $id;
            $summary['folders'] += (int) $result['folder'];
            $summary['transients'] += $result['transients'];
        }

        return $summary;
    }

    /**
     * Path of the BIS image root for the current site, honoring BIS's bis_dir_path filter.
     */
    public function bis_root(): string
    {
        $uploads = wp_upload_dir();
        $root = apply_filters('bis_dir_path', $uploads['basedir'].DIRECTORY_SEPARATOR.'bis-images');

        return untrailingslashit(is_string($root) ? $root : '');
    }

    /**
     * Deletes the bis-images/{ID} folder.
     *
     * @param  int  $id  Attachment ID
     * @return bool True when a folder existed and was removed
     */
    private function delete_folder(int $id): bool
    {
        $root = $this->bis_root();
        if ($root === '') {
            return false;
        }

        $dir = $root.DIRECTORY_SEPARATOR.$id;
        if (! is_dir($dir)) {
            return false;
        }

        require_once ABSPATH.'wp-admin/includes/file.php';
        WP_Filesystem();
        global $wp_filesystem;

        return $wp_filesystem instanceof WP_Filesystem_Base && $wp_filesystem->rmdir($dir, true);
    }

    /**
     * Deletes every image_sizer transient whose cached URL points into bis-images/{ID}/.
     *
     * The transient key is an md5 of the ID and the dimensions, so the ID can only be
     * matched through the stored URL.
     *
     * @param  int  $id  Attachment ID
     * @return int Number of transients deleted
     */
    public function delete_transients_for(int $id): int
    {
        $root = $this->bis_root();
        if ($id < 1 || $root === '') {
            return 0;
        }

        $wpdb = $this->wpdb();
        $needle = '/'.basename($root).'/'.$id.'/';
        $names = $wpdb->get_col($wpdb->prepare(
            'SELECT option_name FROM %i WHERE option_name LIKE %s AND option_value LIKE %s',
            $wpdb->options,
            $wpdb->esc_like('_transient_'.self::TRANSIENT_PREFIX).'%',
            '%'.$wpdb->esc_like($needle).'%'
        ));

        return $this->delete_transient_names($names);
    }

    /**
     * Deletes image_sizer transients that cached an empty URL (a failed resize).
     *
     * These hold no URL, so they cannot be tied to an attachment; clearing them
     * site-wide only sends those images back into the queue.
     *
     * @return int Number of transients deleted
     */
    public function delete_empty_transients(): int
    {
        $wpdb = $this->wpdb();
        $names = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM %i WHERE option_name LIKE %s AND (option_value = '' OR option_value IS NULL)",
            $wpdb->options,
            $wpdb->esc_like('_transient_'.self::TRANSIENT_PREFIX).'%'
        ));

        return $this->delete_transient_names($names);
    }

    /**
     * Deletes transients by option name through the API, so the object cache and timeout rows are cleared too.
     *
     * @param  array<mixed>  $option_names  Option names starting with _transient_
     * @return int Number deleted
     */
    private function delete_transient_names(array $option_names): int
    {
        $deleted = 0;
        foreach ($option_names as $option_name) {
            if (! is_string($option_name) || ! str_starts_with($option_name, '_transient_')) {
                continue;
            }

            if (delete_transient(substr($option_name, strlen('_transient_')))) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Nonced admin-post URL that regenerates one attachment.
     *
     * @param  int  $id  Attachment ID
     */
    public function action_url(int $id): string
    {
        return wp_nonce_url(
            admin_url('admin-post.php?action='.self::ACTION.'&ids='.$id),
            self::ACTION
        );
    }

    /**
     * Adds the page under Tools.
     */
    public function add_tools_page(): void
    {
        if (! $this->user_can()) {
            return;
        }

        add_management_page(
            __('Regenerate BIS image', 'uamswp-bis-regenerate'),
            __('Regenerate BIS image', 'uamswp-bis-regenerate'),
            'upload_files',
            self::PAGE_SLUG,
            [$this, 'render_tools_page']
        );
    }

    /**
     * Renders the Tools page with the ID form.
     */
    public function render_tools_page(): void
    {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Regenerate BIS image', 'uamswp-bis-regenerate'); ?></h1>
            <p><?php esc_html_e('Deletes the Better Image Sizes files and the cached image_sizer entries for an image. The image is queued again the next time a page that uses it is viewed.', 'uamswp-bis-regenerate'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
                <?php wp_nonce_field(self::ACTION); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="bis-ids"><?php esc_html_e('Attachment ID(s)', 'uamswp-bis-regenerate'); ?></label></th>
                        <td>
                            <input name="ids" id="bis-ids" type="text" class="regular-text" placeholder="1234, 5678">
                            <p class="description"><?php esc_html_e('One ID, or several separated by commas or spaces.', 'uamswp-bis-regenerate'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Failed resizes', 'uamswp-bis-regenerate'); ?></th>
                        <td>
                            <label>
                                <input name="clear_empty" type="checkbox" value="1">
                                <?php esc_html_e('Also clear every cached empty result on this site (resizes that failed and are being served blank)', 'uamswp-bis-regenerate'); ?>
                            </label>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Regenerate', 'uamswp-bis-regenerate')); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Handles the Tools form, the row action and the media modal button, then redirects back.
     */
    public function handle_request(): void
    {
        if (! $this->user_can()) {
            wp_die(esc_html__('You are not allowed to regenerate images.', 'uamswp-bis-regenerate'), '', ['response' => 403]);
        }

        check_admin_referer(self::ACTION);

        $raw = isset($_REQUEST['ids']) && is_string($_REQUEST['ids'])
            ? sanitize_text_field(wp_unslash($_REQUEST['ids']))
            : '';

        $summary = $this->regenerate_many(self::parse_ids($raw));
        if (! empty($_REQUEST['clear_empty'])) {
            $summary['empty'] = $this->delete_empty_transients();
        }

        $back = wp_get_referer() ?: admin_url('tools.php?page='.self::PAGE_SLUG);
        wp_safe_redirect(self::with_notice($back, $summary));
        exit;
    }

    /**
     * Adds the result summary to a redirect URL.
     *
     * @param  string  $url  Redirect URL
     * @param  array<string, mixed>  $summary  Result of regenerate_many(), optionally with `empty`
     */
    public static function with_notice(string $url, array $summary): string
    {
        return add_query_arg(self::NOTICE_ARG, rawurlencode((string) wp_json_encode($summary)), $url);
    }

    /**
     * Builds the notice lines for a result summary.
     *
     * @param  array<mixed>  $summary  Decoded summary from the query string
     * @return array{class: string, messages: list<string>}
     */
    public static function notice_for(array $summary): array
    {
        $done = self::int_list($summary['done'] ?? []);
        $skipped = self::int_list($summary['skipped'] ?? []);
        $folders = $summary['folders'] ?? null;
        $folders = is_numeric($folders) ? (int) $folders : 0;
        $transients = $summary['transients'] ?? null;
        $transients = is_numeric($transients) ? (int) $transients : 0;
        $messages = [];

        if ($done !== []) {
            $messages[] = sprintf(
                /* translators: 1: attachment IDs, 2: folder count, 3: cached entry count */
                __('Regenerated %1$s: removed %2$d folder(s) and %3$d cached entries. They will be queued again on the next page view.', 'uamswp-bis-regenerate'),
                implode(', ', $done),
                $folders,
                $transients
            );
        }

        if (isset($summary['empty']) && is_numeric($summary['empty'])) {
            $messages[] = sprintf(
                /* translators: %d: cached entry count */
                __('Cleared %d cached empty result(s).', 'uamswp-bis-regenerate'),
                (int) $summary['empty']
            );
        }

        if ($skipped !== []) {
            $messages[] = sprintf(
                /* translators: %s: attachment IDs */
                __('Skipped %s: not an image attachment on this site.', 'uamswp-bis-regenerate'),
                implode(', ', $skipped)
            );
        }

        if ($messages === []) {
            $messages[] = __('No attachment IDs were given.', 'uamswp-bis-regenerate');
        }

        $class = $done === [] && ($skipped !== [] || ! isset($summary['empty'])) ? 'notice-warning' : 'notice-success';

        return ['class' => $class, 'messages' => $messages];
    }

    /**
     * Keeps the positive integers of an untrusted list.
     *
     * @param  mixed  $value  Decoded JSON value
     * @return list<int>
     */
    private static function int_list(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $ints = [];
        foreach ($value as $item) {
            if (is_int($item) && $item > 0) {
                $ints[] = $item;
            }
        }

        return $ints;
    }

    /**
     * Shows the result after a redirect.
     */
    public function render_notice(): void
    {
        if (! $this->user_can()) {
            return;
        }

        // Display only: the summary changes nothing, and every value in it is escaped below.
        // PHP has already decoded the query string, so $_GET holds the JSON itself.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $raw = isset($_GET[self::NOTICE_ARG]) && is_string($_GET[self::NOTICE_ARG]) ? sanitize_text_field(wp_unslash($_GET[self::NOTICE_ARG])) : '';
        if ($raw === '') {
            return;
        }

        $summary = json_decode($raw, true);
        if (! is_array($summary)) {
            return;
        }

        $notice = self::notice_for($summary);
        printf(
            '<div class="notice %s is-dismissible"><p>%s</p></div>',
            esc_attr($notice['class']),
            wp_kses(implode('<br>', array_map(esc_html(...), $notice['messages'])), ['br' => []])
        );
    }

    /**
     * Adds a row link in the Media Library list view.
     *
     * @param  array<string, string>  $actions  Row actions
     * @param  WP_Post  $post  Attachment
     * @return array<string, string>
     */
    public function add_row_action(array $actions, WP_Post $post): array
    {
        if ($this->user_can() && wp_attachment_is_image($post)) {
            $actions['uamswp-bis-regenerate'] = sprintf(
                '<a href="%s">%s</a>',
                esc_url($this->action_url($post->ID)),
                esc_html__('Regenerate sizes + cache', 'uamswp-bis-regenerate')
            );
        }

        return $actions;
    }

    /**
     * Adds a button to the attachment details (media modal and edit screen).
     *
     * @param  array<string, mixed>  $fields  Form fields
     * @param  WP_Post  $post  Attachment
     * @return array<string, mixed>
     */
    public function add_attachment_field(array $fields, WP_Post $post): array
    {
        if ($this->user_can() && wp_attachment_is_image($post)) {
            $fields['uamswp_bis_regenerate'] = [
                'label' => __('Image sizer', 'uamswp-bis-regenerate'),
                'input' => 'html',
                'html' => sprintf(
                    '<a class="button button-small" href="%s">%s</a><p class="description">%s</p>',
                    esc_url($this->action_url($post->ID)),
                    esc_html__('Regenerate sizes + cache', 'uamswp-bis-regenerate'),
                    esc_html(sprintf(
                        /* translators: %d: attachment ID */
                        __('ID %d. Deletes generated files and cached entries.', 'uamswp-bis-regenerate'),
                        $post->ID
                    ))
                ),
            ];
        }

        return $fields;
    }

    /**
     * Adds the bulk action to the Media Library list view.
     *
     * @param  array<string, string>  $actions  Bulk actions
     * @return array<string, string>
     */
    public function add_bulk_action(array $actions): array
    {
        if ($this->user_can()) {
            $actions[self::ACTION] = __('Regenerate sizes + cache', 'uamswp-bis-regenerate');
        }

        return $actions;
    }

    /**
     * Runs the bulk action. Core has already checked the bulk-posts nonce.
     *
     * @param  string  $redirect  Redirect URL
     * @param  string  $action  Chosen bulk action
     * @param  array<mixed>  $ids  Selected attachment IDs
     */
    public function handle_bulk_action(string $redirect, string $action, array $ids): string
    {
        if ($action !== self::ACTION || ! $this->user_can()) {
            return $redirect;
        }

        $clean = [];
        foreach ($ids as $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $clean[] = (int) $id;
            }
        }

        return self::with_notice($redirect, $this->regenerate_many($clean));
    }

    /**
     * When BIS's own "Regenerate BIS images" link runs, clears the transients too.
     *
     * BIS deletes only the folder, which leaves the theme serving cached URLs to
     * files that no longer exist.
     */
    public function mirror_bis_regenerate_link(): void
    {
        if (! isset($_GET['delete-bis-image'], $_GET['ids'], $_GET['bis_nonce'])) {
            return;
        }

        if (! is_string($_GET['bis_nonce']) || ! is_string($_GET['ids']) || ! $this->user_can()) {
            return;
        }

        $nonce = is_string($_GET['bis_nonce']) ? sanitize_text_field(wp_unslash($_GET['bis_nonce'])) : '';
        if ($nonce === '' || wp_verify_nonce($nonce, 'delete_bis_image') === false) {
            return;
        }

        $raw_ids = is_string($_GET['ids']) ? sanitize_text_field(wp_unslash($_GET['ids'])) : '';
        foreach (self::parse_ids($raw_ids) as $id) {
            $this->delete_transients_for($id);
        }
    }
}
