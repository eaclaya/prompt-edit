import type { Money, Paginated, Timestamp } from './money';

/** App\Enums\LedgerEntryType */
export type LedgerEntryType =
    | 'purchase'
    | 'welcome'
    | 'hold'
    | 'hold_release'
    | 'charge';

/** Shared on every page by the ShareBalance middleware (S3) as `balance`; null for guests. */
export type SharedBalance = {
    balance: Money;
    /** Balance minus open holds. */
    available: Money;
} | null;

export type CreditPack = {
    id: string;
    amount: Money;
    label: string;
};

export type CreditTransactionRow = {
    id: number;
    type: LedgerEntryType;
    /** Signed. */
    amount: Money;
    balance_after: Money;
    generation_id: string | null;
    description: string;
    created_at: Timestamp;
};

/** Page credits/Index — GET /credits (ledger newest first). */
export type CreditsIndexProps = {
    balance: Money;
    available: Money;
    packs: CreditPack[];
    transactions: Paginated<CreditTransactionRow>;
};

/** Body of POST /credits/purchase. `token` is generated per click-intent so a double-click credits once. 422 for an unknown pack. */
export type PurchaseRequest = {
    pack: string;
    token: string;
};
