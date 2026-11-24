<?php

/**
 * The result notice: building it from a summary, and rendering it after a redirect.
 */

declare(strict_types=1);

use function Brain\Monkey\Functions\stubEscapeFunctions;
use function Brain\Monkey\Functions\stubTranslationFunctions;
use function Brain\Monkey\Functions\when;

beforeEach(function (): void {
    stubTranslationFunctions();
    stubEscapeFunctions();
    $_GET = [];
});

afterEach(function (): void {
    $_GET = [];
});

it('reports what was regenerated', function (): void {
    $notice = UAMSWP_BIS_Regenerate::notice_for(['done' => [12, 34], 'skipped' => [], 'folders' => 1, 'transients' => 5]);

    expect($notice['class'])->toBe('notice-success')
        ->and($notice['messages'])->toBe([
            'Regenerated 12, 34: removed 1 folder(s) and 5 cached entries. They will be queued again on the next page view.',
        ]);
});

it('warns when every ID was skipped', function (): void {
    $notice = UAMSWP_BIS_Regenerate::notice_for(['done' => [], 'skipped' => [99], 'folders' => 0, 'transients' => 0]);

    expect($notice['class'])->toBe('notice-warning')
        ->and($notice['messages'])->toBe(['Skipped 99: not an image attachment on this site.']);
});

it('reports done, cleared and skipped together, as a success', function (): void {
    $notice = UAMSWP_BIS_Regenerate::notice_for(['done' => [12], 'skipped' => [99], 'folders' => 1, 'transients' => 2, 'empty' => 3]);

    expect($notice['class'])->toBe('notice-success')
        ->and($notice['messages'])->toHaveCount(3)
        ->and($notice['messages'][1])->toBe('Cleared 3 cached empty result(s).');
});

it('treats clearing failed resizes alone as a success', function (): void {
    $notice = UAMSWP_BIS_Regenerate::notice_for(['done' => [], 'skipped' => [], 'folders' => 0, 'transients' => 0, 'empty' => 0]);

    expect($notice['class'])->toBe('notice-success')
        ->and($notice['messages'])->toBe(['Cleared 0 cached empty result(s).']);
});

it('says so when nothing was asked for', function (): void {
    $notice = UAMSWP_BIS_Regenerate::notice_for(['done' => [], 'skipped' => [], 'folders' => 0, 'transients' => 0]);

    expect($notice['class'])->toBe('notice-warning')
        ->and($notice['messages'])->toBe(['No attachment IDs were given.']);
});

it('ignores values in a tampered summary that are not counts or IDs', function (): void {
    $notice = UAMSWP_BIS_Regenerate::notice_for([
        'done' => [12, '<script>', -3, 0, 4.5],
        'folders' => '<b>',
        'transients' => [],
        'empty' => 'x',
    ]);

    expect($notice['messages'])->toBe([
        'Regenerated 12: removed 0 folder(s) and 0 cached entries. They will be queued again on the next page view.',
    ]);
});

it('round-trips the summary through the redirect URL', function (): void {
    when('wp_json_encode')->alias(fn (mixed $value): string|false => json_encode($value));
    when('add_query_arg')->alias(fn (string $key, string $value, string $url): string => $url.'&'.$key.'='.$value);

    $summary = ['done' => [12], 'skipped' => [], 'folders' => 1, 'transients' => 2];
    $url = UAMSWP_BIS_Regenerate::with_notice('https://example.org/wp-admin/upload.php?mode=list', $summary);

    $query_string = parse_url($url, PHP_URL_QUERY);
    assert(is_string($query_string));
    parse_str($query_string, $query);
    expect($query)->toHaveKey(UAMSWP_BIS_Regenerate::NOTICE_ARG);

    $encoded = $query[UAMSWP_BIS_Regenerate::NOTICE_ARG];
    assert(is_string($encoded));
    expect(json_decode(rawurldecode($encoded), true))->toBe($summary);
});

it('renders the notice for a capable user, escaped', function (): void {
    when('current_user_can')->justReturn(true);
    when('wp_unslash')->returnArg();
    when('wp_kses')->returnArg();
    when('esc_html')->alias(fn (string $text): string => htmlspecialchars($text, ENT_QUOTES));

    when('sanitize_text_field')->returnArg();
    $_GET[UAMSWP_BIS_Regenerate::NOTICE_ARG] = (string) json_encode(['done' => [], 'skipped' => ['<i>'], 'empty' => 1]);

    ob_start();
    (new UAMSWP_BIS_Regenerate)->render_notice();
    $html = (string) ob_get_clean();

    expect($html)->toBe('<div class="notice notice-success is-dismissible"><p>Cleared 1 cached empty result(s).</p></div>');
});

it('renders nothing without a summary, for a user who cannot regenerate, or for a summary that is not JSON', /** @param array<string, mixed> $get */ function (array $get, bool $can): void {
    when('current_user_can')->justReturn($can);
    when('wp_unslash')->returnArg();
    when('sanitize_text_field')->returnArg();

    $_GET = $get;

    ob_start();
    (new UAMSWP_BIS_Regenerate)->render_notice();

    expect((string) ob_get_clean())->toBe('');
})->with([
    'no summary' => [[], true],
    'empty summary' => [[UAMSWP_BIS_Regenerate::NOTICE_ARG => ''], true],
    'summary is an array' => [[UAMSWP_BIS_Regenerate::NOTICE_ARG => ['x']], true],
    'not allowed' => [[UAMSWP_BIS_Regenerate::NOTICE_ARG => '{}'], false],
    'not JSON' => [[UAMSWP_BIS_Regenerate::NOTICE_ARG => 'nope'], true],
]);
