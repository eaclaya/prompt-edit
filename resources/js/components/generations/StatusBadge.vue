<script setup lang="ts">
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Spinner } from '@/components/ui/spinner';
import type { GenerationStatus } from '@/types';

const props = defineProps<{
    status: GenerationStatus;
    /** The server's status_label; derived from the status when omitted. */
    label?: string;
}>();

type Tone = 'queued' | 'processing' | 'succeeded' | 'failed';

// `pending` exists only locally and is shown as "Queued" (PLAN.md §2.7).
const tones: Record<GenerationStatus, Tone> = {
    pending: 'queued',
    queued: 'queued',
    processing: 'processing',
    succeeded: 'succeeded',
    failed: 'failed',
};

const labels: Record<Tone, string> = {
    queued: 'Queued',
    processing: 'Processing',
    succeeded: 'Succeeded',
    failed: 'Failed',
};

const styles: Record<Tone, string> = {
    queued: 'border-transparent bg-secondary text-secondary-foreground',
    processing:
        'border-transparent bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-200',
    succeeded:
        'border-transparent bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200',
    failed: 'border-transparent bg-destructive text-white dark:bg-destructive/60',
};

const tone = computed(() => tones[props.status]);
const inFlight = computed(
    () => props.status !== 'succeeded' && props.status !== 'failed',
);
</script>

<template>
    <Badge :data-tone="tone" :class="styles[tone]">
        <Spinner
            v-if="inFlight"
            data-test="status-spinner"
            class="size-3"
            aria-hidden="true"
        />
        {{ label ?? labels[tone] }}
    </Badge>
</template>
