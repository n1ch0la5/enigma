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
        return env('ENIGMA_SCHEMA_PATH', base_path('db/schema.local.sql'));
    }

    public function up(): void
    {
        $path = $this->schemaPath();
        if (!is_file($path)) {
            throw new RuntimeException("Enigma schema not found at {$path}. Set ENIGMA_SCHEMA_PATH.");
        }
        DB::unprepared(file_get_contents($path));
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS reports, coordination_clusters, edges,
                post_dup_group, dup_groups, narrative_posts, narratives,
                posts, authors, sources, topics CASCADE;
        SQL);
    }
};
