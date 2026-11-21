<script setup lang="ts">
import AmountText from '@/components/generations/AmountText.vue';
import type { EstimateState } from '@/composables/useGenerationEstimate';
import type { CostEstimate } from '@/types';

defineProps<{
    state: EstimateState;
    estimate: CostEstimate | null;
}>();
</script>

<template>
    <div class="flex min-w-0 flex-col gap-0.5 text-sm" aria-live="polite">
        <template v-if="state === 'ready' && estimate">
            <p class="font-medium">
                <AmountText :amount="estimate.typical" prefix="~" />
                <span class="font-normal text-muted-foreground">
                    · at most
                </span>
                <AmountText :amount="estimate.max" />
            </p>
            <p class="text-xs text-muted-foreground">
                The final cost depends on processing. Failed generations are
                free.
            </p>
        </template>
        <p v-else-if="state === 'pending'" class="text-muted-foreground">
            Estimating…
        </p>
        <p v-else-if="state === 'invalid'" class="text-red-400">
            Fix the highlighted fields to see the cost.
        </p>
        <p v-else-if="state === 'unavailable'" class="text-amber-400">
            Estimate unavailable. Please try again in a moment.
        </p>
        <p v-else class="text-muted-foreground">
            Enter a prompt to see the cost.
        </p>
    </div>
</template>
