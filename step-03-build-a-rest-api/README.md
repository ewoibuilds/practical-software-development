# Step 03: Build a REST API

Lesson: [Build a REST API](https://ewoibuilds.com/lessons/build-a-rest-api)

**Your workspace folder:** `~/ewoi-learning/laravel-app` (the same app as step 01, Laravel 13).

## What this step adds

A JSON API for lessons: `GET /api/lessons` (paginated list), `POST /api/lessons` (validated, 201 Created or 422), `GET /api/lessons/{lesson}` (200 or 404).

## The files

```
step-03-build-a-rest-api/
├── routes/api.php                                              the three endpoints
├── app/Models/Lesson.php                                       the model with $fillable
├── database/migrations/2026_01_01_000000_create_lessons_table.php
└── tests/Feature/LessonApiTest.php                             checks every endpoint (extra)
```

First run `php artisan install:api` in your app as the lesson says (it creates `routes/api.php` and installs Sanctum). Then compare or copy these files. Your migration's file name starts with your own date and time; compare the contents, not the name.

## How to run it

From `~/ewoi-learning/laravel-app`:

```bash
php artisan migrate
php artisan serve
```

In a second terminal:

```bash
curl -s -X POST http://127.0.0.1:8000/api/lessons -H "Accept: application/json" -H "Content-Type: application/json" -d "{\"title\": \"Git basics\", \"level\": \"beginner\"}"
curl -s -H "Accept: application/json" http://127.0.0.1:8000/api/lessons
```

## Expected output

The first command returns the new lesson as JSON with an `id`. The second returns `{"current_page":1,"data":[{...}], ...}` with your lesson in `data`. Sending `"level": "expert"` returns status 422 with an `errors` object.

## Check

Copy `tests/Feature/LessonApiTest.php` into your app and run `php artisan test`. Every test should pass.

## Commit

```bash
git add .
git commit -m "Add a lessons API with validation"
```

## Next

[Step 04: Hash passwords properly](../step-04-hash-passwords-properly)

<!-- ewoi:step -->
---

**Next:** [step-04-hash-passwords-properly](../step-04-hash-passwords-properly/) &nbsp;&middot;&nbsp; **Stuck?** [Ask with the "I am stuck" form](https://github.com/ewoibuilds/practical-software-development/issues/new/choose) &nbsp;&middot;&nbsp; **Helped?** Star [the repo](https://github.com/ewoibuilds/practical-software-development) and follow [Ewoi Builds](https://github.com/ewoibuilds).
<!-- /ewoi:step -->
