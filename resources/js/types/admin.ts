import type { Money } from './money';

/** A UTC calendar date, YYYY-MM-DD. */
export type IsoDate = string;

export type ReportTotals = {
    generations: number;
    /** What Pixelforge billed, by our records. */
    provider_cost: Money;
    /** What users were charged. */
    credit_spend: Money;
    /** credit_spend − provider_cost */
    profit: Money;
};

/** One UTC day of completed generations (grouped by completed_at, like GET /v1/usage). */
export type ReportDay = ReportTotals & {
    date: IsoDate;
    /** cost_usd from /v1/usage; null when usage could not be fetched. */
    pixelforge_cost: Money | null;
    /** provider_cost − pixelforge_cost; null when unknown. */
    difference: Money | null;
    mismatch: boolean;
};

export type ReportUser = ReportTotals & {
    user_id: number | null;
    name: string;
    email: string;
};

/** Page admin/Report — GET /admin/report?start=YYYY-MM-DD&end=YYYY-MM-DD (admins only; 403 otherwise). */
export type AdminReportProps = {
    filters: { start: IsoDate; end: IsoDate };
    days: ReportDay[];
    users: ReportUser[];
    totals: ReportTotals & {
        pixelforge_cost: Money | null;
        difference: Money | null;
    };
    /** Set when /v1/usage failed; the report still shows our own numbers. */
    usage_error: string | null;
};
