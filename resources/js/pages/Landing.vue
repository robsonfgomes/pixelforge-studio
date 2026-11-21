<script setup lang="ts">
import { Deferred, Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Activity, Eye, Receipt } from '@lucide/vue';
import AppLogo from '@/components/AppLogo.vue';
import AmountText from '@/components/generations/AmountText.vue';
import { Button } from '@/components/ui/button';
import { home, login, register } from '@/routes';
import { index as generations } from '@/routes/generations';
import type { Amount } from '@/types';

type PricingExample =
    | { available: false }
    | {
          available: true;
          promptLength: number;
          rows: { quality: string; typical: Amount; max: Amount }[];
          perReferenceImage: Amount;
      };

defineProps<{
    markupPercent: number;
    pricingExample?: PricingExample;
}>();

const page = usePage();
const isSignedIn = computed(() => page.props.auth.user !== null);

const steps = [
    {
        icon: Eye,
        title: 'See the cost first',
        description:
            'Write a prompt and pick the size and quality. The price range updates as you type, before you spend anything.',
    },
    {
        icon: Activity,
        title: 'Generate in the background',
        description:
            'Your image is made in the background. The history shows its status live, from queued to done.',
    },
    {
        icon: Receipt,
        title: 'Pay only for real usage',
        description:
            'When the image is ready, the real cost is recorded once. If a generation fails, it is free.',
    },
];

const repositoryUrl = 'https://github.com/robsonfgomes/pixelforge-studio';
</script>

<template>
    <Head title="Generate images, see the cost first" />

    <div class="flex min-h-screen flex-col bg-background text-foreground">
        <header class="border-b border-sidebar-border/80">
            <div
                class="mx-auto flex h-16 w-full max-w-6xl items-center gap-4 px-4 md:px-6"
            >
                <Link :href="home()" class="flex items-center gap-x-2">
                    <AppLogo />
                </Link>
                <nav class="ml-auto flex items-center gap-2">
                    <Button v-if="isSignedIn" as-child>
                        <Link :href="generations()">Open studio</Link>
                    </Button>
                    <template v-else>
                        <Button variant="ghost" as-child>
                            <Link :href="login()">Log in</Link>
                        </Button>
                        <Button variant="outline" as-child>
                            <Link :href="register()">Create account</Link>
                        </Button>
                    </template>
                </nav>
            </div>
        </header>

        <main class="flex flex-1 flex-col">
            <section class="relative overflow-hidden">
                <svg
                    class="pointer-events-none absolute -top-24 right-[-6rem] size-[28rem] text-brand opacity-25 md:right-0"
                    viewBox="0 0 120 120"
                    aria-hidden="true"
                >
                    <g fill="currentColor">
                        <rect x="10" y="10" width="28" height="28" rx="5" />
                        <rect
                            x="46"
                            y="10"
                            width="28"
                            height="28"
                            rx="5"
                            opacity="0.5"
                        />
                        <rect
                            x="82"
                            y="10"
                            width="28"
                            height="28"
                            rx="5"
                            opacity="0.25"
                        />
                        <rect
                            x="10"
                            y="46"
                            width="28"
                            height="28"
                            rx="5"
                            opacity="0.5"
                        />
                        <rect x="46" y="46" width="28" height="28" rx="5" />
                        <rect
                            x="82"
                            y="46"
                            width="28"
                            height="28"
                            rx="5"
                            opacity="0.5"
                        />
                        <rect
                            x="10"
                            y="82"
                            width="28"
                            height="28"
                            rx="5"
                            opacity="0.25"
                        />
                        <rect
                            x="46"
                            y="82"
                            width="28"
                            height="28"
                            rx="5"
                            opacity="0.5"
                        />
                        <rect x="82" y="82" width="28" height="28" rx="5" />
                    </g>
                </svg>

                <div
                    class="relative mx-auto flex w-full max-w-6xl flex-col items-start gap-6 px-4 py-24 md:px-6 md:py-32"
                >
                    <h1
                        class="max-w-2xl text-4xl font-semibold tracking-tight md:text-5xl"
                    >
                        Create images. Know the price before you click.
                    </h1>
                    <p class="max-w-xl text-lg text-muted-foreground">
                        Pixelforge Studio turns your prompts into images and
                        shows what each one will cost before you generate. You
                        only pay for what was really used.
                    </p>
                    <Button
                        size="lg"
                        class="bg-brand text-brand-foreground hover:bg-brand/90"
                        as-child
                    >
                        <Link :href="isSignedIn ? generations() : register()">
                            {{ isSignedIn ? 'Open studio' : 'Start creating' }}
                        </Link>
                    </Button>
                </div>
            </section>

            <section class="border-t border-sidebar-border/70">
                <div
                    class="mx-auto flex w-full max-w-6xl flex-col gap-8 px-4 py-16 md:px-6"
                >
                    <h2 class="text-2xl font-semibold">How it works</h2>
                    <ol class="grid gap-6 md:grid-cols-3">
                        <li
                            v-for="(step, index) in steps"
                            :key="step.title"
                            class="flex flex-col gap-3 rounded-xl border border-sidebar-border/70 bg-card p-6"
                        >
                            <div class="flex items-center gap-3">
                                <span
                                    class="flex size-9 items-center justify-center rounded-full bg-brand/15 text-brand"
                                >
                                    <component :is="step.icon" class="size-5" />
                                </span>
                                <span class="text-sm text-muted-foreground"
                                    >Step {{ index + 1 }}</span
                                >
                            </div>
                            <h3 class="font-medium">{{ step.title }}</h3>
                            <p class="text-sm text-muted-foreground">
                                {{ step.description }}
                            </p>
                        </li>
                    </ol>
                </div>
            </section>

            <section class="border-t border-sidebar-border/70">
                <div
                    class="mx-auto grid w-full max-w-6xl gap-10 px-4 py-16 md:grid-cols-2 md:px-6"
                >
                    <div class="flex flex-col gap-4">
                        <h2 class="text-2xl font-semibold">
                            Transparent pricing
                        </h2>
                        <ul class="flex flex-col gap-3 text-muted-foreground">
                            <li>
                                You pay the image provider's cost plus
                                {{ markupPercent }}%.
                            </li>
                            <li>
                                Before you generate, you see a range: the
                                typical cost and the most it can cost.
                            </li>
                            <li>
                                When the image is ready, the real cost is
                                recorded once.
                            </li>
                            <li>Failed generations are free.</li>
                        </ul>
                    </div>

                    <Deferred data="pricingExample">
                        <template #fallback>
                            <div class="flex flex-col gap-3" aria-busy="true">
                                <div
                                    v-for="row in 3"
                                    :key="row"
                                    class="h-12 animate-pulse rounded-lg bg-muted"
                                />
                            </div>
                        </template>

                        <div
                            v-if="pricingExample?.available"
                            class="flex flex-col gap-3"
                        >
                            <table
                                class="w-full overflow-hidden rounded-xl border border-sidebar-border/70 text-sm"
                            >
                                <thead
                                    class="bg-muted/50 text-left text-muted-foreground"
                                >
                                    <tr>
                                        <th class="px-4 py-3 font-medium">
                                            Quality (1:1)
                                        </th>
                                        <th class="px-4 py-3 font-medium">
                                            Estimated cost
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="row in pricingExample.rows"
                                        :key="row.quality"
                                        class="border-t border-sidebar-border/70"
                                    >
                                        <td class="px-4 py-3">
                                            {{ row.quality }}
                                        </td>
                                        <td class="px-4 py-3">
                                            <AmountText
                                                :amount="row.typical"
                                                prefix="~"
                                            />
                                            <span class="text-muted-foreground">
                                                · at most
                                            </span>
                                            <AmountText :amount="row.max" />
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <p class="text-xs text-muted-foreground">
                                For a
                                {{ pricingExample.promptLength }}-character
                                prompt and no reference images. Each reference
                                image adds
                                <AmountText
                                    :amount="pricingExample.perReferenceImage"
                                />.
                            </p>
                        </div>
                    </Deferred>
                </div>
            </section>
        </main>

        <footer class="border-t border-sidebar-border/70">
            <div
                class="mx-auto flex w-full max-w-6xl flex-col gap-2 px-4 py-6 text-sm text-muted-foreground md:flex-row md:items-center md:justify-between md:px-6"
            >
                <a
                    :href="repositoryUrl"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="hover:text-foreground"
                >
                    Source code on GitHub
                </a>
                <span
                    >Laravel 13 · Inertia 3 · Vue 3 · PostgreSQL · Redis ·
                    Laravel Cloud</span
                >
            </div>
        </footer>
    </div>
</template>
