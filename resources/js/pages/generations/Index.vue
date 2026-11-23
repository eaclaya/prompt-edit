<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ImagePlus } from '@lucide/vue';
import GenerationCard from '@/components/generations/GenerationCard.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { useGenerationPolling } from '@/composables/useGenerationPolling';
import { create, index, show } from '@/routes/generations';
import type { GenerationsIndexProps } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'History', href: index() }],
    },
});

const props = defineProps<GenerationsIndexProps>();

// Live status: reload just this prop while anything on the page is still running.
const { active: polling } = useGenerationPolling(() => props.generations.data, {
    only: ['generations'],
});

/** Laravel's paginator labels carry HTML entities ("&laquo; Previous"). */
function pageLabel(label: string): string {
    return label.replace('&laquo;', '«').replace('&raquo;', '»');
}
</script>

<template>
    <Head title="History" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="History"
                description="Your generations, newest first."
            />
            <Button as-child>
                <Link :href="create()">
                    <ImagePlus />
                    New image
                </Link>
            </Button>
        </div>

        <p
            v-if="polling"
            class="text-xs text-muted-foreground"
            aria-live="polite"
        >
            Updating while generations are in progress…
        </p>

        <div
            v-if="generations.data.length === 0"
            class="flex flex-col items-center gap-4 rounded-xl border border-dashed p-12 text-center"
        >
            <p class="text-muted-foreground">No generations yet.</p>
            <Button as-child variant="outline">
                <Link :href="create()">Create your first image</Link>
            </Button>
        </div>

        <div
            v-else
            class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
        >
            <GenerationCard
                v-for="generation in generations.data"
                :key="generation.id"
                :generation="generation"
                :href="show(generation.id)"
            />
        </div>

        <nav
            v-if="generations.meta.last_page > 1"
            aria-label="Pagination"
            class="flex flex-wrap justify-center gap-1"
        >
            <template v-for="link in generations.meta.links" :key="link.label">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-scroll
                    class="rounded-md px-3 py-1 text-sm"
                    :class="
                        link.active
                            ? 'bg-primary text-primary-foreground'
                            : 'hover:bg-accent'
                    "
                    :aria-current="link.active ? 'page' : undefined"
                >
                    {{ pageLabel(link.label) }}
                </Link>
                <span v-else class="px-3 py-1 text-sm text-muted-foreground">
                    {{ pageLabel(link.label) }}
                </span>
            </template>
        </nav>
    </div>
</template>
