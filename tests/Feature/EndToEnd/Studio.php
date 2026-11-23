<?php

namespace Tests\Feature\EndToEnd;

use App\Enums\LedgerEntryType;
use App\Jobs\ProcessWebhookEvent;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Illuminate\Testing\TestResponse;
use SplObjectStorage;
use Tests\Fakes\PixelforgeFake;
use Tests\Fixtures\PixelforgeFixtures;

/**
 * End-to-end driver. Everything is the real app (routes, controllers, Ledger, pricing,
 * GenerationSync, jobs and their Redis funnel) except the three edges PLAN.md §6 allows:
 * Pixelforge HTTP (PixelforgeFake), the queue (Queue::fake(), drained on demand through the
 * real sync driver so job middleware and serialisation run), and the clock.
 */
final class Studio
{
    /** A Redis database of its own: the dev queue (0) and the funnel tests (15) are never touched. */
    private const int REDIS_DB = 14;

    public static PixelforgeFake $pixelforge;

    /** @var SplObjectStorage<object, null> jobs already run */
    private static SplObjectStorage $ran;

    /** Runs once, during the next POST /generations, before its response comes back. */
    private static ?Closure $duringCreate = null;

    public static function boot(string $now = '2026-11-01 10:00:00'): PixelforgeFake
    {
        test()->travelTo(CarbonImmutable::parse($now, 'UTC'));
        test()->withoutVite();

        config(['database.redis.default.database' => self::REDIS_DB]);
        Redis::flushdb();

        Queue::fake();
        self::$ran = new SplObjectStorage;
        self::$duringCreate = null;

        // Registered before PixelforgeFake so it runs first; it never answers the request itself.
        Http::fake(function (Request $request) {
            if ($request->method() === 'POST' && Str::endsWith($request->url(), '/generations') && self::$duringCreate) {
                $callback = self::$duringCreate;
                self::$duringCreate = null;
                $callback();
            }

            return null;
        });

        return self::$pixelforge = PixelforgeFake::fake()->returnsPricing();
    }

    public static function shutdown(): void
    {
        Redis::flushdb();
    }

    /** Sign up through Fortify (welcome credit and all), verify the email, and log in. */
    public static function register(string $name = 'Ada Lovelace'): User
    {
        $email = Str::slug($name).'@example.com';

        test()->post('/register', [
            'name' => $name,
            'email' => $email,
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ])->assertRedirect();

        self::work();

        $user = User::where('email', $email)->sole();
        $user->markEmailAsVerified();
        test()->actingAs($user);

        return $user;
    }

    /** @param  array<string, mixed>  $input */
    public static function submit(array $input = []): Generation
    {
        $before = Generation::pluck('id');

        test()->post('/generations', [
            'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            ...$input,
        ])->assertSessionHasNoErrors()->assertRedirect(route('generations.index'));

        return Generation::whereNotIn('id', $before)->sole();
    }

    /** Run what happens while Pixelforge is still answering the next POST /generations. */
    public static function duringSubmission(Closure $callback): void
    {
        self::$duringCreate = $callback;
    }

    /** Run every queued job not yet run (optionally of one class), and any they queue. */
    public static function work(?string $only = null): void
    {
        /** @var QueueFake $queue */
        $queue = Queue::getFacadeRoot();

        do {
            $pending = collect($queue->pushedJobs())
                ->only($only === null ? null : [$only])
                ->flatten(1)
                ->pluck('job')
                ->reject(fn (object $job) => self::$ran->contains($job));

            foreach ($pending as $job) {
                self::$ran->attach($job);
                $worker = new SyncQueue;
                $worker->setContainer(app());
                $worker->setConnectionName('sync')->push($job);
            }
        } while ($pending->isNotEmpty());
    }

    public static function submitJobs(): void
    {
        self::work(SubmitGeneration::class);
    }

    /** POST a signed delivery, then let the worker process it. */
    public static function deliver(array $event, int $attempt = 1): TestResponse
    {
        $response = postWebhook(signedWebhook($event, attempt: $attempt))->assertOk();
        self::work(ProcessWebhookEvent::class);

        return $response;
    }

    /**
     * A webhook event about $generation, as Pixelforge would send it: its gen_ id (or $providerId
     * when the POST response has not landed yet) and metadata.local_id echoed back.
     *
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>
     */
    public static function event(Generation $generation, string $type, array $object = [], ?string $providerId = null): array
    {
        $generation->refresh();
        $now = CarbonImmutable::now('UTC');

        return PixelforgeFixtures::event($type, [
            'id' => $providerId ?? $generation->provider_id,
            'prompt' => $generation->prompt,
            'size' => $generation->size->value,
            'quality' => $generation->quality->value,
            'reference_images' => $generation->reference_images,
            'metadata' => ['local_id' => $generation->id],
            'created_at' => $now->subSeconds(20)->toIso8601ZuluString(),
            'started_at' => $now->subSeconds(18)->toIso8601ZuluString(),
            'completed_at' => $now->toIso8601ZuluString(),
            'image_url_expires_at' => $now->addDay()->toIso8601ZuluString(),
            ...$object,
        ]);
    }

    /**
     * The cached balance equals the ledger sum, available = balance − open holds,
     * and nothing is held once every generation is final.
     */
    public static function assertBooksBalance(User $user): void
    {
        $user->refresh();
        $rows = $user->creditTransactions()->get();

        $owned = Money::sum($rows
            ->whereIn('type', [LedgerEntryType::Welcome, LedgerEntryType::Purchase, LedgerEntryType::Charge])
            ->pluck('amount_nanos')->all());
        $openHolds = Money::sum($rows
            ->whereIn('type', [LedgerEntryType::Hold, LedgerEntryType::HoldRelease])
            ->pluck('amount_nanos')->all());

        expect($user->balance_nanos)->toEqualMoney($owned)
            ->and($user->balance_nanos->isNegative())->toBeFalse();

        if ($user->generations()->get()->every(fn (Generation $g) => $g->status->isFinal())) {
            expect($openHolds)->toEqualMoney(0);
        }
    }
}
