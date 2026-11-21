<script setup lang="ts">
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import type { GenerationStatus } from '@/types';

const props = defineProps<{
    status: GenerationStatus;
}>();

const appearance = computed(
    () =>
        ({
            queued: {
                label: 'Queued',
                classes: 'bg-muted text-muted-foreground',
            },
            processing: {
                label: 'Processing',
                classes: 'bg-sky-500/15 text-sky-400',
            },
            succeeded: {
                label: 'Succeeded',
                classes: 'bg-emerald-500/15 text-emerald-400',
            },
            failed: { label: 'Failed', classes: 'bg-red-500/15 text-red-400' },
        })[props.status],
);
</script>

<template>
    <Badge
        variant="secondary"
        :class="cn('border-transparent', appearance.classes)"
    >
        {{ appearance.label }}
    </Badge>
</template>
