<?php

namespace App\Services\Nlp;

use Illuminate\Support\Facades\Http;

/** Client for the Python NLP microservice (/analyze). */
class NlpClient
{
    /**
     * @param  array<int, array{id: int, author_id: string, text: string, posted_at: string}>  $posts
     * @param  array<int|string, array{account_age_days: int}>  $authors
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function analyze(array $posts, array $authors = [], array $params = []): array
    {
        $data = Http::baseUrl(config('enigma.nlp.base_url'))
            ->timeout(config('enigma.nlp.timeout'))
            ->post('/analyze', [
                'posts' => $posts,
                'authors' => (object) $authors,
                'params' => (object) $params,
            ])
            ->throw()
            ->json();

        return is_array($data) ? $data : [];
    }

    /** @return array<string, mixed> */
    public function health(): array
    {
        $data = Http::baseUrl(config('enigma.nlp.base_url'))->get('/health')->json();

        return is_array($data) ? $data : [];
    }
}
