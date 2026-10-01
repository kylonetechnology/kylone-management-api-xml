# Kylone XML API

Management API of Kylone headends: read and change the configuration, apply it, act on
the system and on the devices, from any language that can POST a form and compute an
HMAC.

| document | audience |
|---|---|
| `kylone-xml-api-v2-guide.md` | API v2: authentication, envelope, fields by name, resources, examples |
| `kylone-xml-api-v2-hospitality.md` | v2 addendum: media players, HLS profiles and encryption, feeds |
| `kylone-xml-api-v2-cpe.md` | v2 addendum: Kylone set-top boxes, TV sets, mobile devices, messages |
| `kylone-xml-api-v2-blindscan.md` | v2 addendum: satellite blind scan tasks, runs, reports and diffs, schedule |
| `kylone-xml-api.pdf` | API v1 (2018 guide): password login, positional fields; unchanged |
| `kylone-xml-api-v1-examples.md` | v1 usage examples, refreshed 2026 |
| `apicall-v2.php` | reference client for v2 (PHP, curl) |
| `apicall-v1.php` | reference client for v1 |
| `metrics-get.php` | reads the metrics endpoint (Prometheus text, HTTP basic auth with a read-only key) |
| `fieldmap-dump.php` | dumps every page's field map of a headend and diffs two dumps (the fieldmap changelog between releases) |

Version 2 is the recommended API for new integrations (available from headend v4.1.1;
the read-only key role, the `version` function, the realtime functions and the metrics
endpoint from v4.2.0).
Version 1 keeps working for existing ones.
