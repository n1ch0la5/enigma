<?php

use App\Jobs\CollectRedditThread;
use App\Models\Author;
use App\Models\Post;
use App\Models\Topic;
use App\Services\Reddit\RedditClient;
use App\Services\Reddit\RedditConnector;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'enigma.reddit.client_id' => 'test-id',
        'enigma.reddit.client_secret' => 'test-secret',
        'enigma.reddit.user_agent' => 'enigma-tests/0.1',
    ]);
});

/**
 * @param  array<int, mixed>  $comments
 */
function fakeReddit(array $comments): void
{
    Http::preventStrayRequests();
    Http::fake([
        'www.reddit.com/api/v1/access_token' => Http::response([
            'access_token' => 'test-token',
            'expires_in' => 3600,
        ]),
        'oauth.reddit.com/*' => Http::response([
            ['kind' => 'Listing', 'data' => ['children' => []]],
            ['kind' => 'Listing', 'data' => ['children' => $comments]],
        ]),
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function fakeComment(array $overrides = []): array
{
    return [
        'kind' => 't1',
        'data' => array_merge([
            'name' => 't1_abc',
            'id' => 'abc',
            'author' => 'alice',
            'permalink' => '/r/test/comments/1/thread/abc/',
            'body' => 'Cautiously optimistic about this one',
            'score' => 12,
            'created_utc' => 1_719_400_000,
            'replies' => '',
        ], $overrides),
    ];
}

function runCollect(Topic $topic, string $permalink = '/r/test/comments/1/thread'): void
{
    (new CollectRedditThread($topic->id, $permalink))
        ->handle(app(RedditClient::class), new RedditConnector);
}

test('collects a thread into posts and authors', function () {
    fakeReddit([
        fakeComment(),
        fakeComment(['name' => 't1_def', 'id' => 'def', 'author' => 'bob', 'body' => 'Momoa as Lobo sells me']),
    ]);

    $topic = Topic::factory()->create();
    runCollect($topic);

    expect(Author::count())->toBe(2)
        ->and(Post::count())->toBe(2);

    $post = Post::where('platform_post_id', 't1_abc')->sole();
    expect($post->topic_id)->toBe($topic->id)
        ->and($post->author->handle)->toBe('alice')
        ->and($post->body_normalized)->toBe('cautiously optimistic about this one')
        ->and($post->posted_at->timestamp)->toBe(1_719_400_000)
        ->and($post->raw)->toMatchArray(['platform_post_id' => 't1_abc']);
});

test('skips comments with empty bodies', function () {
    fakeReddit([
        fakeComment(),
        fakeComment(['name' => 't1_empty', 'id' => 'empty', 'author' => 'ghost', 'body' => '   ']),
    ]);

    runCollect(Topic::factory()->create());

    expect(Post::count())->toBe(1);
    // The author row is still upserted; only the empty post is dropped.
    expect(Author::count())->toBe(2);
});

test('re-collecting the same thread is idempotent', function () {
    fakeReddit([fakeComment()]);
    $topic = Topic::factory()->create();

    runCollect($topic);
    runCollect($topic);

    expect(Post::count())->toBe(1)
        ->and(Author::count())->toBe(1);
});

test('requests the comment listing with an authenticated, identified client', function () {
    fakeReddit([fakeComment()]);
    runCollect(Topic::factory()->create(), '/r/test/comments/1/thread');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'oauth.reddit.com/r/test/comments/1/thread.json')
        && $request->hasHeader('User-Agent', 'enigma-tests/0.1')
        && $request->hasHeader('Authorization', 'Bearer test-token'));
});
