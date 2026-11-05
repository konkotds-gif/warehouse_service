// Текстовый поиск для селектов: регистр, «ё», пробелы и знаки не важны,
// текст, набранный в неправильной раскладке (рус ↔ лат), тоже находится,
// кириллица сравнивается и по транслиту («тойота» → toyota).

const RU = 'йцукенгшщзхъфывапролджэячсмитьбю.ё' + 'ЙЦУКЕНГШЩЗХЪФЫВАПРОЛДЖЭЯЧСМИТЬБЮ,Ё';
const EN = "qwertyuiop[]asdfghjkl;'zxcvbnm,./`" + 'QWERTYUIOP{}ASDFGHJKL:"ZXCVBNM<>?~';
const toEn = {};
const toRu = {};
for (let i = 0; i < RU.length; i++) {
    toEn[RU[i]] ??= EN[i];
    toRu[EN[i]] ??= RU[i];
}

/** Набрано в русской раскладке, а имелось в виду латиницей: «лшф» → «kia». */
export const ruToEnLayout = (s) => [...String(s)].map((c) => toEn[c] ?? c).join('');
/** Набрано в латинской раскладке, а имелось в виду кириллицей: «kflf» → «лада». */
export const enToRuLayout = (s) => [...String(s)].map((c) => toRu[c] ?? c).join('');

const TR = { а: 'a', б: 'b', в: 'v', г: 'g', д: 'd', е: 'e', ё: 'e', ж: 'zh', з: 'z', и: 'i', й: 'y', к: 'k', л: 'l', м: 'm', н: 'n', о: 'o', п: 'p', р: 'r', с: 's', т: 't', у: 'u', ф: 'f', х: 'h', ц: 'ts', ч: 'ch', ш: 'sh', щ: 'sch', ъ: '', ы: 'y', ь: '', э: 'e', ю: 'yu', я: 'ya' };
/** Транслитерация кириллицы латиницей. */
export const translit = (s) => [...String(s).toLowerCase()].map((c) => TR[c] ?? c).join('');

/** Ключ сравнения: нижний регистр, ё→е, латиница без диакритики (Š→s), только буквы и цифры. */
export const norm = (s) => String(s).toLowerCase().replace(/ё/g, 'е')
    .replace(/[\u00C0-\u024F]/g, (c) => c.normalize('NFD').replace(/\p{M}/gu, ''))
    .replace(/[^\p{L}\p{N}]/gu, '');

const hasCyr = (s) => /[а-яё]/i.test(s);

/** Варианты запроса пользователя: как есть, в другой раскладке и транслитом. */
export function queryVariants(q) {
    const raw = String(q).trim();
    if (!raw) return [];
    const v = new Set([norm(raw), norm(ruToEnLayout(raw)), norm(enToRuLayout(raw))]);
    if (hasCyr(raw)) v.add(norm(translit(raw)));
    v.delete('');
    return [...v];
}

/** Ключи варианта списка: название, алиасы и их транслит. */
export function optionKeys(label, aliases = []) {
    const k = new Set();
    for (const s of [label, ...aliases]) {
        if (!s) continue;
        k.add(norm(s));
        if (hasCyr(s)) k.add(norm(translit(s)));
    }
    k.delete('');
    return [...k];
}

/**
 * Насколько вариант подходит под запрос: 0 — совпало полностью, 1 — начинается с запроса,
 * 2 — содержит запрос, null — не подходит.
 */
export function matchScore(keys, variants) {
    let best = null;
    for (const v of variants) {
        for (const k of keys) {
            const s = k === v ? 0 : k.startsWith(v) ? 1 : k.includes(v) ? 2 : null;
            if (s !== null && (best === null || s < best)) best = s;
            if (best === 0) return 0;
        }
    }
    return best;
}
