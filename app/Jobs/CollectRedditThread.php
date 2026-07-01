<?php

namespace App\Jobs;

use App\Models\Author;
use App\Models\Post;
use App\Models\Source;
use App\Models\Topic;
use App\Services\Reddit\RedditClient;
use App\Services\Reddit\RedditConnector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Fetch one Reddit submission's comment tree and upsert into posts/authors.
 * Rate-limited so many queued jobs stay under Reddit's 100 req/min free tier.
 */
class CollectRedditThread implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $topicId,
        public string $permalink,
    ) {}

    /**
     * Throttle to the configured RPM across all workers (see AppServiceProvider).
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new RateLimited('reddit')];
    }

    public function handle(RedditClient $client, RedditConnector $connector): void
    {
        $topic = Topic::findOrFail($this->topicId);
        $source = Source::firstOrCreate(['platform' => 'reddit', 'label' => $this->permalink]);

        $listing = $client->commentsByPermalink($this->permalink);
        $normalized = $connector->normalizeComments($listing);

        // Upsert authors, keep a handle -> id map
        $authorIds = [];
        foreach ($normalized['authors'] as $handle => $a) {
            $author = Author::updateOrCreate(
                ['platform' => 'reddit', 'platform_author_id' => $a['platform_author_id']],
                ['handle' => $a['handle'], 'meta' => $a['meta']],
            );
            $authorIds[$handle] = $author->id;
        }

        foreach ($normalized['posts'] as $p) {
            if (trim($p['body']) === '') {
                continue;
            }
            Post::updateOrCreate(
                ['platform' => 'reddit', 'platform_post_id' => $p['platform_post_id']],
                [
                    'topic_id' => $topic->id,
                    'source_id' => $source->id,
                    'author_id' => $authorIds[$p['author']] ?? null,
                    'url' => $p['url'],
                    'body' => $p['body'],
                    'body_normalized' => mb_strtolower(trim($p['body'])),
                    'score' => $p['score'],
                    'posted_at' => Carbon::createFromTimestampUTC($p['posted_at']),
                    'raw' => $p,
                ],
            );
        }
    }
}
