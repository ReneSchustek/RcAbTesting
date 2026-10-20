/**
 * Die eigenen Routen unter `/api/_action/rc-ab-testing/`: Statuswechsel, Auswertung und die Liste der
 * Frontend-Schalter. Als `ApiService` bringt er Anmeldung und Kopfzeilen des Kerns mit, statt sie in der Seite von Hand
 * zu setzen.
 */
const { ApiService } = Shopware.Classes;

export default class RcAbTestingApiService extends ApiService {
    constructor(httpClient, loginService) {
        super(httpClient, loginService, '_action/rc-ab-testing', 'application/json');
        this.name = 'rcAbTestingApiService';
    }

    frontendSwitches() {
        return this.httpClient
            .get(this.getApiBasePath('frontend-switches'), { headers: this.getBasicHeaders() })
            .then((response) => response.data);
    }

    /** `action` ist `start`, `pause`, `end` oder `archive`; nur `end` nimmt einen Gewinner im Körper an. */
    transition(experimentId, action, body = {}) {
        return this.httpClient
            .post(this.getApiBasePath(`experiment/${experimentId}/${action}`), body, { headers: this.getBasicHeaders() })
            .then((response) => response.data);
    }

    stats(experimentId) {
        return this.httpClient
            .get(this.getApiBasePath(`experiment/${experimentId}/stats`), { headers: this.getBasicHeaders() })
            .then((response) => response.data);
    }
}
