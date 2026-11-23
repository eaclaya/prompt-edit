<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Plus, X } from '@lucide/vue';
import { computed } from 'vue';
import EstimateCard from '@/components/generations/EstimateCard.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useEstimate } from '@/composables/useEstimate';
import { index as credits } from '@/routes/credits';
import { create, store } from '@/routes/generations';
import type { GenerationsCreateProps, StoreGenerationRequest } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'New image', href: create() }],
    },
});

const props = defineProps<GenerationsCreateProps>();

const form = useForm<StoreGenerationRequest>({
    prompt: '',
    size: props.defaults.size,
    quality: props.defaults.quality,
    reference_images: [],
});

const {
    result: estimate,
    loading: estimating,
    error: estimateError,
} = useEstimate(() => form.data());

const promptLength = computed(() => [...form.prompt].length);
const promptTooLong = computed(
    () => promptLength.value > props.limits.prompt_max_length,
);
const canAddReference = computed(
    () => form.reference_images.length < props.limits.reference_images_max,
);

// The latest estimate says whether the max is affordable; until then, the page's balance is the best we know.
const available = computed(() => estimate.value?.available ?? props.available);
const canAfford = computed(() => estimate.value?.can_afford ?? null);

const canSubmit = computed(
    () =>
        form.prompt.trim() !== '' &&
        !promptTooLong.value &&
        canAfford.value === true &&
        !estimating.value &&
        !form.processing,
);

const fieldClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';

function addReference(): void {
    if (canAddReference.value) {
        form.reference_images.push('');
    }
}

function setReference(position: number, url: string): void {
    form.reference_images[position] = url;
}

function removeReference(position: number): void {
    form.reference_images.splice(position, 1);
}

function referenceError(position: number): string | undefined {
    return (form.errors as Record<string, string | undefined>)[
        `reference_images.${position}`
    ];
}

function submit(): void {
    form.transform((data) => ({
        ...data,
        reference_images: data.reference_images
            .map((url) => url.trim())
            .filter((url) => url !== ''),
    })).submit(store());
}
</script>

<template>
    <Head title="New image" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <Heading
            title="New image"
            description="Describe the image, pick a size and quality, and see the cost before you submit."
        />

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
            <form class="space-y-6" @submit.prevent="submit">
                <div class="grid gap-2">
                    <div class="flex items-baseline justify-between">
                        <Label for="prompt">Prompt</Label>
                        <span
                            data-test="prompt-counter"
                            class="text-xs tabular-nums"
                            :class="
                                promptTooLong
                                    ? 'text-destructive'
                                    : 'text-muted-foreground'
                            "
                        >
                            {{ promptLength }} /
                            {{ limits.prompt_max_length }}
                        </span>
                    </div>
                    <textarea
                        id="prompt"
                        v-model="form.prompt"
                        name="prompt"
                        rows="6"
                        required
                        :aria-invalid="promptTooLong || !!form.errors.prompt"
                        placeholder="A lighthouse on a cliff at dusk, oil painting"
                        class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive dark:bg-input/30"
                    ></textarea>
                    <InputError :message="form.errors.prompt" />
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="size">Size</Label>
                        <select
                            id="size"
                            v-model="form.size"
                            name="size"
                            :class="fieldClass"
                        >
                            <option
                                v-for="option in sizes"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </select>
                        <InputError :message="form.errors.size" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="quality">Quality</Label>
                        <select
                            id="quality"
                            v-model="form.quality"
                            name="quality"
                            :class="fieldClass"
                        >
                            <option
                                v-for="option in qualities"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </select>
                        <InputError :message="form.errors.quality" />
                    </div>
                </div>

                <fieldset class="grid gap-2">
                    <legend class="mb-2 text-sm font-medium">
                        Reference images
                        <span class="font-normal text-muted-foreground">
                            (optional, up to
                            {{ limits.reference_images_max }})
                        </span>
                    </legend>

                    <div
                        v-for="(url, position) in form.reference_images"
                        :key="position"
                        class="grid gap-1"
                    >
                        <div class="flex gap-2">
                            <input
                                :value="url"
                                type="url"
                                :name="'reference_images.' + position"
                                :aria-label="
                                    'Reference image ' + (position + 1) + ' URL'
                                "
                                placeholder="https://example.com/style.png"
                                :class="fieldClass"
                                @input="
                                    setReference(
                                        position,
                                        ($event.target as HTMLInputElement)
                                            .value,
                                    )
                                "
                            />
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                :aria-label="
                                    'Remove reference image ' + (position + 1)
                                "
                                @click="removeReference(position)"
                            >
                                <X />
                            </Button>
                        </div>
                        <InputError :message="referenceError(position)" />
                    </div>

                    <InputError :message="form.errors.reference_images" />

                    <Button
                        v-if="canAddReference"
                        type="button"
                        variant="outline"
                        size="sm"
                        class="w-fit"
                        @click="addReference"
                    >
                        <Plus />
                        Add reference image URL
                    </Button>
                </fieldset>

                <Button type="submit" :disabled="!canSubmit"> Generate </Button>
            </form>

            <aside class="lg:sticky lg:top-4 lg:self-start">
                <EstimateCard
                    :estimate="estimate?.estimate ?? null"
                    :available="available"
                    :can-afford="canAfford"
                    :loading="estimating"
                    :error="estimateError"
                    :credits-href="credits()"
                />
            </aside>
        </div>
    </div>
</template>
