import type { Money } from '@/types';

const NANOS = 1_000_000_000n;
const CENT = 10_000_000n;

/** A decimal Money string ("-0.381887500") as signed nanodollars, without floats. */
export function toNanos(amount: Money): bigint {
    const match = /^(-)?(\d+)(?:\.(\d{1,9}))?$/.exec(amount);

    if (!match) {
        throw new Error(`Not a Money string: ${amount}`);
    }

    const nanos =
        BigInt(match[2]) * NANOS + BigInt((match[3] ?? '').padEnd(9, '0'));

    return match[1] ? -nanos : nanos;
}

/**
 * Display a Money string as dollars, like App\Support\Money::format():
 * 4 decimals, or 6 when non-zero and below one cent; half-up; "-$0.2500".
 * Display only: never parse the result back.
 */
export function formatCredit(amount: Money): string {
    const nanos = toNanos(amount);
    const negative = nanos < 0n;
    const absolute = negative ? -nanos : nanos;
    const decimals = absolute !== 0n && absolute < CENT ? 6 : 4;
    const unit = 10n ** BigInt(9 - decimals);
    const rounded = (absolute + unit / 2n) / unit;
    const scale = 10n ** BigInt(decimals);
    const whole = rounded / scale;
    const fraction = (rounded % scale).toString().padStart(decimals, '0');

    return `${negative ? '-' : ''}$${whole}.${fraction}`;
}
