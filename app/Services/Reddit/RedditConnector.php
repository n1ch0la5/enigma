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
     * @return array{posts: array<int, array>, authors: array<string, array>}
     */
    public function normalizeComments(array $listing): array
    {
        $posts = [];
        $authors = [];

        // A permalink fetch returns [submission_listing, comments_listing].
        $commentsRoot = $listing[1]['data']['children'] ?? [];
        $this->walk($commentsRoot, $posts, $authors);

        return ['posts' => $posts, 'authors' => $authors];
    }

    private function walk(array $children, array &$posts, array &$authors, ?string $parentId = null): void
    {
        foreach ($children as $child) {
            if (($child['kind'] ?? null) !== 't1') {
                continue; // t1 = comment; skip "more" stubs and non-comments
            }
            $d = $child['data'];
            $author = $d['author'] ?? '[deleted]';

            if ($author !== '[deleted]' && !isset($authors[$author])) {
                $authors[$author] = [
                    'platform'           => 'reddit',
                    'platform_author_id' => $author,
                    'handle'             => $author,
                    // Reddit doesn't return account age on comments; enrich later
                    // via /user/{name}/about.json if you need account_age_days.
                    'meta'               => [],
                ];
            }

            $posts[] = [
                'platform'         => 'reddit',
                'platform_post_id' => $d['name'] ?? ('t1_' . $d['id']),
                'author'           => $author,
                'parent_ext_id'    => $parentId,
                'url'              => 'https://reddit.com' . ($d['permalink'] ?? ''),
                'body'             => $d['body'] ?? '',
                'score'            => $d['score'] ?? 0,
                'posted_at'        => (int) ($d['created_utc'] ?? 0),
            ];

            // Recurse into replies if present (threaded=false usually flattens,
            // but handle both shapes defensively).
            $replies = $d['replies']['data']['children'] ?? [];
            if ($replies) {
                $this->walk($replies, $posts, $authors, $d['name'] ?? null);
            }
        }
    }
}
