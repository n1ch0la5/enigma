<?php

use App\Services\Reddit\RedditConnector;

/**
 * @param  array<int, mixed>  $children
 * @return array<int, mixed>
 */
function redditListing(array $children): array
{
    // A permalink fetch returns [submission_listing, comments_listing].
    return [
        ['kind' => 'Listing', 'data' => ['children' => []]],
        ['kind' => 'Listing', 'data' => ['children' => $children]],
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function redditComment(array $overrides = []): array
{
    return [
        'kind' => 't1',
        'data' => array_merge([
            'name' => 't1_abc',
            'id' => 'abc',
            'author' => 'alice',
            'permalink' => '/r/test/comments/1/thread/abc/',
            'body' => 'A perfectly normal comment',
            'score' => 5,
            'created_utc' => 1_719_400_000,
            'replies' => '', // Reddit sends an empty string for leaf comments
        ], $overrides),
    ];
}

test('normalizes a flat comment listing into posts and authors', function () {
    $listing = redditListing([
        redditComment(),
        redditComment(['name' => 't1_def', 'id' => 'def', 'author' => 'bob', 'body' => 'Another take', 'score' => 2]),
    ]);

    $result = (new RedditConnector)->normalizeComments($listing);

    expect($result['posts'])->toHaveCount(2)
        ->and($result['authors'])->toHaveKeys(['alice', 'bob'])
        ->and($result['posts'][0])->toMatchArray([
            'platform' => 'reddit',
            'platform_post_id' => 't1_abc',
            'author' => 'alice',
            'parent_ext_id' => null,
            'body' => 'A perfectly normal comment',
            'score' => 5,
            'posted_at' => 1_719_400_000,
        ])
        ->and($result['posts'][0]['url'])->toBe('https://reddit.com/r/test/comments/1/thread/abc/');
});

test('walks nested replies and records the parent chain', function () {
    $child = redditComment(['name' => 't1_child', 'id' => 'child', 'author' => 'bob', 'body' => 'reply']);
    $parent = redditComment([
        'replies' => ['kind' => 'Listing', 'data' => ['children' => [$child]]],
    ]);

    $result = (new RedditConnector)->normalizeComments(redditListing([$parent]));

    expect($result['posts'])->toHaveCount(2)
        ->and($result['posts'][1]['platform_post_id'])->toBe('t1_child')
        ->and($result['posts'][1]['parent_ext_id'])->toBe('t1_abc');
});

test('skips "more" stubs and non-comment nodes', function () {
    $listing = redditListing([
        redditComment(),
        ['kind' => 'more', 'data' => ['children' => ['x1', 'x2'], 'count' => 250]],
        'not-even-an-array',
    ]);

    $result = (new RedditConnector)->normalizeComments($listing);

    expect($result['posts'])->toHaveCount(1);
});

test('deleted authors produce posts but no author rows', function () {
    $listing = redditListing([
        redditComment(['author' => '[deleted]', 'name' => 't1_ghost', 'id' => 'ghost']),
    ]);

    $result = (new RedditConnector)->normalizeComments($listing);

    expect($result['posts'])->toHaveCount(1)
        ->and($result['posts'][0]['author'])->toBe('[deleted]')
        ->and($result['authors'])->toBe([]);
});
