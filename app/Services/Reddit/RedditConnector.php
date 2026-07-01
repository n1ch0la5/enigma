<?php

namespace App\Services\Reddit;

/**
 * Normalizes Reddit's nested comment JSON into flat, platform-agnostic rows that
 * match the `posts` / `authors` schema. Every connector (X, Instagram) implements
 * the same shape so the analysis layer never learns platform specifics.
 */
class RedditConnector
{
    /**
     * @param  array<int|string, mixed>  $listing
     * @return array{
     *     posts: list<array{platform: string, platform_post_id: string, author: string, parent_ext_id: string|null, url: string, body: string, score: int, posted_at: int}>,
     *     authors: array<string, array{platform: string, platform_author_id: string, handle: string, meta: array<string, mixed>}>
     * }
     */
    public function normalizeComments(array $listing): array
    {
        $posts = [];
        $authors = [];

        // A permalink fetch returns [submission_listing, comments_listing].
        $this->walk($this->childrenOf($listing[1] ?? null), $posts, $authors);

        return ['posts' => $posts, 'authors' => $authors];
    }

    /**
     * @param  list<mixed>  $children
     * @param  list<array{platform: string, platform_post_id: string, author: string, parent_ext_id: string|null, url: string, body: string, score: int, posted_at: int}>  $posts
     * @param  array<string, array{platform: string, platform_author_id: string, handle: string, meta: array<string, mixed>}>  $authors
     */
    private function walk(array $children, array &$posts, array &$authors, ?string $parentId = null): void
    {
        foreach ($children as $child) {
            if (! is_array($child) || ($child['kind'] ?? null) !== 't1') {
                continue; // t1 = comment; skip "more" stubs and non-comments
            }

            $d = $child['data'] ?? null;
            if (! is_array($d)) {
                continue;
            }

            $author = is_string($d['author'] ?? null) ? $d['author'] : '[deleted]';

            if ($author !== '[deleted]' && ! isset($authors[$author])) {
                $authors[$author] = [
                    'platform' => 'reddit',
                    'platform_author_id' => $author,
                    'handle' => $author,
                    // Reddit doesn't return account age on comments; enrich later
                    // via /user/{name}/about.json if you need account_age_days.
                    'meta' => [],
                ];
            }

            $name = is_string($d['name'] ?? null) ? $d['name'] : ('t1_'.(string) ($d['id'] ?? ''));

            $posts[] = [
                'platform' => 'reddit',
                'platform_post_id' => $name,
                'author' => $author,
                'parent_ext_id' => $parentId,
                'url' => 'https://reddit.com'.(is_string($d['permalink'] ?? null) ? $d['permalink'] : ''),
                'body' => is_string($d['body'] ?? null) ? $d['body'] : '',
                'score' => is_int($d['score'] ?? null) ? $d['score'] : 0,
                'posted_at' => (int) ($d['created_utc'] ?? 0),
            ];

            // Recurse into replies if present (threaded=false usually flattens,
            // but handle both shapes defensively).
            $replies = $this->childrenOf($d['replies'] ?? null);
            if ($replies !== []) {
                $this->walk($replies, $posts, $authors, $name);
            }
        }
    }

    /**
     * Safely pull a Reddit listing's `data.children` array from an untyped node.
     *
     * @return list<mixed>
     */
    private function childrenOf(mixed $node): array
    {
        if (! is_array($node)) {
            return [];
        }

        $data = $node['data'] ?? null;
        if (! is_array($data)) {
            return [];
        }

        $children = $data['children'] ?? null;

        return is_array($children) ? array_values($children) : [];
    }
}
