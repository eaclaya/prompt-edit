<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { purchase } from '@/routes/credits';
import type { CreditPack, PurchaseRequest } from '@/types';

const props = defineProps<{ pack: CreditPack }>();

function newToken(): string {
    return (
        globalThis.crypto?.randomUUID?.() ??
        `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`
    );
}

// One token per click intent: a double-click sends the same token, so the
// server credits once. A fresh token is drawn after each completed purchase.
const form = useForm<PurchaseRequest>({
    pack: props.pack.id,
    token: newToken(),
});

function buy(): void {
    form.post(purchase().url, {
        preserveScroll: true,
        onSuccess: () => {
            form.token = newToken();
        },
    });
}
</script>

<template>
    <Button
        :disabled="form.processing"
        :data-pack="pack.id"
        class="h-auto flex-col gap-1 py-4 select-none"
        variant="outline"
        @click="buy"
    >
        <span class="text-2xl font-semibold">{{ pack.label }}</span>
        <span class="text-xs text-muted-foreground">
            {{ form.processing ? 'Adding…' : 'Buy credits' }}
        </span>
    </Button>
</template>
