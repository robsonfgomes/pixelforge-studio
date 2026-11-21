<script setup lang="ts">
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import type { Amount } from '@/types';

defineProps<{
    amount: Amount;
    prefix?: string;
}>();
</script>

<template>
    <TooltipProvider :delay-duration="150">
        <Tooltip>
            <TooltipTrigger as-child>
                <span class="cursor-help tabular-nums">
                    {{ prefix }}{{ amount.display }}
                </span>
            </TooltipTrigger>
            <TooltipContent>
                <span class="tabular-nums">${{ amount.amount }}</span>
            </TooltipContent>
        </Tooltip>
    </TooltipProvider>
</template>
