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
        case 'waiting_for_slot':
            return 'Waiting for a free slot';
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

/** Literal class names, so Tailwind generates them. */
const aspectClasses: Record<string, string> = {
    '1:1': 'aspect-square',
    '3:2': 'aspect-[3/2]',
    '2:3': 'aspect-[2/3]',
};

const aspectClass = computed(
    () => aspectClasses[props.generation.aspectRatio] ?? 'aspect-square',
);

const createdAt = computed(() =>
    new Date(props.generation.createdAt).toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }),
);

const duration = computed(() => {
    const seconds = props.generation.durationSeconds;

    if (seconds === null) {
        return null;
    }

    const text =
        seconds < 60
            ? `${seconds} s`
            : `${Math.floor(seconds / 60)} min ${seconds % 60} s`;

    return props.generation.status === 'failed'
        ? `Failed after ${text}`
        : `Completed in ${text}`;
});
</script>

<template>
    <article
        class="flex flex-col overflow-hidden rounded-xl border border-sidebar-border/70 bg-card"
    >
        <div
            v-if="generation.status === 'failed'"
            class="flex items-start gap-3 border-b border-red-500/20 bg-red-500/10 px-4 py-3"
        >
            <AlertTriangle class="mt-0.5 size-4 shrink-0 text-red-400" />
            <div class="flex flex-col gap-0.5">
                <span class="text-sm">{{ generation.failureMessage }}</span>
                <span class="text-xs text-muted-foreground">
                    You were not charged.
                </span>
            </div>
        </div>
        <div
            v-else
            class="relative flex w-full items-center justify-center bg-muted/40"
            :class="aspectClass"
        >
            <img
                v-if="
                    generation.status === 'succeeded' &&
                    generation.imageUrl &&
                    !isImageExpired
                "
                :src="generation.imageUrl"
                :alt="generation.prompt"
                class="size-full object-contain"
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
                <span class="ml-auto flex flex-col items-end">
                    <span>{{ createdAt }}</span>
                    <span v-if="duration">{{ duration }}</span>
                </span>
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
                        <span
                            v-if="generation.status === 'failed'"
                            class="text-muted-foreground"
                        >
                            Not charged
                        </span>
                        <AmountText
                            v-else-if="generation.charge"
                            :amount="generation.charge"
                        />
                        <span v-else class="text-muted-foreground">—</span>
                    </dd>
                </div>
            </dl>
        </div>
    </article>
</template>
