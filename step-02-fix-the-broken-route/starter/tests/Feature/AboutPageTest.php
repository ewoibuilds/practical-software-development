<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AboutPageTest extends TestCase
{
    public function test_the_about_route_answers_get_and_has_a_name(): void
    {
        $route = Route::getRoutes()->getByName('about');

        $this->assertNotNull($route, 'No route is named about.');
        $this->assertContains('GET', $route->methods(), 'The about route does not accept GET, which links send.');
    }

    public function test_the_home_page_link_opens_the_about_page(): void
    {
        $home = $this->get('/')->assertOk();
        $this->assertMatchesRegularExpression('/href="([^"]+)"/', $home->getContent());
        preg_match('/href="([^"]+)"/', $home->getContent(), $link);

        $this->get($link[1])->assertOk()->assertSee('About us');
    }

    public function test_the_link_is_built_from_the_route_name(): void
    {
        $this->get('/')->assertSee('href="'.route('about').'"', false);
    }
}
