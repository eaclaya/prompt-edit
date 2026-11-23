import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';
import type { Mock } from 'vite-plus/test';
import { effectScope, nextTick, reactive } from 'vue';
import type { EstimateRequest, EstimateResponse } from '@/types';
import { EstimateValidationError, useEstimate } from './useEstimate';
import type { EstimateFetcher } from './useEstimate';

const response: EstimateResponse = {
    estimate: {
        min: '0.357781250',
        expected: '0.412562500',
        max: '0.467343750',
    },
    available: '1.000000000',
    can_afford: true,
};

function setup(
    request: Mock<EstimateFetcher> = vi.fn<EstimateFetcher>(
        async () => response,
    ),
) {
    const form = reactive<EstimateRequest>({
        prompt: '',
        size: '1024x1024',
        quality: 'medium',
        reference_images: [],
    });
    const scope = effectScope();
    const estimate = scope.run(() =>
        useEstimate(() => form, { debounceMs: 400, request }),
    )!;

    return { form, estimate, request, scope };
}

/** Let the debounce elapse and the request promise settle. */
async function settle(ms = 400) {
    await nextTick();
    await vi.advanceTimersByTimeAsync(ms);
}

describe('useEstimate', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('does not ask for an estimate without a prompt', async () => {
        const { estimate, request } = setup();

        await settle();

        expect(request).not.toHaveBeenCalled();
        expect(estimate.result.value).toBeNull();
    });

    it('posts the form once the user stops typing', async () => {
        const { form, estimate, request } = setup();

        form.prompt = 'A';
        await settle(200);
        form.prompt = 'A lighthouse';
        await settle(200);
        expect(request).not.toHaveBeenCalled();

        await settle(200);

        expect(request).toHaveBeenCalledTimes(1);
        expect(request).toHaveBeenCalledWith(
            {
                prompt: 'A lighthouse',
                size: '1024x1024',
                quality: 'medium',
                reference_images: [],
            },
            expect.any(AbortSignal),
        );
        expect(estimate.result.value).toEqual(response);
    });

    it('re-estimates when size, quality or reference images change', async () => {
        const { form, request } = setup();

        form.prompt = 'A lighthouse';
        await settle();
        form.quality = 'high';
        await settle();
        form.size = '1536x1024';
        await settle();
        form.reference_images.push('https://example.com/a.png');
        await settle();

        expect(request).toHaveBeenCalledTimes(4);
        expect(request.mock.lastCall?.[0]).toMatchObject({
            quality: 'high',
            size: '1536x1024',
            reference_images: ['https://example.com/a.png'],
        });
    });

    it('ignores blank reference image inputs', async () => {
        const { form, request } = setup();

        form.prompt = 'A lighthouse';
        form.reference_images = ['', ' https://example.com/a.png ', '  '];
        await settle();

        expect(request.mock.lastCall?.[0].reference_images).toEqual([
            'https://example.com/a.png',
        ]);
    });

    it('is loading between the change and the response', async () => {
        let resolve!: (value: EstimateResponse) => void;
        const request = vi.fn<EstimateFetcher>(
            () =>
                new Promise<EstimateResponse>((r) => {
                    resolve = r;
                }),
        );
        const { form, estimate } = setup(request);

        form.prompt = 'A lighthouse';
        await nextTick();
        expect(estimate.loading.value).toBe(true);

        await settle();
        expect(estimate.loading.value).toBe(true);

        resolve(response);
        await settle(0);
        expect(estimate.loading.value).toBe(false);
    });

    it('keeps only the latest answer and aborts the stale request', async () => {
        const pending: {
            resolve: (r: EstimateResponse) => void;
            signal: AbortSignal;
        }[] = [];
        const request = vi.fn<EstimateFetcher>(
            (_body, signal) =>
                new Promise<EstimateResponse>((resolve) => {
                    pending.push({ resolve, signal });
                }),
        );
        const { form, estimate } = setup(request);

        form.prompt = 'first';
        await settle();
        form.prompt = 'second';
        await settle();

        expect(pending[0].signal.aborted).toBe(true);

        const latest = {
            ...response,
            estimate: { ...response.estimate, expected: '0.5' },
        };
        pending[1].resolve(latest);
        pending[0].resolve(response);
        await settle(0);

        expect(estimate.result.value).toEqual(latest);
    });

    it('exposes the first validation error and clears the estimate', async () => {
        const request = vi.fn<EstimateFetcher>(async () => {
            throw new EstimateValidationError({
                prompt: ['The prompt may not be greater than 4000 characters.'],
            });
        });
        const { form, estimate } = setup(request);

        form.prompt = 'x';
        await settle();

        expect(estimate.error.value).toBe(
            'The prompt may not be greater than 4000 characters.',
        );
        expect(estimate.result.value).toBeNull();
        expect(estimate.loading.value).toBe(false);
    });

    it('reports a failed request without throwing', async () => {
        const request = vi.fn<EstimateFetcher>(async () => {
            throw new Error('Network down');
        });
        const { form, estimate } = setup(request);

        form.prompt = 'x';
        await settle();

        expect(estimate.error.value).toBe(
            'Could not estimate the cost. Try again.',
        );
    });

    it('clears the estimate when the prompt is emptied', async () => {
        const { form, estimate } = setup();

        form.prompt = 'A lighthouse';
        await settle();
        form.prompt = '   ';
        await settle();

        expect(estimate.result.value).toBeNull();
        expect(estimate.loading.value).toBe(false);
    });

    it('stops when its scope is disposed', async () => {
        const { form, request, scope } = setup();

        form.prompt = 'A lighthouse';
        await nextTick();
        scope.stop();
        await settle();

        expect(request).not.toHaveBeenCalled();
    });
});

describe('postEstimate', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
        document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
    });

    it('posts JSON to /estimate with the XSRF token', async () => {
        const { postEstimate } = await import('./useEstimate');
        document.cookie = 'XSRF-TOKEN=abc%3D';
        const fetch = vi.fn(
            async () => new Response(JSON.stringify(response), { status: 200 }),
        );
        vi.stubGlobal('fetch', fetch);
        const signal = new AbortController().signal;

        const result = await postEstimate(
            {
                prompt: 'A lighthouse',
                size: '1024x1024',
                quality: 'medium',
                reference_images: [],
            },
            signal,
        );

        expect(result).toEqual(response);
        expect(fetch).toHaveBeenCalledWith('/estimate', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': 'abc=',
            },
            body: JSON.stringify({
                prompt: 'A lighthouse',
                size: '1024x1024',
                quality: 'medium',
                reference_images: [],
            }),
            credentials: 'same-origin',
            signal,
        });
    });

    it('turns a 422 into a validation error', async () => {
        const { postEstimate, EstimateValidationError: ValidationError } =
            await import('./useEstimate');
        vi.stubGlobal(
            'fetch',
            vi.fn(
                async () =>
                    new Response(
                        JSON.stringify({
                            message: 'Invalid',
                            errors: { size: ['The selected size is invalid.'] },
                        }),
                        { status: 422 },
                    ),
            ),
        );

        const failure = postEstimate(
            {
                prompt: 'x',
                size: '1024x1024',
                quality: 'medium',
                reference_images: [],
            },
            new AbortController().signal,
        );

        await expect(failure).rejects.toBeInstanceOf(ValidationError);
        await expect(failure).rejects.toMatchObject({
            errors: { size: ['The selected size is invalid.'] },
        });
    });

    it('rejects on any other error status', async () => {
        const { postEstimate } = await import('./useEstimate');
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => new Response('', { status: 500 })),
        );

        await expect(
            postEstimate(
                {
                    prompt: 'x',
                    size: '1024x1024',
                    quality: 'medium',
                    reference_images: [],
                },
                new AbortController().signal,
            ),
        ).rejects.toThrow('500');
    });
});
