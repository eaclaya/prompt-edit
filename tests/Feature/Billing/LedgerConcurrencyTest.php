<?php

use App\Contracts\Ledger;
use App\Enums\LedgerEntryType;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/*
 * Real concurrency: two separate PHP processes race on rows committed through a
 * second connection (outside RefreshDatabase's transaction). The test holds the
 * user's row lock while both workers start, waits until both are blocked on it in
 * Postgres, then lets them go at once.
 *
 * The settle race is safe on two levels: the user row lock serialises the workers,
 * and even without it the partial unique index on (generation_id, type) makes the
 * loser's transaction roll back whole. The hold race needs the lock.
 */

const CONCURRENT = 'pgsql_concurrency';

beforeEach(function () {
    config([
        'database.connections.'.CONCURRENT => config('database.connections.pgsql'),
        'database.connections.'.CONCURRENT.'_probe' => config('database.connections.pgsql'),
    ]);
});

// Committed rows survive RefreshDatabase, so remove them by hand.
afterEach(function () {
    $db = DB::connection(CONCURRENT);
    $users = committedUsers();
    $db->table('credit_transactions')->whereIn('user_id', $users)->delete();
    $db->table('generations')->whereIn('user_id', $users)->delete();
    $db->table('users')->whereIn('id', $users)->delete();
    committedUsers(reset: true);
    DB::purge(CONCURRENT);
    DB::purge(CONCURRENT.'_probe');
});

/** @return list<int> ids of users committed by this test */
function committedUsers(?int $add = null, bool $reset = false): array
{
    static $ids = [];

    if ($reset) {
        $ids = [];
    }
    if ($add !== null) {
        $ids[] = $add;
    }

    return $ids;
}

/** Run $callback with CONCURRENT as the default connection, so everything it writes is committed. */
function committed(Closure $callback): mixed
{
    $default = DB::getDefaultConnection();
    DB::setDefaultConnection(CONCURRENT);

    try {
        return $callback();
    } finally {
        DB::setDefaultConnection($default);
    }
}

/** @return array{0: User, 1: Generation} */
function committedUserWithGeneration(string $balance): array
{
    return committed(function () use ($balance) {
        $user = User::factory()->create();
        app(Ledger::class)->purchase($user, Money::of($balance), 'seed-'.$user->id);
        $generation = Generation::factory()->for($user)->create();
        committedUsers(add: $user->id);

        return [$user, $generation];
    });
}

/**
 * Start the workers while holding $user's row lock, wait until all of them are
 * waiting on a lock, release it, and return their exit codes.
 *
 * @param  list<list<string>>  $jobs  worker arguments
 * @return list<int>
 */
function race(User $user, array $jobs): array
{
    $db = config('database.connections.pgsql');
    $env = array_merge(getenv(), [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => $db['host'],
        'DB_PORT' => (string) $db['port'],
        'DB_DATABASE' => $db['database'],
        'DB_USERNAME' => $db['username'],
        'DB_PASSWORD' => (string) $db['password'],
    ]);

    $gate = DB::connection(CONCURRENT);
    $gate->beginTransaction();
    $gate->table('users')->where('id', $user->id)->lockForUpdate()->first();

    $processes = [];
    foreach ($jobs as $args) {
        $command = array_merge([PHP_BINARY, __DIR__.'/Concurrency/worker.php'], $args);
        $processes[] = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
        $output[] = $pipes;
    }

    $deadline = microtime(true) + 15;
    do {
        usleep(50_000);
        $waiting = (int) DB::connection(CONCURRENT.'_probe')->table('pg_stat_activity')
            ->where('datname', $db['database'])->where('wait_event_type', 'Lock')->count();
    } while ($waiting < count($jobs) && microtime(true) < $deadline);

    $gate->commit();

    expect($waiting)->toBe(count($jobs), 'Every worker should have been blocked on the user row lock.');

    $codes = [];
    foreach ($processes as $i => $process) {
        $stderr = stream_get_contents($output[$i][2]).stream_get_contents($output[$i][1]);
        $codes[] = $code = proc_close($process);
        expect($code)->toBeIn([0, 3], "Worker failed: {$stderr}");
    }

    return $codes;
}

it('records one charge when two processes settle the same generation at once', function () {
    [$user, $generation] = committedUserWithGeneration('1.00');
    committed(fn () => app(Ledger::class)->hold($generation, Money::of('0.40')));

    $codes = race($user, [
        ['settle', $generation->id, '0.30'],
        ['settle', $generation->id, '0.30'],
    ]);

    expect($codes)->toBe([0, 0]);
    committed(function () use ($user, $generation) {
        expect(CreditTransaction::where('generation_id', $generation->id)->orderBy('id')->pluck('type')->all())
            ->toBe([LedgerEntryType::Hold, LedgerEntryType::HoldRelease, LedgerEntryType::Charge])
            ->and($user)->toHaveBalance('0.70')
            ->and(app(Ledger::class)->available($user))->toEqualMoney('0.70');
    });
});

it('lets only one of two concurrent holds through when both do not fit', function () {
    [$user, $first] = committedUserWithGeneration('1.00');
    $second = committed(fn () => Generation::factory()->for($user)->create());

    $codes = race($user, [
        ['hold', $first->id, '0.60'],
        ['hold', $second->id, '0.60'],
    ]);

    sort($codes);
    expect($codes)->toBe([0, 3]);
    committed(function () use ($user) {
        expect(CreditTransaction::where('user_id', $user->id)->where('type', LedgerEntryType::Hold)->count())->toBe(1)
            ->and(app(Ledger::class)->available($user))->toEqualMoney('0.40')
            ->and($user)->toHaveBalance('1.00');
    });
});
