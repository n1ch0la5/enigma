<?php

use App\Jobs\AnalyzeTopic;
use App\Models\Author;
use App\Models\CoordinationCluster;
use App\Models\Narrative;
use App\Models\Post;
use App\Models\Topic;
use App\Services\Nlp\NlpClient;
use Illuminate\Support\Facades\DB;

/**
 * @return array{topic: Topic, posts: array<int, Post>, authors: array<int, Author>}
 */
function seedAnalyzableTopic(): array
{
    $topic = Topic::factory()->create();
    $a = Author::factory()->aged()->create();
    $b = Author::factory()->young()->create();

    $posts = [
        Post::factory()->for($topic)->create(['author_id' => $a->id, 'posted_at' => '2026-06-26 10:00:00']),
        Post::factory()->for($topic)->create(['author_id' => $b->id, 'posted_at' => '2026-06-26 10:00:30']),
        Post::factory()->for($topic)->create(['author_id' => $b->id, 'posted_at' => '2026-06-26 10:01:00']),
    ];

    return ['topic' => $topic, 'posts' => $posts, 'authors' => [$a, $b]];
}

/**
 * @param  array<int, Post>  $posts
 * @param  array<int, Author>  $authors
 * @return array<string, mixed>
 */
function nlpResult(array $posts, array $authors): array
{
    return [
        'counts' => ['posts' => count($posts), 'narratives' => 1, 'dup_groups' => 0],
        'narratives' => [[
            'label' => 'woke / flop',
            'keywords' => ['woke', 'flop'],
            'size' => 3,
            'started_at' => '2026-06-26T10:00:00+00:00',
            'peaked_at' => '2026-06-26T10:00:00+00:00',
            'post_ids' => array_map(fn (Post $p) => $p->id, $posts),
            'representative_post_ids' => [$posts[0]->id],
        ]],
        'repetition' => [],
        'coordination' => [
            'edges' => [[
                'author_a' => (string) $authors[0]->id,
                'author_b' => (string) $authors[1]->id,
                'weight' => 3,
                'window_secs' => 120,
            ]],
            'clusters' => [[
                'author_ids' => [(string) $authors[0]->id, (string) $authors[1]->id],
                'score' => 0.87,
                'label' => 'strong',
                'signals' => ['synchrony' => 0.9, 'similarity' => 0.85],
                'baseline' => ['expected_coactions' => 1.2, 'observed_coactions' => 3],
                'evidence_post_ids' => [$posts[0]->id, $posts[1]->id],
            ]],
            'baseline' => ['mean' => 1.2, 'std' => 0.4],
        ],
    ];
}

test('persists narratives, edges, and coordination clusters from the NLP result', function () {
    ['topic' => $topic, 'posts' => $posts, 'authors' => $authors] = seedAnalyzableTopic();

    $this->mock(NlpClient::class, function ($mock) use ($posts, $authors) {
        $mock->shouldReceive('analyze')
            ->once()
            ->withArgs(function (array $payloadPosts, array $payloadAuthors) use ($posts, $authors) {
                // All 3 posts sent; both authors have account ages (young-account signal).
                return count($payloadPosts) === count($posts)
                    && array_key_exists((string) $authors[1]->id, $payloadAuthors);
            })
            ->andReturn(nlpResult($posts, $authors));
    });

    (new AnalyzeTopic($topic->id))->handle(app(NlpClient::class));

    $narrative = Narrative::sole();
    expect($narrative->label)->toBe('woke / flop')
        ->and($narrative->keywords)->toBe(['woke', 'flop'])
        ->and(DB::table('narrative_posts')->where('narrative_id', $narrative->id)->count())->toBe(3)
        ->and(DB::table('narrative_posts')->where('is_representative', true)->count())->toBe(1);

    expect(DB::table('edges')->where('topic_id', $topic->id)->count())->toBe(1);

    $cluster = CoordinationCluster::sole();
    expect($cluster->score)->toEqualWithDelta(0.87, 0.0001)
        ->and($cluster->label)->toBe('strong')
        ->and($cluster->author_ids)->toBe([(string) $authors[0]->id, (string) $authors[1]->id])
        ->and($cluster->evidence_post_ids)->toBe([$posts[0]->id, $posts[1]->id])
        ->and($cluster->signals)->toMatchArray(['synchrony' => 0.9]);
});

test('re-analysis replaces previous results instead of duplicating them', function () {
    ['topic' => $topic, 'posts' => $posts, 'authors' => $authors] = seedAnalyzableTopic();

    $this->mock(NlpClient::class, function ($mock) use ($posts, $authors) {
        $mock->shouldReceive('analyze')->twice()->andReturn(nlpResult($posts, $authors));
    });

    (new AnalyzeTopic($topic->id))->handle(app(NlpClient::class));
    (new AnalyzeTopic($topic->id))->handle(app(NlpClient::class));

    expect(Narrative::count())->toBe(1)
        ->and(CoordinationCluster::count())->toBe(1)
        ->and(DB::table('edges')->count())->toBe(1)
        ->and(DB::table('narrative_posts')->count())->toBe(3);
});

test('does nothing when a topic has fewer than three posts', function () {
    $topic = Topic::factory()->create();
    Post::factory()->for($topic)->count(2)->create();

    $this->mock(NlpClient::class, function ($mock) {
        $mock->shouldReceive('analyze')->never();
    });

    (new AnalyzeTopic($topic->id))->handle(app(NlpClient::class));

    expect(Narrative::count())->toBe(0);
});
