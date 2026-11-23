<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { formatCredit } from '@/components/credits/formatCredit';
import LedgerTable from '@/components/credits/LedgerTable.vue';
import PackButton from '@/components/credits/PackButton.vue';
import Heading from '@/components/Heading.vue';
import { index } from '@/routes/credits';
import type { CreditsIndexProps } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Credits', href: index() }],
    },
});

defineProps<CreditsIndexProps>();
</script>

<template>
    <Head title="Credits" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            title="Credits"
            description="Generations are paid from your credit. We reserve the maximum estimate when you submit and charge the actual price when it finishes. Failed generations are free."
        />

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="rounded-xl border p-4">
                <div class="text-sm text-muted-foreground">Balance</div>
                <div class="text-3xl font-semibold" data-test="balance">
                    {{ formatCredit(balance) }}
                </div>
            </div>
            <div class="rounded-xl border p-4">
                <div class="text-sm text-muted-foreground">
                    Available to spend
                </div>
                <div class="text-3xl font-semibold" data-test="available">
                    {{ formatCredit(available) }}
                </div>
                <div
                    v-if="available !== balance"
                    class="mt-1 text-xs text-muted-foreground"
                >
                    The rest is reserved for generations in progress.
                </div>
            </div>
        </div>

        <section class="flex flex-col gap-3">
            <h2 class="text-lg font-medium">Buy credits</h2>
            <p class="text-sm text-muted-foreground">
                Demo checkout: credit is added instantly and no payment is
                taken.
            </p>
            <div class="grid grid-cols-3 gap-3 sm:max-w-md">
                <PackButton v-for="pack in packs" :key="pack.id" :pack="pack" />
            </div>
        </section>

        <section class="flex flex-col gap-3">
            <h2 class="text-lg font-medium">History</h2>
            <LedgerTable :transactions="transactions" />
        </section>
    </div>
</template>
