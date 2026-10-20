/**
 * Einstieg des Verwaltungsteils: meldet die Rechte und das A/B-Test-Modul unter
 * „Marketing" an. Statuswechsel (Start, Pause, Beenden, Archivieren) und die
 * Auswertung laufen über eigene Routen unter `/api/_action/rc-ab-testing/` statt
 * über das Repository, weil der Server dort die Startbedingungen prüft und die
 * Kennzahlen berechnet.
 */

import './acl';
import './module/rc-ab-testing';
import RcAbTestingApiService from './service/rc-ab-testing-api.service';

// Der Dienst-Container der Verwaltung hat kein `httpClient`; die Axios-Instanz des init-Containers spricht `/api` an.
Shopware.Service().register('rcAbTestingApiService', (container) => new RcAbTestingApiService(
    Shopware.Application.getContainer('init').httpClient,
    container.loginService,
));
