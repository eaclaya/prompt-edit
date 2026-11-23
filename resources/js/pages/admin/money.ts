import type { Money } from '@/types';

const NANOS_PER_DOLLAR = 1_000_000_000n;

/** A nine-decimal Money string as signed nanodollars, without going through a float. */
function toNanos(amount: Money): bigint {
    const negative = amount.startsWith('-');
    const [whole, fraction = ''] = amount.replace(/^-/, '').split('.');
    const nanos =
        BigInt(whole || '0') * NANOS_PER_DOLLAR +
        BigInt(fraction.padEnd(9, '0').slice(0, 9));

    return negative ? -nanos : nanos;
}

/**
 * Display only, like App\Support\Money::format(): "$0.3819", "$0.000575" below a cent,
 * "-$0.2500"; rounded half-up. The stored value is never rounded.
 */
export function formatMoney(amount: Money): string {
    const nanos = toNanos(amount);
    const abs = nanos < 0n ? -nanos : nanos;
    const decimals = abs !== 0n && abs < 10_000_000n ? 6 : 4;
    const scale = 10n ** BigInt(9 - decimals);
    const rounded = (abs + scale / 2n) / scale;
    const unit = 10n ** BigInt(decimals);
    const fraction = (rounded % unit).toString().padStart(decimals, '0');

    return `${nanos < 0n ? '-' : ''}$${rounded / unit}.${fraction}`;
}

/** The exact amount with an explicit sign, for reconciliation differences. */
export function formatDifference(amount: Money): string {
    const nanos = toNanos(amount);

    return nanos > 0n
        ? `+$${amount}`
        : nanos < 0n
          ? `-$${amount.slice(1)}`
          : '$0';
}
