import template from './rc-ab-testing-help-label.html.twig';

const { Component } = Shopware;

/**
 * Tabellen-Spaltenkopf mit Erklärung des Fachbegriffs, gemeinsam genutzt von allen
 * erklärungsbedürftigen Spalten der Übersicht.
 *
 * Die Erklärung hängt an einem echten Button und ist damit per Tastatur erreichbar
 * und vorlesbar. Ein reines Hover-Icon bliebe für Tastatur- und Screenreader-Nutzer
 * unsichtbar.
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
