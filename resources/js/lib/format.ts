import type { Money, Quality, Size } from '@/types';

const SCALE = 9;
const DECIMAL = /^(-)?(\d+)(?:\.(\d{1,9}))?$/;
const ONE_CENT_NANOS = 10_000_000n;

/** Parse a decimal string ("0.046125000") into integer nanodollars, never via a float. */
function toNanos(amount: Money): bigint {
    const match = DECIMAL.exec(amount);

    if (match === null) {
        throw new TypeError(`Not a money decimal string: "${amount}"`);
    }

    const [, sign, whole, fraction = ''] = match;
    const nanos = BigInt(whole + fraction.padEnd(SCALE, '0'));

    return sign === '-' ? -nanos : nanos;
}

/** Round non-negative nanodollars half up to `decimals` places and print them. */
function toFixed(nanos: bigint, decimals: number): string {
    const unit = 10n ** BigInt(SCALE - decimals);
    const rounded = (nanos + unit / 2n) / unit;
    const digits = rounded.toString().padStart(decimals + 1, '0');

    return `${digits.slice(0, -decimals)}.${digits.slice(-decimals)}`;
}

/**
 * Display a money decimal string the way App\Support\Money::format() does:
 * "$0.0461" (4 dp, half up), "$0.000575" (6 dp) when non-zero and below one
 * cent, "-$0.2500" for negatives. For display only; never store the result.
 */
export function formatMoney(amount: Money): string {
    const nanos = toNanos(amount);
    const absolute = nanos < 0n ? -nanos : nanos;
    const decimals = absolute !== 0n && absolute < ONE_CENT_NANOS ? 6 : 4;

    return `${nanos < 0n ? '-' : ''}$${toFixed(absolute, decimals)}`;
}

/** Token counts with thousands separators: 10411 → "10,411". */
export function formatTokens(tokens: number): string {
    return new Intl.NumberFormat('en-US').format(tokens);
}

/** "1536x1024" → "1536 × 1024". */
export function formatSize(size: Size): string {
    return size.replace('x', ' × ');
}

/** "high" → "High". */
export function formatQuality(quality: Quality): string {
    return quality.charAt(0).toUpperCase() + quality.slice(1);
}
