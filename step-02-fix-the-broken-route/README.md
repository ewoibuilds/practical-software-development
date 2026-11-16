# Step 02: Exercise, fix the broken route

Exercise: **Fix the broken route** (on ewoibuilds.com: `/exercises/fix-the-broken-route`)

**Your workspace folder:** `~/ewoi-learning/route-exercise`, a fresh Laravel 13 app made only for this exercise (`composer create-project laravel/laravel route-exercise`). Do not use `laravel-app`: it already has an about route.

## Try it first

`starter/` is broken on purpose. Copy its files into `route-exercise` at the same paths, run `php artisan serve`, open the home page and click the link. You get a 404. Find every cause before you open `solution/`.

```
step-02-fix-the-broken-route/
├── starter/                    the broken version you fix
│   ├── routes/web.php
│   ├── resources/views/home.blade.php
│   ├── resources/views/about.blade.php
│   └── tests/Feature/AboutPageTest.php
└── solution/                   the fixed version (same files)
```

## Hints, one at a time

1. Run `php artisan route:list --path=about`. Which method does it show?
2. A browser opening a link always sends GET.
3. Read the link's address and the route's address character by character.
4. Named routes let a link follow a route when its address changes.

## Check

From `~/ewoi-learning/route-exercise`, with `tests/Feature/AboutPageTest.php` copied in:

```bash
php artisan test
```

On the starter, three tests fail, each with a message pointing at a cause. When you have fixed everything, every test passes.

## Commit

```bash
git add .
git commit -m "Fix the About route method and link"
```

## Next

[Step 03: Build a REST API](../step-03-build-a-rest-api)

<!-- ewoi:step -->
---

**Next:** [step-03-build-a-rest-api](../step-03-build-a-rest-api/) &nbsp;&middot;&nbsp; **Stuck?** [Ask with the "I am stuck" form](https://github.com/ewoibuilds/practical-software-development/issues/new/choose) &nbsp;&middot;&nbsp; **Helped?** Star [the repo](https://github.com/ewoibuilds/practical-software-development) and follow [Ewoi Builds](https://github.com/ewoibuilds).
<!-- /ewoi:step -->
