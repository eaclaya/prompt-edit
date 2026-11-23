import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vite-plus/test';
import EstimateCard from './EstimateCard.vue';

const estimate = {
    min: '0.357781250',
    expected: '0.412562500',
    max: '0.467343750',
};

const text = (selector: string, props: Record<string, unknown>) =>
    mount(EstimateCard, {
        props: { available: '1.000000000', creditsHref: '/credits', ...props },
    })
        .find(selector)
        .text();

describe('EstimateCard', () => {
    it('shows the expected price and the range, formatted', () => {
        const card = mount(EstimateCard, {
            props: {
                estimate,
                available: '1.000000000',
                creditsHref: '/credits',
            },
        });

        expect(card.find('[data-test="estimate-expected"]').text()).toBe(
            '≈ $0.4126',
        );
        expect(card.find('[data-test="estimate-range"]').text()).toBe(
            'between $0.3578 and $0.4673',
        );
    });

    it('uses six decimals for sub-cent prices', () => {
        expect(
            text('[data-test="estimate-range"]', {
                estimate: {
                    min: '0.000575000',
                    expected: '0.000600000',
                    max: '0.009000000',
                },
            }),
        ).toBe('between $0.000575 and $0.009000');
    });

    it('shows the available balance', () => {
        expect(
            text('[data-test="estimate-available"]', {
                estimate,
                available: '2.500000000',
            }),
        ).toContain('$2.5000');
    });

    it('asks for a prompt before there is an estimate', () => {
        const card = mount(EstimateCard, {
            props: { available: '1.000000000', creditsHref: '/credits' },
        });

        expect(card.find('[data-test="estimate-expected"]').exists()).toBe(
            false,
        );
        expect(card.text()).toContain('Type a prompt');
    });

    it('says it is updating while a new estimate loads', () => {
        const card = mount(EstimateCard, {
            props: {
                estimate,
                loading: true,
                available: '1.000000000',
                creditsHref: '/credits',
            },
        });

        expect(card.find('[data-test="estimate-loading"]').exists()).toBe(
            true,
        );
    });

    it('links to buy credits when the max is not affordable', () => {
        const card = mount(EstimateCard, {
            props: {
                estimate,
                canAfford: false,
                available: '0.200000000',
                creditsHref: '/credits',
            },
        });

        const warning = card.find('[data-test="estimate-unaffordable"]');
        expect(warning.text()).toContain('$0.4673');
        expect(warning.find('a').attributes('href')).toBe('/credits');
    });

    it('does not warn when the max is affordable', () => {
        const card = mount(EstimateCard, {
            props: {
                estimate,
                canAfford: true,
                available: '1.000000000',
                creditsHref: '/credits',
            },
        });

        expect(card.find('[data-test="estimate-unaffordable"]').exists()).toBe(
            false,
        );
    });

    it('shows an error when the estimate failed', () => {
        expect(
            text('[data-test="estimate-error"]', {
                error: 'The prompt field is required.',
            }),
        ).toBe('The prompt field is required.');
    });
});
