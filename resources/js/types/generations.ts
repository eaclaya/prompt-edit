import type { Money, Option, Paginated, Timestamp } from './money';

/** App\Enums\GenerationStatus. `pending` is shown as "Queued" (see `status_label`). */
export type GenerationStatus =
    | 'pending'
    | 'queued'
    | 'processing'
    | 'succeeded'
    | 'failed';

/** App\Enums\Size */
export type Size = '1024x1024' | '1536x1024' | '1024x1536';

/** App\Enums\Quality */
export type Quality = 'low' | 'medium' | 'high';

/** Token kinds, keyed as in the Pixelforge API (App\Enums\TokenType). */
export type TokenType =
    | 'input_text_tokens'
    | 'input_image_tokens'
    | 'output_tokens'
    | 'reasoning_tokens';

export type TokenUsage = Record<TokenType, number> & { total_tokens: number };

/** Pixelforge rates fixed on the generation, USD per 1M tokens. */
export type Rates = Record<TokenType, Money>;

/** User prices (after markup). The max is what is held on submit. */
export type PriceRange = {
    min: Money;
    expected: Money;
    max: Money;
};

export type GenerationError = {
    code: string;
    message: string;
};

/** One card in the history grid (GenerationResource). */
export type GenerationSummary = {
    id: string;
    prompt: string;
    size: Size;
    quality: Quality;
    reference_images: string[];
    status: GenerationStatus;
    status_label: string;
    is_final: boolean;
    estimate: PriceRange;
    /** What the user paid: null until final; "0.000000000" for failed. */
    charged: Money | null;
    /** null unless succeeded and still live. */
    image_url: string | null;
    /** true once Pixelforge's link has expired and we hold no copy (show a placeholder). */
    image_expired: boolean;
    error: GenerationError | null;
    created_at: Timestamp;
    completed_at: Timestamp | null;
};

/** One line of the cost math: tokens × rate / 1M. */
export type CostLine = {
    token_type: TokenType;
    tokens: number;
    /** USD per 1M tokens */
    rate: Money;
    cost: Money;
};

export type CostBreakdown = {
    lines: CostLine[];
    provider_cost: Money;
    /** 2500 = 25% */
    markup_bps: number;
    charged: Money;
};

export type GenerationDetail = GenerationSummary & {
    usage: TokenUsage | null;
    rates: Rates | null;
    /** null until succeeded. */
    breakdown: CostBreakdown | null;
    image_url_expires_at: Timestamp | null;
    started_at: Timestamp | null;
};

/** Page generations/Index — GET /generations (paginated, newest first, own generations only). */
export type GenerationsIndexProps = {
    generations: Paginated<GenerationSummary>;
};

/** Page generations/Create — GET /generations/create */
export type GenerationsCreateProps = {
    sizes: Option<Size>[];
    qualities: Option<Quality>[];
    defaults: { size: Size; quality: Quality };
    limits: { prompt_max_length: number; reference_images_max: number };
    /** Balance minus open holds. */
    available: Money;
};

/** Page generations/Show — GET /generations/{id} (404 for another user's generation). */
export type GenerationsShowProps = {
    generation: GenerationDetail;
};

/** Body of POST /generations (StoreGenerationRequest); errors come back as Inertia validation errors. */
export type StoreGenerationRequest = {
    prompt: string;
    size: Size;
    quality: Quality;
    reference_images: string[];
};

/** Body of POST /estimate (JSON). Same fields and validation as a submit. */
export type EstimateRequest = StoreGenerationRequest;

/** 200 JSON from POST /estimate. 422 is Laravel's `{ message, errors }`. */
export type EstimateResponse = {
    estimate: PriceRange;
    /** Balance minus open holds. */
    available: Money;
    /** available >= estimate.max */
    can_afford: boolean;
};
