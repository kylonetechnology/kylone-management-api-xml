# Kylone XML API v2 — Hospitality Addendum

Revision 2026-09-19. Read `kylone-xml-api-v2-guide.md` first; this addendum covers the
functions a hospitality integration uses when the headend serves third-party devices:
hotel TVs with an HTML5 or native player, OTT-style players, media players on the
property network. Headends running Kylone's own client software have a separate
addendum (`kylone-xml-api-v2-cpe.md`).

Most of what a hospitality middleware needs from the headend is not an API call at all:
channel lists, the programme guide and the manifest are published as files under
`/feeds/` (see the Hospitality Integration Guide on kylone.com). The API is for the
management side: which players may watch, how the HLS outputs are encoded and
encrypted, and where the streams are.

## 1. Media players (`peers`)

Devices that are not Kylone clients are authorised by address range. A record is a
range with a state; the headend's peer policy grants streams, and the HLS key endpoint
grants decryption keys, to addresses inside an active range.

```
php apicall-v2.php 192.0.2.1 $K $S peers
php apicall-v2.php 192.0.2.1 $K $S peers "act=add&addrf=10.20.0.1&addrl=10.20.3.254&name=Guest rooms&ena=1"
php apicall-v2.php 192.0.2.1 $K $S peers "act=save&key=10.20.0.1&ena=0"
php apicall-v2.php 192.0.2.1 $K $S peers "act=del&key=10.20.0.1"
```

| field | meaning |
|---|---|
| `addrf`, `addrl` | first and last address of the range; `addrf` is the record key |
| `name`, `descr` | label and notes |
| `ena` | `1` active, `0` disabled |
| `uage`, `fseen`, `lseen` | read-only: the application seen from that range, first and last contact |

Ranges created by auto-discovery (a device that showed up while unknown devices were
allowed) look the same and can be edited or disabled here.

## 2. HLS output profiles (`output`) and encryption

An HLS output profile decides codec, segmentation, audio publishing mode, subtitle
tracks and encryption. Profiles are module argument sets, so their fields are named
`item<N>`; the fieldmap titles identify them (for example "Encryption", "Chunk Duration",
"Audio Tracks", "Text Subtitles"). Read the view once, note the names, then save:

```
php apicall-v2.php 192.0.2.1 $K $S output
php apicall-v2.php 192.0.2.1 $K $S output "act=view&key=Hospitality"
php apicall-v2.php 192.0.2.1 $K $S output "act=save&key=Hospitality&item43=aes128"
```

`aes128` turns on AES-128 encryption with keys served by the headend itself (no DRM
contract). Encrypted outputs need TS segments; fMP4 profiles refuse it. Apply Changes
restarts the affected outputs.

Keys are handed to players the peer policy allows (section 1) or to applications that
present an HLS API key. HLS keys are ordinary API-key records with Use = `hls`; they are
separate from management keys and can be rotated without touching a client:

```
php apicall-v2.php 192.0.2.1 $K $S apikeys "act=add&name=lobby-app&dname=Lobby signage app&active=1&apuse=hls"
```

The secret is read from the web UI (Management > API Keys) and passed by the player as
the `t` parameter of the key URI. Key rotation runs on the headend's schedule or from
the channel page; it is not an API call.

## 3. Assigning profiles to channels

Which channels publish HLS, with which profile, is a per-channel setting:

```
php apicall-v2.php 192.0.2.1 $K $S channels "act=save&key=DEMO11794_t10601&outpr=Hospitality&active=1"
php apicall-v2.php 192.0.2.1 $K $S commit "act=res,last&rsrv=sync"
```

`outpr` names the HLS output profile, `tcpro` the post-processing (transcoding) profile
and `prepr` the pre-processing profile (captions, for example). The same fields exist on
every input kind: DVB programs (`channels`), HTTP/HLS inputs (`httpget`, `m3u`), RTSP and
SRT inputs (`rtspc`, `srtc`), unicast and multicast inputs (`unicast`, `mulcast`), files
(`localfiles`) and HDMI/SDI captures (`hdmiport`, `sdiport`).

## 4. Delivery settings

Unicast delivery over HTTP-TS, SRT and RTSP, TLS on the HTTP-TS port, ports and
interfaces are in Live Stream Advanced Settings (`advconf`, section 8.4 of the guide).
The stream URLs of every channel, per tier and protocol, are on the Stream Access URLs
page of the web UI and in the `/feeds/` tree.

## 5. Programme guide

The XMLTV feed under `/feeds/` is the integration path. `strepg` shows the guide the
headend currently holds, `xepg` manages third-party EPG sources.

## 6. What the API does not do

- Serve streams or keys: those are the streaming engine's and the key endpoint's job.
- Return the secret of an API key.
- Drive third-party TVs: this API manages the headend; the TV application talks to the
  feeds and to the streams.
