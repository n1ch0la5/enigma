<?php

namespace App\Jobs;

use App\Models\CoordinationCluster;
use App\Models\Narrative;
use App\Models\Post;
use App\Models\Topic;
use App\Services\Nlp\NlpClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Pull a topic's collected posts, run the NLP pipeline, and persist the outputs
 * (narratives, coordination clusters). Idempotent: clears prior results first.
 */
class AnalyzeTopic implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public function __construct(public int $topicId, public array $params = []) {}

    public function handle(NlpClient $nlp): void
    {
        $topic = Topic::findOrFail($this->topicId);

        $posts = Post::with('author')
            ->where('topic_id', $topic->id)
            ->get(['id', 'author_id', 'body', 'posted_at']);

        if ($posts->count() < 3) {
            return;
        }

        $payloadPosts = $posts->map(fn (Post $p) => [
            'id'        => $p->id,
            'author_id' => (string) ($p->author_id ?? 0),
            'text'      => $p->body,
            'posted_at' => $p->posted_at->toIso8601String(),
        ])->all();

        // Author metadata (age) powers the young-account coordination signal.
        $authors = [];
        foreach ($posts as $p) {
            if ($p->author && ($age = $p->author->accountAgeDays()) !== null) {
                $authors[(string) $p->author_id] = ['account_age_days' => $age];
            }
        }

        $result = $nlp->analyze($payloadPosts, $authors, $this->params);

        DB::transaction(function () use ($topic, $result) {
            // Idempotent re-analysis
            Narrative::where('topic_id', $topic->id)->delete();
            CoordinationCluster::where('topic_id', $topic->id)->delete();
            DB::table('edges')->where('topic_id', $topic->id)->delete();

            foreach ($result['narratives'] as $n) {
                $narrative = Narrative::create([
                    'topic_id'   => $topic->id,
                    'label'      => $n['label'],
                    'keywords'   => $n['keywords'],
                    'size'       => $n['size'],
                    'started_at' => $n['started_at'],
                    'peaked_at'  => $n['peaked_at'],
                ]);
                $rows = collect($n['post_ids'])->map(fn ($pid) => [
                    'narrative_id'      => $narrative->id,
                    'post_id'           => $pid,
                    'is_representative' => in_array($pid, $n['representative_post_ids'], true),
                ])->all();
                DB::table('narrative_posts')->insert($rows);
            }

            foreach ($result['coordination']['edges'] as $e) {
                DB::table('edges')->insert([
                    'topic_id'    => $topic->id,
                    'author_a'    => $e['author_a'] ?: null,
                    'author_b'    => $e['author_b'] ?: null,
                    'edge_type'   => 'co_similar',
                    'weight'      => $e['weight'],
                    'window_secs' => $e['window_secs'],
                ]);
            }

            foreach ($result['coordination']['clusters'] as $c) {
                CoordinationCluster::create([
                    'topic_id'          => $topic->id,
                    'author_ids'        => $c['author_ids'],
                    'score'             => $c['score'],
                    'label'             => $c['label'],
                    'signals'           => $c['signals'],
                    'baseline'          => $c['baseline'],
                    'evidence_post_ids' => $c['evidence_post_ids'],
                ]);
            }
        });
    }
}
