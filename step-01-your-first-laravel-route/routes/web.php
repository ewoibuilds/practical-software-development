<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/hello', function () {
    return 'Hello from my first route';
});

Route::get('/lessons/{slug}', function (string $slug) {
    return 'You asked for the lesson: '.e($slug);
})->name('lesson');

Route::get('/about', function () {
    return view('about', ['name' => 'Amina']);
})->name('about');
