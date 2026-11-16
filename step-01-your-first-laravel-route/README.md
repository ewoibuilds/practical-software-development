# Step 01: Your first Laravel route

Lesson: **Your first Laravel route** (on ewoibuilds.com: `/lessons/your-first-laravel-route`)

**Your workspace folder:** `~/ewoi-learning/laravel-app` (created in the lesson with `composer create-project laravel/laravel laravel-app`, Laravel 13).

## What this step adds

Routes that connect addresses to code: `/hello` (plain text), `/lessons/{slug}` (a route parameter, escaped with `e()`, named `lesson`) and `/about` (a Blade view, named `about`, with a link built by `route()`).

## The files

```
step-01-your-first-laravel-route/
├── routes/web.php                     the four routes
├── resources/views/about.blade.php    the about page
└── tests/Feature/FirstRoutesTest.php  checks every route (extra: not in the lesson)
```

Create the app as the lesson says, then compare these files with yours or copy them in at the same paths.

## How to run it

From `~/ewoi-learning/laravel-app`:

```bash
php artisan serve
```

## Expected output

| Open | You see |
|---|---|
| `http://127.0.0.1:8000/hello` | `Hello from my first route` |
| `http://127.0.0.1:8000/lessons/git` | `You asked for the lesson: git` |
| `http://127.0.0.1:8000/about` | A page with the heading `About Amina` and a link to the Git lesson |
| `http://127.0.0.1:8000/goodbye` | `404 Not Found` |

## Check

Copy `tests/Feature/FirstRoutesTest.php` into your app and run `php artisan test`. Expected: every test passes (the summary says `passed`). `php artisan route:list` should show the `about` and `lesson` names.

## Commit

```bash
git add .
git commit -m "Add first routes and an about page"
```

## Next

[Step 02: Fix the broken route](../step-02-fix-the-broken-route)

<!-- ewoi:step -->
---

**Next:** [step-02-fix-the-broken-route](../step-02-fix-the-broken-route/) &nbsp;&middot;&nbsp; **Stuck?** [Ask with the "I am stuck" form](https://github.com/ewoibuilds/practical-software-development/issues/new/choose) &nbsp;&middot;&nbsp; **Helped?** Star [the repo](https://github.com/ewoibuilds/practical-software-development) and follow [Ewoi Builds](https://github.com/ewoibuilds).
<!-- /ewoi:step -->
