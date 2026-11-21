import { useHttp } from '@inertiajs/vue3';
import { onBeforeUnmount, ref, watch } from 'vue';
import type { Ref } from 'vue';
import { estimate as estimateRoute } from '@/routes/generations';
import type { CostEstimate, GenerationInput } from '@/types';

/**
 * - `empty`: no prompt yet, so nothing to estimate.
 * - `pending`: waiting for the debounce or for the response to the current input.
 * - `ready`: the estimate matches the current input.
 * - `invalid`: the server rejected the input (see `errors`).
 * - `unavailable`: the server can't estimate right now (no price table, throttled, network).
 */
export type EstimateState =
    | 'empty'
    | 'pending'
    | 'ready'
    | 'invalid'
    | 'unavailable';

const DEBOUNCE_MILLISECONDS = 300;

/**
 * Keeps the cost estimate in sync with the composer input. Every change cancels the request in flight before
 * scheduling the next one, so a late response for an older input can never update the estimate.
 */
export function useGenerationEstimate(input: () => GenerationInput): {
    state: Ref<EstimateState>;
    estimate: Ref<CostEstimate | null>;
    errors: Ref<Record<string, string>>;
} {
    const http = useHttp<Record<string, never>, { data: CostEstimate }>({});
    const state = ref<EstimateState>('empty');
    const estimate = ref<CostEstimate | null>(null);
    const errors = ref<Record<string, string>>({});
    let debounceTimer: ReturnType<typeof setTimeout> | undefined;

    function refresh(current: GenerationInput): void {
        http.cancel();
        clearTimeout(debounceTimer);

        if (current.prompt.trim() === '') {
            state.value = 'empty';
            estimate.value = null;
            errors.value = {};

            return;
        }

        state.value = 'pending';
        debounceTimer = setTimeout(
            () => request(current),
            DEBOUNCE_MILLISECONDS,
        );
    }

    function request(current: GenerationInput): void {
        http.transform(() => current)
            .post(estimateRoute.url(), {
                onSuccess: (response) => {
                    estimate.value = response.data;
                    errors.value = {};
                    state.value = 'ready';
                },
                onError: (validationErrors) => {
                    estimate.value = null;
                    errors.value = validationErrors as Record<string, string>;
                    state.value = 'invalid';
                },
                onHttpException: () => {
                    estimate.value = null;
                    state.value = 'unavailable';
                },
                onNetworkError: () => {
                    estimate.value = null;
                    state.value = 'unavailable';
                },
            })
            // Cancellations and HTTP errors also reject the promise; the callbacks above already handled them.
            .catch(() => undefined);
    }

    watch(input, refresh, { deep: true, immediate: true });

    onBeforeUnmount(() => {
        clearTimeout(debounceTimer);
        http.cancel();
    });

    return { state, estimate, errors };
}
