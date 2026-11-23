<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Coins } from '@lucide/vue';
import { computed } from 'vue';
import { formatCredit } from '@/components/credits/formatCredit';
import {
    SidebarGroup,
    SidebarGroupContent,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { index } from '@/routes/credits';

// The `balance` prop shared on every page by App\Http\Middleware\ShareBalance.
const page = usePage();
const balance = computed(() => page.props.balance ?? null);
const reserved = computed(
    () =>
        balance.value !== null &&
        balance.value.available !== balance.value.balance,
);
</script>

<template>
    <SidebarGroup v-if="balance" class="group-data-[collapsible=icon]:p-0">
        <SidebarGroupContent>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton
                        size="lg"
                        as-child
                        :tooltip="`Available ${formatCredit(balance.available)}`"
                    >
                        <Link :href="index()" data-test="sidebar-balance">
                            <Coins />
                            <div class="grid flex-1 text-left leading-tight">
                                <span class="text-xs text-muted-foreground">
                                    Credits
                                </span>
                                <span class="font-semibold">
                                    {{ formatCredit(balance.available) }}
                                </span>
                                <span
                                    v-if="reserved"
                                    class="text-xs text-muted-foreground"
                                >
                                    of {{ formatCredit(balance.balance) }}
                                </span>
                            </div>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarGroupContent>
    </SidebarGroup>
</template>
