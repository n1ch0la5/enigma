<?php

namespace App\Services\Nlp;

use Illuminate\Support\Facades\Http;

/** Client for the Python NLP microservice (/analyze). */
class NlpClient
{
    /**
     * @param array $posts   [['id'=>, 'author_id'=>, 'text'=>, 'posted_at'=>ISO|epoch], ...]
     * @param array $authors ['author_id' => ['account_age_days' => int], ...]
     */
    public function analyze(array $posts, array $authors = [], array $params = []): array
    {
        return Http::baseUrl(config('enigma.nlp.base_url'))
            ->timeout(config('enigma.nlp.timeout'))
            ->post('/analyze', [
                'posts'   => $posts,
                'authors' => (object) $authors,
                'params'  => (object) $params,
            ])
            ->throw()
            ->json();
    }

    public function health(): array
    {
        return Http::baseUrl(config('enigma.nlp.base_url'))->get('/health')->json();
    }
}
