<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { InertiaLinkProps } from '@inertiajs/vue3';
import ExpiredImage from '@/components/generations/ExpiredImage.vue';
import StatusBadge from '@/components/generations/StatusBadge.vue';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import {
    formatMoney,
    formatQuality,
    formatSize,
} from '@/lib/format';
import type { GenerationSummary } from '@/types';

defineProps<{
    generation: GenerationSummary;
    href: NonNullable<InertiaLinkProps['href']>;
}>();
</script>

<template>
    <article
        class="flex flex-col overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border"
    >
        <Link :href="href" class="relative block aspect-square bg-muted">
            <img
                v-if="generation.image_url"
                :src="generation.image_url"
                :alt="generation.prompt"
                loading="lazy"
                class="size-full object-cover"
            />
            <ExpiredImage v-else-if="generation.image_expired" />
            <PlaceholderPattern v-else />
        </Link>

        <div class="flex flex-1 flex-col gap-3 p-4">
            <div class="flex items-center justify-between gap-2">
                <StatusBadge
                    :status="generation.status"
                    :label="generation.status_label"
                />
                <span class="text-xs text-muted-foreground">
                    {{ formatSize(generation.size) }} ·
                    {{ formatQuality(generation.quality) }}
                </span>
            </div>

            <p class="line-clamp-3 text-sm" :title="generation.prompt">
                {{ generation.prompt }}
            </p>

            <p
                v-if="generation.error"
                data-test="card-error"
                class="text-sm text-destructive"
            >
                {{ generation.error.message }}
            </p>

            <dl class="mt-auto grid grid-cols-2 gap-2 text-xs tabular-nums">
                <div data-test="card-estimate">
                    <dt class="text-muted-foreground">Estimated</dt>
                    <dd>
                        {{ formatMoney(generation.estimate.min) }} –
                        {{ formatMoney(generation.estimate.max) }}
                    </dd>
                </div>
                <div data-test="card-charged">
                    <dt class="text-muted-foreground">Actual</dt>
                    <dd>
                        {{
                            generation.charged === null
                                ? '—'
                                : formatMoney(generation.charged)
                        }}
                    </dd>
                </div>
            </dl>
        </div>
    </article>
</template>
