<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { computed } from 'vue';
import ExpiredImage from '@/components/generations/ExpiredImage.vue';
import StatusBadge from '@/components/generations/StatusBadge.vue';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import { Button } from '@/components/ui/button';
import { useGenerationPolling } from '@/composables/useGenerationPolling';
import {
    formatMoney,
    formatQuality,
    formatSize,
    formatTokens,
} from '@/lib/format';
import { index } from '@/routes/generations';
import type { GenerationsShowProps, TokenType } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'History', href: index() }],
    },
});

const props = defineProps<GenerationsShowProps>();

useGenerationPolling(() => [props.generation], { only: ['generation'] });

const tokenLabels: Record<TokenType, string> = {
    input_text_tokens: 'Text input',
    input_image_tokens: 'Image input',
    output_tokens: 'Output',
    reasoning_tokens: 'Reasoning',
};

const markupPercent = computed(() =>
    props.generation.breakdown
        ? `${props.generation.breakdown.markup_bps / 100}%`
        : null,
);

function formatTime(timestamp: string | null): string {
    return timestamp === null ? '—' : new Date(timestamp).toLocaleString();
}
</script>

<template>
    <Head title="Generation" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div>
            <Button as-child variant="ghost" size="sm">
                <Link :href="index()">
                    <ArrowLeft />
                    History
                </Link>
            </Button>
        </div>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
            <div
                class="relative aspect-square overflow-hidden rounded-xl border border-sidebar-border/70 bg-muted dark:border-sidebar-border"
            >
                <img
                    v-if="generation.image_url"
                    :src="generation.image_url"
                    :alt="generation.prompt"
                    class="size-full object-contain"
                />
                <ExpiredImage v-else-if="generation.image_expired" />
                <PlaceholderPattern v-else />
            </div>

            <div class="flex flex-col gap-6">
                <section class="space-y-3">
                    <div class="flex items-center gap-2">
                        <StatusBadge
                            :status="generation.status"
                            :label="generation.status_label"
                        />
                        <span class="text-sm text-muted-foreground">
                            {{ formatSize(generation.size) }} ·
                            {{ formatQuality(generation.quality) }}
                        </span>
                    </div>
                    <p class="whitespace-pre-wrap">{{ generation.prompt }}</p>
                    <p
                        v-if="generation.error"
                        class="rounded-md border border-destructive/40 bg-destructive/10 p-3 text-sm"
                    >
                        <span class="font-medium">Failed:</span>
                        {{ generation.error.message }}
                        <span class="text-muted-foreground">
                            ({{ generation.error.code }}). You were not charged.
                        </span>
                    </p>
                    <p
                        v-if="generation.image_url_expires_at"
                        class="text-xs text-muted-foreground"
                    >
                        {{ generation.image_expired ? 'Expired' : 'Expires' }}
                        {{ formatTime(generation.image_url_expires_at) }}
                    </p>
                </section>

                <section v-if="generation.reference_images.length">
                    <h2 class="mb-2 text-sm font-medium">Reference images</h2>
                    <ul class="space-y-1 text-sm break-all">
                        <li
                            v-for="url in generation.reference_images"
                            :key="url"
                        >
                            <a
                                :href="url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="underline underline-offset-4"
                                >{{ url }}</a
                            >
                        </li>
                    </ul>
                </section>

                <section>
                    <h2 class="mb-2 text-sm font-medium">Cost</h2>
                    <dl class="grid grid-cols-2 gap-2 text-sm tabular-nums">
                        <dt class="text-muted-foreground">Estimated</dt>
                        <dd>
                            ≈ {{ formatMoney(generation.estimate.expected) }}
                            <span class="text-muted-foreground">
                                ({{ formatMoney(generation.estimate.min) }} –
                                {{ formatMoney(generation.estimate.max) }})
                            </span>
                        </dd>
                        <dt class="text-muted-foreground">Actual</dt>
                        <dd>
                            {{
                                generation.charged === null
                                    ? '—'
                                    : formatMoney(generation.charged)
                            }}
                        </dd>
                    </dl>
                </section>

                <section v-if="generation.breakdown">
                    <h2 class="mb-2 text-sm font-medium">Token usage</h2>
                    <table class="w-full text-sm tabular-nums">
                        <thead class="text-left text-muted-foreground">
                            <tr>
                                <th class="py-1 font-normal">Type</th>
                                <th class="py-1 text-right font-normal">
                                    Tokens
                                </th>
                                <th class="py-1 text-right font-normal">
                                    Rate / 1M
                                </th>
                                <th class="py-1 text-right font-normal">
                                    Cost
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="line in generation.breakdown.lines"
                                :key="line.token_type"
                                class="border-t"
                            >
                                <td class="py-1">
                                    {{ tokenLabels[line.token_type] }}
                                </td>
                                <td class="py-1 text-right">
                                    {{ formatTokens(line.tokens) }}
                                </td>
                                <td class="py-1 text-right">
                                    {{ formatMoney(line.rate) }}
                                </td>
                                <td class="py-1 text-right">
                                    {{ formatMoney(line.cost) }}
                                </td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="border-t">
                                <td class="py-1">Pixelforge cost</td>
                                <td class="py-1 text-right">
                                    {{
                                        generation.usage
                                            ? formatTokens(
                                                  generation.usage.total_tokens,
                                              )
                                            : ''
                                    }}
                                </td>
                                <td />
                                <td class="py-1 text-right">
                                    {{
                                        formatMoney(
                                            generation.breakdown.provider_cost,
                                        )
                                    }}
                                </td>
                            </tr>
                            <tr>
                                <td class="py-1" colspan="3">
                                    Charged (+{{ markupPercent }}, rounded up)
                                </td>
                                <td class="py-1 text-right font-medium">
                                    {{
                                        formatMoney(
                                            generation.breakdown.charged,
                                        )
                                    }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </section>

                <section class="text-xs text-muted-foreground">
                    <p>Created {{ formatTime(generation.created_at) }}</p>
                    <p v-if="generation.started_at">
                        Started {{ formatTime(generation.started_at) }}
                    </p>
                    <p v-if="generation.completed_at">
                        Completed {{ formatTime(generation.completed_at) }}
                    </p>
                </section>
            </div>
        </div>
    </div>
</template>
