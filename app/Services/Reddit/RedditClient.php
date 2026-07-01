<?php

namespace App\Services\Reddit;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Thin Reddit API client using app-only (client_credentials) OAuth.
 *
 * Scope for the MVP: fetch the comment tree for a specific submission. That is
 * the cheapest, richest unit of discourse and keeps us comfortably inside the
 * free tier's 100 req/min.
 */
class RedditClient
{
    private string $userAgent;

    public function __construct()
    {
        $this->userAgent = (string) config('enigma.reddit.user_agent');
    }

    /** Fetch (and cache) an app-only bearer token. */
    private function token(): string
    {
        return Cache::remember('reddit_token', now()->addMinutes(55), function (): string {
            $resp = Http::withBasicAuth(
                config('enigma.reddit.client_id'),
                config('enigma.reddit.client_secret'),
            )
                ->withHeaders(['User-Agent' => $this->userAgent])
                ->asForm()
                ->post('https://www.reddit.com/api/v1/access_token', [
                    'grant_type' => 'client_credentials',
                ])
                ->throw()
                ->json();

            return is_array($resp) && isset($resp['access_token']) ? (string) $resp['access_token'] : '';
        });
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        $data = Http::withToken($this->token())
            ->withHeaders(['User-Agent' => $this->userAgent])
            ->baseUrl('https://oauth.reddit.com')
            ->get($path, $query)
            ->throw()
            ->json();

        return is_array($data) ? $data : [];
    }

    /**
     * Return the raw comment listing for a submission.
     * $permalink e.g. "/r/DC_Cinematic/comments/abc123/supergirl_trailer/"
     *
     * @return array<string, mixed>
     */
    public function commentsByPermalink(string $permalink, int $limit = 500): array
    {
        $path = rtrim($permalink, '/').'.json';

        return $this->get($path, ['limit' => $limit, 'depth' => 10, 'threaded' => false]);
    }

    /**
     * Search submissions in a subreddit for a query (to discover threads).
     *
     * @return array<string, mixed>
     */
    public function searchSubreddit(string $subreddit, string $query, string $sort = 'new', int $limit = 100): array
    {
        return $this->get("/r/{$subreddit}/search", [
            'q' => $query, 'restrict_sr' => 1, 'sort' => $sort, 'limit' => $limit, 't' => 'all',
        ]);
    }
}
