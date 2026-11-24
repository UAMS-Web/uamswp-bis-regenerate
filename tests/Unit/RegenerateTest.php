<?php

/**
 * Which IDs are regenerated and how the result is totalled.
 */

declare(strict_types=1);

use Brain\Monkey\Functions;

use function Brain\Monkey\Functions\when;

beforeEach(function (): void {
    // A basedir with no bis-images folder in it, so no folder is ever removed here.
    when('wp_upload_dir')->justReturn(['basedir' => sys_get_temp_dir().'/uamswp-bis-regenerate-unit-'.getmypid()]);
    when('untrailingslashit')->alias(fn (string $path): string => rtrim($path, '/\\'));

    $wpdb = bis_mock_wpdb();
    $wpdb->shouldReceive('esc_like')->andReturnUsing(fn (string $text): string => $text);
    $wpdb->shouldReceive('prepare')->andReturn('PREPARED');
    $wpdb->shouldReceive('get_col')->andReturn(['_transient_image_sizer_a', '_transient_image_sizer_b']);

    when('delete_transient')->justReturn(true);
});

afterEach(function (): void {
    unset($GLOBALS['wpdb']);
});

it('refuses an ID below 1 without looking it up', function (int $id): void {
    Functions\expect('get_post_type')->never();

    expect((new UAMSWP_BIS_Regenerate)->regenerate($id))->toBeNull();
})->with([0, -1]);

it('refuses a post that is not an attachment, or an attachment that is not an image', function (string|false $type, bool $image): void {
    when('get_post_type')->justReturn($type);
    when('wp_attachment_is_image')->justReturn($image);

    expect((new UAMSWP_BIS_Regenerate)->regenerate(12))->toBeNull();
})->with([
    'no such post' => [false, true],
    'a page' => ['page', true],
    'a PDF' => ['attachment', false],
]);

it('reports no folder when the image has none, and the transients it deleted', function (): void {
    when('get_post_type')->justReturn('attachment');
    when('wp_attachment_is_image')->justReturn(true);

    expect((new UAMSWP_BIS_Regenerate)->regenerate(12))->toBe(['folder' => false, 'transients' => 2]);
});

it('totals done and skipped IDs in the order given', function (): void {
    when('get_post_type')->alias(fn (int $id): string => $id === 99 ? 'page' : 'attachment');
    when('wp_attachment_is_image')->justReturn(true);

    expect((new UAMSWP_BIS_Regenerate)->regenerate_many([12, 99, 34]))->toBe([
        'done' => [12, 34],
        'skipped' => [99],
        'folders' => 0,
        'transients' => 4,
    ]);
});

it('regenerates the numeric IDs a bulk action passes and returns the redirect with the summary', function (): void {
    when('current_user_can')->justReturn(true);
    when('get_post_type')->justReturn('attachment');
    when('wp_attachment_is_image')->justReturn(true);
    when('wp_json_encode')->alias(fn (mixed $value): string|false => json_encode($value));
    Functions\expect('add_query_arg')
        ->once()
        ->with('bis_regenerated', rawurlencode('{"done":[5,6],"skipped":[],"folders":0,"transients":4}'), 'https://example.org/back')
        ->andReturn('https://example.org/back?bis_regenerated=x');

    expect((new UAMSWP_BIS_Regenerate)->handle_bulk_action('https://example.org/back', 'uamswp_bis_regenerate', ['5', 6, 'x', 0, null]))
        ->toBe('https://example.org/back?bis_regenerated=x');
});
