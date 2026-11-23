<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { report } from '@/routes/admin';
import type { AdminReportProps } from '@/types';
import { formatDifference, formatMoney } from './money';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Admin report', href: report() }],
    },
});

const props = defineProps<AdminReportProps>();

const form = useForm({ start: props.filters.start, end: props.filters.end });

function apply(): void {
    form.get(report().url, { preserveScroll: true });
}

const mismatchedDays = computed(
    () => props.days.filter((day) => day.mismatch).length,
);

/** Newest day first, the way an operator reads it. */
const daysNewestFirst = computed(() => [...props.days].reverse());
</script>

<template>
    <Head title="Admin report" />

    <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto p-4">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Admin report</h1>
                <p class="text-sm text-muted-foreground">
                    Billed generations by UTC day of completion, matching
                    Pixelforge's <code>/v1/usage</code>.
                </p>
            </div>

            <form
                class="flex flex-wrap items-end gap-3"
                @submit.prevent="apply"
            >
                <div class="grid gap-1">
                    <Label for="start">From</Label>
                    <Input
                        id="start"
                        v-model="form.start"
                        type="date"
                        required
                    />
                    <InputError :message="form.errors.start" />
                </div>
                <div class="grid gap-1">
                    <Label for="end">To</Label>
                    <Input id="end" v-model="form.end" type="date" required />
                    <InputError :message="form.errors.end" />
                </div>
                <Button type="submit" :disabled="form.processing">Apply</Button>
            </form>
        </div>

        <Alert v-if="usage_error" variant="destructive">
            <AlertTitle>Pixelforge usage unavailable</AlertTitle>
            <AlertDescription>
                {{ usage_error }} Our own numbers are shown without
                reconciliation.
            </AlertDescription>
        </Alert>

        <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border p-4">
                <dt class="text-sm text-muted-foreground">Credit spend</dt>
                <dd
                    class="text-2xl font-semibold tabular-nums"
                    :title="totals.credit_spend"
                >
                    {{ formatMoney(totals.credit_spend) }}
                </dd>
            </div>
            <div class="rounded-xl border p-4">
                <dt class="text-sm text-muted-foreground">Provider cost</dt>
                <dd
                    class="text-2xl font-semibold tabular-nums"
                    :title="totals.provider_cost"
                >
                    {{ formatMoney(totals.provider_cost) }}
                </dd>
            </div>
            <div class="rounded-xl border p-4">
                <dt class="text-sm text-muted-foreground">Profit</dt>
                <dd
                    class="text-2xl font-semibold tabular-nums"
                    :title="totals.profit"
                >
                    {{ formatMoney(totals.profit) }}
                </dd>
            </div>
            <div class="rounded-xl border p-4">
                <dt class="text-sm text-muted-foreground">Pixelforge billed</dt>
                <dd class="text-2xl font-semibold tabular-nums">
                    <template v-if="totals.pixelforge_cost !== null">
                        <span :title="totals.pixelforge_cost">{{
                            formatMoney(totals.pixelforge_cost)
                        }}</span>
                    </template>
                    <template v-else>—</template>
                </dd>
                <p class="mt-1 text-xs text-muted-foreground">
                    {{ totals.generations }} generations ·
                    <span v-if="mismatchedDays > 0" class="text-destructive">
                        {{ mismatchedDays }} day(s) differ
                    </span>
                    <span v-else-if="totals.pixelforge_cost !== null">
                        all days match
                    </span>
                    <span v-else>not reconciled</span>
                </p>
            </div>
        </dl>

        <section class="grid gap-2">
            <h2 class="font-medium">Per day (UTC)</h2>
            <div class="overflow-x-auto rounded-xl border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="px-3 py-2 font-medium">Date</th>
                            <th class="px-3 py-2 text-right font-medium">
                                Generations
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Credit spend
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Provider cost
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Profit
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Pixelforge billed
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Difference
                            </th>
                        </tr>
                    </thead>
                    <tbody class="tabular-nums">
                        <tr
                            v-for="day in daysNewestFirst"
                            :key="day.date"
                            class="border-t"
                            :class="{ 'bg-destructive/10': day.mismatch }"
                        >
                            <td class="px-3 py-2 font-mono whitespace-nowrap">
                                {{ day.date }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                {{ day.generations }}
                            </td>
                            <td
                                class="px-3 py-2 text-right"
                                :title="day.credit_spend"
                            >
                                {{ formatMoney(day.credit_spend) }}
                            </td>
                            <td
                                class="px-3 py-2 text-right"
                                :title="day.provider_cost"
                            >
                                {{ formatMoney(day.provider_cost) }}
                            </td>
                            <td
                                class="px-3 py-2 text-right"
                                :title="day.profit"
                            >
                                {{ formatMoney(day.profit) }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                <span
                                    v-if="day.pixelforge_cost !== null"
                                    :title="day.pixelforge_cost"
                                    >{{
                                        formatMoney(day.pixelforge_cost)
                                    }}</span
                                >
                                <span v-else class="text-muted-foreground"
                                    >—</span
                                >
                            </td>
                            <td class="px-3 py-2 text-right">
                                <Badge
                                    v-if="
                                        day.mismatch && day.difference !== null
                                    "
                                    variant="destructive"
                                >
                                    {{ formatDifference(day.difference) }}
                                </Badge>
                                <span
                                    v-else-if="day.difference !== null"
                                    class="text-muted-foreground"
                                    >match</span
                                >
                                <span v-else class="text-muted-foreground"
                                    >—</span
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="grid gap-2">
            <h2 class="font-medium">Per user</h2>
            <div class="overflow-x-auto rounded-xl border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="px-3 py-2 font-medium">User</th>
                            <th class="px-3 py-2 text-right font-medium">
                                Generations
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Credit spend
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Provider cost
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Profit
                            </th>
                        </tr>
                    </thead>
                    <tbody class="tabular-nums">
                        <tr
                            v-for="user in users"
                            :key="user.user_id ?? 'deleted'"
                            class="border-t"
                        >
                            <td class="px-3 py-2">
                                <div>{{ user.name }}</div>
                                <div class="text-xs text-muted-foreground">
                                    {{ user.email }}
                                </div>
                            </td>
                            <td class="px-3 py-2 text-right">
                                {{ user.generations }}
                            </td>
                            <td
                                class="px-3 py-2 text-right"
                                :title="user.credit_spend"
                            >
                                {{ formatMoney(user.credit_spend) }}
                            </td>
                            <td
                                class="px-3 py-2 text-right"
                                :title="user.provider_cost"
                            >
                                {{ formatMoney(user.provider_cost) }}
                            </td>
                            <td
                                class="px-3 py-2 text-right"
                                :title="user.profit"
                            >
                                {{ formatMoney(user.profit) }}
                            </td>
                        </tr>
                        <tr v-if="users.length === 0" class="border-t">
                            <td
                                colspan="5"
                                class="px-3 py-6 text-center text-muted-foreground"
                            >
                                No billed generations in this range.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
