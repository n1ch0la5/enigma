<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Loads the single-source-of-truth schema from db/schema.sql (pgvector required).
 * Keeping the DDL in one .sql file avoids drift between the raw schema and
 * Laravel migrations. Point ENIGMA_SCHEMA_PATH at db/schema.sql.
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
        // The Enigma schema is raw Postgres DDL (pg_trgm, identity columns, JSONB,
        // array columns). It only runs on Postgres; on other drivers such as the
        // sqlite in-memory database used by the test suite it's a no-op, since
        // those tests don't exercise the Enigma tables.
        if (! $this->onPostgres()) {
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
};
