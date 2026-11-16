<?php

namespace Tests\Feature;

use App\Models\Lesson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LessonApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_list_starts_empty_and_is_paginated(): void
    {
        $this->getJson('/api/lessons')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('per_page', 20);
    }

    public function test_a_valid_lesson_is_created_with_201(): void
    {
        $this->postJson('/api/lessons', ['title' => 'Git basics', 'level' => 'beginner'])
            ->assertCreated()
            ->assertJsonPath('title', 'Git basics');

        $this->assertDatabaseHas('lessons', ['title' => 'Git basics', 'level' => 'beginner']);
    }

    public function test_bad_input_gets_422_and_saves_nothing(): void
    {
        $this->postJson('/api/lessons', ['title' => '', 'level' => 'expert'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'level']);

        $this->assertDatabaseCount('lessons', 0);
    }

    public function test_only_validated_fields_are_saved(): void
    {
        $this->postJson('/api/lessons', ['title' => 'SQL', 'level' => 'beginner', 'id' => 999])->assertCreated();

        $this->assertDatabaseMissing('lessons', ['id' => 999]);
    }

    public function test_show_returns_the_lesson_or_404(): void
    {
        $lesson = Lesson::create(['title' => 'Routes', 'level' => 'intermediate']);

        $this->getJson("/api/lessons/{$lesson->id}")->assertOk()->assertJsonPath('title', 'Routes');
        $this->getJson('/api/lessons/9999')->assertNotFound();
    }

    public function test_api_errors_are_json_even_without_an_accept_header(): void
    {
        $this->post('/api/lessons', ['title' => '', 'level' => 'expert'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'level']);
    }
}
