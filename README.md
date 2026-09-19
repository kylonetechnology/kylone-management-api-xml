# Kylone XML API

Kylone headends expose their management functions through an XML API: everything the
web UI does with content, streams, delivery, devices and the underlying system can be
done by another application. The API is a web service over HTTPS: a POST request carries
an XML document, the answer is an XML document. Any language with an HTTP client can use
it.

Two versions of the API are served side by side:

- **API v2** (headends from v4.1.1): API-key authentication with signed requests, fields
  addressed by name, discoverable page actions. Recommended for new integrations.
- **API v1** (headends from MicroCMS v2.0.64): password login with a session cookie,
  fields addressed by position. Unchanged, for existing integrations.

## Documentation

| document | content |
|---|---|
| `doc/kylone-xml-api-v2-guide.md` | API v2: transport, authentication, envelope, working model, functions and actions, resources, failures, examples, migration from v1 |
| `doc/kylone-xml-api-v2-hospitality.md` | v2 addendum for headends serving third-party TVs and players: media player ranges, HLS output profiles and encryption, feeds |
| `doc/kylone-xml-api-v2-cpe.md` | v2 addendum for headends with Kylone's own client software: set-top boxes, TV sets, mobile devices, messages, service packages |
| `doc/kylone-xml-api-v1.pdf` | API v1 usage guide (2018) |
| `doc/kylone-xml-api-v1-examples.md` | API v1 usage examples, refreshed in 2026 |

## Reference clients

Two command-line clients written in PHP (7.2 or later, with the curl extension) show the
complete request flow and are enough for testing every function from a shell:

```
php apicall-v2.php <host> <key name> <key secret> <function> [arguments] [export name]
php apicall-v1.php <host> <username> <password> <function> [arguments] [export name]
```

Both print the request and the response, or only `ok`/`failed` with `CLQUITE=yes`, and
exit with status 0 when the call succeeded. `CLINSECURE=yes` accepts a self-signed
certificate. The v2 client uses HTTPS by default; the v1 client uses plain HTTP for a
bare host name and takes a full `https://` URL for a headend on TLS.

## Quick start (API v2)

1. On the headend, create a key: **Management > API Keys > New**, Use = *Management
   API v2*, API Rights = *Admin*. Copy the key name and the secret.
2. List the functions the key may call:

   ```
   php apicall-v2.php 192.0.2.1 mgmt1 <secret> apicalls
   ```

3. Read a page, then change it:

   ```
   php apicall-v2.php 192.0.2.1 mgmt1 <secret> network "act=view&key=lan1"
   php apicall-v2.php 192.0.2.1 mgmt1 <secret> network "act=save&key=lan1&smtu=1500"
   ```

The guide explains the response, the field names and when a change needs Apply Changes.

## Quick start (API v1)

```
php apicall-v1.php 192.0.2.1 admin <password> cpustat
```

The 2018 guide describes the login flow and the positional field mapping.
