import { describe, expect, it } from 'vite-plus/test';
import { formatMoney, formatQuality, formatSize, formatTokens } from './format';

describe('formatMoney', () => {
    it('shows four decimals, rounding half up', () => {
        expect(formatMoney('0.046125000')).toBe('$0.0461');
        expect(formatMoney('12.345650000')).toBe('$12.3457');
        expect(formatMoney('0.381887500')).toBe('$0.3819');
        expect(formatMoney('1.000000000')).toBe('$1.0000');
    });

    it('shows six decimals for a non-zero amount below one cent', () => {
        expect(formatMoney('0.000575000')).toBe('$0.000575');
        expect(formatMoney('0.000000490')).toBe('$0.000000');
        expect(formatMoney('0.009999950')).toBe('$0.010000');
        expect(formatMoney('0.000060000')).toBe('$0.000060');
    });

    it('shows zero with four decimals', () => {
        expect(formatMoney('0.000000000')).toBe('$0.0000');
        expect(formatMoney('0')).toBe('$0.0000');
    });

    it('puts the sign before the dollar sign', () => {
        expect(formatMoney('-0.250000000')).toBe('-$0.2500');
        expect(formatMoney('-0.000575000')).toBe('-$0.000575');
    });

    it('accepts fewer than nine decimals and integers', () => {
        expect(formatMoney('5.00')).toBe('$5.0000');
        expect(formatMoney('25')).toBe('$25.0000');
    });

    it('stays exact beyond float precision', () => {
        expect(formatMoney('90071992547.409912345')).toBe('$90071992547.4099');
        expect(formatMoney('0.123450000')).toBe('$0.1235');
    });

    it('rejects anything that is not a decimal string', () => {
        expect(() => formatMoney('1e3')).toThrow();
        expect(() => formatMoney('$1')).toThrow();
        expect(() => formatMoney('0.0000000001')).toThrow();
        expect(() => formatMoney('')).toThrow();
    });
});

describe('formatTokens', () => {
    it('groups thousands', () => {
        expect(formatTokens(10411)).toBe('10,411');
        expect(formatTokens(12)).toBe('12');
    });
});

describe('formatSize and formatQuality', () => {
    it('shows dimensions with a multiplication sign', () => {
        expect(formatSize('1536x1024')).toBe('1536 × 1024');
    });

    it('capitalises the quality', () => {
        expect(formatQuality('high')).toBe('High');
    });
});
