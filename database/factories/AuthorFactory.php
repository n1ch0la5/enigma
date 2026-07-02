<?php

namespace Database\Factories;

use App\Models\Author;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Author>
 */
class AuthorFactory extends Factory
{
    protected $model = Author::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $handle = fake()->unique()->userName();

        return [
            'platform' => 'reddit',
            'platform_author_id' => $handle,
            'handle' => $handle,
            'meta' => [],
        ];
    }

    /** An account created recently (young-account coordination signal). */
    public function young(int $days = 20): static
    {
        return $this->state(fn (): array => ['account_created_at' => now()->subDays($days)]);
    }

    /** An account with a known age, so accountAgeDays() is non-null. */
    public function aged(int $days = 1500): static
    {
        return $this->state(fn (): array => ['account_created_at' => now()->subDays($days)]);
    }
}
