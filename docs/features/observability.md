# Health and metrics

Stackiq has two endpoints for monitoring: a health check that an uptime probe can call without logging in, and Prometheus metrics for administrators. Both come from OpenRegister's AppHost and answer from the `observability` block in `src/manifest.json`, so a new check or metric is configuration, not code.

## Health: `GET /api/health`

```
GET https://<host>/index.php/apps/stackiq/api/health
```

- Public: no session or app password is needed, so a load balancer or uptime monitor can call it.
- Rate limited to 240 anonymous calls a minute, which is enough for a probe every few seconds.
- The body names the overall status, the app, the installed version and the result of each check:

```json
{
    "status": "ok",
    "app": "stackiq",
    "version": "0.2.13",
    "checks": {
        "database": "ok",
        "openregister": "ok"
    }
}
```

| Check | What it checks |
|-------|----------------|
| `database` | Nextcloud's database answers a query (`SELECT 1`) |
| `openregister` | OpenRegister's object service can be reached; stackiq keeps all its data there |

A failed check reads `failed` or `failed: <reason>` instead of `ok`, for example `failed: openregister unavailable`. The reason never carries internal error text; that goes to the Nextcloud log.

### Status codes

The manifest sets `statusCodePolicy` to `adr006`:

| Overall status | When | HTTP code |
|----------------|------|-----------|
| `ok` | Every check passed | 200 |
| `degraded` | Only a check marked `degraded` failed | 200 |
| `error` | A critical check failed (both checks are critical) | 503 |

A probe only has to look at the HTTP code: 503 means stackiq cannot serve its users.

Example probe:

```bash
curl -fsS https://<host>/index.php/apps/stackiq/api/health || echo "stackiq is down"
```

## Metrics: `GET /api/metrics`

```
GET https://<host>/index.php/apps/stackiq/api/metrics
```

- Administrators only. Anyone else gets the Nextcloud login redirect or a 401, never metric data.
- The response is Prometheus text exposition format 0.0.4 (`Content-Type: text/plain; version=0.0.4; charset=utf-8`).
- Every metric name starts with `stackiq_`.

| Metric | Type | Meaning |
|--------|------|---------|
| `stackiq_info` | gauge | Always 1; its labels carry the app version, the PHP version and the Nextcloud version |
| `stackiq_up` | gauge | 1 while the app answers |
| `stackiq_gebruik_total` | gauge | The number of usage records in the `stackiq` register (schema `usage`) |

Example scrape configuration, with an app password of an administrator account:

```yaml
scrape_configs:
  - job_name: stackiq
    scheme: https
    metrics_path: /index.php/apps/stackiq/api/metrics
    basic_auth:
      username: monitoring-admin
      password: <app password>
    static_configs:
      - targets: ['<host>']
```

## The manifest is the source of truth

The checks and metrics are listed in the `observability` block of `src/manifest.json`:

```json
"observability": {
    "health": {
        "statusCodePolicy": "adr006",
        "checks": [
            { "id": "database", "type": "database" },
            { "id": "openregister", "type": "orAvailable" }
        ]
    },
    "metrics": [
        {
            "name": "gebruik_total",
            "type": "gauge",
            "help": "Gebruik (usage) records",
            "source": { "kind": "objectCount", "register": "stackiq", "schema": "usage" }
        }
    ]
}
```

To add a check or a metric, add an entry here. `stackiq_info` and `stackiq_up` are always there and are not listed.

## `/api/settings/status` is not a health check

`GET /api/settings/status` says whether stackiq's settings are configured (`fullyConfigured`, `autoConfigCompleted`, the version information). It needs a login and answers 200 even when OpenRegister is down, so a monitor must not use it as a health check. Use `/api/health` instead.
