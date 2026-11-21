<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    aspectRatio: string;
}>();

const box = computed(() => {
    const [width, height] = props.aspectRatio.split(':').map(Number);
    const scale = 12 / Math.max(width, height);

    return { width: width * scale, height: height * scale };
});
</script>

<template>
    <svg viewBox="0 0 16 16" class="size-4 shrink-0" aria-hidden="true">
        <rect
            :x="(16 - box.width) / 2"
            :y="(16 - box.height) / 2"
            :width="box.width"
            :height="box.height"
            rx="1.5"
            fill="none"
            stroke="currentColor"
            stroke-width="1.5"
        />
    </svg>
</template>
