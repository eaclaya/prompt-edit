<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { formatCredit } from '@/components/credits/formatCredit';
import type { CreditTransactionRow, Paginated } from '@/types';

defineProps<{ transactions: Paginated<CreditTransactionRow> }>();

function formatDate(timestamp: string): string {
    return new Date(timestamp).toLocaleString();
}

function amountClass(row: CreditTransactionRow): string {
    if (row.type === 'hold' || row.type === 'hold_release') {
        return 'text-muted-foreground';
    }

    return row.amount.startsWith('-')
        ? 'text-red-600 dark:text-red-400'
        : 'text-green-600 dark:text-green-400';
}
</script>

<template>
    <div class="overflow-x-auto rounded-xl border">
        <table class="w-full text-sm">
            <thead class="bg-muted/50 text-left text-muted-foreground">
                <tr>
                    <th class="px-4 py-2 font-medium">Date</th>
                    <th class="px-4 py-2 font-medium">Description</th>
                    <th class="px-4 py-2 text-right font-medium">Amount</th>
                    <th class="px-4 py-2 text-right font-medium">Balance</th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="transactions.data.length === 0">
                    <td
                        colspan="4"
                        class="px-4 py-6 text-center text-muted-foreground"
                    >
                        No credit activity yet.
                    </td>
                </tr>
                <tr
                    v-for="row in transactions.data"
                    :key="row.id"
                    class="border-t"
                >
                    <td
                        class="px-4 py-2 whitespace-nowrap text-muted-foreground"
                    >
                        {{ formatDate(row.created_at) }}
                    </td>
                    <td class="px-4 py-2">
                        {{ row.description }}
                        <span
                            v-if="
                                row.type === 'hold' ||
                                row.type === 'hold_release'
                            "
                            class="ml-1 text-xs text-muted-foreground"
                        >
                            (reserved, not spent)
                        </span>
                    </td>
                    <td
                        class="px-4 py-2 text-right font-mono whitespace-nowrap"
                        :class="amountClass(row)"
                    >
                        {{ formatCredit(row.amount) }}
                    </td>
                    <td
                        class="px-4 py-2 text-right font-mono whitespace-nowrap"
                    >
                        {{ formatCredit(row.balance_after) }}
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <nav
        v-if="transactions.meta.last_page > 1"
        class="mt-4 flex items-center justify-between text-sm"
    >
        <Link
            v-if="transactions.links.prev"
            :href="transactions.links.prev"
            preserve-scroll
            class="underline-offset-4 hover:underline"
        >
            ← Newer
        </Link>
        <span v-else />
        <span class="text-muted-foreground">
            Page {{ transactions.meta.current_page }} of
            {{ transactions.meta.last_page }}
        </span>
        <Link
            v-if="transactions.links.next"
            :href="transactions.links.next"
            preserve-scroll
            class="underline-offset-4 hover:underline"
        >
            Older →
        </Link>
        <span v-else />
    </nav>
</template>
