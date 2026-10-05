/**
 * Workspace branding helpers. The primary color is the only part of the palette a Workspace can change:
 * everything else (surfaces, text, status colors, typography) stays the Ava design system.
 */

const hexToRgb = (hex) => [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16));

const toHex = (rgb) => `#${rgb.map((value) => Math.round(value).toString(16).padStart(2, '0')).join('')}`;

/** Mixes color `a` with color `b`; `t` is the share of `b` (0..1). */
export const mix = (a, b, t) => {
    const [from, to] = [hexToRgb(a), hexToRgb(b)];

    return toHex(from.map((value, i) => value * (1 - t) + to[i] * t));
};

/** WCAG contrast ratio between white text and the given #RRGGBB background. */
export const contrastWithWhite = (hex) => {
    const [r, g, b] = hexToRgb(hex).map((channel) => {
        const value = channel / 255;

        return value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
    });

    return 1.05 / (0.2126 * r + 0.7152 * g + 0.0722 * b + 0.05);
};

export const isHexColor = (value) => /^#[0-9a-fA-F]{6}$/.test(value);

/** CSS variables that re-tint the Ava primary color (dark mode lifts it so it stays readable on dark surfaces). */
export const brandVariables = (hex, dark = false) => {
    const primary = dark ? mix(hex, '#ffffff', 0.25) : hex;

    return {
        '--color-primary': primary,
        '--color-primary-strong': mix(primary, '#000000', 0.18),
        '--color-primary-soft': dark ? mix(primary, '#0b1220', 0.78) : mix(primary, '#ffffff', 0.88),
    };
};
