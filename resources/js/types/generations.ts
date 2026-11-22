/** An amount the server already formatted: the exact value for the tooltip and the value to show. */
export type Amount = {
    amount: string;
    display: string;
};

export type GenerationStatus = 'queued' | 'processing' | 'succeeded' | 'failed';

export type SubmissionState =
    | 'waiting'
    | 'waiting_for_slot'
    | 'retrying'
    | 'taking_longer'
    | null;

export type Generation = {
    id: string;
    prompt: string;
    size: string;
    aspectRatio: string;
    quality: string;
    status: GenerationStatus;
    submissionState: SubmissionState;
    estimatedCharge: Amount;
    estimatedMaxCharge: Amount;
    charge: Amount | null;
    imageUrl: string | null;
    imageUrlExpiresAt: string | null;
    failureMessage: string | null;
    createdAt: string;
    completedAt: string | null;
    durationSeconds: number | null;
};

export type ComposerOptions = {
    sizes: { value: string; aspectRatio: string }[];
    qualities: { value: string; label: string }[];
    defaults: { size: string; quality: string };
    limits: { promptLength: number; referenceImages: number };
};

export type CostEstimate = {
    min: Amount;
    typical: Amount;
    max: Amount;
};

export type GenerationInput = {
    prompt: string;
    size: string;
    quality: string;
    reference_images: string[];
};
