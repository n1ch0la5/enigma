<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\AnalyzeTopic;
use App\Jobs\CollectRedditThread;
use App\Models\CoordinationCluster;
use App\Models\Narrative;
use App\Models\Post;
use App\Models\Topic;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TopicController extends Controller
{
    /** Create a saved investigation. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'label'         => 'required|string|max:200',
            'query_terms'   => 'array',
            'query_terms.*' => 'string',
        ]);

        $topic = Topic::create([
            'slug'        => Str::slug($data['label']) . '-' . Str::random(4),
            'label'       => $data['label'],
            'query_terms' => $data['query_terms'] ?? [],
        ]);

        return response()->json($topic, 201);
    }

    /** Queue collection of one or more Reddit threads for this topic. */
    public function collect(Request $request, Topic $topic)
    {
        $data = $request->validate([
            'permalinks'   => 'required|array|min:1',
            'permalinks.*' => 'string',
        ]);

        foreach ($data['permalinks'] as $permalink) {
            CollectRedditThread::dispatch($topic->id, $permalink);
        }

        return response()->json(['queued' => count($data['permalinks'])]);
    }

    /** Queue the analysis pipeline. */
    public function analyze(Request $request, Topic $topic)
    {
        AnalyzeTopic::dispatch($topic->id, $request->input('params', []));
        return response()->json(['status' => 'queued']);
    }

    /** Full picture for the dashboard: timeline + narratives + coordination. */
    public function show(Topic $topic)
    {
        // Hourly mention timeline (the first thing the dashboard renders)
        $timeline = Post::where('topic_id', $topic->id)
            ->selectRaw("date_trunc('hour', posted_at) as bucket, count(*) as mentions")
            ->groupBy('bucket')->orderBy('bucket')
            ->get();

        return response()->json([
            'topic'        => $topic,
            'counts'       => ['posts' => Post::where('topic_id', $topic->id)->count()],
            'timeline'     => $timeline,
            'narratives'   => Narrative::where('topic_id', $topic->id)
                                ->orderByDesc('size')->get(),
            'coordination' => CoordinationCluster::where('topic_id', $topic->id)
                                ->orderByDesc('score')->get(),
        ]);
    }
}
