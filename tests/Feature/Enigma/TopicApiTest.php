<?php

use App\Jobs\AnalyzeTopic;
use App\Jobs\CollectRedditThread;
use App\Models\CoordinationCluster;
use App\Models\Narrative;
use App\Models\Post;
use App\Models\Topic;
use Illuminate\Support\Facades\Queue;

test('a topic can be created', function () {
    $response = $this->postJson('/api/topics', [
        'label' => 'Supergirl',
        'query_terms' => ['supergirl', 'milly alcock'],
    ]);

    $response->assertCreated()->assertJsonPath('label', 'Supergirl');

    $topic = Topic::sole();
    expect($topic->slug)->toStartWith('supergirl-')
        ->and($topic->query_terms)->toBe(['supergirl', 'milly alcock']);
});

test('topic creation requires a label', function () {
    $this->postJson('/api/topics', ['query_terms' => ['x']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('label');
});

test('collect queues one job per permalink', function () {
    Queue::fake();
    $topic = Topic::factory()->create();

    $this->postJson("/api/topics/{$topic->id}/collect", [
        'permalinks' => [
            '/r/DC_Cinematic/comments/abc/supergirl_trailer/',
            '/r/movies/comments/def/supergirl_reviews/',
        ],
    ])->assertOk()->assertJson(['queued' => 2]);

    Queue::assertPushed(CollectRedditThread::class, 2);
    Queue::assertPushed(
        CollectRedditThread::class,
        fn (CollectRedditThread $job) => $job->topicId === $topic->id
    );
});

test('collect requires at least one permalink', function () {
    $topic = Topic::factory()->create();

    $this->postJson("/api/topics/{$topic->id}/collect", ['permalinks' => []])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('permalinks');
});

test('analyze queues the analysis job with params', function () {
    Queue::fake();
    $topic = Topic::factory()->create();

    $this->postJson("/api/topics/{$topic->id}/analyze", [
        'params' => ['window_secs' => 60],
    ])->assertOk()->assertJson(['status' => 'queued']);

    Queue::assertPushed(
        AnalyzeTopic::class,
        fn (AnalyzeTopic $job) => $job->topicId === $topic->id && $job->params === ['window_secs' => 60]
    );
});

test('show returns the timeline, narratives, and coordination clusters', function () {
    $topic = Topic::factory()->create();

    // Two posts in one hour bucket, one in the next.
    Post::factory()->for($topic)->create(['posted_at' => '2026-06-26 10:15:00']);
    Post::factory()->for($topic)->create(['posted_at' => '2026-06-26 10:45:00']);
    Post::factory()->for($topic)->create(['posted_at' => '2026-06-26 11:05:00']);

    Narrative::create([
        'topic_id' => $topic->id,
        'label' => 'going to flop',
        'keywords' => ['flop', 'woke'],
        'size' => 3,
    ]);

    CoordinationCluster::create([
        'topic_id' => $topic->id,
        'author_ids' => [1, 2, 3],
        'score' => 0.91,
        'label' => 'strong',
        'signals' => ['synchrony' => 0.9],
        'baseline' => ['expected_coactions' => 4.2],
        'evidence_post_ids' => [10, 11],
    ]);

    $response = $this->getJson("/api/topics/{$topic->id}")->assertOk();

    $response->assertJsonPath('counts.posts', 3)
        ->assertJsonPath('narratives.0.label', 'going to flop')
        ->assertJsonPath('coordination.0.score', 0.91)
        ->assertJsonPath('coordination.0.author_ids', [1, 2, 3]);

    $timeline = $response->json('timeline');
    expect($timeline)->toHaveCount(2)
        ->and((int) $timeline[0]['mentions'])->toBe(2)
        ->and((int) $timeline[1]['mentions'])->toBe(1);
});
