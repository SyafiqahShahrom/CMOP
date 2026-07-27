# Milestone 2 — Trade Import & Matching Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the trade file import pipeline, a data-driven two-sided matching engine, and single-pass break detection — the first real TBIP business logic, ending at a dispatched `BreakDetected` event with no consumer yet (Milestone 3 adds Cases).

**Architecture:** `Trades` domain owns file upload/parsing/normalization; `Reconciliation` domain owns matching rules, the matching engine, and break detection, wired together by the `TradeImported` domain event (per `docs/ARCHITECTURE.md` §6's event-based cross-domain communication). Both domains follow the DDD subfolder convention established in Milestone 1.

**Tech Stack:** Laravel 12, `maatwebsite/laravel-excel` (XLSX import/export), PostgreSQL (Supabase-hosted), Pest, existing RBAC/Policy/DDD patterns from Milestone 1.

## Global Constraints

- Import format is XLSX via Laravel Excel (`docs/superpowers/specs/2026-07-27-milestone-2-trade-import-matching-design.md` Decision 1).
- Two-sided matching: `trade_files.source_side` (`internal`|`counterparty`) distinguishes sides — NOT `trades.side` (buy/sell direction, unchanged meaning) (Decision 2, 8).
- Match criteria: exact `external_trade_id` match required; tolerance on `notional_amount`/dates is rule-defined JSON, not hardcoded (Decision 3, 5).
- Single-pass matching: runs once synchronously after each file import; no counterpart found by end of that pass = immediate `UNMATCHED` break (Decision 4) — this is a documented simplification, not a bug to "fix" mid-implementation.
- Severity/SLA: hardcoded PHP thresholds (not rule-configurable), calendar time (not business-day-aware) (Decision 6).
- Milestone stops at `trade_breaks` + dispatched `BreakDetected` — no `Cases` domain, no listener consumes it (Decision 7).
- Money columns are `bigint` minor units per `docs/DATABASE.md` §1 / ADR-003 — never float/decimal.
- Enum-like columns are PHP backed enums stored as `string`, never native DB enums, per `docs/DATABASE.md` §1 / ADR-004.
- Desk scoping enforced via Policies at the query layer, never UI-only, per `docs/SECURITY.md` §4 — a cross-desk request returns 404, not 403, per `docs/API.md` §7.
- Controllers are thin — one Action/Service call, return an Inertia response, per `docs/ARCHITECTURE.md` §4.
- Modular monolith DDD folder layout under `app/Domains/{Trades,Reconciliation}/` with subfolders `Actions/ Services/ Models/ Enums/ Events/ Listeners/ Jobs/ Policies/ Requests/ Support/` as needed — only create the ones actually used.
- Migrations are additive-only per `docs/DEPLOYMENT.md` §9 — `matches.payment_id`/`matched_payment_id` are added as plain nullable `unsignedBigInteger` columns with NO foreign key constraint this milestone (the `payments` table doesn't exist yet); a future migration adds the FK when the payments pipeline lands. Same for `trade_breaks.payment_id`.
- `QUEUE_CONNECTION=sync` in local `.env` (set in Milestone 1) means queued jobs/listeners run inline during tests and local dev without a running worker — rely on this for straightforward feature testing.

---

## File Structure

```
app/Domains/Trades/
  Models/TradeFile.php
  Models/Trade.php
  Enums/TradeFileSource.php
  Enums/TradeFileStatus.php
  Enums/TradeStatus.php
  Events/TradeImported.php
  Actions/ImportTradeFileAction.php
  Jobs/ImportTradeFileJob.php
  Support/TradeRowImport.php
  Policies/TradeFilePolicy.php
  Requests/UploadTradeFileRequest.php

app/Domains/Reconciliation/
  Models/MatchingRule.php
  Models/Match.php
  Models/TradeBreak.php
  Enums/BreakType.php
  Enums/BreakSeverity.php
  Enums/TradeBreakStatus.php
  Enums/MatchType.php
  Events/BreakDetected.php
  Services/MatchingEngine.php
  Support/BreakSeverityCalculator.php
  Listeners/RunMatchingEngine.php

app/Http/Controllers/Trades/TradeFileController.php

database/migrations/
  xxxx_create_trade_files_table.php
  xxxx_create_trades_table.php
  xxxx_create_matching_rules_table.php
  xxxx_create_matches_table.php
  xxxx_create_trade_breaks_table.php

database/factories/
  TradeFileFactory.php
  TradeFactory.php
  MatchingRuleFactory.php

database/seeders/
  MatchingRuleSeeder.php
  DatabaseSeeder.php (modified)

tests/Support/ArrayToXlsxExport.php
tests/Support/GeneratesTradeFixtures.php

resources/js/Pages/Trades/Upload.vue
resources/js/Pages/Trades/Index.vue
resources/js/Components/NavBar.vue (modified — add Trades link)

routes/web.php (modified)

tests/Unit/Trades/TradeModelTest.php
tests/Unit/Reconciliation/TradeBreakModelTest.php
tests/Unit/Trades/TradeFilePolicyTest.php
tests/Feature/Trades/UploadTradeFileTest.php
tests/Unit/Reconciliation/MatchingEngineTest.php
tests/Unit/Reconciliation/BreakSeverityCalculatorTest.php
tests/Feature/Trades/TradeImportPipelineTest.php
tests/Feature/Trades/TradeFileIndexTest.php
```

---

## Task 1: Trades base schema, models, and enums

**Files:**
- Create: `database/migrations/xxxx_create_trade_files_table.php`
- Create: `database/migrations/xxxx_create_trades_table.php`
- Create: `app/Domains/Trades/Enums/TradeFileSource.php`
- Create: `app/Domains/Trades/Enums/TradeFileStatus.php`
- Create: `app/Domains/Trades/Enums/TradeStatus.php`
- Create: `app/Domains/Trades/Models/TradeFile.php`
- Create: `app/Domains/Trades/Models/Trade.php`
- Create: `database/factories/TradeFileFactory.php`
- Create: `database/factories/TradeFactory.php`
- Test: `tests/Unit/Trades/TradeModelTest.php`

**Interfaces:**
- Consumes: `App\Domains\Administration\Models\Desk` (Milestone 1), `App\Models\User` (Milestone 1).
- Produces: `TradeFile` (fillable: `filename`, `source_system`, `source_side`, `uploaded_by`, `desk_id`, `status`, `imported_at`, `row_count`, `error_count`; relations `trades(): HasMany`, `desk(): BelongsTo`, `uploader(): BelongsTo`). `Trade` (fillable: `trade_file_id`, `external_trade_id`, `instrument`, `counterparty`, `trade_date`, `settlement_date`, `notional_amount`, `notional_currency`, `side`, `status`, `raw_payload`; relation `tradeFile(): BelongsTo`). `TradeFileSource::Internal`/`::Counterparty` (values `internal`/`counterparty`). `TradeFileStatus::Pending`/`::Imported`/`::Failed`. `TradeStatus::Unmatched`/`::Matched`/`::Break`. Later tasks construct/query these exact fields and enum cases.

- [ ] **Step 1: Write the failing model test**

```php
// tests/Unit/Trades/TradeModelTest.php
<?php

use App\Domains\Administration\Models\Desk;
use App\Domains\Trades\Enums\TradeFileSource;
use App\Domains\Trades\Enums\TradeFileStatus;
use App\Domains\Trades\Enums\TradeStatus;
use App\Domains\Trades\Models\Trade;
use App\Domains\Trades\Models\TradeFile;
use App\Models\User;

test('a trade file belongs to a desk and an uploader, and has many trades', function () {
    $desk = Desk::factory()->create();
    $user = User::factory()->create(['desk_id' => $desk->id]);

    $file = TradeFile::factory()->create([
        'desk_id' => $desk->id,
        'uploaded_by' => $user->id,
        'source_side' => TradeFileSource::Internal->value,
        'status' => TradeFileStatus::Pending->value,
    ]);

    $trade = Trade::factory()->create([
        'trade_file_id' => $file->id,
        'status' => TradeStatus::Unmatched->value,
    ]);

    expect($file->desk->id)->toBe($desk->id)
        ->and($file->uploader->id)->toBe($user->id)
        ->and($file->trades)->toHaveCount(1)
        ->and($file->trades->first()->id)->toBe($trade->id)
        ->and($trade->tradeFile->id)->toBe($file->id);
});

test('a trade stores notional as an integer minor-unit amount and casts dates', function () {
    $trade = Trade::factory()->create([
        'notional_amount' => 150075, // $1,500.75
        'notional_currency' => 'USD',
        'trade_date' => '2026-07-20',
        'settlement_date' => '2026-07-22',
    ]);

    expect($trade->notional_amount)->toBeInt()->toBe(150075)
        ->and($trade->trade_date->format('Y-m-d'))->toBe('2026-07-20')
        ->and($trade->settlement_date->format('Y-m-d'))->toBe('2026-07-22');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=TradeModelTest`
Expected: FAIL — classes/tables do not exist.

- [ ] **Step 3: Write the enums**

```php
// app/Domains/Trades/Enums/TradeFileSource.php
<?php

namespace App\Domains\Trades\Enums;

enum TradeFileSource: string
{
    case Internal = 'internal';
    case Counterparty = 'counterparty';
}
```

```php
// app/Domains/Trades/Enums/TradeFileStatus.php
<?php

namespace App\Domains\Trades\Enums;

enum TradeFileStatus: string
{
    case Pending = 'pending';
    case Imported = 'imported';
    case Failed = 'failed';
}
```

```php
// app/Domains/Trades/Enums/TradeStatus.php
<?php

namespace App\Domains\Trades\Enums;

enum TradeStatus: string
{
    case Unmatched = 'unmatched';
    case Matched = 'matched';
    case Break = 'break';
}
```

- [ ] **Step 4: Write the migrations**

```php
// database/migrations/xxxx_create_trade_files_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_files', function (Blueprint $table) {
            $table->id();
            $table->string('filename');
            $table->string('source_system')->default('manual-upload');
            $table->string('source_side');
            $table->foreignId('uploaded_by')->constrained('users');
            $table->foreignId('desk_id')->constrained('desks');
            $table->string('status')->default('pending');
            $table->timestamp('imported_at')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_files');
    }
};
```

```php
// database/migrations/xxxx_create_trades_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trade_file_id')->constrained('trade_files');
            $table->string('external_trade_id');
            $table->string('instrument');
            $table->string('counterparty');
            $table->date('trade_date');
            $table->date('settlement_date');
            $table->bigInteger('notional_amount');
            $table->char('notional_currency', 3);
            $table->string('side');
            $table->string('status')->default('unmatched');
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->index('external_trade_id');
            $table->index('trade_date');
            $table->index('counterparty');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trades');
    }
};
```

- [ ] **Step 5: Write the models**

```php
// app/Domains/Trades/Models/TradeFile.php
<?php

namespace App\Domains\Trades\Models;

use App\Domains\Administration\Models\Desk;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TradeFile extends Model
{
    use HasFactory;

    protected $fillable = [
        'filename',
        'source_system',
        'source_side',
        'uploaded_by',
        'desk_id',
        'status',
        'imported_at',
        'row_count',
        'error_count',
    ];

    protected function casts(): array
    {
        return [
            'imported_at' => 'datetime',
        ];
    }

    public function trades(): HasMany
    {
        return $this->hasMany(Trade::class);
    }

    public function desk(): BelongsTo
    {
        return $this->belongsTo(Desk::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
```

```php
// app/Domains/Trades/Models/Trade.php
<?php

namespace App\Domains\Trades\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Trade extends Model
{
    use HasFactory;

    protected $fillable = [
        'trade_file_id',
        'external_trade_id',
        'instrument',
        'counterparty',
        'trade_date',
        'settlement_date',
        'notional_amount',
        'notional_currency',
        'side',
        'status',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'trade_date' => 'date',
            'settlement_date' => 'date',
            'notional_amount' => 'integer',
            'raw_payload' => 'array',
        ];
    }

    public function tradeFile(): BelongsTo
    {
        return $this->belongsTo(TradeFile::class);
    }
}
```

- [ ] **Step 6: Write the factories**

```php
// database/factories/TradeFileFactory.php
<?php

namespace Database\Factories;

use App\Domains\Administration\Models\Desk;
use App\Domains\Trades\Enums\TradeFileSource;
use App\Domains\Trades\Enums\TradeFileStatus;
use App\Domains\Trades\Models\TradeFile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TradeFileFactory extends Factory
{
    protected $model = TradeFile::class;

    public function definition(): array
    {
        return [
            'filename' => $this->faker->word() . '.xlsx',
            'source_system' => 'manual-upload',
            'source_side' => TradeFileSource::Internal->value,
            'uploaded_by' => User::factory(),
            'desk_id' => Desk::factory(),
            'status' => TradeFileStatus::Pending->value,
            'imported_at' => null,
            'row_count' => 0,
            'error_count' => 0,
        ];
    }
}
```

```php
// database/factories/TradeFactory.php
<?php

namespace Database\Factories;

use App\Domains\Trades\Enums\TradeStatus;
use App\Domains\Trades\Models\Trade;
use App\Domains\Trades\Models\TradeFile;
use Illuminate\Database\Eloquent\Factories\Factory;

class TradeFactory extends Factory
{
    protected $model = Trade::class;

    public function definition(): array
    {
        return [
            'trade_file_id' => TradeFile::factory(),
            'external_trade_id' => strtoupper($this->faker->bothify('TRD-####??')),
            'instrument' => $this->faker->randomElement(['USD/JPY', 'EUR/USD', 'GBP/USD']),
            'counterparty' => $this->faker->company(),
            'trade_date' => $this->faker->dateTimeBetween('-5 days', 'now')->format('Y-m-d'),
            'settlement_date' => $this->faker->dateTimeBetween('now', '+5 days')->format('Y-m-d'),
            'notional_amount' => $this->faker->numberBetween(10_000_00, 5_000_000_00),
            'notional_currency' => 'USD',
            'side' => $this->faker->randomElement(['buy', 'sell']),
            'status' => TradeStatus::Unmatched->value,
            'raw_payload' => null,
        ];
    }
}
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `php artisan migrate`
Run: `php artisan test --filter=TradeModelTest`
Expected: PASS

- [ ] **Step 8: Run the full suite to confirm no regressions**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 9: Commit**

```bash
git add database/migrations database/factories/TradeFileFactory.php database/factories/TradeFactory.php app/Domains/Trades/Enums app/Domains/Trades/Models tests/Unit/Trades/TradeModelTest.php
git commit -m "feat: add trade_files and trades schema, models, and enums"
```

---

## Task 2: Reconciliation schema, models, enums, and default matching rule seeder

**Files:**
- Create: `database/migrations/xxxx_create_matching_rules_table.php`
- Create: `database/migrations/xxxx_create_matches_table.php`
- Create: `database/migrations/xxxx_create_trade_breaks_table.php`
- Create: `app/Domains/Reconciliation/Enums/BreakType.php`
- Create: `app/Domains/Reconciliation/Enums/BreakSeverity.php`
- Create: `app/Domains/Reconciliation/Enums/TradeBreakStatus.php`
- Create: `app/Domains/Reconciliation/Enums/MatchType.php`
- Create: `app/Domains/Reconciliation/Models/MatchingRule.php`
- Create: `app/Domains/Reconciliation/Models/Match.php`
- Create: `app/Domains/Reconciliation/Models/TradeBreak.php`
- Create: `database/factories/MatchingRuleFactory.php`
- Create: `database/seeders/MatchingRuleSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Test: `tests/Unit/Reconciliation/TradeBreakModelTest.php`

**Interfaces:**
- Consumes: `Trade` (Task 1), `User` (Milestone 1).
- Produces: `MatchingRule` (fillable: `name`, `applies_to`, `rule_definition`, `is_active`, `priority`; `rule_definition` casts to array). `Match` (table `matches`; fillable: `trade_id`, `matched_trade_id`, `match_type`, `matched_by`, `confidence_score`, `matched_at`). `TradeBreak` (fillable: `trade_id`, `break_type`, `severity`, `status`, `detected_at`, `sla_due_at`, `resolved_at`; `reference` auto-generated on create, format `TBIP-{year}-{6-digit zero-padded id}`, e.g. `TBIP-2026-000001` — NOT fillable). `BreakType::Mismatch`/`::Unmatched`. `BreakSeverity::Critical`/`::High`/`::Medium`/`::Low`. `TradeBreakStatus::Open`/`::Closed`. `MatchType::Auto`/`::Manual`. A seeded `MatchingRule` row exists after `DatabaseSeeder` runs with `applies_to='trade'`, `is_active=true`, and `rule_definition` matching the exact JSON shape in the spec (`match_key: "external_trade_id"`, `tolerance.notional_amount.minor_units: 0`, `tolerance.trade_date.exact: true`, `tolerance.settlement_date.exact: true`) — Task 5's `MatchingEngine` reads this exact shape.

- [ ] **Step 1: Write the failing model test**

```php
// tests/Unit/Reconciliation/TradeBreakModelTest.php
<?php

use App\Domains\Reconciliation\Enums\BreakSeverity;
use App\Domains\Reconciliation\Enums\BreakType;
use App\Domains\Reconciliation\Enums\TradeBreakStatus;
use App\Domains\Reconciliation\Models\TradeBreak;
use App\Domains\Trades\Models\Trade;

test('a trade break auto-generates a human-readable reference on creation', function () {
    $trade = Trade::factory()->create();

    $break = TradeBreak::create([
        'trade_id' => $trade->id,
        'break_type' => BreakType::Unmatched->value,
        'severity' => BreakSeverity::Medium->value,
        'status' => TradeBreakStatus::Open->value,
        'detected_at' => now(),
        'sla_due_at' => now()->addDays(3),
    ]);

    expect($break->reference)->toMatch('/^TBIP-\d{4}-\d{6}$/')
        ->and($break->reference)->toContain((string) now()->year)
        ->and($break->trade->id)->toBe($trade->id);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=TradeBreakModelTest`
Expected: FAIL — class/table does not exist.

- [ ] **Step 3: Write the enums**

```php
// app/Domains/Reconciliation/Enums/BreakType.php
<?php

namespace App\Domains\Reconciliation\Enums;

enum BreakType: string
{
    case Mismatch = 'mismatch';
    case Unmatched = 'unmatched';
}
```

```php
// app/Domains/Reconciliation/Enums/BreakSeverity.php
<?php

namespace App\Domains\Reconciliation\Enums;

enum BreakSeverity: string
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
}
```

```php
// app/Domains/Reconciliation/Enums/TradeBreakStatus.php
<?php

namespace App\Domains\Reconciliation\Enums;

enum TradeBreakStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}
```

```php
// app/Domains/Reconciliation/Enums/MatchType.php
<?php

namespace App\Domains\Reconciliation\Enums;

enum MatchType: string
{
    case Auto = 'auto';
    case Manual = 'manual';
}
```

- [ ] **Step 4: Write the migrations**

```php
// database/migrations/xxxx_create_matching_rules_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matching_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('applies_to');
            $table->json('rule_definition');
            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matching_rules');
    }
};
```

```php
// database/migrations/xxxx_create_matches_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trade_id')->nullable()->constrained('trades');
            // payment_id: no FK yet, `payments` table does not exist until the payments milestone.
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->foreignId('matched_trade_id')->nullable()->constrained('trades');
            $table->unsignedBigInteger('matched_payment_id')->nullable();
            $table->string('match_type');
            $table->foreignId('matched_by')->nullable()->constrained('users');
            $table->decimal('confidence_score', 5, 2)->nullable();
            $table->timestamp('matched_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matches');
    }
};
```

```php
// database/migrations/xxxx_create_trade_breaks_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_breaks', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('trade_id')->nullable()->constrained('trades');
            // payment_id: no FK yet, `payments` table does not exist until the payments milestone.
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->string('break_type');
            $table->string('severity');
            $table->string('status')->default('open');
            $table->timestamp('detected_at');
            $table->timestamp('sla_due_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('severity');
            $table->index('sla_due_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_breaks');
    }
};
```

- [ ] **Step 5: Write the models**

```php
// app/Domains/Reconciliation/Models/MatchingRule.php
<?php

namespace App\Domains\Reconciliation\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MatchingRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'applies_to',
        'rule_definition',
        'is_active',
        'priority',
    ];

    protected function casts(): array
    {
        return [
            'rule_definition' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
```

```php
// app/Domains/Reconciliation/Models/Match.php
<?php

namespace App\Domains\Reconciliation\Models;

use App\Domains\Trades\Models\Trade;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Match extends Model
{
    protected $table = 'matches';

    protected $fillable = [
        'trade_id',
        'payment_id',
        'matched_trade_id',
        'matched_payment_id',
        'match_type',
        'matched_by',
        'confidence_score',
        'matched_at',
    ];

    protected function casts(): array
    {
        return [
            'matched_at' => 'datetime',
        ];
    }

    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }

    public function matchedTrade(): BelongsTo
    {
        return $this->belongsTo(Trade::class, 'matched_trade_id');
    }

    public function matchedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_by');
    }
}
```

```php
// app/Domains/Reconciliation/Models/TradeBreak.php
<?php

namespace App\Domains\Reconciliation\Models;

use App\Domains\Trades\Models\Trade;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradeBreak extends Model
{
    protected $fillable = [
        'trade_id',
        'payment_id',
        'break_type',
        'severity',
        'status',
        'detected_at',
        'sla_due_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'detected_at' => 'datetime',
            'sla_due_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (TradeBreak $break): void {
            $break->update([
                'reference' => sprintf('TBIP-%d-%06d', $break->detected_at->year, $break->id),
            ]);
        });
    }

    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }
}
```

- [ ] **Step 6: Write the factory and seeder**

```php
// database/factories/MatchingRuleFactory.php
<?php

namespace Database\Factories;

use App\Domains\Reconciliation\Models\MatchingRule;
use Illuminate\Database\Eloquent\Factories\Factory;

class MatchingRuleFactory extends Factory
{
    protected $model = MatchingRule::class;

    public function definition(): array
    {
        return [
            'name' => 'Default Trade Matching Rule',
            'applies_to' => 'trade',
            'rule_definition' => [
                'match_key' => 'external_trade_id',
                'tolerance' => [
                    'notional_amount' => ['minor_units' => 0],
                    'trade_date' => ['exact' => true],
                    'settlement_date' => ['exact' => true],
                ],
            ],
            'is_active' => true,
            'priority' => 0,
        ];
    }
}
```

```php
// database/seeders/MatchingRuleSeeder.php
<?php

namespace Database\Seeders;

use App\Domains\Reconciliation\Models\MatchingRule;
use Illuminate\Database\Seeder;

class MatchingRuleSeeder extends Seeder
{
    public function run(): void
    {
        MatchingRule::factory()->create();
    }
}
```

```php
// database/seeders/DatabaseSeeder.php (modify — add MatchingRuleSeeder to the call list)
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
            MatchingRuleSeeder::class,
        ]);
    }
}
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `php artisan migrate`
Run: `php artisan test --filter=TradeBreakModelTest`
Expected: PASS

- [ ] **Step 8: Run migrations + seeders fresh, run full suite**

Run: `php artisan migrate:fresh --seed`
Run: `php artisan test`
Expected: seeders run without error; full suite passes.

- [ ] **Step 9: Commit**

```bash
git add database/migrations database/factories/MatchingRuleFactory.php database/seeders app/Domains/Reconciliation/Enums app/Domains/Reconciliation/Models tests/Unit/Reconciliation/TradeBreakModelTest.php
git commit -m "feat: add matching_rules, matches, trade_breaks schema and models"
```

---

## Task 3: TradeFilePolicy and upload validation

**Files:**
- Create: `app/Domains/Trades/Policies/TradeFilePolicy.php`
- Create: `app/Domains/Trades/Requests/UploadTradeFileRequest.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Unit/Trades/TradeFilePolicyTest.php`

**Interfaces:**
- Consumes: `TradeFile` (Task 1), `CmopRole` (Milestone 1), `TradeFileSource` (Task 1).
- Produces: `TradeFilePolicy::create(User $user): bool`, `TradeFilePolicy::view(User $user, TradeFile $file): bool`, `TradeFilePolicy::viewAny(User $user): bool` (true for any authenticated user — filtering to the user's own desk happens in the query, not this gate). `UploadTradeFileRequest` validates `desk_id` (required, exists in `desks`), `source_side` (required, `in:internal,counterparty`), `file` (required, `mimes:xlsx`). Task 4's controller consumes both.

- [ ] **Step 1: Write the failing policy test**

```php
// tests/Unit/Trades/TradeFilePolicyTest.php
<?php

use App\Domains\Administration\Enums\CmopRole;
use App\Domains\Administration\Models\Desk;
use App\Domains\Trades\Models\TradeFile;
use App\Domains\Trades\Policies\TradeFilePolicy;
use App\Models\User;

test('an analyst can create trade files', function () {
    $analyst = User::factory()->create();
    $analyst->assignRole(CmopRole::Analyst->value);

    expect((new TradeFilePolicy())->create($analyst))->toBeTrue();
});

test('a compliance user cannot create trade files', function () {
    $compliance = User::factory()->create();
    $compliance->assignRole(CmopRole::Compliance->value);

    expect((new TradeFilePolicy())->create($compliance))->toBeFalse();
});

test('a user can view a trade file on their own desk', function () {
    $desk = Desk::factory()->create();
    $analyst = User::factory()->create(['desk_id' => $desk->id]);
    $analyst->assignRole(CmopRole::Analyst->value);
    $file = TradeFile::factory()->create(['desk_id' => $desk->id]);

    expect((new TradeFilePolicy())->view($analyst, $file))->toBeTrue();
});

test('a user cannot view a trade file on a different desk', function () {
    $otherDesk = Desk::factory()->create();
    $analyst = User::factory()->create(['desk_id' => Desk::factory()->create()->id]);
    $analyst->assignRole(CmopRole::Analyst->value);
    $file = TradeFile::factory()->create(['desk_id' => $otherDesk->id]);

    expect((new TradeFilePolicy())->view($analyst, $file))->toBeFalse();
});

test('an ops_manager can view a trade file on any desk', function () {
    $opsManager = User::factory()->create();
    $opsManager->assignRole(CmopRole::OpsManager->value);
    $file = TradeFile::factory()->create();

    expect((new TradeFilePolicy())->view($opsManager, $file))->toBeTrue();
});

test('a compliance user can view a trade file on any desk', function () {
    $compliance = User::factory()->create();
    $compliance->assignRole(CmopRole::Compliance->value);
    $file = TradeFile::factory()->create();

    expect((new TradeFilePolicy())->view($compliance, $file))->toBeTrue();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=TradeFilePolicyTest`
Expected: FAIL — class does not exist.

- [ ] **Step 3: Write the policy**

```php
// app/Domains/Trades/Policies/TradeFilePolicy.php
<?php

namespace App\Domains\Trades\Policies;

use App\Domains\Administration\Enums\CmopRole;
use App\Domains\Trades\Models\TradeFile;
use App\Models\User;

class TradeFilePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, TradeFile $file): bool
    {
        if ($user->hasRole(CmopRole::OpsManager->value) || $user->hasRole(CmopRole::Compliance->value)) {
            return true;
        }

        return $user->desk_id === $file->desk_id;
    }

    public function create(User $user): bool
    {
        return $user->hasRole(CmopRole::Analyst->value)
            || $user->hasRole(CmopRole::TeamLead->value)
            || $user->hasRole(CmopRole::Admin->value);
    }
}
```

- [ ] **Step 4: Register the policy**

```php
// app/Providers/AppServiceProvider.php (modify boot() — add alongside the existing Desk policy registration)
use App\Domains\Trades\Models\TradeFile;
use App\Domains\Trades\Policies\TradeFilePolicy;

// inside boot():
Gate::policy(TradeFile::class, TradeFilePolicy::class);
```

- [ ] **Step 5: Write the upload request**

```php
// app/Domains/Trades/Requests/UploadTradeFileRequest.php
<?php

namespace App\Domains\Trades\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadTradeFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Domains\Trades\Models\TradeFile::class);
    }

    public function rules(): array
    {
        return [
            'desk_id' => ['required', 'exists:desks,id'],
            'source_side' => ['required', 'in:internal,counterparty'],
            'file' => ['required', 'file', 'mimes:xlsx'],
        ];
    }
}
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test --filter=TradeFilePolicyTest`
Expected: PASS

- [ ] **Step 7: Run the full suite to confirm no regressions**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add app/Domains/Trades/Policies app/Domains/Trades/Requests app/Providers/AppServiceProvider.php tests/Unit/Trades/TradeFilePolicyTest.php
git commit -m "feat: add TradeFilePolicy with real desk scoping and upload validation"
```

---

## Task 4: Import pipeline — upload, parse, normalize

**Files:**
- Create: `app/Domains/Trades/Support/TradeRowImport.php`
- Create: `app/Domains/Trades/Events/TradeImported.php`
- Create: `app/Domains/Trades/Actions/ImportTradeFileAction.php`
- Create: `app/Domains/Trades/Jobs/ImportTradeFileJob.php`
- Create: `app/Http/Controllers/Trades/TradeFileController.php`
- Modify: `routes/web.php`
- Create: `tests/Support/ArrayToXlsxExport.php`
- Create: `tests/Support/GeneratesTradeFixtures.php`
- Test: `tests/Feature/Trades/UploadTradeFileTest.php`

**Interfaces:**
- Consumes: `TradeFile`/`Trade`/`TradeFileSource`/`TradeFileStatus`/`TradeStatus` (Task 1), `TradeFilePolicy`/`UploadTradeFileRequest` (Task 3).
- Produces: `ImportTradeFileAction::execute(User $user, int $deskId, string $sourceSide, \Illuminate\Http\UploadedFile $file): TradeFile` — stores the file, creates the `trade_files` row (status `pending`), dispatches `ImportTradeFileJob`. `ImportTradeFileJob` (implements `ShouldQueue`, queue `imports`) parses the stored file via `TradeRowImport`, creates `trades` rows, sets `trade_files.status` to `imported`/`failed` and `row_count`/`error_count`/`imported_at`, dispatches `TradeImported` (payload: `public int $tradeFileId`). `POST /trades/files` route (auth + `TradeFilePolicy::create`). `tests\Support\GeneratesTradeFixtures::makeXlsxUpload(array $rows): \Illuminate\Http\Testing\File` — later tasks (5, 6) reuse this helper for fixture generation; `$rows` is an array of associative arrays with keys `external_trade_id, instrument, counterparty, trade_date, settlement_date, notional_amount, notional_currency, side` (the XLSX column headers, snake_case, matching Laravel Excel's `WithHeadingRow`).

- [ ] **Step 1: Write the XLSX fixture-generation test helpers**

```php
// tests/Support/ArrayToXlsxExport.php
<?php

namespace Tests\Support;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ArrayToXlsxExport implements FromArray, WithHeadings
{
    public function __construct(private array $rows) {}

    public function array(): array
    {
        return array_map('array_values', $this->rows);
    }

    public function headings(): array
    {
        return empty($this->rows) ? [] : array_keys($this->rows[0]);
    }
}
```

```php
// tests/Support/GeneratesTradeFixtures.php
<?php

namespace Tests\Support;

use Illuminate\Http\Testing\File as TestingFile;
use Maatwebsite\Excel\Facades\Excel;

trait GeneratesTradeFixtures
{
    protected function makeXlsxUpload(array $rows, string $filename = 'trades.xlsx'): TestingFile
    {
        $relativePath = 'fixtures/' . uniqid() . '-' . $filename;

        Excel::store(new ArrayToXlsxExport($rows), $relativePath, 'local');

        $absolutePath = storage_path('app/private/' . $relativePath);

        return new TestingFile($filename, fopen($absolutePath, 'r'));
    }
}
```

- [ ] **Step 2: Write the failing feature test**

```php
// tests/Feature/Trades/UploadTradeFileTest.php
<?php

use App\Domains\Administration\Enums\CmopRole;
use App\Domains\Administration\Models\Desk;
use App\Domains\Trades\Enums\TradeFileSource;
use App\Domains\Trades\Enums\TradeFileStatus;
use App\Domains\Trades\Models\Trade;
use App\Domains\Trades\Models\TradeFile;
use App\Models\User;
use Tests\Support\GeneratesTradeFixtures;

uses(GeneratesTradeFixtures::class);

test('an analyst can upload a trade file and it is imported synchronously', function () {
    $desk = Desk::factory()->create();
    $analyst = User::factory()->create(['desk_id' => $desk->id]);
    $analyst->assignRole(CmopRole::Analyst->value);

    $upload = $this->makeXlsxUpload([
        [
            'external_trade_id' => 'TRD-1001',
            'instrument' => 'USD/JPY',
            'counterparty' => 'Acme Bank',
            'trade_date' => '2026-07-20',
            'settlement_date' => '2026-07-22',
            'notional_amount' => '1500.75',
            'notional_currency' => 'USD',
            'side' => 'buy',
        ],
    ]);

    $response = $this->actingAs($analyst)->post('/trades/files', [
        'desk_id' => $desk->id,
        'source_side' => TradeFileSource::Internal->value,
        'file' => $upload,
    ]);

    $response->assertRedirect();

    $file = TradeFile::first();
    expect($file)->not->toBeNull()
        ->and($file->status)->toBe(TradeFileStatus::Imported->value)
        ->and($file->row_count)->toBe(1)
        ->and($file->error_count)->toBe(0);

    $trade = Trade::first();
    expect($trade->external_trade_id)->toBe('TRD-1001')
        ->and($trade->notional_amount)->toBe(150075)
        ->and($trade->notional_currency)->toBe('USD')
        ->and($trade->trade_date->format('Y-m-d'))->toBe('2026-07-20');
});

test('a user cannot upload a trade file for a desk that is not their own without elevated role', function () {
    $ownDesk = Desk::factory()->create();
    $otherDesk = Desk::factory()->create();
    $analyst = User::factory()->create(['desk_id' => $ownDesk->id]);
    $analyst->assignRole(CmopRole::Analyst->value);

    $upload = $this->makeXlsxUpload([[
        'external_trade_id' => 'TRD-1002',
        'instrument' => 'EUR/USD',
        'counterparty' => 'Beta Corp',
        'trade_date' => '2026-07-20',
        'settlement_date' => '2026-07-22',
        'notional_amount' => '2000.00',
        'notional_currency' => 'USD',
        'side' => 'sell',
    ]]);

    $response = $this->actingAs($analyst)->post('/trades/files', [
        'desk_id' => $otherDesk->id,
        'source_side' => TradeFileSource::Internal->value,
        'file' => $upload,
    ]);

    // create() authorizes by role only (per TradeFilePolicy) -- desk scoping for
    // upload targeting is enforced by the Action trusting the authenticated
    // user's own desk_id, not the request's desk_id. This test documents that
    // the controller must use $request->user()->desk_id, not the posted value.
    expect(TradeFile::first()->desk_id)->toBe($ownDesk->id);
});
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `php artisan test --filter=UploadTradeFileTest`
Expected: FAIL — route/classes do not exist.

- [ ] **Step 4: Write the `TradeImported` event**

```php
// app/Domains/Trades/Events/TradeImported.php
<?php

namespace App\Domains\Trades\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TradeImported
{
    use Dispatchable, SerializesModels;

    public function __construct(public int $tradeFileId) {}
}
```

- [ ] **Step 5: Write the Laravel Excel import class**

```php
// app/Domains/Trades/Support/TradeRowImport.php
<?php

namespace App\Domains\Trades\Support;

use App\Domains\Trades\Enums\TradeStatus;
use App\Domains\Trades\Models\Trade;
use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Row;

class TradeRowImport implements OnEachRow, WithHeadingRow
{
    public int $importedCount = 0;

    public int $errorCount = 0;

    public function __construct(private int $tradeFileId) {}

    public function onRow(Row $row): void
    {
        $data = $row->toArray();

        try {
            Trade::create([
                'trade_file_id' => $this->tradeFileId,
                'external_trade_id' => (string) $data['external_trade_id'],
                'instrument' => (string) $data['instrument'],
                'counterparty' => (string) $data['counterparty'],
                'trade_date' => (string) $data['trade_date'],
                'settlement_date' => (string) $data['settlement_date'],
                'notional_amount' => (int) round(((float) $data['notional_amount']) * 100),
                'notional_currency' => (string) $data['notional_currency'],
                'side' => (string) $data['side'],
                'status' => TradeStatus::Unmatched->value,
                'raw_payload' => $data,
            ]);

            $this->importedCount++;
        } catch (\Throwable) {
            $this->errorCount++;
        }
    }
}
```

- [ ] **Step 6: Write the job**

```php
// app/Domains/Trades/Jobs/ImportTradeFileJob.php
<?php

namespace App\Domains\Trades\Jobs;

use App\Domains\Trades\Enums\TradeFileStatus;
use App\Domains\Trades\Events\TradeImported;
use App\Domains\Trades\Models\TradeFile;
use App\Domains\Trades\Support\TradeRowImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Maatwebsite\Excel\Facades\Excel;

class ImportTradeFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $queue = 'imports';

    public function __construct(public int $tradeFileId) {}

    public function handle(): void
    {
        $file = TradeFile::findOrFail($this->tradeFileId);

        $import = new TradeRowImport($file->id);
        Excel::import($import, storage_path('app/private/' . $file->filename));

        $file->update([
            'status' => $import->errorCount > 0 && $import->importedCount === 0
                ? TradeFileStatus::Failed->value
                : TradeFileStatus::Imported->value,
            'row_count' => $import->importedCount,
            'error_count' => $import->errorCount,
            'imported_at' => now(),
        ]);

        TradeImported::dispatch($file->id);
    }
}
```

- [ ] **Step 7: Write the Action**

```php
// app/Domains/Trades/Actions/ImportTradeFileAction.php
<?php

namespace App\Domains\Trades\Actions;

use App\Domains\Trades\Enums\TradeFileStatus;
use App\Domains\Trades\Jobs\ImportTradeFileJob;
use App\Domains\Trades\Models\TradeFile;
use App\Models\User;
use Illuminate\Http\UploadedFile;

class ImportTradeFileAction
{
    public function execute(User $user, int $deskId, string $sourceSide, UploadedFile $file): TradeFile
    {
        $storedPath = $file->store('trade-files', 'local');

        $tradeFile = TradeFile::create([
            'filename' => $storedPath,
            'source_side' => $sourceSide,
            'uploaded_by' => $user->id,
            'desk_id' => $deskId,
            'status' => TradeFileStatus::Pending->value,
        ]);

        ImportTradeFileJob::dispatch($tradeFile->id);

        return $tradeFile;
    }
}
```

- [ ] **Step 8: Write the thin controller**

```php
// app/Http/Controllers/Trades/TradeFileController.php
<?php

namespace App\Http\Controllers\Trades;

use App\Domains\Trades\Actions\ImportTradeFileAction;
use App\Domains\Trades\Requests\UploadTradeFileRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

class TradeFileController extends Controller
{
    public function store(UploadTradeFileRequest $request, ImportTradeFileAction $action): RedirectResponse
    {
        $action->execute(
            user: $request->user(),
            deskId: $request->user()->desk_id,
            sourceSide: $request->string('source_side')->toString(),
            file: $request->file('file'),
        );

        return redirect('/trades');
    }
}
```

Note: the Action always uses `$request->user()->desk_id` (the authenticated user's own desk), never the posted `desk_id` — this is what Step 2's second test verifies. The `desk_id` field in `UploadTradeFileRequest` exists for admin/cross-desk upload scenarios in a future milestone; for now it's validated but intentionally not trusted as the target desk.

- [ ] **Step 9: Register the route**

```php
// routes/web.php (add)
use App\Http\Controllers\Trades\TradeFileController;

Route::post('/trades/files', [TradeFileController::class, 'store'])
    ->middleware('auth')
    ->name('trades.files.store');
```

- [ ] **Step 10: Run the test to verify it passes**

Run: `php artisan test --filter=UploadTradeFileTest`
Expected: PASS

- [ ] **Step 11: Run the full suite to confirm no regressions**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 12: Commit**

```bash
git add app/Domains/Trades/Support app/Domains/Trades/Events app/Domains/Trades/Actions app/Domains/Trades/Jobs app/Http/Controllers/Trades routes/web.php tests/Support tests/Feature/Trades/UploadTradeFileTest.php
git commit -m "feat: add trade file upload, parsing, and normalization pipeline"
```

---

## Task 5: Matching engine and break severity calculator

**Files:**
- Create: `app/Domains/Reconciliation/Events/BreakDetected.php`
- Create: `app/Domains/Reconciliation/Support/BreakSeverityCalculator.php`
- Create: `app/Domains/Reconciliation/Services/MatchingEngine.php`
- Create: `app/Domains/Reconciliation/Listeners/RunMatchingEngine.php`
- Modify: `app/Providers/EventServiceProvider.php` (or `AppServiceProvider::boot()` if Laravel 12's skeleton has no separate `EventServiceProvider` — verify which exists first)
- Test: `tests/Unit/Reconciliation/BreakSeverityCalculatorTest.php`
- Test: `tests/Unit/Reconciliation/MatchingEngineTest.php`

**Interfaces:**
- Consumes: `Trade`/`TradeFile`/`TradeFileSource`/`TradeStatus` (Task 1), `MatchingRule`/`Match`/`TradeBreak`/`BreakType`/`BreakSeverity`/`MatchType` (Task 2), `TradeImported` (Task 4).
- Produces: `BreakSeverityCalculator::calculate(Trade $trade): BreakSeverity`, `BreakSeverityCalculator::slaDueAt(BreakSeverity $severity, \Carbon\Carbon $detectedAt): \Carbon\Carbon`. `MatchingEngine::runForFile(TradeFile $file): void` — the entry point Task 6's integration test and `RunMatchingEngine` both call. `BreakDetected` event (payload: `public int $tradeBreakId`). `RunMatchingEngine` is a queued listener (`ShouldQueue`, queue `matching`) on `TradeImported` that calls `MatchingEngine::runForFile()`.

- [ ] **Step 1: Write the failing `BreakSeverityCalculator` test**

```php
// tests/Unit/Reconciliation/BreakSeverityCalculatorTest.php
<?php

use App\Domains\Reconciliation\Enums\BreakSeverity;
use App\Domains\Reconciliation\Support\BreakSeverityCalculator;
use App\Domains\Trades\Models\Trade;

test('severity is critical for notional at or above $1,000,000', function () {
    $trade = Trade::factory()->make(['notional_amount' => 1_000_000_00]);

    expect((new BreakSeverityCalculator())->calculate($trade))->toBe(BreakSeverity::Critical);
});

test('severity is high for notional at or above $100,000 and below $1,000,000', function () {
    $trade = Trade::factory()->make(['notional_amount' => 500_000_00]);

    expect((new BreakSeverityCalculator())->calculate($trade))->toBe(BreakSeverity::High);
});

test('severity is medium for notional at or above $10,000 and below $100,000', function () {
    $trade = Trade::factory()->make(['notional_amount' => 50_000_00]);

    expect((new BreakSeverityCalculator())->calculate($trade))->toBe(BreakSeverity::Medium);
});

test('severity is low for notional below $10,000', function () {
    $trade = Trade::factory()->make(['notional_amount' => 5_000_00]);

    expect((new BreakSeverityCalculator())->calculate($trade))->toBe(BreakSeverity::Low);
});

test('sla due date is calculated per severity tier', function () {
    $calculator = new BreakSeverityCalculator();
    $detectedAt = now();

    expect($calculator->slaDueAt(BreakSeverity::Critical, $detectedAt)->diffInHours($detectedAt))->toBe(4)
        ->and($calculator->slaDueAt(BreakSeverity::High, $detectedAt)->diffInHours($detectedAt))->toBe(24)
        ->and($calculator->slaDueAt(BreakSeverity::Medium, $detectedAt)->diffInHours($detectedAt))->toBe(72)
        ->and($calculator->slaDueAt(BreakSeverity::Low, $detectedAt)->diffInHours($detectedAt))->toBe(120);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=BreakSeverityCalculatorTest`
Expected: FAIL — class does not exist.

- [ ] **Step 3: Write `BreakSeverityCalculator`**

```php
// app/Domains/Reconciliation/Support/BreakSeverityCalculator.php
<?php

namespace App\Domains\Reconciliation\Support;

use App\Domains\Reconciliation\Enums\BreakSeverity;
use App\Domains\Trades\Models\Trade;
use Carbon\Carbon;

class BreakSeverityCalculator
{
    private const CRITICAL_THRESHOLD_MINOR_UNITS = 1_000_000_00;

    private const HIGH_THRESHOLD_MINOR_UNITS = 100_000_00;

    private const MEDIUM_THRESHOLD_MINOR_UNITS = 10_000_00;

    public function calculate(Trade $trade): BreakSeverity
    {
        return match (true) {
            $trade->notional_amount >= self::CRITICAL_THRESHOLD_MINOR_UNITS => BreakSeverity::Critical,
            $trade->notional_amount >= self::HIGH_THRESHOLD_MINOR_UNITS => BreakSeverity::High,
            $trade->notional_amount >= self::MEDIUM_THRESHOLD_MINOR_UNITS => BreakSeverity::Medium,
            default => BreakSeverity::Low,
        };
    }

    public function slaDueAt(BreakSeverity $severity, Carbon $detectedAt): Carbon
    {
        $hours = match ($severity) {
            BreakSeverity::Critical => 4,
            BreakSeverity::High => 24,
            BreakSeverity::Medium => 72,
            BreakSeverity::Low => 120,
        };

        return $detectedAt->copy()->addHours($hours);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=BreakSeverityCalculatorTest`
Expected: PASS

- [ ] **Step 5: Write the failing `MatchingEngine` test**

```php
// tests/Unit/Reconciliation/MatchingEngineTest.php
<?php

use App\Domains\Administration\Models\Desk;
use App\Domains\Reconciliation\Enums\BreakType;
use App\Domains\Reconciliation\Models\Match;
use App\Domains\Reconciliation\Models\MatchingRule;
use App\Domains\Reconciliation\Models\TradeBreak;
use App\Domains\Reconciliation\Services\MatchingEngine;
use App\Domains\Trades\Enums\TradeFileSource;
use App\Domains\Trades\Enums\TradeStatus;
use App\Domains\Trades\Models\Trade;
use App\Domains\Trades\Models\TradeFile;

beforeEach(function () {
    $this->desk = Desk::factory()->create();
    $this->rule = MatchingRule::factory()->create();
});

test('a trade with a matching counterparty within tolerance produces a Match and marks both trades matched', function () {
    $internalFile = TradeFile::factory()->create(['desk_id' => $this->desk->id, 'source_side' => TradeFileSource::Internal->value]);
    $counterpartyFile = TradeFile::factory()->create(['desk_id' => $this->desk->id, 'source_side' => TradeFileSource::Counterparty->value]);

    $counterpartyTrade = Trade::factory()->create([
        'trade_file_id' => $counterpartyFile->id,
        'external_trade_id' => 'TRD-1',
        'notional_amount' => 100000,
        'trade_date' => '2026-07-20',
        'settlement_date' => '2026-07-22',
        'status' => TradeStatus::Unmatched->value,
    ]);

    $internalTrade = Trade::factory()->create([
        'trade_file_id' => $internalFile->id,
        'external_trade_id' => 'TRD-1',
        'notional_amount' => 100000,
        'trade_date' => '2026-07-20',
        'settlement_date' => '2026-07-22',
        'status' => TradeStatus::Unmatched->value,
    ]);

    (new MatchingEngine())->runForFile($internalFile->fresh());

    expect(Match::count())->toBe(1);
    $match = Match::first();
    expect($match->trade_id)->toBe($internalTrade->id)
        ->and($match->matched_trade_id)->toBe($counterpartyTrade->id);

    expect($internalTrade->fresh()->status)->toBe(TradeStatus::Matched->value)
        ->and($counterpartyTrade->fresh()->status)->toBe(TradeStatus::Matched->value);

    expect(TradeBreak::count())->toBe(0);
});

test('a trade with a counterparty outside tolerance produces a mismatch break and marks both trades break', function () {
    $internalFile = TradeFile::factory()->create(['desk_id' => $this->desk->id, 'source_side' => TradeFileSource::Internal->value]);
    $counterpartyFile = TradeFile::factory()->create(['desk_id' => $this->desk->id, 'source_side' => TradeFileSource::Counterparty->value]);

    $counterpartyTrade = Trade::factory()->create([
        'trade_file_id' => $counterpartyFile->id,
        'external_trade_id' => 'TRD-2',
        'notional_amount' => 100000,
        'trade_date' => '2026-07-20',
        'settlement_date' => '2026-07-22',
        'status' => TradeStatus::Unmatched->value,
    ]);

    $internalTrade = Trade::factory()->create([
        'trade_file_id' => $internalFile->id,
        'external_trade_id' => 'TRD-2',
        'notional_amount' => 999999, // mismatched notional
        'trade_date' => '2026-07-20',
        'settlement_date' => '2026-07-22',
        'status' => TradeStatus::Unmatched->value,
    ]);

    (new MatchingEngine())->runForFile($internalFile->fresh());

    expect(TradeBreak::count())->toBe(1);
    $break = TradeBreak::first();
    expect($break->break_type)->toBe(BreakType::Mismatch->value)
        ->and($break->trade_id)->toBe($internalTrade->id);

    expect($internalTrade->fresh()->status)->toBe(TradeStatus::Break->value)
        ->and($counterpartyTrade->fresh()->status)->toBe(TradeStatus::Break->value);

    expect(Match::count())->toBe(0);
});

test('a trade with no counterparty produces an unmatched break', function () {
    $internalFile = TradeFile::factory()->create(['desk_id' => $this->desk->id, 'source_side' => TradeFileSource::Internal->value]);

    $internalTrade = Trade::factory()->create([
        'trade_file_id' => $internalFile->id,
        'external_trade_id' => 'TRD-3',
        'status' => TradeStatus::Unmatched->value,
    ]);

    (new MatchingEngine())->runForFile($internalFile->fresh());

    expect(TradeBreak::count())->toBe(1);
    $break = TradeBreak::first();
    expect($break->break_type)->toBe(BreakType::Unmatched->value)
        ->and($break->trade_id)->toBe($internalTrade->id);

    expect($internalTrade->fresh()->status)->toBe(TradeStatus::Break->value);
});

test('matching only pairs trades within the same desk', function () {
    $otherDesk = Desk::factory()->create();
    $internalFile = TradeFile::factory()->create(['desk_id' => $this->desk->id, 'source_side' => TradeFileSource::Internal->value]);
    $counterpartyFileOtherDesk = TradeFile::factory()->create(['desk_id' => $otherDesk->id, 'source_side' => TradeFileSource::Counterparty->value]);

    Trade::factory()->create([
        'trade_file_id' => $counterpartyFileOtherDesk->id,
        'external_trade_id' => 'TRD-4',
        'status' => TradeStatus::Unmatched->value,
    ]);

    $internalTrade = Trade::factory()->create([
        'trade_file_id' => $internalFile->id,
        'external_trade_id' => 'TRD-4',
        'status' => TradeStatus::Unmatched->value,
    ]);

    (new MatchingEngine())->runForFile($internalFile->fresh());

    expect(TradeBreak::count())->toBe(1)
        ->and(TradeBreak::first()->break_type)->toBe(BreakType::Unmatched->value)
        ->and($internalTrade->fresh()->status)->toBe(TradeStatus::Break->value);
});
```

- [ ] **Step 6: Run the test to verify it fails**

Run: `php artisan test --filter=MatchingEngineTest`
Expected: FAIL — class does not exist.

- [ ] **Step 7: Write `BreakDetected`**

```php
// app/Domains/Reconciliation/Events/BreakDetected.php
<?php

namespace App\Domains\Reconciliation\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BreakDetected
{
    use Dispatchable, SerializesModels;

    public function __construct(public int $tradeBreakId) {}
}
```

- [ ] **Step 8: Write `MatchingEngine`**

```php
// app/Domains/Reconciliation/Services/MatchingEngine.php
<?php

namespace App\Domains\Reconciliation\Services;

use App\Domains\Reconciliation\Enums\BreakType;
use App\Domains\Reconciliation\Enums\MatchType;
use App\Domains\Reconciliation\Enums\TradeBreakStatus;
use App\Domains\Reconciliation\Events\BreakDetected;
use App\Domains\Reconciliation\Models\Match;
use App\Domains\Reconciliation\Models\MatchingRule;
use App\Domains\Reconciliation\Models\TradeBreak;
use App\Domains\Reconciliation\Support\BreakSeverityCalculator;
use App\Domains\Trades\Enums\TradeFileSource;
use App\Domains\Trades\Enums\TradeStatus;
use App\Domains\Trades\Models\Trade;
use App\Domains\Trades\Models\TradeFile;

class MatchingEngine
{
    public function __construct(private ?BreakSeverityCalculator $severityCalculator = null)
    {
        $this->severityCalculator ??= new BreakSeverityCalculator();
    }

    public function runForFile(TradeFile $file): void
    {
        $rule = MatchingRule::query()
            ->where('applies_to', 'trade')
            ->where('is_active', true)
            ->orderByDesc('priority')
            ->firstOrFail();

        $trades = Trade::query()
            ->where('trade_file_id', $file->id)
            ->where('status', TradeStatus::Unmatched->value)
            ->get();

        foreach ($trades as $trade) {
            $this->matchTrade($trade, $file, $rule->rule_definition);
        }
    }

    private function matchTrade(Trade $trade, TradeFile $file, array $rule): void
    {
        $oppositeSide = $file->source_side === TradeFileSource::Internal->value
            ? TradeFileSource::Counterparty->value
            : TradeFileSource::Internal->value;

        $matchKey = $rule['match_key'];

        $counterpart = Trade::query()
            ->where('status', TradeStatus::Unmatched->value)
            ->where($matchKey, $trade->{$matchKey})
            ->whereHas('tradeFile', function ($query) use ($file, $oppositeSide) {
                $query->where('desk_id', $file->desk_id)->where('source_side', $oppositeSide);
            })
            ->first();

        if (! $counterpart) {
            $this->recordBreak($trade, BreakType::Unmatched);

            return;
        }

        if ($this->withinTolerance($trade, $counterpart, $rule['tolerance'])) {
            $this->recordMatch($trade, $counterpart, $file);

            return;
        }

        $this->recordBreak($trade, BreakType::Mismatch);
        $this->recordBreak($counterpart, BreakType::Mismatch);
    }

    private function withinTolerance(Trade $trade, Trade $counterpart, array $tolerance): bool
    {
        $notionalDiff = abs($trade->notional_amount - $counterpart->notional_amount);
        if ($notionalDiff > $tolerance['notional_amount']['minor_units']) {
            return false;
        }

        if (($tolerance['trade_date']['exact'] ?? false) && ! $trade->trade_date->equalTo($counterpart->trade_date)) {
            return false;
        }

        if (($tolerance['settlement_date']['exact'] ?? false) && ! $trade->settlement_date->equalTo($counterpart->settlement_date)) {
            return false;
        }

        return true;
    }

    private function recordMatch(Trade $trade, Trade $counterpart, TradeFile $file): void
    {
        $internalTrade = $file->source_side === TradeFileSource::Internal->value ? $trade : $counterpart;
        $counterpartyTrade = $file->source_side === TradeFileSource::Internal->value ? $counterpart : $trade;

        Match::create([
            'trade_id' => $internalTrade->id,
            'matched_trade_id' => $counterpartyTrade->id,
            'match_type' => MatchType::Auto->value,
            'matched_at' => now(),
        ]);

        $trade->update(['status' => TradeStatus::Matched->value]);
        $counterpart->update(['status' => TradeStatus::Matched->value]);
    }

    private function recordBreak(Trade $trade, BreakType $type): void
    {
        $severity = $this->severityCalculator->calculate($trade);
        $detectedAt = now();

        $break = TradeBreak::create([
            'trade_id' => $trade->id,
            'break_type' => $type->value,
            'severity' => $severity->value,
            'status' => TradeBreakStatus::Open->value,
            'detected_at' => $detectedAt,
            'sla_due_at' => $this->severityCalculator->slaDueAt($severity, $detectedAt),
        ]);

        $trade->update(['status' => TradeStatus::Break->value]);

        BreakDetected::dispatch($break->id);
    }
}
```

- [ ] **Step 9: Run the test to verify it passes**

Run: `php artisan test --filter=MatchingEngineTest`
Expected: PASS

- [ ] **Step 10: Wire the queued listener**

First check which file registers listeners in this Laravel 12 app — Laravel 12's default skeleton has no `app/Providers/EventServiceProvider.php`; event-to-listener auto-discovery is on by default, OR listeners self-register via `#[AsEventListener]` attribute-style. Run `php artisan event:list` to see current behavior before adding files. If auto-discovery is active (default), skip creating a provider mapping and instead let the listener register itself:

```php
// app/Domains/Reconciliation/Listeners/RunMatchingEngine.php
<?php

namespace App\Domains\Reconciliation\Listeners;

use App\Domains\Reconciliation\Services\MatchingEngine;
use App\Domains\Trades\Events\TradeImported;
use App\Domains\Trades\Models\TradeFile;
use Illuminate\Contracts\Queue\ShouldQueue;

class RunMatchingEngine implements ShouldQueue
{
    public string $queue = 'matching';

    public function handle(TradeImported $event): void
    {
        $file = TradeFile::findOrFail($event->tradeFileId);

        (new MatchingEngine())->runForFile($file);
    }
}
```

Laravel 12's event auto-discovery finds `handle(TradeImported $event)` automatically since the listener lives under `app/` and type-hints the event — verify with `php artisan event:list` that `App\Domains\Trades\Events\TradeImported` now shows `App\Domains\Reconciliation\Listeners\RunMatchingEngine` as a listener. If it does NOT appear, auto-discovery is off in this app (check `bootstrap/app.php` for `->withEvents(discover: false)` or similar) — in that case, register the mapping explicitly in `app/Providers/AppServiceProvider.php`:

```php
// app/Providers/AppServiceProvider.php (only if auto-discovery is off — verify first)
use App\Domains\Reconciliation\Listeners\RunMatchingEngine;
use App\Domains\Trades\Events\TradeImported;
use Illuminate\Support\Facades\Event;

// inside boot():
Event::listen(TradeImported::class, RunMatchingEngine::class);
```

- [ ] **Step 11: Run the full suite to confirm no regressions**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 12: Commit**

```bash
git add app/Domains/Reconciliation/Events app/Domains/Reconciliation/Support app/Domains/Reconciliation/Services app/Domains/Reconciliation/Listeners app/Providers/AppServiceProvider.php tests/Unit/Reconciliation/BreakSeverityCalculatorTest.php tests/Unit/Reconciliation/MatchingEngineTest.php
git commit -m "feat: add matching engine, break severity calculator, and BreakDetected wiring"
```

---

## Task 6: End-to-end pipeline integration test

**Files:**
- Test: `tests/Feature/Trades/TradeImportPipelineTest.php`

**Interfaces:**
- Consumes: everything from Tasks 1-5 — `ImportTradeFileAction` (Task 4), `GeneratesTradeFixtures` (Task 4), `MatchingEngine`/`TradeBreak`/`Match` (Tasks 2, 5). No new production code — this task is pure verification that the pieces work together as a whole, end to end, via real queued execution (relying on `QUEUE_CONNECTION=sync`, per Global Constraints).

- [ ] **Step 1: Write the full pipeline test**

```php
// tests/Feature/Trades/TradeImportPipelineTest.php
<?php

use App\Domains\Administration\Enums\CmopRole;
use App\Domains\Administration\Models\Desk;
use App\Domains\Reconciliation\Enums\BreakType;
use App\Domains\Reconciliation\Models\Match;
use App\Domains\Reconciliation\Models\TradeBreak;
use App\Domains\Trades\Enums\TradeFileSource;
use App\Domains\Trades\Enums\TradeStatus;
use App\Domains\Trades\Models\Trade;
use App\Models\User;
use Tests\Support\GeneratesTradeFixtures;

uses(GeneratesTradeFixtures::class);

beforeEach(function () {
    $this->desk = Desk::factory()->create();
    $this->analyst = User::factory()->create(['desk_id' => $this->desk->id]);
    $this->analyst->assignRole(CmopRole::Analyst->value);
});

function uploadTradeFile(string $sourceSide, array $rows): void
{
    test()->actingAs(test()->analyst)->post('/trades/files', [
        'desk_id' => test()->desk->id,
        'source_side' => $sourceSide,
        'file' => test()->makeXlsxUpload($rows),
    ])->assertRedirect();
}

test('a clean matching pair across two files results in a Match and no break', function () {
    uploadTradeFile(TradeFileSource::Counterparty->value, [[
        'external_trade_id' => 'TRD-E2E-1',
        'instrument' => 'USD/JPY',
        'counterparty' => 'Acme Bank',
        'trade_date' => '2026-07-20',
        'settlement_date' => '2026-07-22',
        'notional_amount' => '250000.00',
        'notional_currency' => 'USD',
        'side' => 'sell',
    ]]);

    uploadTradeFile(TradeFileSource::Internal->value, [[
        'external_trade_id' => 'TRD-E2E-1',
        'instrument' => 'USD/JPY',
        'counterparty' => 'Acme Bank',
        'trade_date' => '2026-07-20',
        'settlement_date' => '2026-07-22',
        'notional_amount' => '250000.00',
        'notional_currency' => 'USD',
        'side' => 'buy',
    ]]);

    expect(Match::count())->toBe(1)
        ->and(TradeBreak::count())->toBe(0)
        ->and(Trade::where('status', TradeStatus::Matched->value)->count())->toBe(2);
});

test('a notional mismatch across two files results in a mismatch break', function () {
    uploadTradeFile(TradeFileSource::Counterparty->value, [[
        'external_trade_id' => 'TRD-E2E-2',
        'instrument' => 'EUR/USD',
        'counterparty' => 'Beta Corp',
        'trade_date' => '2026-07-20',
        'settlement_date' => '2026-07-22',
        'notional_amount' => '75000.00',
        'notional_currency' => 'USD',
        'side' => 'buy',
    ]]);

    uploadTradeFile(TradeFileSource::Internal->value, [[
        'external_trade_id' => 'TRD-E2E-2',
        'instrument' => 'EUR/USD',
        'counterparty' => 'Beta Corp',
        'trade_date' => '2026-07-20',
        'settlement_date' => '2026-07-22',
        'notional_amount' => '75500.00', // $500 off
        'notional_currency' => 'USD',
        'side' => 'sell',
    ]]);

    expect(TradeBreak::count())->toBe(1);
    $break = TradeBreak::first();
    expect($break->break_type)->toBe(BreakType::Mismatch->value)
        ->and($break->reference)->toMatch('/^TBIP-\d{4}-\d{6}$/')
        ->and(Match::count())->toBe(0);
});

test('an internal trade with no counterparty file results in an unmatched break', function () {
    uploadTradeFile(TradeFileSource::Internal->value, [[
        'external_trade_id' => 'TRD-E2E-3',
        'instrument' => 'GBP/USD',
        'counterparty' => 'Gamma LLC',
        'trade_date' => '2026-07-20',
        'settlement_date' => '2026-07-22',
        'notional_amount' => '9500.00',
        'notional_currency' => 'USD',
        'side' => 'buy',
    ]]);

    expect(TradeBreak::count())->toBe(1);
    $break = TradeBreak::first();
    expect($break->break_type)->toBe(BreakType::Unmatched->value)
        ->and($break->severity)->toBe(\App\Domains\Reconciliation\Enums\BreakSeverity::Low->value);
});
```

- [ ] **Step 2: Run the test**

Run: `php artisan test --filter=TradeImportPipelineTest`
Expected: PASS — if any case fails, debug via the individual unit/feature tests from Tasks 1-5 first (this task adds no new production code, so a failure here means an integration gap between already-tested pieces, most likely in queue wiring from Task 5 Step 10).

- [ ] **Step 3: Run the full suite to confirm no regressions**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/Trades/TradeImportPipelineTest.php
git commit -m "test: add end-to-end trade import and matching pipeline coverage"
```

---

## Task 7: Upload and trade file list UI

**Files:**
- Create: `resources/js/Pages/Trades/Upload.vue`
- Create: `resources/js/Pages/Trades/Index.vue`
- Create: `app/Http/Controllers/Trades/TradeFileController.php` (modify — add `create`/`index` methods to the controller from Task 4)
- Modify: `routes/web.php`
- Modify: `resources/js/Components/NavBar.vue`
- Test: `tests/Feature/Trades/TradeFileIndexTest.php`

**Interfaces:**
- Consumes: `TradeFile`/`TradeFilePolicy` (Tasks 1, 3), existing `AppLayout.vue`/`NavBar.vue` (Milestone 1).
- Produces: `GET /trades` (index page, desk-scoped list), `GET /trades/upload` (upload form page). No new interfaces later tasks depend on — this is the milestone's final, UI-facing task.

- [ ] **Step 1: Write the failing feature test**

```php
// tests/Feature/Trades/TradeFileIndexTest.php
<?php

use App\Domains\Administration\Enums\CmopRole;
use App\Domains\Administration\Models\Desk;
use App\Domains\Trades\Models\TradeFile;
use App\Models\User;

test('a guest is redirected to login when visiting the trade files index', function () {
    $this->get('/trades')->assertRedirect('/login');
});

test('a user sees only trade files from their own desk on the index page', function () {
    $ownDesk = Desk::factory()->create();
    $otherDesk = Desk::factory()->create();
    $analyst = User::factory()->create(['desk_id' => $ownDesk->id]);
    $analyst->assignRole(CmopRole::Analyst->value);

    $ownFile = TradeFile::factory()->create(['desk_id' => $ownDesk->id]);
    TradeFile::factory()->create(['desk_id' => $otherDesk->id]);

    $response = $this->actingAs($analyst)->get('/trades');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Trades/Index')
        ->has('tradeFiles', 1)
        ->where('tradeFiles.0.id', $ownFile->id)
    );
});

test('an ops_manager sees trade files across all desks on the index page', function () {
    $deskA = Desk::factory()->create();
    $deskB = Desk::factory()->create();
    $opsManager = User::factory()->create();
    $opsManager->assignRole(CmopRole::OpsManager->value);

    TradeFile::factory()->create(['desk_id' => $deskA->id]);
    TradeFile::factory()->create(['desk_id' => $deskB->id]);

    $response = $this->actingAs($opsManager)->get('/trades');

    $response->assertInertia(fn ($page) => $page->has('tradeFiles', 2));
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=TradeFileIndexTest`
Expected: FAIL — route does not exist.

- [ ] **Step 3: Add `index`/`create` methods to the controller**

```php
// app/Http/Controllers/Trades/TradeFileController.php (modify — add these methods alongside the existing store())
use App\Domains\Administration\Enums\CmopRole;
use App\Domains\Trades\Models\TradeFile;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

public function index(Request $request): Response
{
    $user = $request->user();

    $query = TradeFile::query()->with('desk')->latest();

    if (! $user->hasRole(CmopRole::OpsManager->value) && ! $user->hasRole(CmopRole::Compliance->value)) {
        $query->where('desk_id', $user->desk_id);
    }

    return Inertia::render('Trades/Index', [
        'tradeFiles' => $query->get()->map(fn (TradeFile $file) => [
            'id' => $file->id,
            'filename' => $file->filename,
            'source_side' => $file->source_side,
            'status' => $file->status,
            'desk_name' => $file->desk->name,
            'row_count' => $file->row_count,
            'error_count' => $file->error_count,
            'imported_at' => $file->imported_at?->toDateTimeString(),
        ]),
    ]);
}

public function create(): Response
{
    return Inertia::render('Trades/Upload');
}
```

- [ ] **Step 4: Register the routes**

```php
// routes/web.php (add, alongside the existing POST /trades/files route)
Route::get('/trades', [TradeFileController::class, 'index'])->middleware('auth')->name('trades.index');
Route::get('/trades/upload', [TradeFileController::class, 'create'])->middleware('auth')->name('trades.upload');
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --filter=TradeFileIndexTest`
Expected: PASS

- [ ] **Step 6: Build the Vue pages**

```vue
<!-- resources/js/Pages/Trades/Upload.vue -->
<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { useForm } from '@inertiajs/vue3';

const form = useForm({
  source_side: 'internal',
  file: null,
});

function submit() {
  form.post('/trades/files', {
    forceFormData: true,
  });
}
</script>

<template>
  <AppLayout>
    <h1 class="text-2xl font-semibold text-gray-900">Upload Trade File</h1>

    <form class="mt-6 max-w-md space-y-4" @submit.prevent="submit">
      <div>
        <label class="block text-sm text-gray-700" for="source_side">Source</label>
        <select id="source_side" v-model="form.source_side" class="mt-1 w-full rounded border px-3 py-2">
          <option value="internal">Internal</option>
          <option value="counterparty">Counterparty</option>
        </select>
      </div>

      <div>
        <label class="block text-sm text-gray-700" for="file">File (.xlsx)</label>
        <input
          id="file"
          type="file"
          accept=".xlsx"
          class="mt-1 w-full"
          @change="form.file = $event.target.files[0]"
        />
        <p v-if="form.errors.file" class="mt-1 text-sm text-red-600">{{ form.errors.file }}</p>
      </div>

      <button type="submit" class="rounded bg-gray-900 px-4 py-2 text-white" :disabled="form.processing">
        Upload
      </button>
    </form>
  </AppLayout>
</template>
```

```vue
<!-- resources/js/Pages/Trades/Index.vue -->
<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link } from '@inertiajs/vue3';

defineProps({
  tradeFiles: {
    type: Array,
    required: true,
  },
});
</script>

<template>
  <AppLayout>
    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-semibold text-gray-900">Trade Files</h1>
      <Link href="/trades/upload" class="rounded bg-gray-900 px-4 py-2 text-white">Upload File</Link>
    </div>

    <table class="mt-6 w-full text-left text-sm">
      <thead>
        <tr class="border-b text-gray-500">
          <th class="py-2">Filename</th>
          <th class="py-2">Desk</th>
          <th class="py-2">Source</th>
          <th class="py-2">Status</th>
          <th class="py-2">Rows</th>
          <th class="py-2">Errors</th>
          <th class="py-2">Imported</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="file in tradeFiles" :key="file.id" class="border-b">
          <td class="py-2">{{ file.filename }}</td>
          <td class="py-2">{{ file.desk_name }}</td>
          <td class="py-2">{{ file.source_side }}</td>
          <td class="py-2">{{ file.status }}</td>
          <td class="py-2">{{ file.row_count }}</td>
          <td class="py-2">{{ file.error_count }}</td>
          <td class="py-2">{{ file.imported_at ?? '—' }}</td>
        </tr>
      </tbody>
    </table>
  </AppLayout>
</template>
```

- [ ] **Step 7: Add a nav link**

```vue
<!-- resources/js/Components/NavBar.vue (modify — add inside the <nav>, before the user info block) -->
<Link href="/trades" class="text-sm text-gray-600 hover:underline">Trades</Link>
```

(Add the corresponding `import { Link } from '@inertiajs/vue3';` if not already imported in this file — check the existing Milestone 1 `NavBar.vue` first, since it may already import `router` from the same package.)

- [ ] **Step 8: Build the frontend and run the full suite**

Run: `npm run build`
Run: `php artisan test`
Expected: frontend builds without error; full suite passes.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/Trades/TradeFileController.php routes/web.php resources/js/Pages/Trades resources/js/Components/NavBar.vue tests/Feature/Trades/TradeFileIndexTest.php
git commit -m "feat: add trade file upload and index UI"
```

---

## Self-Review Notes

- **Spec coverage**: Decision 1 (XLSX) ✅ Task 4. Decision 2 (two-sided, `source_side`) ✅ Task 1, 5. Decision 3 (match criteria) ✅ Task 5. Decision 4 (single-pass, no waiting window) ✅ Task 5's `MatchingEngine::matchTrade` — no pending state, immediate break on no-counterpart. Decision 5 (data-driven rule) ✅ Task 2 (schema + seed), Task 5 (interprets `rule_definition` JSON at runtime, not hardcoded). Decision 6 (hardcoded severity/SLA thresholds, calendar time) ✅ Task 5's `BreakSeverityCalculator`. Decision 7 (stop at `BreakDetected`, no consumer) ✅ Task 5 — `BreakDetected` is dispatched, no listener registered for it anywhere in this plan. Decision 8 (`trade_files.source_side` not `trades.side`) ✅ Task 1's migrations — `trades.side` migration is unchanged buy/sell semantics, `trade_files.source_side` is the new column. Policies/desk-scoping (spec's "Policies & Desk Scoping" section) ✅ Task 3, with real `view()` scoping logic (not just a role check), addressing the Milestone 1 parked finding. Testing strategy (fixtures, integration test, unit tests) ✅ Tasks 4, 5, 6. UI scope (upload + list page) ✅ Task 7.
- **Explicitly out of scope items from the spec** (payments, case auto-open, rule-builder UI, business-day SLA, waiting-window matching, manual match override UI) — none of these have a task in this plan, correctly.
- **Type/signature consistency check**: `MatchingEngine::runForFile(TradeFile $file): void` signature is identical between Task 5's definition and Task 6's usage. `ImportTradeFileAction::execute()` parameter order/types match between Task 4's definition and its only caller (`TradeFileController::store()`, same task). `TradeStatus::Break` (not `TradeStatus::Broken` or similar) is used consistently in Task 1's enum, Task 5's `MatchingEngine`, and Task 6's assertions. `GeneratesTradeFixtures::makeXlsxUpload()` signature matches across Task 4 (definition + first use) and Task 6 (reuse). `TradeFilePolicy::view()`/`create()` signatures match between Task 3's definition and Task 7's controller usage (`$user->hasRole(...)` checks mirrored, not re-derived differently).
- **Reference format consistency**: `TBIP-{year}-{6-digit id}` is defined once in Task 2's `TradeBreak::booted()` and asserted with the same regex (`/^TBIP-\d{4}-\d{6}$/`) in both Task 2's and Task 6's tests.
