<?php

use Ayangzy\RealSeed\Tests\Fixtures\SaasSchema;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-06-15 12:00:00');
    SaasSchema::create();
    $this->setEnvironment('local');
});

function seedSaas(array $options = []): int
{
    return Artisan::call('real:seed', ['--seed' => 42, '--size' => 'small', '--no-interaction' => true, ...$options]);
}

function scalar(string $sql): int
{
    return (int) DB::scalar($sql);
}

function dumpTables(): array
{
    return collect(['organizations', 'users', 'projects', 'project_user', 'tasks', 'comments', 'tags', 'taggables'])
        ->mapWithKeys(fn ($table) => [$table => DB::table($table)->get()->map(function ($row) {
            unset($row->password); // bcrypt salts differ per run by design
            return (array) $row;
        })->all()])
        ->all();
}

it('generates the planned rows', function () {
    expect(seedSaas())->toBe(0);

    // Artisan::output() drains the buffer, so read it once.
    expect(Artisan::output())->toContain('RealSeed Complete')->toContain('✓ Temporal consistency')
        ->and(DB::table('organizations')->count())->toBe(3)
        ->and(DB::table('users')->count())->toBe(9)
        ->and(DB::table('tags')->count())->toBe(5)
        ->and(DB::table('tasks')->count())->toBeGreaterThan(0)
        ->and(DB::table('comments')->count())->toBeGreaterThan(0);
});

it('keeps related records inside the same organization', function (int $seed) {
    seedSaas(['--seed' => $seed]);

    // Task assignees belong to the organization of the task's project.
    expect(scalar('select count(*) from tasks t join projects p on p.id = t.project_id join users u on u.id = t.assignee_id where u.organization_id != p.organization_id'))->toBe(0)
        // Project members belong to the project's organization.
        ->and(scalar('select count(*) from project_user pu join projects p on p.id = pu.project_id join users u on u.id = pu.user_id where u.organization_id != p.organization_id'))->toBe(0)
        // Comments are written by users of the organization that owns the commented record.
        ->and(scalar("select count(*) from comments c join users u on u.id = c.user_id join tasks t on c.commentable_type like '%Task' and t.id = c.commentable_id join projects p on p.id = t.project_id where p.organization_id != u.organization_id"))->toBe(0)
        ->and(scalar("select count(*) from comments c join users u on u.id = c.user_id join projects p on c.commentable_type like '%Project' and p.id = c.commentable_id where p.organization_id != u.organization_id"))->toBe(0)
        // Managers are earlier colleagues in the same organization.
        ->and(scalar('select count(*) from users u join users m on m.id = u.manager_id where m.organization_id != u.organization_id or m.id >= u.id'))->toBe(0)
        // Cycle back-fill: an organization is owned by one of its own members.
        ->and(scalar('select count(*) from organizations o join users u on u.id = o.owner_id where u.organization_id != o.id'))->toBe(0)
        ->and(scalar('select count(*) from organizations where owner_id is null'))->toBe(0)
        // Coverage: no organization is left without members.
        ->and(scalar('select count(*) from organizations o where not exists (select 1 from users u where u.organization_id = o.id)'))->toBe(0);
})->with([1, 42, 1234, 99999]);

it('keeps timestamps coherent', function () {
    seedSaas();

    foreach (['organizations', 'users', 'projects', 'tasks', 'comments'] as $table) {
        expect(scalar("select count(*) from {$table} where created_at > updated_at"))->toBe(0, $table);
    }

    expect(scalar('select count(*) from projects p join organizations o on o.id = p.organization_id where p.created_at < o.created_at'))->toBe(0)
        ->and(scalar('select count(*) from tasks t join projects p on p.id = t.project_id where t.created_at < p.created_at'))->toBe(0)
        ->and(scalar("select count(*) from comments c join tasks t on c.commentable_type like '%Task' and t.id = c.commentable_id where c.created_at < t.created_at"))->toBe(0)
        ->and(scalar('select count(*) from tasks where completed_at is not null and completed_at < created_at'))->toBe(0)
        ->and(scalar("select count(*) from tasks where completed_at is not null and status != 'done'"))->toBe(0)
        ->and(scalar("select count(*) from tasks where status = 'done' and completed_at is not null"))->toBeGreaterThan(0)
        ->and(scalar("select count(*) from users where created_at < '2025-12-15' or updated_at > '2026-06-15 12:00:00'"))->toBe(0);
});

it('generates valid, realistic field values', function () {
    seedSaas();

    $users = DB::table('users')->get();

    expect($users->pluck('status')->unique()->diff(['active', 'invited', 'suspended']))->toBeEmpty()
        ->and($users->pluck('email')->unique())->toHaveCount($users->count())
        ->and($users->every(fn ($u) => preg_match('/@example\.(com|org|net)$/', $u->email)))->toBeTrue()
        ->and(password_verify('password', $users->first()->password))->toBeTrue()
        ->and(DB::table('tasks')->pluck('status')->unique()->diff(['todo', 'in_progress', 'done', 'cancelled']))->toBeEmpty()
        ->and(DB::table('organizations')->pluck('slug')->unique())->toHaveCount(3);
});

it('is reproducible for the same seed and differs for another', function () {
    seedSaas();
    $first = dumpTables();

    Schema::dropAllTables();
    SaasSchema::create();
    seedSaas();

    expect(dumpTables())->toBe($first);

    Schema::dropAllTables();
    SaasSchema::create();
    seedSaas(['--seed' => 43]);

    expect(dumpTables())->not->toBe($first);
});

it('makes no changes on a dry run', function () {
    Artisan::call('real:seed', ['--dry-run' => true, '--size' => 'small']);

    expect(Artisan::output())->toContain('Generation Plan')->toContain('No database changes were made.')
        ->and(DB::table('users')->count())->toBe(0);
});

it('scales to an approximate total with --count', function () {
    seedSaas(['--count' => 200]);

    $total = collect(['organizations', 'users', 'projects', 'project_user', 'tasks', 'comments', 'tags', 'taggables'])
        ->sum(fn ($table) => DB::table($table)->count());

    expect($total)->toBeGreaterThan(150)->toBeLessThan(250);
});

it('generates only the minimum dependencies with --only', function () {
    seedSaas(['--only' => 'tasks']);

    expect(DB::table('tasks')->count())->toBeGreaterThan(0)
        ->and(DB::table('projects')->count())->toBeGreaterThan(0)
        ->and(DB::table('organizations')->count())->toBeGreaterThan(0)
        ->and(DB::table('comments')->count())->toBe(0)
        ->and(DB::table('tags')->count())->toBe(0);

    $projects = DB::table('projects')->count();
    $users = DB::table('users')->count();

    // Dependencies that already have rows are reused, not regenerated.
    seedSaas(['--only' => 'tasks', '--seed' => 7]);

    expect(DB::table('projects')->count())->toBe($projects)
        ->and(DB::table('users')->count())->toBe($users);
});

it('refuses to skip a required table that has no rows', function () {
    expect(seedSaas(['--except' => 'users']))->toBe(1)
        ->and(Artisan::output())->toContain('Cannot skip [users]')
        ->and(DB::table('organizations')->count())->toBe(0);
});

it('rejects unknown tables and conflicting options', function () {
    expect(seedSaas(['--only' => 'nope']))->toBe(1)
        ->and(Artisan::output())->toContain('Unknown table [nope]');

    expect(seedSaas(['--only' => 'tasks', '--except' => 'users']))->toBe(1)
        ->and(Artisan::output())->toContain('either --only or --except');
});

it('adds to existing data without key collisions', function () {
    seedSaas();
    seedSaas(['--seed' => 99]);

    expect(DB::table('organizations')->count())->toBe(6)
        ->and(DB::table('users')->max('id'))->toBe(18);
});

it('requires typed confirmation for --fresh and replaces existing rows', function () {
    seedSaas();

    $database = config('database.connections.testbench.database');
    $question = "This will permanently delete the rows listed above. Type the database name [{$database}] to continue";

    $this->artisan('real:seed', ['--seed' => 1, '--size' => 'small', '--fresh' => true])
        ->expectsOutputToContain('existing data in these tables will be removed')
        ->expectsQuestion($question, 'wrong')
        ->expectsOutputToContain('No database changes were made.')
        ->assertExitCode(1);

    expect(DB::table('organizations')->count())->toBe(3);

    $this->artisan('real:seed', ['--seed' => 1, '--size' => 'small', '--fresh' => true])
        ->expectsQuestion($question, $database)
        ->assertExitCode(0);

    expect(DB::table('organizations')->count())->toBe(3)
        ->and(DB::table('users')->count())->toBe(9);
});

it('refuses --fresh without an interactive confirmation', function () {
    seedSaas();

    expect(seedSaas(['--fresh' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('must be confirmed interactively')
        ->and(DB::table('organizations')->count())->toBe(3);
});

it('rolls back everything when generation fails', function () {
    // Comments are inserted last, so every other table has been written when this fires.
    DB::unprepared(match (DB::getDriverName()) {
        'mysql', 'mariadb' => "create trigger fail_comments before insert on comments for each row signal sqlstate '45000' set message_text = 'simulated failure'",
        'pgsql' => "create function fail_comments() returns trigger as \$\$ begin raise exception 'simulated failure'; end; \$\$ language plpgsql;
                    create trigger fail_comments before insert on comments for each row execute function fail_comments();",
        default => "create trigger fail_comments before insert on comments begin select raise(abort, 'simulated failure'); end",
    });

    expect(seedSaas())->toBe(1);

    expect(Artisan::output())->toContain('Generation failed')->toContain('simulated failure')->toContain('rolled back')
        ->and(DB::table('organizations')->count())->toBe(0)
        ->and(DB::table('users')->count())->toBe(0)
        ->and(DB::table('tasks')->count())->toBe(0);
});

it('gives exactly --count rows to the selected table, whatever the AI suggests', function (?int $aiCount) {
    app()->instance(\Ayangzy\RealSeed\AI\AIProviderInterface::class, new \Ayangzy\RealSeed\Tests\Fixtures\FakeAIProvider([
        'domain' => 'x', 'timeline_months' => null,
        'tables' => $aiCount === null ? [] : [['table' => 'tasks', 'count' => $aiCount, 'states' => null, 'fields' => []]],
    ]));

    seedSaas(['--only' => 'tasks', '--count' => 5]);

    expect(DB::table('tasks')->count())->toBe(5)
        // Parents added only for the tasks: never more than the tasks need.
        ->and(DB::table('projects')->count())->toBeLessThanOrEqual(5)
        ->and(DB::table('users')->count())->toBeLessThanOrEqual(5)
        ->and(DB::table('comments')->count())->toBe(0);
})->with([null, 1, 40, 900]);

it('splits --count across several selected tables', function () {
    seedSaas(['--only' => 'tasks,comments', '--count' => 60]);

    $selected = DB::table('tasks')->count() + DB::table('comments')->count();

    expect($selected)->toBeGreaterThanOrEqual(58)->toBeLessThanOrEqual(62);
});
