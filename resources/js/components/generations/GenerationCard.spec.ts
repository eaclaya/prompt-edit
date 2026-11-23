import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vite-plus/test';
import type { GenerationSummary } from '@/types';
import GenerationCard from './GenerationCard.vue';

function summary(
    overrides: Partial<GenerationSummary> = {},
): GenerationSummary {
    return {
        id: '01KAB000000000000000000000',
        prompt: 'A lighthouse at dusk',
        size: '1536x1024',
        quality: 'high',
        reference_images: [],
        status: 'succeeded',
        status_label: 'Succeeded',
        is_final: true,
        estimate: {
            min: '0.347750000',
            expected: '0.402450000',
            max: '0.457150000',
        },
        charged: '0.381887500',
        image_url: 'https://pixelforge.test/images/a.png',
        image_expired: false,
        error: null,
        created_at: '2026-11-04T14:03:11.000000Z',
        completed_at: '2026-11-04T14:03:40.000000Z',
        ...overrides,
    };
}

const card = (generation: GenerationSummary) =>
    mount(GenerationCard, {
        props: { generation, href: `/generations/${generation.id}` },
    });

describe('GenerationCard', () => {
    it('shows the prompt, size, quality and status', () => {
        const wrapper = card(summary());

        expect(wrapper.text()).toContain('A lighthouse at dusk');
        expect(wrapper.text()).toContain('1536 × 1024');
        expect(wrapper.text()).toContain('High');
        expect(wrapper.find('[data-tone="succeeded"]').text()).toBe(
            'Succeeded',
        );
    });

    it('shows the estimated range next to the actual cost', () => {
        const wrapper = card(summary());

        expect(wrapper.find('[data-test="card-estimate"]').text()).toContain(
            '$0.3478 – $0.4572',
        );
        expect(wrapper.find('[data-test="card-charged"]').text()).toContain(
            '$0.3819',
        );
    });

    it('shows a dash for the actual cost until it is known', () => {
        const wrapper = card(
            summary({
                status: 'processing',
                status_label: 'Processing',
                is_final: false,
                charged: null,
                image_url: null,
            }),
        );

        expect(wrapper.find('[data-test="card-charged"]').text()).toContain(
            '—',
        );
    });

    it('shows the live image', () => {
        const img = card(summary()).find('img');

        expect(img.attributes('src')).toBe(
            'https://pixelforge.test/images/a.png',
        );
        expect(img.attributes('alt')).toBe('A lighthouse at dusk');
    });

    it('shows a placeholder once the image has expired', () => {
        const wrapper = card(summary({ image_url: null, image_expired: true }));

        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.find('[data-test="image-expired"]').text()).toContain(
            'expired',
        );
    });

    it('shows the error of a failed generation and its $0 charge', () => {
        const wrapper = card(
            summary({
                status: 'failed',
                status_label: 'Failed',
                charged: '0.000000000',
                image_url: null,
                error: {
                    code: 'content_policy_violation',
                    message: 'Blocked by the content policy.',
                },
            }),
        );

        expect(wrapper.find('[data-test="card-error"]').text()).toBe(
            'Blocked by the content policy.',
        );
        expect(wrapper.find('[data-test="card-charged"]').text()).toContain(
            '$0.0000',
        );
        expect(wrapper.find('img').exists()).toBe(false);
    });

    it('links to the detail page', () => {
        expect(card(summary()).find('a').attributes('href')).toBe(
            '/generations/01KAB000000000000000000000',
        );
    });
});
