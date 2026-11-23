<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { InertiaLinkProps } from '@inertiajs/vue3';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { formatMoney } from '@/lib/format';
import type { Money, PriceRange } from '@/types';

withDefaults(
    defineProps<{
        estimate?: PriceRange | null;
        available: Money;
        /** false when available < estimate.max; null while unknown. */
        canAfford?: boolean | null;
        loading?: boolean;
        error?: string | null;
        creditsHref: NonNullable<InertiaLinkProps['href']>;
    }>(),
    { estimate: null, canAfford: null, loading: false, error: null },
);
</script>

<template>
    <Card aria-live="polite">
        <CardHeader>
            <CardTitle class="flex items-center justify-between gap-2">
                Estimated cost
                <Spinner
                    v-if="loading"
                    data-test="estimate-loading"
                    class="size-4 text-muted-foreground"
                />
            </CardTitle>
            <CardDescription>
                Pixelforge bills the actual tokens used; you never pay more than
                the maximum.
            </CardDescription>
        </CardHeader>
        <CardContent class="space-y-3">
            <p
                v-if="error"
                data-test="estimate-error"
                class="text-sm text-destructive"
            >
                {{ error }}
            </p>
            <template v-else-if="estimate">
                <p
                    data-test="estimate-expected"
                    class="text-3xl font-semibold tabular-nums"
                >
                    ≈ {{ formatMoney(estimate.expected) }}
                </p>
                <p
                    data-test="estimate-range"
                    class="text-sm text-muted-foreground tabular-nums"
                >
                    between {{ formatMoney(estimate.min) }} and
                    {{ formatMoney(estimate.max) }}
                </p>
            </template>
            <p v-else class="text-sm text-muted-foreground">
                Type a prompt to see the estimated cost.
            </p>

            <p
                data-test="estimate-available"
                class="text-sm text-muted-foreground tabular-nums"
            >
                Available: {{ formatMoney(available) }}
            </p>

            <div
                v-if="estimate && canAfford === false"
                data-test="estimate-unaffordable"
                class="rounded-md border border-destructive/40 bg-destructive/10 p-3 text-sm"
            >
                You need up to {{ formatMoney(estimate.max) }} available to
                submit this.
                <Link
                    :href="creditsHref"
                    class="font-medium underline underline-offset-4"
                >
                    Buy credits
                </Link>
            </div>
        </CardContent>
    </Card>
</template>
