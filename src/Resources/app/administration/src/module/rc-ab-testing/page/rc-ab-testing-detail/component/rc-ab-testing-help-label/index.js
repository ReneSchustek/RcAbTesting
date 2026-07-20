import template from './rc-ab-testing-help-label.html.twig';

const { Component } = Shopware;

/**
 * Tabellen-Spaltenkopf mit Erklaerung des Fachbegriffs. Ersetzt sechs identische
 * Slot-Bloecke in der Auswertung.
 *
 * Barrierefreiheit: die Erklaerung haengt an einem echten Button — damit ist sie
 * per Tastatur erreichbar und wird vorgelesen. Ein reines MouseOver-Icon bliebe
 * fuer Tastatur- und Screenreader-Nutzer unsichtbar.
 */
Component.register('rc-ab-testing-help-label', {
    template,

    props: {
        label: {
            type: String,
            required: true,
        },
        help: {
            type: String,
            required: true,
        },
    },
});
