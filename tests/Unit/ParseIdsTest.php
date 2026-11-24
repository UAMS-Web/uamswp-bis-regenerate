<?php

/**
 * UAMSWP_BIS_Regenerate::parse_ids(): the ID box on the Tools page and the `ids` argument.
 */

declare(strict_types=1);

it('parses IDs separated by commas, spaces and new lines', /** @param list<int> $expected */ function (string $raw, array $expected): void {
    expect(UAMSWP_BIS_Regenerate::parse_ids($raw))->toBe($expected);
})->with([
    'one' => ['12', [12]],
    'commas' => ['12,34', [12, 34]],
    'commas and spaces' => ['12, 34  56', [12, 34, 56]],
    'new lines and tabs' => ["12\n34\t56", [12, 34, 56]],
    'leading and trailing separators' => [' ,12, ', [12]],
    'duplicates keep the first' => ['34 12 34', [34, 12]],
    'empty' => ['', []],
]);

it('drops parts that are not positive whole numbers', /** @param list<int> $expected */ function (string $raw, array $expected): void {
    expect(UAMSWP_BIS_Regenerate::parse_ids($raw))->toBe($expected);
})->with([
    'zero' => ['0 7', [7]],
    'negative' => ['-5 7', [7]],
    'letters' => ['abc 7', [7]],
    'digits then letters' => ['12abc 7', [7]],
    'decimal' => ['1.5 7', [7]],
    'hyphenated range' => ['1-3 7', [7]],
]);
