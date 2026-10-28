export const DEFAULT_PRIMARY = '#5B4BDB';

function rgb(hex: string): [number, number, number] {
  const h = /^#[0-9a-f]{6}$/i.test(hex) ? hex : DEFAULT_PRIMARY;
  return [0, 2, 4].map((i) => parseInt(h.slice(1 + i, 3 + i), 16)) as [number, number, number];
}

function hex([r, g, b]: number[]): string {
  return '#' + [r, g, b].map((v) => Math.round(Math.max(0, Math.min(255, v))).toString(16).padStart(2, '0')).join('');
}

/** Luminanza relativa WCAG (0 = nero, 1 = bianco). */
export function luminance(color: string): number {
  const [r, g, b] = rgb(color).map((v) => {
    const c = v / 255;
    return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
  });
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/** Testo leggibile sopra il colore indicato. */
export function textOn(color: string): string {
  return luminance(color) > 0.45 ? '#1C1F2E' : '#FFFFFF';
}

/** Mescola due colori: amount 0 = a, 1 = b. */
export function mix(a: string, b: string, amount: number): string {
  const ca = rgb(a);
  const cb = rgb(b);
  return hex(ca.map((v, i) => v + (cb[i] - v) * amount));
}

/** Versione del colore abbastanza scura da leggersi su sfondo chiaro. */
export function readableOnLight(color: string): string {
  let c = color;
  for (let i = 0; i < 6 && luminance(c) > 0.3; i++) c = mix(c, '#000000', 0.2);
  return c;
}
