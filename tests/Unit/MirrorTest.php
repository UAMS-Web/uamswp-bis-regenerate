<?php

/**
 * Clearing the transients when BIS's own "Regenerate BIS images" link runs.
 */

declare(strict_types=1);

use function Brain\Monkey\Functions\expect;
use function Brain\Monkey\Functions\when;

beforeEach(function (): void {
    $_GET = [];
    when('sanitize_text_field')->returnArg();
    when('wp_unslash')->returnArg();
    when('current_user_can')->justReturn(true);
});

afterEach(function (): void {
    $_GET = [];
});

/**
 * The query string of BIS's own regenerate link for IDs 12 and 34.
 *
 * @return array<string, string>
 */
function bis_link_request(): array
{
    return ['page' => 'better-image-sizes/better-image-sizes.php', 'delete-bis-image' => '', 'ids' => '12,34', 'bis_nonce' => 'abc'];
}

it('does nothing on a request that is not the BIS regenerate link', /** @param array<string, mixed> $get */ function (array $get): void {
    $_GET = $get;
    expect('wp_verify_nonce')->never();

    (new UAMSWP_BIS_Regenerate)->mirror_bis_regenerate_link();
})->with([
    'no query' => [[]],
    'no nonce' => [['delete-bis-image' => '', 'ids' => '12']],
    'no ids' => [['delete-bis-image' => '', 'bis_nonce' => 'abc']],
    'ids is an array' => [['delete-bis-image' => '', 'ids' => ['12'], 'bis_nonce' => 'abc']],
]);

it('does nothing for a user who cannot regenerate', function (): void {
    $_GET = bis_link_request();
    when('current_user_can')->justReturn(false);
    expect('wp_verify_nonce')->never();

    (new UAMSWP_BIS_Regenerate)->mirror_bis_regenerate_link();
});

it('does nothing when the BIS nonce does not verify', function (): void {
    $_GET = bis_link_request();
    expect('wp_verify_nonce')->once()->with('abc', 'delete_bis_image')->andReturn(false);
    expect('wp_upload_dir')->never();

    (new UAMSWP_BIS_Regenerate)->mirror_bis_regenerate_link();
});

it('clears the transients of each ID the link names', function (): void {
    $_GET = bis_link_request();
    expect('wp_verify_nonce')->once()->with('abc', 'delete_bis_image')->andReturn(1);
    when('wp_upload_dir')->justReturn(['basedir' => '/srv/uploads']);
    when('untrailingslashit')->returnArg();

    $wpdb = bis_mock_wpdb();
    $wpdb->shouldReceive('esc_like')->andReturnUsing(fn (string $text): string => $text);
    $wpdb->shouldReceive('prepare')->once()->with(Mockery::any(), Mockery::any(), Mockery::any(), '%/bis-images/12/%')->andReturn('Q12');
    $wpdb->shouldReceive('prepare')->once()->with(Mockery::any(), Mockery::any(), Mockery::any(), '%/bis-images/34/%')->andReturn('Q34');
    $wpdb->shouldReceive('get_col')->twice()->andReturn([]);

    try {
        (new UAMSWP_BIS_Regenerate)->mirror_bis_regenerate_link();
    } finally {
        unset($GLOBALS['wpdb']);
    }
});
