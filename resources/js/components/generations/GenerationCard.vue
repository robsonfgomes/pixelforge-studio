<script setup lang="ts">
import { AlertTriangle, ImageOff, Loader2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import AmountText from '@/components/generations/AmountText.vue';
import AspectRatioIcon from '@/components/generations/AspectRatioIcon.vue';
import GenerationStatusBadge from '@/components/generations/GenerationStatusBadge.vue';
import type { Generation } from '@/types';

const props = defineProps<{
    generation: Generation;
}>();

const imageFailedToLoad = ref(false);

const isImageExpired = computed(
    () =>
        imageFailedToLoad.value ||
        (props.generation.imageUrlExpiresAt !== null &&
            new Date(props.generation.imageUrlExpiresAt).getTime() <=
                Date.now()),
);

const pendingLabel = computed(() => {
    switch (props.generation.submissionState) {
        case 'waiting':
            return 'Waiting to submit';
        case 'retrying':
            return 'Retrying…';
        case 'taking_longer':
            return 'Taking longer than expected';
        default:
            return props.generation.status === 'processing'
                ? 'Generating…'
                : 'Queued';
    }
});

const aspectStyle = computed(() => ({
    aspectRatio: props.generation.aspectRatio.replace(':', ' / '),
}));

const createdAt = computed(() =>
    new Date(props.generation.createdAt).toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }),
);
</script>

<template>
    <article
        class="flex flex-col overflow-hidden rounded-xl border border-sidebar-border/70 bg-card"
    >
        <div
            class="relative flex max-h-80 items-center justify-center bg-muted/40"
            :style="aspectStyle"
        >
            <img
                v-if="
                    generation.status === 'succeeded' &&
                    generation.imageUrl &&
                    !isImageExpired
                "
                :src="generation.imageUrl"
                :alt="generation.prompt"
                class="size-full object-cover"
                loading="lazy"
                @error="imageFailedToLoad = true"
            />
            <div
                v-else-if="generation.status === 'succeeded'"
                class="flex flex-col items-center gap-2 text-muted-foreground"
            >
                <ImageOff class="size-6" />
                <span class="text-sm">Image expired</span>
            </div>
            <div
                v-else-if="generation.status === 'failed'"
                class="flex flex-col items-center gap-2 px-6 text-center"
            >
                <AlertTriangle class="size-6 text-red-400" />
                <span class="text-sm">{{ generation.failureMessage }}</span>
                <span class="text-xs text-muted-foreground">
                    You were not charged.
                </span>
            </div>
            <div
                v-else
                class="flex flex-col items-center gap-2 text-muted-foreground"
            >
                <Loader2
                    v-if="generation.submissionState !== 'taking_longer'"
                    class="size-6 animate-spin"
                />
                <AlertTriangle v-else class="size-6 text-amber-400" />
                <span class="text-sm">{{ pendingLabel }}</span>
            </div>
        </div>

        <div class="flex flex-col gap-3 p-4">
            <p class="line-clamp-3 text-sm" :title="generation.prompt">
                {{ generation.prompt }}
            </p>

            <div
                class="flex flex-wrap items-center gap-2 text-xs text-muted-foreground"
            >
                <GenerationStatusBadge :status="generation.status" />
                <span class="flex items-center gap-1">
                    <AspectRatioIcon :aspect-ratio="generation.aspectRatio" />
                    {{ generation.aspectRatio }}
                </span>
                <span class="capitalize">{{ generation.quality }}</span>
                <span class="ml-auto">{{ createdAt }}</span>
            </div>

            <dl class="grid grid-cols-2 gap-2 text-xs">
                <div class="flex flex-col gap-0.5">
                    <dt class="text-muted-foreground">Estimated</dt>
                    <dd>
                        <AmountText
                            :amount="generation.estimatedCharge"
                            prefix="~"
                        />
                        <span class="text-muted-foreground"> · at most </span>
                        <AmountText :amount="generation.estimatedMaxCharge" />
                    </dd>
                </div>
                <div class="flex flex-col gap-0.5">
                    <dt class="text-muted-foreground">Charged</dt>
                    <dd>
                        <AmountText
                            v-if="generation.charge"
                            :amount="generation.charge"
                        />
                        <span v-else class="text-muted-foreground">—</span>
                    </dd>
                </div>
            </dl>
        </div>
    </article>
</template>
