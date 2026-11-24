<?php

/**
 * The Media Library row link, the attachment-details button, the bulk action and the Tools page.
 */

declare(strict_types=1);

use Brain\Monkey\Functions;

use function Brain\Monkey\Functions\stubEscapeFunctions;
use function Brain\Monkey\Functions\stubTranslationFunctions;
use function Brain\Monkey\Functions\when;

beforeEach(function (): void {
    stubTranslationFunctions();
    stubEscapeFunctions();
    when('admin_url')->alias(fn (string $path): string => 'https://example.org/wp-admin/'.$path);
    when('wp_nonce_url')->alias(fn (string $url, string $action): string => $url.'&_wpnonce=nonce-for-'.$action);
});

function bis_attachment(int $id): WP_Post
{
    return new WP_Post((object) ['ID' => $id, 'post_mime_type' => 'image/jpeg']);
}

it('builds a nonced admin-post URL for one attachment', function (): void {
    expect((new UAMSWP_BIS_Regenerate)->action_url(42))
        ->toBe('https://example.org/wp-admin/admin-post.php?action=uamswp_bis_regenerate&ids=42&_wpnonce=nonce-for-uamswp_bis_regenerate');
});

it('adds the row link to an image for a user who can regenerate', function (): void {
    when('current_user_can')->justReturn(true);
    Functions\expect('wp_attachment_is_image')->once()->andReturn(true);

    $actions = (new UAMSWP_BIS_Regenerate)->add_row_action(['edit' => '<a>Edit</a>'], bis_attachment(42));

    expect($actions)->toHaveKeys(['edit', 'uamswp-bis-regenerate'])
        ->and($actions['uamswp-bis-regenerate'])->toContain('ids=42')
        ->and($actions['uamswp-bis-regenerate'])->toContain('_wpnonce=nonce-for-uamswp_bis_regenerate')
        ->and($actions['uamswp-bis-regenerate'])->toContain('Regenerate sizes + cache');
});

it('accepts the regenerate_images capability as well as manage_options', function (): void {
    when('current_user_can')->alias(fn (string $cap): bool => $cap === 'regenerate_images');
    when('wp_attachment_is_image')->justReturn(true);

    expect((new UAMSWP_BIS_Regenerate)->add_row_action([], bis_attachment(42)))->toHaveKey('uamswp-bis-regenerate');
});

it('leaves the row actions alone for a non-image or a user who cannot regenerate', function (bool $can, bool $image): void {
    when('current_user_can')->justReturn($can);
    when('wp_attachment_is_image')->justReturn($image);

    expect((new UAMSWP_BIS_Regenerate)->add_row_action(['edit' => 'x'], bis_attachment(42)))->toBe(['edit' => 'x']);
})->with([
    'not allowed' => [false, true],
    'not an image' => [true, false],
]);

it('adds the button to the attachment details with the ID', function (): void {
    when('current_user_can')->justReturn(true);
    when('wp_attachment_is_image')->justReturn(true);

    $fields = (new UAMSWP_BIS_Regenerate)->add_attachment_field([], bis_attachment(42));
    expect($fields)->toHaveKey('uamswp_bis_regenerate');

    $field = $fields['uamswp_bis_regenerate'];
    assert(is_array($field));

    expect($field['input'])->toBe('html')
        ->and($field['html'])->toContain('ids=42')
        ->and($field['html'])->toContain('ID 42.');
});

it('leaves the attachment fields alone for a user who cannot regenerate', function (): void {
    when('current_user_can')->justReturn(false);
    when('wp_attachment_is_image')->justReturn(true);

    expect((new UAMSWP_BIS_Regenerate)->add_attachment_field(['x' => 1], bis_attachment(42)))->toBe(['x' => 1]);
});

it('offers the bulk action only to a user who can regenerate', /** @param array<string, string> $expected */ function (bool $can, array $expected): void {
    when('current_user_can')->justReturn($can);

    expect((new UAMSWP_BIS_Regenerate)->add_bulk_action(['delete' => 'Delete']))->toBe($expected);
})->with([
    'allowed' => [true, ['delete' => 'Delete', 'uamswp_bis_regenerate' => 'Regenerate sizes + cache']],
    'not allowed' => [false, ['delete' => 'Delete']],
]);

it('passes another bulk action, or a user who cannot regenerate, straight through', function (string $action, bool $can): void {
    when('current_user_can')->justReturn($can);
    Functions\expect('get_post_type')->never();

    expect((new UAMSWP_BIS_Regenerate)->handle_bulk_action('https://example.org/back', $action, [1, 2]))
        ->toBe('https://example.org/back');
})->with([
    'other action' => ['delete', true],
    'not allowed' => ['uamswp_bis_regenerate', false],
]);

it('adds the Tools page only for a user who can regenerate', function (bool $can, int $times): void {
    when('current_user_can')->justReturn($can);
    Functions\expect('add_management_page')
        ->times($times)
        ->with('Regenerate BIS image', 'Regenerate BIS image', 'upload_files', 'uamswp-bis-regenerate', Mockery::type('array'));

    (new UAMSWP_BIS_Regenerate)->add_tools_page();
})->with([
    'allowed' => [true, 1],
    'not allowed' => [false, 0],
]);
