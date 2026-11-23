<?php

namespace Tests\Fakes;

use App\Contracts\Exceptions\InsufficientCredits;
use App\Contracts\Ledger;
use App\Models\Generation;
use App\Models\User;
use App\Support\Money;
use PHPUnit\Framework\Assert;

/**
 * An in-memory ledger. Each user's balance starts from users.balance_nanos;
 * nothing is written to the database. Same idempotency rules as the contract.
 */
final class FakeLedger implements Ledger
{
    /** @var array<int, Money> user id => balance */
    private array $balances = [];

    /** @var array<string, array{user: int, amount: Money}> generation id => open hold */
    private array $holds = [];

    /** @var array<string, Money> generation id => every hold ever placed */
    public array $held = [];

    /** @var array<string, Money> generation id => charge */
    public array $settled = [];

    /** @var array<string, true> */
    public array $released = [];

    /** @var array<int, true> */
    private array $welcomed = [];

    /** @var array<string, true> */
    private array $tokens = [];

    public static function swap(): self
    {
        return app()->instance(Ledger::class, new self);
    }

    public function setBalance(User $user, Money $balance): self
    {
        $this->balances[$user->id] = $balance;

        return $this;
    }

    public function balance(User $user): Money
    {
        return $this->balances[$user->id] ??= $user->balance_nanos;
    }

    public function grantWelcome(User $user): void
    {
        if (isset($this->welcomed[$user->id])) {
            return;
        }

        $this->welcomed[$user->id] = true;
        $this->balances[$user->id] = $this->balance($user)->add(Money::of((string) config('pixelforge.welcome_credit')));
    }

    public function purchase(User $user, Money $amount, string $token): void
    {
        if (isset($this->tokens[$token])) {
            return;
        }

        $this->tokens[$token] = true;
        $this->balances[$user->id] = $this->balance($user)->add($amount);
    }

    public function hold(Generation $generation, Money $amount): void
    {
        if (isset($this->held[$generation->id])) {
            return;
        }

        $user = $this->owner($generation);
        $available = $this->available($user);

        if ($available->isLessThan($amount)) {
            throw new InsufficientCredits($amount, $available);
        }

        $this->holds[$generation->id] = ['user' => $user->id, 'amount' => $amount];
        $this->held[$generation->id] = $amount;
    }

    public function settle(Generation $generation, Money $charge): void
    {
        if (isset($this->settled[$generation->id]) || isset($this->released[$generation->id])) {
            return;
        }

        $user = $this->owner($generation);
        unset($this->holds[$generation->id]);
        $this->settled[$generation->id] = $charge;
        $this->balances[$user->id] = $this->balance($user)->subtract($charge);
    }

    public function release(Generation $generation): void
    {
        if (isset($this->settled[$generation->id]) || isset($this->released[$generation->id])) {
            return;
        }

        unset($this->holds[$generation->id]);
        $this->released[$generation->id] = true;
    }

    public function available(User $user): Money
    {
        $open = array_filter($this->holds, fn (array $hold) => $hold['user'] === $user->id);

        return $this->balance($user)->subtract(Money::sum(array_column($open, 'amount')));
    }

    public function assertHeld(Generation $generation, Money|string|null $amount = null): void
    {
        Assert::assertArrayHasKey($generation->id, $this->held, "No hold was placed for generation [{$generation->id}].");

        if ($amount !== null) {
            $expected = $amount instanceof Money ? $amount : Money::of($amount);
            Assert::assertSame($expected->toDecimal(), $this->held[$generation->id]->toDecimal(), 'The hold amount differs.');
        }
    }

    public function assertNothingHeld(): void
    {
        Assert::assertSame([], $this->held, 'Holds were placed.');
    }

    public function assertSettled(Generation $generation, Money|string|null $charge = null): void
    {
        Assert::assertArrayHasKey($generation->id, $this->settled, "Generation [{$generation->id}] was not settled.");

        if ($charge !== null) {
            $expected = $charge instanceof Money ? $charge : Money::of($charge);
            Assert::assertSame($expected->toDecimal(), $this->settled[$generation->id]->toDecimal(), 'The charge differs.');
        }
    }

    public function assertNotSettled(Generation $generation): void
    {
        Assert::assertArrayNotHasKey($generation->id, $this->settled, "Generation [{$generation->id}] was settled.");
    }

    public function assertReleased(Generation $generation): void
    {
        Assert::assertArrayHasKey($generation->id, $this->released, "The hold of generation [{$generation->id}] was not released.");
    }

    public function assertNotReleased(Generation $generation): void
    {
        Assert::assertArrayNotHasKey($generation->id, $this->released, "The hold of generation [{$generation->id}] was released.");
    }

    private function owner(Generation $generation): User
    {
        return $generation->user ?? throw new \LogicException("Generation [{$generation->id}] has no user.");
    }
}
