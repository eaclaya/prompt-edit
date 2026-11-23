<script setup lang="ts">
import { ImageOff } from '@lucide/vue';
</script>

<template>
    <div
        data-test="image-expired"
        class="flex size-full flex-col items-center justify-center gap-2 bg-muted p-4 text-center text-sm text-muted-foreground"
    >
        <ImageOff class="size-6" aria-hidden="true" />
        <span>Image link expired</span>
        <span class="text-xs">Pixelforge keeps images for 24 hours.</span>
    </div>
</template>
