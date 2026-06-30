// Детерминированный цвет «плашки» по строке (имя роли/тега): стабильный хэш
// имени → индекс в палитре. Единый источник цвета на все страницы, чтобы одна
// роль красилась одинаково везде. Презентационная утилита уровня приложения
// (не DS — дизайн-система остаётся locale/domain-agnostic).
const PALETTE = [
    "#6f76f5", // indigo (DS «Владелец»)
    "#2f6bff", // blue
    "#27a567", // green
    "#e0a23b", // amber (DS «Редактор»)
    "#8a90a2", // gray (DS «Наблюдатель»)
    "#d6516b", // rose
    "#3aa6b9", // teal
    "#9b5de5", // violet
];

export function swatchColor(name) {
    const str = String(name ?? "");
    let hash = 0;
    for (let i = 0; i < str.length; i++) {
        hash = (hash * 31 + str.charCodeAt(i)) >>> 0;
    }
    return PALETTE[hash % PALETTE.length];
}
