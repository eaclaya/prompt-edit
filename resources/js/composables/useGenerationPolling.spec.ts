import { mount } from '@vue/test-utils';
import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';
import { defineComponent, h, nextTick, ref } from 'vue';
import type { Ref } from 'vue';
import { useGenerationPolling } from './useGenerationPolling';

const reload = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/vue3', () => ({ router: { reload } }));

type Item = { is_final: boolean };

function mountPolling(items: Ref<Item[]>, only = ['generations']) {
    let polling!: ReturnType<typeof useGenerationPolling>;

    const wrapper = mount(
        defineComponent({
            setup() {
                polling = useGenerationPolling(() => items.value, {
                    only,
                    intervalMs: 3000,
                });

                return () => h('div');
            },
        }),
    );

    return { wrapper, polling: () => polling };
}

describe('useGenerationPolling', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        reload.mockReset();
        // Inertia calls onFinish when the partial reload completes.
        reload.mockImplementation((options: { onFinish?: () => void }) =>
            options.onFinish?.(),
        );
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('reloads only the given props while any generation is not final', () => {
        const items = ref<Item[]>([{ is_final: true }, { is_final: false }]);
        mountPolling(items);

        vi.advanceTimersByTime(3000);
        expect(reload).toHaveBeenCalledTimes(1);
        expect(reload).toHaveBeenCalledWith(
            expect.objectContaining({ only: ['generations'] }),
        );

        vi.advanceTimersByTime(6000);
        expect(reload).toHaveBeenCalledTimes(3);
    });

    it('never polls when every generation is already final', () => {
        const items = ref<Item[]>([{ is_final: true }, { is_final: true }]);
        const { polling } = mountPolling(items);

        vi.advanceTimersByTime(30_000);

        expect(reload).not.toHaveBeenCalled();
        expect(polling().active.value).toBe(false);
    });

    it('never polls an empty history', () => {
        mountPolling(ref<Item[]>([]));

        vi.advanceTimersByTime(30_000);

        expect(reload).not.toHaveBeenCalled();
    });

    it('stops once every generation has become final', async () => {
        const items = ref<Item[]>([{ is_final: false }]);
        const { polling } = mountPolling(items);

        vi.advanceTimersByTime(3000);
        expect(reload).toHaveBeenCalledTimes(1);

        items.value = [{ is_final: true }];
        await nextTick();
        vi.advanceTimersByTime(30_000);

        expect(reload).toHaveBeenCalledTimes(1);
        expect(polling().active.value).toBe(false);
    });

    it('starts again when a non-final generation appears', async () => {
        const items = ref<Item[]>([{ is_final: true }]);
        mountPolling(items);

        items.value = [{ is_final: false }, { is_final: true }];
        await nextTick();
        vi.advanceTimersByTime(3000);

        expect(reload).toHaveBeenCalledTimes(1);
    });

    it('does not stack reloads while one is still in flight', () => {
        reload.mockImplementation(() => undefined); // never finishes
        mountPolling(ref<Item[]>([{ is_final: false }]));

        vi.advanceTimersByTime(9000);

        expect(reload).toHaveBeenCalledTimes(1);
    });

    it('stops polling when the component unmounts', () => {
        const { wrapper } = mountPolling(ref<Item[]>([{ is_final: false }]));

        wrapper.unmount();
        vi.advanceTimersByTime(30_000);

        expect(reload).not.toHaveBeenCalled();
    });

    it('can poll a different prop, such as a single generation', () => {
        mountPolling(ref<Item[]>([{ is_final: false }]), ['generation']);

        vi.advanceTimersByTime(3000);

        expect(reload).toHaveBeenCalledWith(
            expect.objectContaining({ only: ['generation'] }),
        );
    });
});
