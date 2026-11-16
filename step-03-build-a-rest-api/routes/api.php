<?php

use App\Models\Lesson;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/lessons', function () {
    return Lesson::query()->latest()->paginate(20);
});

Route::post('/lessons', function (Request $request) {
    $data = $request->validate([
        'title' => ['required', 'string', 'max:200'],
        'level' => ['required', 'in:beginner,intermediate,advanced'],
    ]);

    $lesson = Lesson::create($data);

    return response()->json($lesson, 201);
});

Route::get('/lessons/{lesson}', function (Lesson $lesson) {
    return $lesson;
});
