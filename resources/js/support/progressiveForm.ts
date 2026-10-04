export type ProgressiveVisibility<T extends Record<string, unknown>> =
    Partial<Record<keyof T, boolean>>;

/**
 * Build a submission payload that excludes fields currently hidden by the
 * guided journey. The original source object is never mutated, so upstream
 * answers may hide a dependent field without silently deleting its draft value.
 */
export const visibleProgressivePayload = <
    T extends Record<string, unknown>,
>(
    source: T,
    visibility: ProgressiveVisibility<T>,
): Partial<T> => {
    const payload: Partial<T> = {};

    for (const key of Object.keys(source) as Array<keyof T>) {
        if (visibility[key] === false) {
            continue;
        }

        payload[key] = source[key];
    }

    return payload;
};
