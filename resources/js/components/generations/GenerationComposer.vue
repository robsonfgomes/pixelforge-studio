<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { useEventListener } from '@vueuse/core';
import { Plus, Sparkles, X } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import AspectRatioIcon from '@/components/generations/AspectRatioIcon.vue';
import EstimateSummary from '@/components/generations/EstimateSummary.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useGenerationEstimate } from '@/composables/useGenerationEstimate';
import { store } from '@/routes/generations';
import type { ComposerOptions, GenerationInput } from '@/types';

const props = defineProps<{
    options: ComposerOptions;
}>();

const composerForm = useForm({
    prompt: '',
    size: props.options.defaults.size,
    quality: props.options.defaults.quality,
    reference_images: [] as string[],
});

const currentInput = (): GenerationInput => ({
    prompt: composerForm.prompt,
    size: composerForm.size,
    quality: composerForm.quality,
    reference_images: composerForm.reference_images
        .map((url) => url.trim())
        .filter((url) => url !== ''),
});

const {
    state,
    estimate,
    errors: estimateErrors,
} = useGenerationEstimate(currentInput);

/** Unicode characters, like the server counts them (a string's length counts UTF-16 units). */
const promptLength = computed(() => [...composerForm.prompt].length);

const canAddReference = computed(
    () =>
        composerForm.reference_images.length <
        props.options.limits.referenceImages,
);

const canGenerate = computed(
    () => !composerForm.processing && state.value === 'ready',
);

// A static `placeholder="https://…"` on a component breaks vue-tsc 3.3's template check, so it is bound.
const referenceUrlPlaceholder = 'https://example.com/style.png';

const shortcutHint = navigator.platform.toLowerCase().includes('mac')
    ? '⌘ ↵'
    : 'Ctrl ↵';

const selectedAspectRatio = computed(
    () =>
        props.options.sizes.find((size) => size.value === composerForm.size)
            ?.aspectRatio ?? '1:1',
);

function errorFor(field: string): string | undefined {
    const submitErrors = composerForm.errors as Record<
        string,
        string | undefined
    >;

    return submitErrors[field] ?? estimateErrors.value[field];
}

function addReference(): void {
    if (canAddReference.value) {
        composerForm.reference_images.push('');
    }
}

function removeReference(index: number): void {
    composerForm.reference_images.splice(index, 1);
}

function generate(): void {
    if (!canGenerate.value) {
        return;
    }

    composerForm.transform(currentInput).submit(store(), {
        preserveScroll: true,
        onSuccess: () => composerForm.reset(),
    });
}

useEventListener(window, 'keydown', (event: KeyboardEvent) => {
    if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
        event.preventDefault();
        generate();
    }
});
</script>

<template>
    <form class="flex min-h-0 flex-col" @submit.prevent="generate">
        <div class="flex min-h-0 flex-1 flex-col gap-6 overflow-y-auto p-5">
            <section class="flex flex-col gap-2">
                <div class="flex items-center justify-between">
                    <h2
                        class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Reference images
                    </h2>
                    <span class="text-xs text-muted-foreground tabular-nums">
                        {{ composerForm.reference_images.length }}/{{
                            options.limits.referenceImages
                        }}
                    </span>
                </div>
                <div
                    v-for="(url, index) in composerForm.reference_images"
                    :key="index"
                    class="flex flex-col gap-1"
                >
                    <div class="flex items-center gap-2">
                        <Input
                            v-model="composerForm.reference_images[index]"
                            type="url"
                            :placeholder="referenceUrlPlaceholder"
                            :aria-invalid="
                                errorFor(`reference_images.${index}`) !==
                                undefined
                            "
                        />
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            :aria-label="`Remove reference image ${index + 1}`"
                            @click="removeReference(index)"
                        >
                            <X />
                        </Button>
                    </div>
                    <InputError
                        :message="errorFor(`reference_images.${index}`)"
                    />
                </div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    class="self-start"
                    :disabled="!canAddReference"
                    @click="addReference"
                >
                    <Plus /> Add image URL
                </Button>
                <InputError :message="errorFor('reference_images')" />
            </section>

            <section class="flex flex-col gap-2">
                <div class="flex items-center justify-between">
                    <label
                        for="prompt"
                        class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Prompt
                    </label>
                    <span
                        class="text-xs tabular-nums"
                        :class="
                            promptLength > options.limits.promptLength
                                ? 'text-red-400'
                                : 'text-muted-foreground'
                        "
                    >
                        {{ promptLength }}/{{ options.limits.promptLength }}
                    </span>
                </div>
                <textarea
                    id="prompt"
                    v-model="composerForm.prompt"
                    rows="6"
                    placeholder="Describe the image you want to create"
                    class="w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive dark:bg-input/30"
                    :aria-invalid="errorFor('prompt') !== undefined"
                />
                <InputError :message="errorFor('prompt')" />
            </section>

            <div class="grid grid-cols-2 gap-3">
                <section class="flex flex-col gap-2">
                    <h2
                        class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Aspect ratio
                    </h2>
                    <Select v-model="composerForm.size">
                        <SelectTrigger class="w-full" aria-label="Aspect ratio">
                            <AspectRatioIcon
                                :aspect-ratio="selectedAspectRatio"
                            />
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="size in options.sizes"
                                :key="size.value"
                                :value="size.value"
                            >
                                {{ size.aspectRatio }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="errorFor('size')" />
                </section>
                <section class="flex flex-col gap-2">
                    <h2
                        class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Quality
                    </h2>
                    <Select v-model="composerForm.quality">
                        <SelectTrigger class="w-full" aria-label="Quality">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="quality in options.qualities"
                                :key="quality.value"
                                :value="quality.value"
                            >
                                {{ quality.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="errorFor('quality')" />
                </section>
            </div>
        </div>

        <footer class="flex shrink-0 items-center gap-4 bg-sidebar p-5">
            <div class="min-w-0 flex-1">
                <EstimateSummary :state="state" :estimate="estimate" />
                <InputError :message="errorFor('estimate')" />
            </div>
            <Button
                type="submit"
                size="lg"
                class="shrink-0 bg-brand text-brand-foreground hover:bg-brand/90"
                :disabled="!canGenerate"
            >
                <Sparkles />
                Generate
                <kbd
                    class="ml-1 rounded bg-black/15 px-1.5 py-0.5 text-[10px] font-medium"
                >
                    {{ shortcutHint }}
                </kbd>
            </Button>
        </footer>
    </form>
</template>
