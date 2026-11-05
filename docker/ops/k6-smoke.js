// Progressive load test of the public and token-authenticated read paths. ONLY against a disposable stack (never `ccg_ava`, never real providers):
//   docker run --rm -i -e BASE_URL=http://host.docker.internal:18080 -e AGENT_TOKEN=<token of a TEST chatbot> grafana/k6 run - < docker/ops/k6-smoke.js
// It reads only: login page, public pages, health check and the agent's config. It writes nothing and calls no external service.
import http from 'k6/http';
import { check, sleep } from 'k6';

const base = __ENV.BASE_URL || 'http://127.0.0.1:18080';
const token = __ENV.AGENT_TOKEN || '';

export const options = {
    // Progressive: 5 -> 20 -> 50 virtual users, then back down to see the recovery.
    stages: [
        { duration: '20s', target: 5 },
        { duration: '30s', target: 20 },
        { duration: '30s', target: 50 },
        { duration: '20s', target: 0 },
    ],
    thresholds: {
        http_req_failed: ['rate<0.01'],
        http_req_duration: ['p(95)<1500'],
        'http_req_duration{kind:health}': ['p(95)<300'],
    },
};

export default function () {
    const health = http.get(`${base}/up`, { tags: { kind: 'health' } });
    check(health, { 'health 200': (r) => r.status === 200 });

    const login = http.get(`${base}/pre-login`, { tags: { kind: 'page' } });
    check(login, { 'pre-login 200': (r) => r.status === 200, 'has CSP': (r) => !!r.headers['Content-Security-Policy'] });

    check(http.get(`${base}/registro`, { tags: { kind: 'page' } }), { 'registro 200': (r) => r.status === 200 });
    check(http.get(`${base}/build/manifest.json`, { tags: { kind: 'asset' } }), { 'manifest 200': (r) => r.status === 200 });

    if (token) {
        const config = http.get(`${base}/api/agent/config`, { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' }, tags: { kind: 'agent' } });
        check(config, { 'agent config 200': (r) => r.status === 200 });
    }

    // An invalid token must be refused (401) and, when it is hammered, throttled (429): never a 5xx, never a crash. Both answers are the EXPECTED ones here, not failures.
    const bad = http.get(`${base}/api/agent/config`, { headers: { Authorization: 'Bearer ava_invalid', Accept: 'application/json' }, tags: { kind: 'agent-bad' }, responseCallback: http.expectedStatuses(401, 429) });
    check(bad, { 'bad token refused (401/429)': (r) => r.status === 401 || r.status === 429 });

    sleep(0.5);
}
