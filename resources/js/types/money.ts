/**
 * An exact US-dollar amount as the server sends it: a decimal string with
 * nine decimal places, e.g. "0.046125000" (App\Support\Money::toDecimal()).
 * Never parse it into a float for arithmetic; format it for display only.
 */
export type Money = string;

/** An ISO 8601 timestamp, e.g. "2026-11-04T14:03:11.000000Z". */
export type Timestamp = string;

/** Laravel's paginated JsonResource collection (`Resource::collection($paginator)`). */
export type Paginated<T> = {
    data: T[];
    links: {
        first: string | null;
        last: string | null;
        prev: string | null;
        next: string | null;
    };
    meta: {
        current_page: number;
        from: number | null;
        last_page: number;
        path: string;
        per_page: number;
        to: number | null;
        total: number;
        links: { url: string | null; label: string; active: boolean }[];
    };
};

/** A choice for a select input. */
export type Option<T extends string> = {
    value: T;
    label: string;
};
