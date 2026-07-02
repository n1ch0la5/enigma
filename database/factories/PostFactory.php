<?php

namespace Database\Factories;

use App\Models\Author;
use App\Models\Post;
use App\Models\Topic;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Post>
 */
class PostFactory extends Factory
{
    protected $model = Post::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $body = fake()->sentence(8);

        return [
            'topic_id' => Topic::factory(),
            'author_id' => Author::factory(),
            'platform' => 'reddit',
            'platform_post_id' => 't1_'.fake()->unique()->lexify('??????'),
            'url' => fake()->url(),
            'body' => $body,
            'body_normalized' => mb_strtolower(trim($body)),
            'score' => fake()->numberBetween(0, 500),
            'posted_at' => fake()->dateTimeBetween('-7 days'),
            'raw' => [],
        ];
    }
}
