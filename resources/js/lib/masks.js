// Маски ввода: телефон РФ и госномер РФ.

/** Любой ввод (8 912…, +7 912…, 912 345 67 89) → '+7 (912) 345-67-89' по мере набора. */
export function formatPhone(value) {
    let digits = String(value || '').replace(/\D/g, '');
    if (!digits) return '';
    if (digits[0] === '8' || digits[0] === '7') digits = digits.slice(1); // код страны: 8 или 7
    digits = digits.slice(0, 10);
    let out = '+7';
    if (digits.length) out += ' (' + digits.slice(0, 3);
    if (digits.length > 3) out += ') ' + digits.slice(3, 6);
    if (digits.length > 6) out += '-' + digits.slice(6, 8);
    if (digits.length > 8) out += '-' + digits.slice(8, 10);
    return out;
}

export const phoneComplete = (value) => formatPhone(value).replace(/\D/g, '').length === 11;

// Буквы госномера РФ и похожие латинские (набор на английской раскладке)
const PLATE_LETTERS = 'АВЕКМНОРСТУХ';
const LATIN = { A: 'А', B: 'В', E: 'Е', K: 'К', M: 'М', H: 'Н', O: 'О', P: 'Р', C: 'С', T: 'Т', Y: 'У', X: 'Х' };
const toPlateLetter = (ch) => {
    const up = ch.toUpperCase();
    if (PLATE_LETTERS.includes(up)) return up;
    return LATIN[up] || null;
};

/** Госномер РФ формата А123ВС86 / А123ВС186 → 'А123ВС 86'; лишние символы отбрасываются. */
export function formatRuPlate(value) {
    const slots = ['L', 'D', 'D', 'D', 'L', 'L', 'D', 'D', 'D'];
    let out = '';
    let i = 0;
    for (const ch of String(value || '')) {
        if (i >= slots.length) break;
        if (slots[i] === 'L') {
            const l = toPlateLetter(ch);
            if (l) { out += l; i++; }
        } else if (/\d/.test(ch)) {
            if (i === 6) out += ' ';
            out += ch;
            i++;
        }
    }
    return out;
}

export const ruPlateComplete = (value) => /^[АВЕКМНОРСТУХ]\d{3}[АВЕКМНОРСТУХ]{2} \d{2,3}$/.test(formatRuPlate(value));

/** Иностранный/нестандартный номер: верхний регистр, буквы, цифры, пробел и дефис. */
export const formatForeignPlate = (value) => String(value || '').toUpperCase().replace(/[^0-9A-ZА-ЯЁ \-]/g, '').slice(0, 16);

/** Госномер при показе: 'А123ВС86' → 'А123ВС 86' (иностранные — как есть). */
export function displayPlate(plate) {
    if (!plate) return 'без номера';
    const m = String(plate).match(/^([АВЕКМНОРСТУХ]\d{3}[АВЕКМНОРСТУХ]{2})(\d{2,3})$/);
    return m ? `${m[1]} ${m[2]}` : plate;
}
