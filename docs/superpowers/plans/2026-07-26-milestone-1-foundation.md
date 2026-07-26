# Milestone 1 — Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stand up the CMOP Laravel 12 application skeleton — Docker environment, domain folder structure, RBAC-backed authentication, a role-aware base layout with an empty dashboard shell, and a green CI pipeline — so later milestones (Trade Import, Case Management, etc.) build on a real, tested foundation rather than default Laravel scaffolding.

**Architecture:** Single Laravel 12 app using the `app/Domains/{Domain}` + `app/Shared` structure from `docs/ARCHITECTURE.md`. This milestone only populates the `Authentication` and `Administration` domains (users, desks, roles/permissions) plus a thin `resources/js` Inertia/Vue 3 frontend. Every other domain folder is created empty with a `.gitkeep` so the structure is visible from commit one but no other business logic is implemented yet (per `docs/ROADMAP.md` Milestone 1 scope).

**Tech Stack:** Laravel 12, PHP 8.3, PostgreSQL (Supabase-hosted — see DECISIONS.md ADR-008; supersedes this plan's original MySQL 8), Redis, Laravel Sanctum (SPA cookie auth), Spatie Permission, Spatie Activitylog (installed but not wired to models yet — that's Milestone 4), Vue 3, Inertia.js, TailwindCSS, Pest, PHPStan, Laravel Pint, Docker Compose, GitHub Actions.

## Global Constraints

- Laravel 12 / PHP 8.3+ / PostgreSQL (Supabase-hosted) / Redis — fixed stack, no substitutions (`docs/PROJECT.md` §10, `docs/ARCHITECTURE.md`, `docs/DECISIONS.md` ADR-008). **Amendment (mid-Milestone-1):** the plan originally specified MySQL; this was changed to Supabase-hosted PostgreSQL after Task 1 to avoid local MySQL colliding with an unrelated database on the developer's machine. Task 1's Docker artifacts (`docker/php/Dockerfile`'s `pdo_mysql` extension, `docker-compose.yml`'s `mysql` service, `.env.example`'s `DB_*` values) were amended accordingly by the controller directly — Tasks 2 onward are written against Postgres.
- Modular monolith, DDD folder layout under `app/Domains/` + `app/Shared/` — no code outside this structure except framework-required paths (`docs/ARCHITECTURE.md` §2).
- Controllers are thin — call one Action/Service, return an Inertia response (`docs/ARCHITECTURE.md` §4). No business logic in controllers, ever.
- RBAC via Spatie Permission, roles from `docs/SECURITY.md` §2: `analyst`, `team_lead`, `ops_manager`, `compliance`, `admin`.
- Every domain subfolder set is `Actions/ Services/ DTOs/ Policies/ Events/ Listeners/ Jobs/ Models/ Requests/ Resources/ Enums/ Exceptions/ Queries/ Support/` — only create the ones a domain actually needs (`docs/ARCHITECTURE.md` §2).
- Docker Compose is the local dev path for the app/queue/scheduler/nginx processes (`docs/DEPLOYMENT.md` §1) — services: `app`, `nginx`, `redis`, `queue`, `scheduler`, `mailpit`, `vite`. There is no local database container — the app connects to a Supabase-hosted PostgreSQL project instead (see amendment above).
- CI stages: Lint (Pint, ESLint) → Static analysis (PHPStan) → Test (Pest against a real PostgreSQL service container, not SQLite) → Build (`docs/DEPLOYMENT.md` §7). A red pipeline blocks merge.
- Money columns, enum-as-PHP-enum, soft-delete rules from `docs/DATABASE.md` §1 apply to any table this milestone creates (only `users`, `desks`, Spatie's own tables here — no money columns in this milestone).
- Desk scoping and maker-checker are enforced via Policies at the query/Action layer, never UI-only (`docs/SECURITY.md` §4-5) — this milestone lays the RBAC/Policy groundwork; maker-checker itself is Milestone 4.

---

## File Structure

```
docker/
  php/Dockerfile
  nginx/default.conf
docker-compose.yml
docker-compose.prod.yml
.env.example

app/
  Domains/
    Authentication/
      Actions/LoginUserAction.php
      Actions/LogoutUserAction.php
      Requests/LoginRequest.php
    Administration/
      Models/Desk.php
      Policies/DeskPolicy.php
      Enums/CmopRole.php
    Trades/.gitkeep
    Reconciliation/.gitkeep
    Cases/.gitkeep
    Workflow/.gitkeep
    Audit/.gitkeep
    Reporting/.gitkeep
    Notifications/.gitkeep
  Shared/
    Concerns/.gitkeep
    ValueObjects/.gitkeep
    Support/.gitkeep
  Models/User.php   (framework default location, extended with desk_id + roles)
  Http/Controllers/Auth/LoginController.php
  Http/Controllers/DashboardController.php
  Http/Middleware/HandleInertiaRequests.php

database/migrations/
  xxxx_add_desk_id_and_is_active_to_users_table.php
  xxxx_create_desks_table.php
  (Spatie Permission's own published migration)

database/seeders/
  RolePermissionSeeder.php
  DeskSeeder.php
  DatabaseSeeder.php (updated)

resources/js/
  Pages/Auth/Login.vue
  Pages/Dashboard.vue
  Layouts/AppLayout.vue
  Components/NavBar.vue

routes/web.php (updated)

tests/Feature/Auth/LoginTest.php
tests/Feature/DashboardAccessTest.php
tests/Unit/Administration/DeskPolicyTest.php

.github/workflows/ci.yml
```

---

## Task 1: Scaffold Laravel 12 project with Docker Compose

**Files:**
- Create: entire Laravel 12 skeleton (via `composer create-project laravel/laravel:^12.0 .`)
- Create: `docker/php/Dockerfile`
- Create: `docker/nginx/default.conf`
- Create: `docker-compose.yml`
- Create: `.env.example`
- Modify: `.gitignore` (Laravel's default is fine, verify `/vendor`, `/node_modules`, `.env` are present)

**Interfaces:**
- Consumes: nothing (first task).
- Produces: a running `docker compose up` stack reachable at `http://localhost:8080`, with `php artisan` runnable inside the `app` container. Later tasks assume this container is up and `docker compose exec app php artisan ...` works.

- [ ] **Step 1: Scaffold Laravel in a temp dir and move contents into repo root**

Since the repo root already has `docs/` and `README.md`, scaffold into a temp directory first, then merge — `composer create-project` refuses a non-empty target.

```bash
composer create-project laravel/laravel:^12.0 /tmp/cmop-scaffold
```

Copy everything from `/tmp/cmop-scaffold/` into `c:\laragon\www\CMOP\`, **except** do not overwrite the existing `README.md` or `docs/` — merge Laravel's generated files alongside them.

- [ ] **Step 2: Verify the scaffold**

Run: `php artisan --version`
Expected: `Laravel Framework 12.x.x`

- [ ] **Step 3: Write the PHP Dockerfile**

```dockerfile
# docker/php/Dockerfile
FROM php:8.3-fpm AS base

RUN apt-get update && apt-get install -y \
    git unzip libzip-dev libpng-dev libonig-dev libxml2-dev \
    && docker-php-ext-install pdo_mysql zip gd mbstring exif pcntl bcmath opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

FROM base AS dependencies
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

FROM base AS development
COPY . .
COPY --from=dependencies /var/www/html/vendor ./vendor
RUN composer dump-autoload
CMD ["php-fpm"]

FROM base AS production
COPY . .
COPY --from=dependencies /var/www/html/vendor ./vendor
RUN composer dump-autoload --optimize
RUN php artisan config:cache && php artisan route:cache
CMD ["php-fpm"]
```

- [ ] **Step 4: Write the Nginx config**

```nginx
# docker/nginx/default.conf
server {
    listen 80;
    index index.php index.html;
    root /var/www/html/public;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass app:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.ht {
        deny all;
    }
}
```

- [ ] **Step 5: Write docker-compose.yml**

```yaml
# docker-compose.yml
services:
  app:
    build:
      context: .
      dockerfile: docker/php/Dockerfile
      target: development
    volumes:
      - .:/var/www/html
    environment:
      - DB_HOST=mysql
      - REDIS_HOST=redis
    depends_on:
      - mysql
      - redis

  nginx:
    image: nginx:alpine
    ports:
      - "8080:80"
    volumes:
      - .:/var/www/html
      - ./docker/nginx/default.conf:/etc/nginx/conf.d/default.conf
    depends_on:
      - app

  mysql:
    image: mysql:8.0
    environment:
      - MYSQL_DATABASE=cmop
      - MYSQL_ROOT_PASSWORD=secret
      - MYSQL_USER=cmop
      - MYSQL_PASSWORD=secret
    ports:
      - "3306:3306"
    volumes:
      - mysql-data:/var/lib/mysql

  redis:
    image: redis:7-alpine
    ports:
      - "6379:6379"

  queue:
    build:
      context: .
      dockerfile: docker/php/Dockerfile
      target: development
    command: php artisan queue:work --tries=3 --backoff=10,30,60
    volumes:
      - .:/var/www/html
    depends_on:
      - mysql
      - redis

  scheduler:
    build:
      context: .
      dockerfile: docker/php/Dockerfile
      target: development
    command: php artisan schedule:work
    volumes:
      - .:/var/www/html
    depends_on:
      - mysql
      - redis

  mailpit:
    image: axllent/mailpit
    ports:
      - "8025:8025"
      - "1025:1025"

  vite:
    build:
      context: .
      dockerfile: docker/php/Dockerfile
      target: development
    command: sh -c "npm install && npm run dev -- --host"
    volumes:
      - .:/var/www/html
    ports:
      - "5173:5173"

volumes:
  mysql-data:
```

- [ ] **Step 6: Update `.env.example` with the Docker service hostnames**

```
APP_NAME=CMOP
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost:8080

DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=cmop
DB_USERNAME=cmop
DB_PASSWORD=secret

REDIS_HOST=redis
REDIS_PORT=6379

QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
CACHE_STORE=redis

MAIL_MAILER=smtp
MAIL_HOST=mailpit
MAIL_PORT=1025
```

- [ ] **Step 7: Build and boot the stack**

```bash
cp .env.example .env
docker compose build
docker compose up -d
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```

Expected: all containers start, `php artisan migrate` runs Laravel's default migrations against MySQL with no errors, `http://localhost:8080` returns the default Laravel welcome page.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "chore: scaffold Laravel 12 with Docker Compose environment"
```

---

## Task 2: Create the domain folder structure

**Files:**
- Create: `app/Domains/Authentication/` (empty, populated in Task 4)
- Create: `app/Domains/Administration/` (empty, populated in Task 3)
- Create: `app/Domains/Trades/.gitkeep`
- Create: `app/Domains/Reconciliation/.gitkeep`
- Create: `app/Domains/Cases/.gitkeep`
- Create: `app/Domains/Workflow/.gitkeep`
- Create: `app/Domains/Audit/.gitkeep`
- Create: `app/Domains/Reporting/.gitkeep`
- Create: `app/Domains/Notifications/.gitkeep`
- Create: `app/Shared/Concerns/.gitkeep`
- Create: `app/Shared/ValueObjects/.gitkeep`
- Create: `app/Shared/Support/.gitkeep`
- Modify: `composer.json` (add PSR-4 autoload mapping for `App\Domains\` and `App\Shared\` — these already fall under the default `App\` -> `app/` PSR-4 mapping Laravel ships with, so no change is actually needed; verify instead)

**Interfaces:**
- Consumes: nothing new.
- Produces: the directory structure that Tasks 3+ place files into. `App\Domains\Administration\...` and `App\Domains\Authentication\...` namespaces resolve correctly via Laravel's default `App\` PSR-4 root.

- [ ] **Step 1: Create the empty domain directories with `.gitkeep`**

```bash
for d in Trades Reconciliation Cases Workflow Audit Reporting Notifications; do
  mkdir -p "app/Domains/$d"
  touch "app/Domains/$d/.gitkeep"
done
mkdir -p app/Domains/Authentication app/Domains/Administration
for d in Concerns ValueObjects Support; do
  mkdir -p "app/Shared/$d"
  touch "app/Shared/$d/.gitkeep"
done
```

- [ ] **Step 2: Verify autoloading resolves the new namespace roots**

Create a throwaway smoke-test class to confirm PSR-4 autoloading works out of the box:

```php
// app/Domains/Administration/Support/Placeholder.php (temporary, deleted in Step 4)
<?php

namespace App\Domains\Administration\Support;

class Placeholder
{
    public static function ping(): string
    {
        return 'ok';
    }
}
```

Run: `docker compose exec app php artisan tinker --execute="echo \App\Domains\Administration\Support\Placeholder::ping();"`
Expected: `ok`

- [ ] **Step 3: Delete the throwaway smoke-test class**

```bash
rm app/Domains/Administration/Support/Placeholder.php
rmdir app/Domains/Administration/Support 2>/dev/null || true
```

- [ ] **Step 4: Commit**

```bash
git add app/Domains app/Shared
git commit -m "chore: create DDD domain folder structure per ARCHITECTURE.md"
```

---

## Task 3: Install RBAC (Spatie Permission), Desk model, and CmopRole enum

**Files:**
- Modify: `composer.json` (add `spatie/laravel-permission`, `spatie/laravel-activitylog`)
- Create: `database/migrations/xxxx_create_desks_table.php`
- Create: `database/migrations/xxxx_add_desk_id_and_is_active_to_users_table.php`
- Create: (published) `database/migrations/xxxx_create_permission_tables.php`
- Create: `app/Domains/Administration/Models/Desk.php`
- Create: `app/Domains/Administration/Enums/CmopRole.php`
- Modify: `app/Models/User.php` (add `HasRoles` trait, `desk()` relation, `is_active` cast)
- Test: `tests/Unit/Administration/DeskModelTest.php`

**Interfaces:**
- Produces: `App\Domains\Administration\Models\Desk` (fields: `id`, `name`, `entity`, `region`, timestamps); `App\Domains\Administration\Enums\CmopRole` (backed string enum: `Analyst = 'analyst'`, `TeamLead = 'team_lead'`, `OpsManager = 'ops_manager'`, `Compliance = 'compliance'`, `Admin = 'admin'`); `User::desk(): BelongsTo`; `User` has Spatie's `hasRole()`/`assignRole()` available.
- Consumes: nothing from earlier tasks beyond the booted Docker stack (Task 1) and domain folders (Task 2).

- [ ] **Step 1: Require the packages**

```bash
docker compose exec app composer require spatie/laravel-permission spatie/laravel-activitylog
```

- [ ] **Step 2: Publish Spatie Permission's migration and config**

```bash
docker compose exec app php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
```

Expected: creates `database/migrations/xxxx_create_permission_tables.php` and `config/permission.php`.

- [ ] **Step 3: Write the failing model test for `Desk`**

```php
// tests/Unit/Administration/DeskModelTest.php
<?php

use App\Domains\Administration\Models\Desk;

test('a desk has name, entity, and region attributes', function () {
    $desk = Desk::factory()->create([
        'name' => 'FX Trade Support',
        'entity' => 'CMOP Bank plc',
        'region' => 'EMEA',
    ]);

    expect($desk->name)->toBe('FX Trade Support')
        ->and($desk->entity)->toBe('CMOP Bank plc')
        ->and($desk->region)->toBe('EMEA');
});
```

- [ ] **Step 4: Run the test to verify it fails**

Run: `docker compose exec app php artisan test --filter=DeskModelTest`
Expected: FAIL — class `App\Domains\Administration\Models\Desk` not found, and no `desks` table.

- [ ] **Step 5: Write the `desks` migration**

```php
// database/migrations/xxxx_create_desks_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('desks', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('entity');
            $table->string('region');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('desks');
    }
};
```

Desks get `softDeletes()` per `docs/DATABASE.md` §5 (user-manageable configuration, not a transactional/audit-relevant table).

- [ ] **Step 6: Write the `Desk` model, factory, and `CmopRole` enum**

```php
// app/Domains/Administration/Models/Desk.php
<?php

namespace App\Domains\Administration\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Desk extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['name', 'entity', 'region'];

    protected static function newFactory()
    {
        return \Database\Factories\DeskFactory::new();
    }
}
```

```php
// database/factories/DeskFactory.php
<?php

namespace Database\Factories;

use App\Domains\Administration\Models\Desk;
use Illuminate\Database\Eloquent\Factories\Factory;

class DeskFactory extends Factory
{
    protected $model = Desk::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company() . ' Desk',
            'entity' => 'CMOP Bank plc',
            'region' => $this->faker->randomElement(['EMEA', 'APAC', 'AMERICAS']),
        ];
    }
}
```

```php
// app/Domains/Administration/Enums/CmopRole.php
<?php

namespace App\Domains\Administration\Enums;

enum CmopRole: string
{
    case Analyst = 'analyst';
    case TeamLead = 'team_lead';
    case OpsManager = 'ops_manager';
    case Compliance = 'compliance';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Analyst => 'Operations Analyst',
            self::TeamLead => 'Trade Support Team Lead',
            self::OpsManager => 'Operations Manager',
            self::Compliance => 'Compliance Officer',
            self::Admin => 'Platform Administrator',
        };
    }
}
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `docker compose exec app php artisan migrate:fresh`
Run: `docker compose exec app php artisan test --filter=DeskModelTest`
Expected: PASS

- [ ] **Step 8: Add `desk_id` and `is_active` to `users`, wire `HasRoles` on `User`**

```php
// database/migrations/xxxx_add_desk_id_and_is_active_to_users_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('desk_id')->nullable()->after('id')->constrained('desks')->nullOnDelete();
            $table->boolean('is_active')->default(true)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('desk_id');
            $table->dropColumn('is_active');
        });
    }
};
```

```php
// app/Models/User.php (modify existing file)
<?php

namespace App\Models;

use App\Domains\Administration\Models\Desk;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'desk_id',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function desk(): BelongsTo
    {
        return $this->belongsTo(Desk::class);
    }
}
```

- [ ] **Step 9: Run all migrations fresh and re-run the full test suite**

Run: `docker compose exec app php artisan migrate:fresh`
Run: `docker compose exec app php artisan test`
Expected: PASS (no regressions)

- [ ] **Step 10: Commit**

```bash
git add composer.json composer.lock database/migrations database/factories app/Domains/Administration app/Models/User.php tests/Unit/Administration
git commit -m "feat: add Desk model, CmopRole enum, and RBAC packages"
```

---

## Task 4: Role/permission seeding and Sanctum-based authentication

**Files:**
- Create: `database/seeders/RolePermissionSeeder.php`
- Create: `database/seeders/DeskSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Create: `app/Domains/Authentication/Requests/LoginRequest.php`
- Create: `app/Domains/Authentication/Actions/LoginUserAction.php`
- Create: `app/Domains/Authentication/Actions/LogoutUserAction.php`
- Create: `app/Http/Controllers/Auth/LoginController.php`
- Modify: `routes/web.php`
- Modify: `bootstrap/app.php` (Sanctum SPA `stateful` middleware, already default in Laravel 12 — verify only)
- Test: `tests/Feature/Auth/LoginTest.php`

**Interfaces:**
- Consumes: `Desk` (Task 3), `CmopRole` (Task 3), `User::assignRole()` (Spatie, via `HasRoles`).
- Produces: `POST /login` (session-authenticates via `LoginUserAction::execute(string $email, string $password): void`, throws `\Illuminate\Validation\ValidationException` on failure), `POST /logout` (`LogoutUserAction::execute(): void`). Later tasks (Dashboard, and Milestone 3+ Policies) rely on `auth()->user()->hasRole(CmopRole::Analyst->value)` etc. being populated correctly after login.

- [ ] **Step 1: Write the failing feature test for login**

```php
// tests/Feature/Auth/LoginTest.php
<?php

use App\Domains\Administration\Enums\CmopRole;
use App\Domains\Administration\Models\Desk;
use App\Models\User;

test('a user with valid credentials can log in and is redirected to the dashboard', function () {
    $desk = Desk::factory()->create();
    $user = User::factory()->create([
        'email' => 'amara@cmop.test',
        'password' => bcrypt('password123'),
        'desk_id' => $desk->id,
    ]);
    $user->assignRole(CmopRole::Analyst->value);

    $response = $this->post('/login', [
        'email' => 'amara@cmop.test',
        'password' => 'password123',
    ]);

    $response->assertRedirect('/dashboard');
    $this->assertAuthenticatedAs($user);
});

test('a user with invalid credentials is not logged in', function () {
    User::factory()->create([
        'email' => 'amara@cmop.test',
        'password' => bcrypt('password123'),
    ]);

    $response = $this->from('/login')->post('/login', [
        'email' => 'amara@cmop.test',
        'password' => 'wrong-password',
    ]);

    $response->assertRedirect('/login');
    $response->assertSessionHasErrors('email');
    $this->assertGuest();
});

test('an inactive user cannot log in even with correct credentials', function () {
    User::factory()->create([
        'email' => 'inactive@cmop.test',
        'password' => bcrypt('password123'),
        'is_active' => false,
    ]);

    $response = $this->from('/login')->post('/login', [
        'email' => 'inactive@cmop.test',
        'password' => 'password123',
    ]);

    $response->assertRedirect('/login');
    $response->assertSessionHasErrors('email');
    $this->assertGuest();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose exec app php artisan test --filter=LoginTest`
Expected: FAIL — route `/login` does not exist (404).

- [ ] **Step 3: Write the `LoginRequest`**

```php
// app/Domains/Authentication/Requests/LoginRequest.php
<?php

namespace App\Domains\Authentication\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ];
    }
}
```

- [ ] **Step 4: Write the `LoginUserAction` and `LogoutUserAction`**

```php
// app/Domains/Authentication/Actions/LoginUserAction.php
<?php

namespace App\Domains\Authentication\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoginUserAction
{
    public function execute(string $email, string $password): void
    {
        $user = User::where('email', $email)->first();

        if (! $user || ! $user->is_active || ! Auth::attempt(['email' => $email, 'password' => $password])) {
            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }
    }
}
```

```php
// app/Domains/Authentication/Actions/LogoutUserAction.php
<?php

namespace App\Domains\Authentication\Actions;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LogoutUserAction
{
    public function execute(Request $request): void
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
```

Note: `Auth::attempt` runs its own password check; the explicit `is_active` guard runs first so an inactive user is rejected even if `Auth::attempt` would otherwise succeed, and short-circuits via `||` before Laravel's own throttling logic — no separate throttle test is written here since Laravel's default `ThrottlesLogins` behavior is unmodified.

- [ ] **Step 5: Write the thin `LoginController`**

```php
// app/Http/Controllers/Auth/LoginController.php
<?php

namespace App\Http\Controllers\Auth;

use App\Domains\Authentication\Actions\LoginUserAction;
use App\Domains\Authentication\Actions\LogoutUserAction;
use App\Domains\Authentication\Requests\LoginRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(LoginRequest $request, LoginUserAction $action): RedirectResponse
    {
        $action->execute($request->string('email')->toString(), $request->string('password')->toString());

        $request->session()->regenerate();

        return redirect()->intended('/dashboard');
    }

    public function destroy(Request $request, LogoutUserAction $action): RedirectResponse
    {
        $action->execute($request);

        return redirect('/login');
    }
}
```

- [ ] **Step 6: Register the routes**

```php
// routes/web.php (add)
use App\Http\Controllers\Auth\LoginController;

Route::get('/login', [LoginController::class, 'create'])->middleware('guest')->name('login');
Route::post('/login', [LoginController::class, 'store'])->middleware('guest');
Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth');
```

- [ ] **Step 7: Run the test again to verify it passes**

Run: `docker compose exec app php artisan test --filter=LoginTest`
Expected: PASS (all 3 cases). Note the second/third tests will fail with a 500 until `resources/js/Pages/Auth/Login.vue` exists for the `create()` action's Inertia render — the POST-only assertions don't render that page, but running the full suite requires the page to exist; it's created in Task 5. If Task 4's tests are run in isolation before Task 5, the `GET /login` case (not present in these three tests) isn't exercised, so this test file passes standalone.

- [ ] **Step 8: Write the seeders**

```php
// database/seeders/RolePermissionSeeder.php
<?php

namespace Database\Seeders;

use App\Domains\Administration\Enums\CmopRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (CmopRole::cases() as $role) {
            Role::findOrCreate($role->value);
        }
    }
}
```

```php
// database/seeders/DeskSeeder.php
<?php

namespace Database\Seeders;

use App\Domains\Administration\Models\Desk;
use Illuminate\Database\Seeder;

class DeskSeeder extends Seeder
{
    public function run(): void
    {
        Desk::factory()->createMany([
            ['name' => 'FX Trade Support', 'entity' => 'CMOP Bank plc', 'region' => 'EMEA'],
            ['name' => 'Fixed Income Ops', 'entity' => 'CMOP Bank plc', 'region' => 'AMERICAS'],
        ]);
    }
}
```

```php
// database/seeders/DatabaseSeeder.php (modify)
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            DeskSeeder::class,
        ]);
    }
}
```

- [ ] **Step 9: Run migrations + seeders fresh, run full suite**

Run: `docker compose exec app php artisan migrate:fresh --seed`
Run: `docker compose exec app php artisan test`
Expected: seeders run without error; full suite still passes (Login tests pass; Task 5 UI not built yet so no dashboard test exists to fail).

- [ ] **Step 10: Commit**

```bash
git add database/seeders app/Domains/Authentication app/Http/Controllers/Auth routes/web.php tests/Feature/Auth
git commit -m "feat: add Sanctum session login/logout and role/desk seeders"
```

---

## Task 5: Base Inertia/Vue layout, login page, and role-aware dashboard skeleton

**Files:**
- Create: `resources/js/Layouts/AppLayout.vue`
- Create: `resources/js/Components/NavBar.vue`
- Create: `resources/js/Pages/Auth/Login.vue`
- Create: `resources/js/Pages/Dashboard.vue`
- Create: `app/Http/Middleware/HandleInertiaRequests.php` (Laravel 12 Inertia starter normally ships this — verify/create)
- Create: `app/Http/Controllers/DashboardController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/DashboardAccessTest.php`

**Interfaces:**
- Consumes: `LoginController` routes (Task 4), `User::hasRole()` (Task 3/Spatie).
- Produces: `GET /dashboard` (auth-protected), Inertia shared prop `auth.user` (`{ id, name, email, roles: string[] }`) available on every page via `HandleInertiaRequests::share()` — later milestones' frontend pages rely on this shared prop shape for role-conditional UI.

- [ ] **Step 1: Write the failing feature test for dashboard access**

```php
// tests/Feature/DashboardAccessTest.php
<?php

use App\Domains\Administration\Enums\CmopRole;
use App\Models\User;

test('a guest is redirected to login when visiting the dashboard', function () {
    $response = $this->get('/dashboard');

    $response->assertRedirect('/login');
});

test('an authenticated user sees the dashboard with their roles shared to Inertia', function () {
    $user = User::factory()->create();
    $user->assignRole(CmopRole::Analyst->value);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Dashboard')
        ->where('auth.user.email', $user->email)
        ->where('auth.user.roles', [CmopRole::Analyst->value])
    );
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose exec app php artisan test --filter=DashboardAccessTest`
Expected: FAIL — `/dashboard` route not registered.

- [ ] **Step 3: Ensure Inertia scaffolding is installed**

Laravel 12's `laravel/breeze` or manual Inertia install provides `HandleInertiaRequests`. If not already present from Task 1's scaffold:

```bash
docker compose exec app composer require inertiajs/inertia-laravel
docker compose exec app php artisan inertia:middleware
```

- [ ] **Step 4: Configure shared props in `HandleInertiaRequests`**

```php
// app/Http/Middleware/HandleInertiaRequests.php
<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user() ? [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                    'roles' => $request->user()->getRoleNames()->values()->all(),
                ] : null,
            ],
        ];
    }
}
```

- [ ] **Step 5: Write the thin `DashboardController` and register routes**

```php
// app/Http/Controllers/DashboardController.php
<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Dashboard');
    }
}
```

```php
// routes/web.php (add)
use App\Http\Controllers\DashboardController;

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware('auth')
    ->name('dashboard');

Route::get('/', fn () => redirect('/dashboard'));
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `docker compose exec app php artisan test --filter=DashboardAccessTest`
Expected: PASS

- [ ] **Step 7: Build the Vue frontend pages**

```vue
<!-- resources/js/Layouts/AppLayout.vue -->
<script setup>
import NavBar from '@/Components/NavBar.vue';
</script>

<template>
  <div class="min-h-screen bg-gray-50">
    <NavBar />
    <main class="mx-auto max-w-7xl px-4 py-6">
      <slot />
    </main>
  </div>
</template>
```

```vue
<!-- resources/js/Components/NavBar.vue -->
<script setup>
import { usePage, router } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const user = computed(() => page.props.auth.user);

function logout() {
  router.post('/logout');
}
</script>

<template>
  <nav class="flex items-center justify-between border-b bg-white px-6 py-3">
    <span class="text-lg font-semibold text-gray-900">CMOP</span>
    <div v-if="user" class="flex items-center gap-4 text-sm text-gray-600">
      <span>{{ user.name }} ({{ user.roles.join(', ') }})</span>
      <button class="text-red-600 hover:underline" @click="logout">Log out</button>
    </div>
  </nav>
</template>
```

```vue
<!-- resources/js/Pages/Auth/Login.vue -->
<script setup>
import { useForm } from '@inertiajs/vue3';

const form = useForm({
  email: '',
  password: '',
});

function submit() {
  form.post('/login');
}
</script>

<template>
  <div class="flex min-h-screen items-center justify-center bg-gray-50">
    <form class="w-full max-w-sm space-y-4 rounded border bg-white p-6 shadow-sm" @submit.prevent="submit">
      <h1 class="text-xl font-semibold text-gray-900">CMOP Sign In</h1>

      <div>
        <label class="block text-sm text-gray-700" for="email">Email</label>
        <input id="email" v-model="form.email" type="email" class="mt-1 w-full rounded border px-3 py-2" />
        <p v-if="form.errors.email" class="mt-1 text-sm text-red-600">{{ form.errors.email }}</p>
      </div>

      <div>
        <label class="block text-sm text-gray-700" for="password">Password</label>
        <input id="password" v-model="form.password" type="password" class="mt-1 w-full rounded border px-3 py-2" />
      </div>

      <button type="submit" class="w-full rounded bg-gray-900 px-4 py-2 text-white" :disabled="form.processing">
        Sign in
      </button>
    </form>
  </div>
</template>
```

```vue
<!-- resources/js/Pages/Dashboard.vue -->
<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
</script>

<template>
  <AppLayout>
    <h1 class="text-2xl font-semibold text-gray-900">Dashboard</h1>
    <p class="mt-2 text-gray-600">
      Milestone 1 skeleton — operational widgets (open breaks, SLA breaches, aging) land in Milestone 5.
    </p>
  </AppLayout>
</template>
```

- [ ] **Step 8: Build the frontend and run the full test suite**

Run: `docker compose exec vite npm run build` (or confirm `vite` dev server compiles without error via `docker compose logs vite`)
Run: `docker compose exec app php artisan test`
Expected: frontend builds without error; full backend suite passes.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/DashboardController.php app/Http/Middleware/HandleInertiaRequests.php routes/web.php resources/js tests/Feature/DashboardAccessTest.php
git commit -m "feat: add base Inertia layout, login page, and dashboard skeleton"
```

---

## Task 6: Role-scoped Desk policy (RBAC groundwork)

**Files:**
- Create: `app/Domains/Administration/Policies/DeskPolicy.php`
- Modify: `app/Providers/AppServiceProvider.php` (register the policy)
- Test: `tests/Unit/Administration/DeskPolicyTest.php`

**Interfaces:**
- Consumes: `Desk` model (Task 3), `CmopRole` (Task 3).
- Produces: `DeskPolicy::viewAny(User $user): bool` and `DeskPolicy::update(User $user, Desk $desk): bool` — the pattern later domains' Policies (Cases, Workflow) follow for desk-scoped authorization per `docs/SECURITY.md` §4.

- [ ] **Step 1: Write the failing policy test**

```php
// tests/Unit/Administration/DeskPolicyTest.php
<?php

use App\Domains\Administration\Enums\CmopRole;
use App\Domains\Administration\Models\Desk;
use App\Domains\Administration\Policies\DeskPolicy;
use App\Models\User;

test('an admin can update any desk', function () {
    $admin = User::factory()->create();
    $admin->assignRole(CmopRole::Admin->value);
    $desk = Desk::factory()->create();

    expect((new DeskPolicy())->update($admin, $desk))->toBeTrue();
});

test('an analyst cannot update desk configuration', function () {
    $analyst = User::factory()->create();
    $analyst->assignRole(CmopRole::Analyst->value);
    $desk = Desk::factory()->create();

    expect((new DeskPolicy())->update($analyst, $desk))->toBeFalse();
});

test('any authenticated user can view the desk list', function () {
    $analyst = User::factory()->create();
    $analyst->assignRole(CmopRole::Analyst->value);

    expect((new DeskPolicy())->viewAny($analyst))->toBeTrue();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose exec app php artisan test --filter=DeskPolicyTest`
Expected: FAIL — class `App\Domains\Administration\Policies\DeskPolicy` not found.

- [ ] **Step 3: Write the `DeskPolicy`**

```php
// app/Domains/Administration/Policies/DeskPolicy.php
<?php

namespace App\Domains\Administration\Policies;

use App\Domains\Administration\Enums\CmopRole;
use App\Domains\Administration\Models\Desk;
use App\Models\User;

class DeskPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function update(User $user, Desk $desk): bool
    {
        return $user->hasRole(CmopRole::Admin->value);
    }
}
```

- [ ] **Step 4: Register the policy mapping**

```php
// app/Providers/AppServiceProvider.php (modify boot())
use App\Domains\Administration\Models\Desk;
use App\Domains\Administration\Policies\DeskPolicy;
use Illuminate\Support\Facades\Gate;

public function boot(): void
{
    Gate::policy(Desk::class, DeskPolicy::class);
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `docker compose exec app php artisan test --filter=DeskPolicyTest`
Expected: PASS

- [ ] **Step 6: Run the full suite to confirm no regressions**

Run: `docker compose exec app php artisan test`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add app/Domains/Administration/Policies app/Providers/AppServiceProvider.php tests/Unit/Administration/DeskPolicyTest.php
git commit -m "feat: add DeskPolicy establishing the RBAC policy pattern"
```

---

## Task 7: Pint, PHPStan, and GitHub Actions CI

**Files:**
- Create: `pint.json`
- Create: `phpstan.neon`
- Create: `.github/workflows/ci.yml`
- Modify: `composer.json` (require-dev `phpstan/phpstan`, `larastan/larastan`)

**Interfaces:**
- Consumes: nothing new — this task wires up existing code (Tasks 1-6) to lint/analysis/test gates.
- Produces: a GitHub Actions workflow that runs on every PR and push to `main`; later milestones' PRs are gated by this workflow per `docs/DEPLOYMENT.md` §7.

- [ ] **Step 1: Add Pint config**

```json
// pint.json
{
    "preset": "laravel"
}
```

- [ ] **Step 2: Install and configure PHPStan (Larastan)**

```bash
docker compose exec app composer require --dev larastan/larastan
```

```neon
# phpstan.neon
includes:
    - vendor/larastan/larastan/extension.neon

parameters:
    paths:
        - app
    level: 5
    excludePaths:
        - app/Domains/*/.gitkeep
```

- [ ] **Step 3: Run Pint and PHPStan locally to confirm a clean baseline**

Run: `docker compose exec app ./vendor/bin/pint --test`
Run: `docker compose exec app ./vendor/bin/phpstan analyse`
Expected: both exit 0. Fix any violations Pint/PHPStan surface in the code written in Tasks 1-6 before proceeding (fix inline — do not disable rules to force a pass).

- [ ] **Step 4: Write the GitHub Actions workflow**

```yaml
# .github/workflows/ci.yml
name: CI

on:
  pull_request:
  push:
    branches: [main]

jobs:
  lint:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
      - run: composer install --prefer-dist --no-progress
      - run: ./vendor/bin/pint --test

  static-analysis:
    runs-on: ubuntu-latest
    needs: lint
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
      - run: composer install --prefer-dist --no-progress
      - run: ./vendor/bin/phpstan analyse

  test:
    runs-on: ubuntu-latest
    needs: static-analysis
    services:
      postgres:
        image: postgres:16
        env:
          POSTGRES_DB: cmop_testing
          POSTGRES_USER: postgres
          POSTGRES_PASSWORD: secret
        ports:
          - 5432:5432
        options: >-
          --health-cmd="pg_isready -U postgres"
          --health-interval=10s
          --health-timeout=5s
          --health-retries=5
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: pdo_pgsql
      - run: composer install --prefer-dist --no-progress
      - run: cp .env.example .env
      - run: php artisan key:generate
      - run: php artisan migrate --force
        env:
          DB_CONNECTION: pgsql
          DB_HOST: 127.0.0.1
          DB_PORT: 5432
          DB_DATABASE: cmop_testing
          DB_USERNAME: postgres
          DB_PASSWORD: secret
      - run: php artisan test
        env:
          DB_CONNECTION: pgsql
          DB_HOST: 127.0.0.1
          DB_PORT: 5432
          DB_DATABASE: cmop_testing
          DB_USERNAME: postgres
          DB_PASSWORD: secret

  build:
    runs-on: ubuntu-latest
    needs: test
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with:
          node-version: '20'
      - run: npm ci
      - run: npm run build
```

- [ ] **Step 5: Commit and push to trigger the workflow**

```bash
git add pint.json phpstan.neon .github/workflows/ci.yml composer.json composer.lock
git commit -m "chore: add Pint, PHPStan, and GitHub Actions CI pipeline"
git push
```

- [ ] **Step 6: Verify the workflow runs green on GitHub**

Check the Actions tab on the repository for this push/PR. Expected: `lint`, `static-analysis`, `test`, and `build` jobs all pass.

---

## Self-Review Notes

- **Spec coverage**: Milestone 1 scope from `docs/ROADMAP.md` §1 — Authentication ✅ (Task 4), RBAC ✅ (Task 3, 6), Layout ✅ (Task 5), Dashboard Skeleton ✅ (Task 5), Docker ✅ (Task 1), CI ✅ (Task 7). Exit criteria ("a user can log in, see a role-appropriate empty dashboard shell, and CI runs green on an empty-but-real test suite") is covered by Tasks 4, 5, 7.
- **Deferred to later milestones, intentionally not in this plan**: Trades/Reconciliation/Cases/Workflow/Audit/Reporting/Notifications domain logic (Milestones 2-5), Spatie Activitylog wiring to models (Milestone 4 per `docs/ROADMAP.md`), any money/enum-heavy tables (no such tables exist yet in Milestone 1).
- **Type/signature consistency check**: `CmopRole` enum cases and values match `docs/SECURITY.md` §2 exactly; `Desk` fields match `docs/DATABASE.md` §2 (`name`, `entity`, `region` — `id`/timestamps implicit); `User.desk_id`/`is_active` match `docs/DATABASE.md` §2's `users` table description. `HandleInertiaRequests::share()`'s `auth.user.roles` shape is referenced consistently in Task 5's `DashboardAccessTest` and `NavBar.vue`.
