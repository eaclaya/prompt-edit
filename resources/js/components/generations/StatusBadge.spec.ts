import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vite-plus/test';
import type { GenerationStatus } from '@/types';
import StatusBadge from './StatusBadge.vue';

const cases: [GenerationStatus, string, string][] = [
    ['pending', 'Queued', 'queued'],
    ['queued', 'Queued', 'queued'],
    ['processing', 'Processing', 'processing'],
    ['succeeded', 'Succeeded', 'succeeded'],
    ['failed', 'Failed', 'failed'],
];

describe('StatusBadge', () => {
    it.each(cases)(
        'shows %s as "%s" with the %s tone',
        (status, label, tone) => {
            const badge = mount(StatusBadge, {
                props: { status, label },
            });

            expect(badge.text()).toBe(label);
            expect(badge.attributes('data-tone')).toBe(tone);
        },
    );

    it('falls back to a label derived from the status', () => {
        expect(
            mount(StatusBadge, { props: { status: 'pending' } }).text(),
        ).toBe('Queued');
        expect(
            mount(StatusBadge, { props: { status: 'processing' } }).text(),
        ).toBe('Processing');
    });

    it('spins only while the generation is in flight', () => {
        const spinning = (status: GenerationStatus) =>
            mount(StatusBadge, { props: { status } })
                .find('[data-test="status-spinner"]')
                .exists();

        expect(spinning('pending')).toBe(true);
        expect(spinning('queued')).toBe(true);
        expect(spinning('processing')).toBe(true);
        expect(spinning('succeeded')).toBe(false);
        expect(spinning('failed')).toBe(false);
    });

    it('uses the destructive style for failures only', () => {
        expect(
            mount(StatusBadge, { props: { status: 'failed' } }).classes(),
        ).toContain('bg-destructive');
        expect(
            mount(StatusBadge, { props: { status: 'succeeded' } }).classes(),
        ).not.toContain('bg-destructive');
    });
});
