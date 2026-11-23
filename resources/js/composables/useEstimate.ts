import { computed, getCurrentScope, onScopeDispose, ref, watch } from 'vue';
import type { Ref } from 'vue';
import type { EstimateRequest, EstimateResponse } from '@/types';

/** URI of the `estimate` route (docs/CONTRACTS.md §7). */
const ESTIMATE_URL = '/estimate';

/** A 422 from POST /estimate: Laravel's `errors` bag. */
export class EstimateValidationError extends Error {
    constructor(public readonly errors: Record<string, string[]>) {
        super('The estimate request is invalid.');
        this.name = 'EstimateValidationError';
    }
}

export type EstimateFetcher = (
    body: EstimateRequest,
    signal: AbortSignal,
) => Promise<EstimateResponse>;

function xsrfToken(): string | null {
    const cookie = document.cookie
        .split('; ')
        .find((part) => part.startsWith('XSRF-TOKEN='));

    return cookie
        ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length))
        : null;
}

/** POST /estimate as JSON with Laravel's XSRF cookie. */
export const postEstimate: EstimateFetcher = async (body, signal) => {
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };
    const token = xsrfToken();

    if (token !== null) {
        headers['X-XSRF-TOKEN'] = token;
    }

    const response = await fetch(ESTIMATE_URL, {
        method: 'POST',
        headers,
        body: JSON.stringify(body),
        credentials: 'same-origin',
        signal,
    });

    if (response.status === 422) {
        const payload = (await response.json()) as {
            errors?: Record<string, string[]>;
        };

        throw new EstimateValidationError(payload.errors ?? {});
    }

    if (!response.ok) {
        throw new Error(`Estimate failed with HTTP ${response.status}.`);
    }

    return (await response.json()) as EstimateResponse;
};

export type UseEstimateOptions = {
    debounceMs?: number;
    request?: EstimateFetcher;
};

export type UseEstimate = {
    result: Ref<EstimateResponse | null>;
    loading: Ref<boolean>;
    error: Ref<string | null>;
};

function normalise(form: EstimateRequest): EstimateRequest {
    return {
        prompt: form.prompt,
        size: form.size,
        quality: form.quality,
        reference_images: form.reference_images
            .map((url) => url.trim())
            .filter((url) => url !== ''),
    };
}

/**
 * A live, debounced estimate for the create form. Re-estimates whenever the
 * prompt, size, quality or reference images change; only the latest answer
 * wins and the stale request is aborted.
 */
export function useEstimate(
    form: () => EstimateRequest,
    { debounceMs = 400, request = postEstimate }: UseEstimateOptions = {},
): UseEstimate {
    const result = ref<EstimateResponse | null>(null);
    const loading = ref(false);
    const error = ref<string | null>(null);

    let timer: ReturnType<typeof setTimeout> | undefined;
    let controller: AbortController | undefined;
    let latest = 0;

    function cancel(): void {
        clearTimeout(timer);
        timer = undefined;
        controller?.abort();
        controller = undefined;
    }

    async function run(body: EstimateRequest): Promise<void> {
        const id = ++latest;
        controller = new AbortController();

        try {
            const response = await request(body, controller.signal);

            if (id === latest) {
                result.value = response;
                error.value = null;
            }
        } catch (failure) {
            if (id !== latest) {
                return;
            }

            result.value = null;
            error.value =
                failure instanceof EstimateValidationError
                    ? (Object.values(failure.errors).flat()[0] ??
                      'The form is invalid.')
                    : 'Could not estimate the cost. Try again.';
        } finally {
            if (id === latest) {
                loading.value = false;
            }
        }
    }

    // Compare by value, so re-renders that change nothing don't re-estimate.
    const key = computed(() => JSON.stringify(normalise(form())));

    watch(
        key,
        (value) => {
            cancel();
            latest++;

            const body = JSON.parse(value) as EstimateRequest;

            if (body.prompt.trim() === '') {
                result.value = null;
                error.value = null;
                loading.value = false;

                return;
            }

            loading.value = true;
            timer = setTimeout(() => void run(body), debounceMs);
        },
        { immediate: true },
    );

    if (getCurrentScope()) {
        onScopeDispose(cancel);
    }

    return { result, loading, error };
}
