<?php

namespace Tests\Feature;

use Tests\TestCase;

class FirstRoutesTest extends TestCase
{
    public function test_hello_returns_the_text(): void
    {
        $this->get('/hello')->assertOk()->assertSee('Hello from my first route');
    }

    public function test_an_unknown_address_is_not_found(): void
    {
        $this->get('/goodbye')->assertNotFound();
    }

    public function test_the_lesson_route_reads_the_slug_and_escapes_it(): void
    {
        $this->get('/lessons/git')->assertOk()->assertSee('You asked for the lesson: git');
        $this->get('/lessons/'.rawurlencode('<img src=x>'))->assertOk()->assertDontSee('<img src=x>', false)->assertSee('&lt;img src=x&gt;', false);
    }

    public function test_the_about_page_shows_the_name_and_links_by_route_name(): void
    {
        $this->assertSame(url('/about'), route('about'));

        $this->get('/about')
            ->assertOk()
            ->assertSee('About Amina')
            ->assertSee(route('lesson', 'git'), false);
    }
}
