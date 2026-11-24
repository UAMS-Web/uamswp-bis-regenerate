<?php

/**
 * Finding and deleting the theme's image_sizer transients, with $wpdb mocked.
 *
 * What MySQL does with the LIKE patterns is the Integration suite's; this pins the
 * patterns themselves and what is done with the rows.
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Mockery\MockInterface;

use function Brain\Monkey\Functions\when;

beforeEach(function (): void {
    when('wp_upload_dir')->justReturn(['basedir' => '/srv/uploads/sites/3']);
    when('untrailingslashit')->alias(fn (string $path): string => rtrim($path, '/\\'));

    bis_mock_wpdb('wp_3_options')
        ->shouldReceive('esc_like')
        ->andReturnUsing(fn (string $text): string => addcslashes($text, '_%\\'));
});

afterEach(function (): void {
    unset($GLOBALS['wpdb']);
});

function bis_wpdb(): MockInterface
{
    $wpdb = $GLOBALS['wpdb'] ?? null;
    assert($wpdb instanceof MockInterface);

    return $wpdb;
}

it('matches transients whose cached URL is inside bis-images/{ID}/', function (): void {
    $wpdb = bis_wpdb();
    $wpdb->shouldReceive('prepare')
        ->once()
        ->with(
            'SELECT option_name FROM %i WHERE option_name LIKE %s AND option_value LIKE %s',
            'wp_3_options',
            '\\_transient\\_image\\_sizer\\_%',
            '%/bis-images/12/%'
        )
        ->andReturn('PREPARED');
    $wpdb->shouldReceive('get_col')->once()->with('PREPARED')->andReturn([
        '_transient_image_sizer_aaa',
        '_transient_image_sizer_bbb',
    ]);

    Functions\expect('delete_transient')->once()->with('image_sizer_aaa')->andReturn(true);
    Functions\expect('delete_transient')->once()->with('image_sizer_bbb')->andReturn(true);

    expect((new UAMSWP_BIS_Regenerate)->delete_transients_for(12))->toBe(2);
});

it('uses the folder name a bis_dir_path filter sets', function (): void {
    Functions\expect('apply_filters')->once()->with('bis_dir_path', '/srv/uploads/sites/3/bis-images')->andReturn('/srv/cache/resized/');

    $wpdb = bis_wpdb();
    $wpdb->shouldReceive('prepare')->once()->with(Mockery::any(), Mockery::any(), Mockery::any(), '%/resized/12/%')->andReturn('PREPARED');
    $wpdb->shouldReceive('get_col')->once()->andReturn([]);

    expect((new UAMSWP_BIS_Regenerate)->delete_transients_for(12))->toBe(0);
});

it('counts only the transients WordPress reports as deleted', function (): void {
    $wpdb = bis_wpdb();
    $wpdb->shouldReceive('prepare')->andReturn('PREPARED');
    $wpdb->shouldReceive('get_col')->andReturn(['_transient_image_sizer_aaa', '_transient_image_sizer_bbb']);

    when('delete_transient')->alias(fn (string $key): bool => $key === 'image_sizer_bbb');

    expect((new UAMSWP_BIS_Regenerate)->delete_transients_for(12))->toBe(1);
});

it('skips rows that are not transient option names', function (): void {
    $wpdb = bis_wpdb();
    $wpdb->shouldReceive('prepare')->andReturn('PREPARED');
    $wpdb->shouldReceive('get_col')->andReturn([null, 7, 'image_sizer_aaa', '_transient_image_sizer_ok']);

    Functions\expect('delete_transient')->once()->with('image_sizer_ok')->andReturn(true);

    expect((new UAMSWP_BIS_Regenerate)->delete_transients_for(12))->toBe(1);
});

it('queries nothing for an ID below 1, or when the filter empties the folder', function (int $id, mixed $filtered): void {
    when('apply_filters')->justReturn($filtered);
    bis_wpdb()->shouldReceive('get_col')->never();

    expect((new UAMSWP_BIS_Regenerate)->delete_transients_for($id))->toBe(0);
})->with([
    'zero' => [0, '/srv/uploads/bis-images'],
    'negative' => [-4, '/srv/uploads/bis-images'],
    'filter returned a non-string' => [12, false],
    'filter returned an empty string' => [12, ''],
]);

it('matches empty or NULL image_sizer transients when clearing failed resizes', function (): void {
    $wpdb = bis_wpdb();
    $wpdb->shouldReceive('prepare')
        ->once()
        ->with(
            "SELECT option_name FROM %i WHERE option_name LIKE %s AND (option_value = '' OR option_value IS NULL)",
            'wp_3_options',
            '\\_transient\\_image\\_sizer\\_%'
        )
        ->andReturn('PREPARED');
    $wpdb->shouldReceive('get_col')->once()->with('PREPARED')->andReturn(['_transient_image_sizer_ccc']);

    Functions\expect('delete_transient')->once()->with('image_sizer_ccc')->andReturn(true);

    expect((new UAMSWP_BIS_Regenerate)->delete_empty_transients())->toBe(1);
});
