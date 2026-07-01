<?php

use App\Http\Controllers\Api\TopicController;
use Illuminate\Support\Facades\Route;

// Enigma API (add these to your app's routes/api.php)
Route::post('/topics', [TopicController::class, 'store']);
Route::get('/topics/{topic}', [TopicController::class, 'show']);
Route::post('/topics/{topic}/collect', [TopicController::class, 'collect']);
Route::post('/topics/{topic}/analyze', [TopicController::class, 'analyze']);
