<script setup lang="ts">
import { Head, usePoll } from '@inertiajs/vue3';
import { ImagePlus } from '@lucide/vue';
import { watch } from 'vue';
import GenerationCard from '@/components/generations/GenerationCard.vue';
import GenerationComposer from '@/components/generations/GenerationComposer.vue';
import type { ComposerOptions, Generation } from '@/types';

const props = defineProps<{
    composer: ComposerOptions;
    generations: Generation[];
    hasInProgress: boolean;
}>();

const { start, stop } = usePoll(
    2500,
    { only: ['generations', 'hasInProgress'] },
    { autoStart: false, mode: 'rest' },
);

watch(
    () => props.hasInProgress,
    (hasInProgress) => (hasInProgress ? start() : stop()),
    { immediate: true },
);
</script>

<template>
    <Head title="Generations" />

    <div
        class="flex flex-1 flex-col md:h-[calc(100svh-4rem-1px)] md:flex-none md:flex-row md:overflow-hidden"
    >
        <GenerationComposer
            :options="composer"
            class="border-b border-sidebar-border/70 md:w-[26rem] md:shrink-0 md:border-r md:border-b-0"
        />

        <section class="flex min-h-0 flex-1 flex-col gap-6 overflow-y-auto p-6">
            <h1 class="text-lg font-semibold">Your generations</h1>

            <div
                v-if="generations.length === 0"
                class="flex flex-1 flex-col items-center justify-center gap-3 py-16 text-center"
            >
                <div
                    class="flex size-12 items-center justify-center rounded-full bg-muted"
                >
                    <ImagePlus class="size-6 text-muted-foreground" />
                </div>
                <h2 class="font-medium">No images yet</h2>
                <p class="max-w-sm text-sm text-muted-foreground">
                    Describe what you want on the left. You'll see the cost
                    before you generate.
                </p>
            </div>

            <div
                v-else
                class="grid grid-cols-[repeat(auto-fill,minmax(17rem,1fr))] gap-4"
            >
                <GenerationCard
                    v-for="generation in generations"
                    :key="generation.id"
                    :generation="generation"
                />
            </div>
        </section>
    </div>
</template>
