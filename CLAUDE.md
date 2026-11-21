# CLAUDE.md

Pixelforge Studio: a Laravel app where signed-in users generate images through the Pixelforge API and are charged
Pixelforge's cost plus a 25% markup. Stack: Laravel 13, PHP 8.5, PostgreSQL 17, Redis (Laravel Valkey in production),
Inertia + Vue 3 + TypeScript + Tailwind (Laravel Vue starter kit, auth via Fortify), Docker Compose locally,
Laravel Cloud in production.

Read before any task:

- `docs/SPEC.md`: decisions, data model, folder structure and milestones. The source of truth.
- `docs/TASK.md`: the assignment as given (kept out of git).
- `docs/PIXELFORGE_API.md`: the Pixelforge API reference (kept out of git).

## Scope

The scope is the whole assignment, including both stretch goals (admin report and balances), in this order: every
required feature working, tested and deployed first; stretch goals after. Correct money beats features and polish.

## Working rules

- Work one milestone of `docs/SPEC.md` at a time. When a milestone is done, stop and report in at most 10 lines:
  files changed (one line each), decisions made, anything you were not sure about, and how I can verify it manually.
  Wait for my approval before the next milestone.
- Never assume. If the task or the SPEC is ambiguous, or a decision is not covered by the SPEC, stop and ask me before
  deciding. Present open questions as: problem, context, options with their impact, and your recommendation.
- Do not add features the task does not ask for. If you think one is worth it, propose it as optional.
- Every time I ask you to change something you produced, add one line to `docs/AGENT_CORRECTIONS.md`: what you had
  done, what I asked to change, and why (ask me for the reason if I didn't give one).
- When the code and `docs/SPEC.md` disagree, stop and tell me; once we decide, update the SPEC to match the code.
- Before writing code that depends on Laravel, Inertia, Fortify or Wayfinder APIs, configuration or version-specific
  behavior, check the Laravel Boost docs (`search-docs`). Before writing migrations or models, check the current
  schema (`database-schema`).
- Do not add packages, layers or patterns that are not in `docs/SPEC.md` without asking first.
- If something fails (install, dependency conflict, a failing test you cannot explain), stop and report it. Do not
  work around it.
- New environment variables go in `.env.example` with empty or safe default values, and are read only in `config/`.
  Never hardcode secrets.
- Do not change `Dockerfile`, `docker-compose.yaml`, `phpunit.xml`, `pint.json` or files in `config/` without asking
  first. Do not rename `DB_DATABASE`.
- Everything in the repository is in English: code, names, comments, error messages, UI copy, tests, docs and commit
  messages. Explanations to me can be in Portuguese.
- If a skill or plugin workflow says to commit, create git worktrees or save plans somewhere else, follow this file
  instead: no commits until I ask, no worktrees, and the plan lives in `docs/SPEC.md`.

## Architecture

Domain-oriented, following *Laravel Beyond CRUD*, kept inside `app/` (no `src/` root, no custom `Application` class):
business rules live in domains grouped by business concept; HTTP, jobs and console are application layers that use
the domains; external systems live in `Integrations/`.

```
app/
├── Domain/<Concept>/          Actions, Data, Enums, Exceptions, Models, QueryBuilders, ValueObjects
├── Integrations/<Provider>/   contract, HTTP client, signature verification, exceptions
├── Http/                      Controllers, Requests, Resources, ViewModels, Middleware
├── Jobs/
└── Models/User.php            stays where Laravel puts it
```

- **Models** (`Domain/*/Models`): relationships, casts and the custom query builder only. No business rules.
- **QueryBuilders** (`Domain/*/QueryBuilders`): read queries named in the domain language (`forUser()`,
  `inProgress()`, `completedBetween()`), bound to the model. No writes.
- **Actions** (`Domain/*/Actions`): one use case per class, with a single public `handle()` method. Business rules and
  database writes live here. Actions may call other Actions. Actions that write more than one row or table use
  `DB::transaction()`; state changes that can race (webhook vs polling) lock the row with `lockForUpdate()`.
- **Data** (`Domain/*/Data`): readonly DTOs, ending in `Data`, for structured data crossing layers. Provider payloads
  become DTOs at the edge (`ProviderGenerationData::fromArray()`); no loose arrays past the integration.
- **Enums** for statuses and fixed sets. Status rules live on the enum (`isFinal()`, `canTransitionTo()`); the
  transition itself happens in an Action. No state-pattern package.
- **ValueObjects** (`Domain/*/ValueObjects`): `final readonly`, validated in the constructor, stored through a custom
  cast.
- **Integrations** (`Integrations/<Provider>`): a contract in the domain's language (DTOs in, DTOs out), an HTTP
  implementation, and named exceptions (e.g. `PixelforgeRateLimited` with its `retryAfter`). Domain code depends on
  the contract only. Bind it in `AppServiceProvider`.
- **Interfaces** only for external integrations or when there are two implementations. A test fake
  (`FakePixelforgeApi`) counts as the second implementation. No interfaces for Actions, calculators or view models.
- **Controllers** are thin: validate with a Form Request, call an Action, return a redirect, an Inertia response or a
  JSON Resource.
- **ViewModels** (`Http/ViewModels`): build each Inertia page's props, implement `Arrayable`, and return expensive
  props as closures so partial reloads (`only: [...]`) compute only what was asked.
- **Resources** shape models into Inertia props and the few JSON responses (estimate endpoint).
- **Jobs** (`app/Jobs`) are application layer: thin and idempotent, receive ids, call one Action and can run twice
  without side effects. Dispatch them after the commit (`afterCommit`), never inside a transaction. Use
  `ShouldBeUnique` when a duplicate job would waste money or quota. Retry rules (`retryUntil()`, `backoff()`, release
  with `Retry-After`) live in the job class, never in CLI flags.
- **Exceptions**: business rule violations and external API errors are named exceptions. No generic `\Exception`.
- **Dependencies and config**: classes receive config values and dependencies through the constructor (bound in a
  service provider). Actions, view models and domain classes never call `config()` or `env()`.
- **Naming**: Actions are verb + noun (`CreateGeneration`), DTOs end in `Data`, view models end in `ViewModel`,
  integration clients are nouns (`HttpPixelforgeClient`).
- **Traits (Concerns)**: shared behavior used by two or more classes of the same kind goes in a trait inside a
  `Concerns` folder next to them. Never a global traits folder, never a trait for code used in one place.
- **Auth**: the starter kit's Fortify setup stays as it is. Auth is not one of our domains.

## Money

- Never use floats for money, anywhere (PHP, SQL or TypeScript).
- Amounts are integer nanodollars (`bigint`), wrapped in the `Money` value object. All arithmetic happens in `Money`
  and `PricingCalculator`; nothing else multiplies or rounds money.
- Provider cost = Σ tokens × rate. Actual cost uses the `usage` and the `rates` returned on the generation (fixed at
  creation); estimates use `GET /v1/pricing` (cached).
- User charge = provider cost × 1.25, rounded up to the nanodollar. Failed generations cost nothing.
- Estimates are always recomputed on the server; never trust an amount sent by the client.
- The frontend never calculates money. It only formats amounts the server sent.

## Pixelforge

- One API key per environment. Tests never call the real API: use `Http::fake()` for the client and
  `FakePixelforgeApi` everywhere else.
- `Idempotency-Key` and `metadata.generation_id` are our generation id, so retries and early webhooks are safe.
- Webhooks: verify the signature on the raw body before decoding, reject timestamps outside 300 seconds, deduplicate
  by event id, and answer 2xx fast.
- Webhook and polling go through the same Action. A final status (`succeeded`, `failed`) never changes.
- Mark a generation `failed` locally only when Pixelforge told us nothing was created. Never after an uncertain
  outcome (timeout, connection error) or when polling gives up.

## Frontend

- Vue single-file components with `<script setup lang="ts">`, typed props and emits. Pages in `resources/js/pages`,
  shared components in `resources/js/components`.
- Reuse the starter kit's UI components and Tailwind; no new UI libraries.
- Call backend routes through Wayfinder's generated functions, never with hand-written URLs.
- Poll (`usePoll`) only while a generation is in progress, and only the props that change.
- Every state is visible: loading, empty, in progress, failed (with a friendly reason and "you were not charged").

## Git

- Never commit on your own. Read-only commands (`git status`, `git diff`, `git log`) are fine at any time.
- Only commit when I ask. Suggest the message first; after my approval, commit and then run `git push`.
- Commit messages follow Conventional Commits: `<type>: <description>`. Types: `feat`, `fix`, `test`, `refactor`,
  `docs`, `style`, `build`, `chore`. English, imperative mood, lowercase, no trailing period, subject up to ~50
  characters. Add a body only when the why is not obvious. When a milestone has distinct changes, suggest one commit
  per change and list the files in each.
- Never run `git rebase`, `git reset`, `git stash`, `git commit --amend` or `git push --force`.

## Code style

- Follow Laravel conventions and PSR-12. Run Laravel Pint before finishing a milestone.
- `declare(strict_types=1);` in every PHP file under `app/` and `tests/`.
- Typed properties, parameters and return types everywhere.
- The code explains itself: descriptive names and small private methods instead of comments.
- No comments that describe what the code does, and no class or method docblocks that repeat the name or the types.
  Comments only for a non-obvious why: a business rule, an external API quirk, or what a config value means. PHPDoc
  only for what types can't express (array shapes, generics). This overrides the Boost rule that prefers PHPDoc
  blocks.
- Max line length: 120 characters.

## Tests

- TDD: write the milestone's tests first and show me they fail, then implement.
- PHPUnit only (no Pest). Mark every test with `#[Test]` (`PHPUnit\Framework\Attributes\Test`). Method names in
  camelCase, without the `test` prefix, describing the behavior: `itNeverChargesAFailedGeneration()`. `pint.json`
  enforces camelCase.
- Tests run on PostgreSQL in a separate database: `phpunit.xml` sets `DB_DATABASE=pixelforge_testing`, created by
  `docker/postgres/`. They never touch the development database. Use `RefreshDatabase`.
- Money and pricing tests use the worked examples from `docs/PIXELFORGE_API.md` as expected values.
- Use `Queue::fake()` / `Bus::fake()` to assert dispatches, call a job's `handle()` directly to test its logic, and
  `$this->travel()` for time.
- Test the behavior the SPEC asks for and its important failure modes. Nothing beyond that.
- Every milestone ends with the full test suite passing.

## README

Write it only when I ask, directly and in at most ~120 lines, with exactly these sections:

1. **Overview**: what it does in 2–3 lines, the stack and the deployed URL.
2. **How it works**: the architecture diagram (Mermaid) and the generation flow in a few steps.
3. **Billing rules**: estimate vs actual cost, markup, rounding, failed generations.
4. **How to run**: only commands you have run yourself from a clean state (`docker compose down -v`, then every
   step), including the tunnel for webhooks.
5. **Tests**: how to run them.
6. **Decisions**: one line each, with the reason.
7. **Where I corrected the agent**: the 3–5 most relevant entries from `docs/AGENT_CORRECTIONS.md`. Prefer
   corrections that changed behavior, correctness or architecture over naming and style. Link to the file.
8. **Left out and next steps**: one line each.

Don't repeat the SPEC; link to it.

## Laravel Boost

The Boost guidelines below apply. When they conflict with this file, this file wins.

- PHP, Composer, Artisan and Pint run only inside the container. Where Boost says `php artisan ...`,
  `vendor/bin/pint ...` or `composer ...`, prefix with `docker compose exec app`. npm runs on the host.
- When a test or request fails, check Boost's last error and log tools before guessing the cause.
- Ask me before creating seeders, unless the SPEC already covers it.
- `app/Domain`, `app/Integrations` and `app/Http/ViewModels` are approved base folders (see Architecture).
- After installing a Composer package, run `docker compose exec app php artisan boost:update`.

## Commands

- Tests: `docker compose exec app php artisan test --compact`
- Code style: `docker compose exec app ./vendor/bin/pint --dirty`
- Artisan: `docker compose exec app php artisan <command>`
- Frontend (host): `npm run dev`, `npm run build`, `npm run lint`
- Worker logs: `docker compose logs worker --tail=50` (the worker uses `queue:listen`, so code changes apply without a
  restart)

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.5. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.
- Activate the `deploying-to-cloud` skill whenever deploying to Laravel Cloud, configuring Cloud environments or resources, using the Cloud CLI, or troubleshooting Cloud deployments.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

# Inertia v3

- Use all Inertia features from v1, v2, and v3. Check the documentation before making changes to ensure the correct approach.
- New v3 features: standalone HTTP requests (`useHttp` hook), optimistic updates with automatic rollback, layout props (`useLayoutProps` hook), instant visits, simplified SSR via `@inertiajs/vite` plugin, custom exception handling for error pages.
- Carried over from v2: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.
- Axios has been removed. Use the built-in XHR client with interceptors, or install Axios separately if needed.
- `Inertia::lazy()` / `LazyProp` has been removed. Use `Inertia::optional()` instead.
- Prop types (`Inertia::optional()`, `Inertia::defer()`, `Inertia::merge()`) work inside nested arrays with dot-notation paths.
- SSR works automatically in Vite dev mode with `@inertiajs/vite` - no separate Node.js server needed during development.
- Event renames: `invalid` is now `httpException`, `exception` is now `networkError`.
- `router.cancel()` replaced by `router.cancelAll()`.
- The `future` configuration namespace has been removed - all v2 future options are now always enabled.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== wayfinder/core rules ===

# Laravel Wayfinder

Use Wayfinder to generate TypeScript functions for Laravel routes. Import from `@/actions/` (controllers) or `@/routes/` (named routes).

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This project uses PHPUnit. Create tests with `php artisan make:test --phpunit {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/phpunit` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

=== inertia-vue/core rules ===

# Inertia + Vue

Vue components must have a single root element.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

</laravel-boost-guidelines>
