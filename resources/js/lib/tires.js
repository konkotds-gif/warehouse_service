// Справочники и разбор размера/бренда шин для селектов.

const range = (from, to, step) => Array.from({ length: Math.floor((to - from) / step) + 1 }, (_, i) => String(from + i * step));

export const WIDTHS = range(135, 355, 10);
export const PROFILES = range(25, 85, 5);
export const DIAMETERS = [...range(12, 24, 1), '14C', '15C', '16C', '17C'];

const SIZE_RE = /^(\d{3})\s*\/\s*(\d{2})\s*Z?R\s*(\d{2}C?)(?:\s+(\S.*))?$/i;
export const INDEX_RE = /^\d{2,3}(\/\d{2,3})?\s*[A-Z]$/i;

/** '205/55 R16 94H' → { width: '205', profile: '55', diameter: '16', index: '94H' } или null, если не раскладывается на селекты. */
export function parseTireSize(value) {
    const m = String(value || '').trim().toUpperCase().match(SIZE_RE);
    if (!m || !WIDTHS.includes(m[1]) || !PROFILES.includes(m[2]) || !DIAMETERS.includes(m[3])) return null;
    return { width: m[1], profile: m[2], diameter: m[3], index: (m[4] || '').replace(/\s+/g, '') };
}

/** Собирает полный размер; пустая строка, пока не выбраны ширина, профиль и диаметр. */
export function buildTireSize({ width, profile, diameter, index }) {
    if (!width || !profile || !diameter) return '';
    const idx = String(index || '').trim().toUpperCase().replace(/\s+/g, '');
    return `${width}/${profile} R${diameter}${idx ? ' ' + idx : ''}`;
}

/** 'Nokian Tyres (Ikon Tyres) Hakkapeliitta 10' → { brand, model } по справочнику (самое длинное совпадение). */
export function splitBrandModel(value, brands) {
    const v = String(value || '').trim();
    if (!v) return { brand: '', model: '' };
    const low = v.toLowerCase();
    const hit = [...brands].sort((a, b) => b.length - a.length)
        .find((b) => low === b.toLowerCase() || low.startsWith(b.toLowerCase() + ' '));
    if (hit) return { brand: hit, model: v.slice(hit.length).trim() };
    const [first, ...rest] = v.split(/\s+/);
    return { brand: first, model: rest.join(' ') };
}

export const joinBrandModel = (brand, model) => [brand, model].map((x) => String(x || '').trim()).filter(Boolean).join(' ');
