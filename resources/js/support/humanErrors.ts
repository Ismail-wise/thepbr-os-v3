type ErrorValue = string | string[] | null | undefined;

export const humanErrorMessages = (
    errors: Record<string, ErrorValue>,
): string[] => {
    const messages = Object.values(errors)
        .flatMap((value) =>
            Array.isArray(value) ? value : [value],
        )
        .filter(
            (value): value is string =>
                typeof value === 'string' && value.trim() !== '',
        )
        .map((value) => value.trim());

    return [...new Set(messages)];
};
