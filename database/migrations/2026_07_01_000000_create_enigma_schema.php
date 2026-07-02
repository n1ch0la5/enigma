<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Loads the single-source-of-truth schema from db/schema.sql (pgvector required).
 * Keeping the DDL in one .sql file avoids drift between the raw schema and
 * Laravel migrations. Point ENIGMA_SCHEMA_PATH at db/schema.sql.
 *
 * On non-Postgres drivers (the sqlite in-memory database used by the test
 * suite) an equivalent schema is built with the Schema builder instead, so
 * feature tests can exercise the Enigma tables. db/schema.sql remains the
 * source of truth for production; keep createPortableSchema() in sync with it.
 */
return new class extends Migration
{
    private function schemaPath(): string
    {
        // Monorepo layout: db/ sits inside the Laravel app root. Local dev uses the
        // no-vector schema; override with ENIGMA_SCHEMA_PATH=db/schema.sql on pgvector.
        $path = config('enigma.schema_path');

        return is_string($path) && $path !== '' ? $path : base_path('db/schema.local.sql');
    }

    public function up(): void
    {
        if (! $this->onPostgres()) {
            $this->createPortableSchema();

            return;
        }

        $path = $this->schemaPath();
        if (! is_file($path)) {
            throw new RuntimeException("Enigma schema not found at {$path}. Set ENIGMA_SCHEMA_PATH.");
        }

        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException("Unable to read Enigma schema at {$path}.");
        }

        // Run the trusted, developer-authored schema file directly through PDO.
        DB::connection()->getPdo()->exec($sql);
    }

    public function down(): void
    {
        if (! $this->onPostgres()) {
            foreach ([
                'reports', 'coordination_clusters', 'edges', 'post_dup_group',
                'dup_groups', 'narrative_posts', 'narratives', 'posts',
                'authors', 'sources', 'topics',
            ] as $table) {
                Schema::dropIfExists($table);
            }

            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS reports, coordination_clusters, edges,
                post_dup_group, dup_groups, narrative_posts, narratives,
                posts, authors, sources, topics CASCADE;
        SQL);
    }

    private function onPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    /**
     * Driver-portable mirror of db/schema.sql (minus pgvector columns and
     * Postgres-specific indexes). Used for the sqlite test database.
     */
    private function createPortableSchema(): void
    {
        Schema::create('topics', function (Blueprint $table): void {
            $table->id();
            $table->text('slug')->unique();
            $table->text('label');
            $table->json('query_terms')->default('[]');
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('sources', function (Blueprint $table): void {
            $table->id();
            $table->text('platform');
            $table->text('label')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('authors', function (Blueprint $table): void {
            $table->id();
            $table->text('platform');
            $table->text('platform_author_id');
            $table->text('handle')->nullable();
            $table->timestampTz('account_created_at')->nullable();
            $table->integer('followers')->nullable();
            $table->integer('following')->nullable();
            $table->integer('total_posts')->nullable();
            $table->text('profile_location')->nullable();
            $table->text('inferred_timezone')->nullable();
            $table->json('meta')->default('{}');
            $table->timestampTz('first_seen_at')->useCurrent();
            $table->unique(['platform', 'platform_author_id']);
        });

        Schema::create('posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('topic_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('source_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('author_id')->nullable()->constrained()->nullOnDelete();
            $table->text('platform');
            $table->text('platform_post_id');
            $table->foreignId('parent_post_id')->nullable()->constrained('posts')->nullOnDelete();
            $table->text('url')->nullable();
            $table->text('body');
            $table->text('body_normalized')->nullable();
            $table->text('lang')->nullable();
            $table->integer('score')->nullable();
            $table->timestampTz('posted_at');
            $table->json('raw')->default('{}');
            $table->timestampTz('ingested_at')->useCurrent();
            $table->unique(['platform', 'platform_post_id']);
            $table->index(['topic_id', 'posted_at']);
        });

        Schema::create('narratives', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('topic_id')->nullable()->constrained()->cascadeOnDelete();
            $table->text('label')->nullable();
            $table->json('keywords')->default('[]');
            $table->integer('size')->default(0);
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('peaked_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('narrative_posts', function (Blueprint $table): void {
            $table->foreignId('narrative_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->float('similarity')->nullable();
            $table->boolean('is_representative')->default(false);
            $table->primary(['narrative_id', 'post_id']);
        });

        Schema::create('dup_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('topic_id')->nullable()->constrained()->cascadeOnDelete();
            $table->text('kind');
            $table->text('canonical')->nullable();
            $table->integer('size')->default(0);
        });

        Schema::create('post_dup_group', function (Blueprint $table): void {
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained('dup_groups')->cascadeOnDelete();
            $table->float('similarity')->nullable();
            $table->primary(['post_id', 'group_id']);
        });

        Schema::create('edges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('topic_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('author_a')->nullable()->constrained('authors')->cascadeOnDelete();
            $table->foreignId('author_b')->nullable()->constrained('authors')->cascadeOnDelete();
            $table->text('edge_type');
            $table->float('weight')->default(0);
            $table->integer('window_secs')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['topic_id', 'edge_type']);
        });

        Schema::create('coordination_clusters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('topic_id')->nullable()->constrained()->cascadeOnDelete();
            $table->json('author_ids')->default('[]');
            $table->float('score');
            $table->json('signals')->default('{}');
            $table->json('baseline')->default('{}');
            $table->json('evidence_post_ids')->default('[]');
            $table->text('label')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('topic_id')->nullable()->constrained()->cascadeOnDelete();
            $table->text('format');
            $table->json('content');
            $table->text('variant')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });
    }
};
