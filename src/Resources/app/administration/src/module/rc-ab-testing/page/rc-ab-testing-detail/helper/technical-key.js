/**
 * Technische Schlüssel aus dem Namen eines Experiments. Die Spalte fasst 64 Zeichen und ist
 * eindeutig. Ohne Kürzung und ohne Blick auf die vorhandenen Schlüssel scheiterte ein langer oder
 * doppelter Name erst beim Speichern an der Datenbank.
 */

export const MAX_LENGTH = 64;

/**
 * Nur a bis z und Ziffern bleiben stehen; Umlaute und ß fallen dabei weg, statt umschrieben zu
 * werden. Gekürzt wird auf `maxLength`, ohne Bindestrich am Ende.
 */
export function slugify(text, maxLength = MAX_LENGTH) {
    return (text || '')
        .toString()
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, maxLength)
        .replace(/-+$/g, '');
}

/**
 * Hängt `-2`, `-3` … an, bis der Schlüssel in `takenKeys` nicht vorkommt. Der Grundschlüssel wird
 * dafür so weit gekürzt, dass auch mit Zusatz höchstens `MAX_LENGTH` Zeichen herauskommen.
 */
export function uniqueKey(base, takenKeys) {
    const taken = new Set(takenKeys);
    if (!taken.has(base)) {
        return base;
    }

    for (let counter = 2; ; counter += 1) {
        const suffix = `-${counter}`;
        const candidate = `${base.slice(0, MAX_LENGTH - suffix.length).replace(/-+$/g, '')}${suffix}`;
        if (!taken.has(candidate)) {
            return candidate;
        }
    }
}
