import { router } from '@inertiajs/vue3';
import { computed, getCurrentScope, onScopeDispose, watch } from 'vue';
import type { ComputedRef } from 'vue';

type Pollable = { is_final: boolean };

export type GenerationPollingOptions = {
    /** The props to reload, e.g. ['generations'] on the history page. */
    only: string[];
    intervalMs?: number;
};

/**
 * While any generation is not final, reload the given Inertia props on an
 * interval (`router.reload({ only })`). Stops on its own once every generation
 * is final, starts again if a non-final one appears, and never stacks a reload
 * on top of one still in flight.
 */
export function useGenerationPolling(
    items: () => Pollable[],
    { only, intervalMs = 3000 }: GenerationPollingOptions,
): { active: ComputedRef<boolean> } {
    const active = computed(() => items().some((item) => !item.is_final));

    let timer: ReturnType<typeof setInterval> | undefined;
    let inFlight = false;

    function tick(): void {
        if (inFlight) {
            return;
        }

        inFlight = true;
        router.reload({
            only,
            // Don't cancel a navigation the user starts meanwhile.
            async: true,
            onFinish: () => {
                inFlight = false;
            },
        });
    }

    function start(): void {
        timer ??= setInterval(tick, intervalMs);
    }

    function stop(): void {
        clearInterval(timer);
        timer = undefined;
    }

    watch(active, (on) => (on ? start() : stop()), { immediate: true });

    if (getCurrentScope()) {
        onScopeDispose(stop);
    }

    return { active };
}
