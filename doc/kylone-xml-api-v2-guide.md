# Kylone XML API v2 — Management API Guide

Revision 2026-09-19. Applies to Kylone headends from v4.1.1.

The XML API lets an integrator or an operator's own tooling do what the web UI does:
read and change the configuration, apply it, act on the system. Version 2 keeps the
XML envelope of the 2018 API and changes three things:

- **API-key authentication.** Every request signs itself with an API key (HMAC-SHA256).
  There is no login, no session cookie, no password on the wire.
- **Fields by name.** Fields, options and records are addressed by their internal names
  (the configuration database names), not by position (`k4`). Names survive UI changes.
- **Discoverable resources.** Every action button of a page ("Apply Changes", "Reboot",
  "Configure Programs") is listed with its key and, where declared, its arguments.

Version 1 (the 2018 guide, positional `k` indices, password login) keeps working
unchanged for existing integrations. A request without a version marker is a v1 request.

Companion documents: `kylone-xml-api-v2-hospitality.md` (media players, feeds, HLS
encryption for headends serving third-party TVs and players) and
`kylone-xml-api-v2-cpe.md` (set-top boxes, TV sets and mobile devices running Kylone's
own client software). Reference clients: `apicall-v2.php` (this API) and `apicall-v1.php`.

## 1. Transport

One endpoint, HTTPS only:

```
POST https://<headend>/portal/
Content-Type: multipart/form-data  (or application/x-www-form-urlencoded)

xml     the request document (section 3)
akey    API key name
ats     Unix time on the client
anonce  16 to 64 hexadecimal characters, unique per request
asig    request signature (section 2)
```

The answer is an XML document with `Content-Type: text/xml`. Job failures come back as
HTTP 200 with `<status>failed</status>`; credential problems come back as HTTP 401 or
403 with the same XML body, so a client can tell them apart without parsing.

Plain HTTP is refused (`https-required`). A headend used in a lab can allow it by creating
the file `var/apiplain` under the portal directory.

## 2. Authentication

Create the key in the web UI: **Management > API Keys > New**, Use = *Management API v2*,
API Rights = *Admin* or *Operator*. The page shows the key name and a 52-character secret.
Regenerate the secret at any time from the same page ("Regenerate Key"); the old one stops
working immediately.

A management key acts with the rights of a normal admin or operator user. It can never
act as the super administrator. Keys can be disabled without deleting them, and
"Last Used" on the key page shows the last successful call.

**Signature.** Build one string from five lines joined by `\n` and sign it with
HMAC-SHA256, the secret taken exactly as shown (ASCII), output as lowercase hex:

```
kylone-api-v2
<akey>
<ats>
<anonce>
<sha256 hex of the xml field, byte for byte as sent>
```

Signing the `xml` field as a string means no XML canonicalisation and no dependency on
how the form is encoded. PHP reference:

```php
$ts = (string)time();
$nonce = bin2hex(random_bytes(8));
$msg = "kylone-api-v2\n".$keyname."\n".$ts."\n".$nonce."\n".hash("sha256", $doc);
$sig = hash_hmac("sha256", $msg, $secret);
```

The headend accepts a request when the signature matches, the timestamp is within
300 seconds of its own clock and the nonce has not been used by that key inside that
window. A refused request names the reason (section 7). An `expired` refusal carries the
headend's time in the data element so a client can resync its clock and retry once.

Failed and refused calls are logged on the headend. Every call that changes something
(save, add, delete, resource) writes one line to the system log with the key name, so
the operator sees API activity next to web UI activity.

## 3. Request and response envelope

Request (the `xml` field):

```xml
<?xml version="1.0"?>
<cLst>
  <container>
    <operation>
      <type>request</type>
      <version>2</version>
    </operation>
    <data model="struct">
      <request>network</request>
      <args>
        <atr name="act">view</atr>
        <atr name="key">lan1</atr>
      </args>
    </data>
  </container>
</cLst>
```

`request` is the function name (section 5), `args` the arguments. `version` 2 selects
this contract; leave it out and the request is handled as v1.

Response:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<cLst><container>
  <operation>
    <type>response</type>
    <status>ok</status>
    <version>2</version>
    <fieldmap>
      <atr name="_sec0" k="0" must="false" ro="true" type="section">Interface</atr>
      <atr name="name" k="1" must="false" ro="true" type="str">Name</atr>
      <atr name="sipa" k="5" must="true" ro="false" type="inetaddr">IP Address</atr>
      <atr name="smas" k="4" must="false" ro="false" type="option">Group</atr>
      <atr name="commit" k="15" must="false" ro="true" type="res">Commit Changes</atr>
      ...
    </fieldmap>
    <keyfield>name</keyfield>
    <options>
      <opt name="smas"><atr val="-">None</atr><atr val="bond0">bond0</atr></opt>
    </options>
    <resources>
      <res name="commit" mode="res" k="15" title="Commit Changes"></res>
    </resources>
  </operation>
  <data model="tree">
    <elm><atr n="name">lan1</atr><atr n="sipa">192.0.2.10</atr>...</elm>
  </data>
</container></cLst>
```

- `fieldmap` describes every field of the page: its name, whether it must be given on
  save or add (`must`), whether it is read-only (`ro`), and its type. `k` is the v1
  position of the same field, kept for one release so a v1 integration can map its
  indices to names.
- `keyfield` names the field that identifies a record (the `key` argument).
- `options` lists the allowed values of every `option` field: send the `val`, not the
  label.
- `resources` lists the page's actions (section 6).
- `data` carries the records: `model="tree"` for lists and views (one `elm` per record,
  attributes named by field), `model="text"` for messages and reports.

Field types: `str`, `longtext`, `bigtext`, `bool` (`true`/`false`), `option`, `range`
(integer within the UI's bounds), `timestamp` (read-only, formatted), `image` (base64
data URI), `color`, and address types such as `inetaddr`, `inetmask`, `inetport`,
`inetn`, `pidlist`. Any type not listed here is sent as a string. `section` entries are
headings and cannot be written; their names start with `_sec`.

## 4. Working model

The API has one kind of request. `request` names a function, and a function is a page
of the web UI: the same records, the same fields, the same buttons, the same
permissions. Whatever an admin can do on that page, a management key with admin rights
can do through the function; whatever the page refuses, the function refuses. Arguments
travel in the `args` block of the document; arguments in the URL are ignored, and the
four key fields are the only other form fields the headend reads.

**Two kinds of pages.** A function answers either with a list of objects or with a
single entity, and the response tells which:

- A **list of objects** has a `keyfield` in the operation block. Records are searched,
  viewed, changed, added and deleted by calling the same function with `act` and, for one
  record, `key` set to that record's key field value. Network interfaces, transponders,
  DVB programs and API keys are lists.
- A **single entity** has no `keyfield` and one record in the data. It exists once and
  cannot be created or deleted; it is viewed and saved without `key`, and its resources
  are called with `sres`. Live Stream Advanced Settings, Commit and the messaging pages
  are single entities.

**The cycle.** An integration works the way an operator works:

1. Discover: `apicalls` for the functions, then the function itself for its records.
2. Inquire: `act=view&key=<k>` (or the bare function for a single entity) to get the
   fieldmap, the options and the current values.
3. Act: `save`, `add`, `del`, or a resource.
4. Apply: streaming changes wait for Apply Changes, network changes for the interface's
   Commit Changes resource; other pages take effect on save.
5. Verify: view again. What comes back is what the headend holds.

**Field properties.** The fieldmap is the contract of a page. Use its `name` values as
argument names. `must="true"` fields have to be given on `add`, and an empty value for
one of them on `save` is ignored rather than stored; `ro="true"` fields are not written
by `save` (some, such as the record name, are set once on `add`); `type` says how to read
and write the value. Fields of a form can be saved one at a time: a `save` with two
arguments changes two fields and leaves the rest.

**Options.** A field of type `option` takes one of the values listed for it under
`options`. Send the `val`; the label is for display. For a field with a fixed list a
value outside it is refused (`bad-arg`); for a list drawn from other records (a package,
a profile, an interface) the value is stored as given, so take it from the list.

**Dynamic values.** Fields of type `res` and `sres` are not stored values: their content
is gathered when the page is built (the current date and clock on the System Date/Time
page, the engine status on the Tools page) and they are also the page's actions. Their
value in the data is what the web UI would show in that spot; calling them with
`act=res,<name>` runs the action (section 6).

**Edit and view.** The web UI distinguishes viewing from editing a record. For the API
they are the same request: `act=view` returns everything a `save` needs. `act=edit` is
accepted and answers the same way.

## 5. Functions and actions

`apicalls` returns every function the key may call, with its title. Call a function
without arguments to get the list of its records (or the record itself for pages that
hold one record, such as Live Stream Advanced Settings).

| Argument | Meaning |
|---|---|
| `act=view&key=<k>` | one record, full fieldmap, options and resources |
| `act=save&key=<k>&<field>=<v>...` | change the given fields of a record (partial saves are fine) |
| `act=add&<field>=<v>...` | create a record; the key field and every `must` field are required |
| `act=del&key=<k>` | delete a record |
| `act=res,<resource>&key=<k>&<arg>=<v>...` | run a record's action (section 6) |
| `act=sres,<resource>&<arg>=<v>...` | run a page-level action, no record |
| `srt=<field>&dir=asc|desc` | sort a list |
| `dsb=<field>&src=<text>` | search a list on one field |

Saves and adds take effect in the configuration database only. Streaming changes need
**Apply Changes** afterwards (section 8.3); network changes need the interface's
**Commit Changes** resource (section 8.2). This mirrors the web UI exactly.

## 6. Resources

A resource is an action of a page. The `resources` block of a view response lists them:

```xml
<res name="last" mode="sres" k="1" title="Apply Changes">
  <arg name="rsrv" must="false" type="option" default="noop">Streaming Engine
    <opt val="noop">Keep running</opt><opt val="sync">Update</opt><opt val="kill">Restart</opt>
  </arg>
  <arg name="rstb" must="false" type="bool" default="false">Restart set-top boxes</arg>
</res>
<res name="summary" mode="res" k="19" declared="false" title="Configuration Summary"/>
```

- `name` is what `act=res,<name>` takes. `mode="res"` needs `key`, `mode="sres"` does not.
- Declared resources list their arguments with the fieldmap vocabulary; the headend
  validates them before running the action (`missing-arg`, `bad-arg`) and applies the
  defaults.
- `declared="false"` means the resource takes no documented arguments yet; call it with
  the key alone. Resources whose behaviour depends on form fields (a message to send, for
  example) read the record: save the fields first, then call the resource.
- A resource returns `text` data when it produces a report, otherwise the record view.

## 7. Failures

```xml
<operation><type>response</type><status>failed</status><version>2</version>
<reason>bad-arg</reason></operation>
<data model="text">argument 'rsrv' of resource 'last' does not accept 'bogus'</data>
```

| reason | HTTP | meaning |
|---|---|---|
| `auth-required` | 401 | no key fields in the request |
| `bad-request` | 400 | malformed key fields |
| `bad-signature` | 401 | wrong secret or unknown key (deliberately the same answer) |
| `inactive-key` | 401 | the key is disabled |
| `not-allowed` | 403 | the key's Use is not Management API, or the function is not available to keys |
| `expired` | 401 | timestamp outside the 300-second window; the data element carries the server time |
| `replayed` | 401 | nonce already used inside the window |
| `https-required` | 403 | plain HTTP |
| `unknown-target` | 404 | `res,<name>` or `get,<name>` names nothing on the page |
| `missing-arg`, `bad-arg` | 400 | a declared resource argument is absent or out of range, or an option field is given a value outside its fixed list |
| (none) | 200 | the function itself failed: permission, unknown record, validation; the data element says why |

## 8. Examples

All examples use the reference client. Replace the host, key name and secret:

```
export CLINSECURE=yes          # only for a headend with a self-signed certificate
K=mgmt1; S=<secret>
php apicall-v2.php 192.0.2.1 $K $S <function> "<arguments>"
```

The client prints the HTTP status, the API status and the reason, then the request and
the response. `CLQUITE=yes` prints only `ok` or `failed (reason)`.

### 8.1 Discover

```
php apicall-v2.php 192.0.2.1 $K $S apicalls
php apicall-v2.php 192.0.2.1 $K $S network
php apicall-v2.php 192.0.2.1 $K $S network "act=view&key=lan1"
```

A list answer carries the list's columns in the fieldmap and one `elm` per record:

```xml
<operation><type>response</type><status>ok</status><version>2</version>
  <fieldmap>
    <atr name="name" k="1" type="str">Name</atr>
    <atr name="supd" k="2" type="str">Default</atr>
    <atr name="mac" k="3" type="str">MAC Address</atr>
    <atr name="sipa" k="4" type="str">IP Address</atr>
    ...
  </fieldmap>
  <keyfield>name</keyfield>
</operation>
<data model="tree">
  <elm><atr n="name">lan1</atr><atr n="supd"></atr><atr n="mac">00:1b:21:00:00:01</atr><atr n="sipa">0.0.0.0</atr>...</elm>
  <elm><atr n="name">lan2</atr><atr n="supd">*</atr><atr n="mac">00:1b:21:00:00:02</atr><atr n="sipa">192.0.2.1</atr>...</elm>
</data>
```

List columns are display values (a state shows as "Active", not `1`); the view of a
record gives the raw values and the options to write back.

### 8.2 Network interface

Address, netmask, gateway, DNS, MTU, admin state; then commit that interface:

```
php apicall-v2.php 192.0.2.1 $K $S network "act=save&key=lan2&smas=-&sipa=10.0.0.1&snms=255.255.255.0&sdgw=0.0.0.0&smet=10&sdna=0.0.0.0&sdnb=0.0.0.0&smtu=1500&sigv=v2&srpf=0&sadm=UP"
php apicall-v2.php 192.0.2.1 $K $S network "act=res,commit&key=lan2"
```

`smas` is the bond group (`-` for none), `sigv` the IGMP version, `srpf` the reverse path
filter, `sadm` the admin status (`UP`/`DOWN`). The commit resource appears in the view only
while the interface has uncommitted changes.

### 8.3 Apply changes (streaming configuration)

Every save on a streaming page waits for Apply Changes. The engine argument decides how
the streaming engine takes the new configuration:

```
php apicall-v2.php 192.0.2.1 $K $S commit "act=res,last"                       # config only
php apicall-v2.php 192.0.2.1 $K $S commit "act=res,last&rsrv=sync"             # update the engine
php apicall-v2.php 192.0.2.1 $K $S commit "act=res,last&rsrv=kill"             # restart the engine
php apicall-v2.php 192.0.2.1 $K $S commit "act=res,last&rsrv=sync&rstb=true"   # also restart set-top boxes
```

`commit` (no arguments) shows the last commit time and tells whether a commit is still
running; a second commit during that time answers "Last Commit still in progress".

A resource answers with the page it belongs to, refreshed after the action. For Apply
Changes that is the commit record with the new timestamp:

```xml
<operation><type>response</type><status>ok</status><version>2</version>
  <fieldmap>
    <atr name="lcommit" k="0" must="true" ro="true" type="timestamp">Last Commit</atr>
    <atr name="last" k="1" must="false" ro="true" type="res">Apply Changes</atr>
  </fieldmap>
  <resources><res name="last" mode="res" k="1" title="Apply Changes">...</res></resources>
</operation>
<data model="tree"><elm><atr n="lcommit">19 September 2026 Saturday, 16:57:10</atr><atr n="last"></atr></elm></data>
```

A resource that fails (busy engine, refused by a check) answers `<status>failed</status>`
with the message in the data element and no reason code, since the failure comes from
the page, not from the API layer.

### 8.4 Live stream advanced settings

One record, saved partially. SRT listener block:

```
php apicall-v2.php 192.0.2.1 $K $S advconf
php apicall-v2.php 192.0.2.1 $K $S advconf "act=save&sten=yes&srtpor=5618&srtadr=lan1&srtbal=true&srtpwd=<passphrase>&srtpbk=16&srtlat=500"
```

HTTP-TS on TLS (`sslproto`), the HTTP bind port (`strport`), RTSP (`rten`, `rtscon`) and
the TS processing defaults live on the same page; the view lists every field with its
options. Apply Changes afterwards.

### 8.5 DVB transponders: add, change, scan

```
# add: the key field (name), the display name, the system and the state
php apicall-v2.php 192.0.2.1 $K $S tponders "act=add&name=DEMO11794&dname=Demo Mux 11794&satdvb=DVBS2&active=1"

# tuning parameters (the add form takes the system only; everything else is a save)
php apicall-v2.php 192.0.2.1 $K $S tponders "act=save&key=DEMO11794&satfrq=11794000&satsrt=30000000&satpol=v&satmod=psk_8&satfec=999&satdsq=1&satdsp=0&satfen=none&satlnv=1"

# review: the options block lists systems, polarities, modulations, FEC codes, DiSEqC and unicable values
php apicall-v2.php 192.0.2.1 $K $S tponders "act=view&key=DEMO11794"
```

Program scan. The transponder's **Configure Programs** resource opens the scan form; the
scan itself runs through the `autoscan` function with the form's values. The form fields
keep their positional names on this page by design (the form is generated, not stored):

| field | meaning | values |
|---|---|---|
| `k1` | tuner input to scan with | from the form's options |
| `k3` | create one stream per PMT | `true`/`false` |
| `k4` | only streams with video or audio | `true`/`false` |
| `k5` | one stream per audio track | `true`/`false` |
| `k6` | filter out unknown PIDs on new streams | `yes`/`no` |
| `k7` | encrypted streams | `yes` = mark disabled, `no` = mark enabled |
| `k8` | LCN of new streams | `yes` = from the source, `no` = automatic |
| `k10` | update service names and logos of existing streams | `true`/`false` |
| `k11`, `k12`, `k13` | PID filter, encrypted-stream state and LCN of existing streams | as above, or `ign` = keep as they are |

```
# the form (tuner inputs and the scan options with their allowed values)
php apicall-v2.php 192.0.2.1 $K $S autoscan "cont=tponder&key=DEMO11794&kA=autoask"

# scan on tuner input 2: one stream per PMT, video or audio required, LCN from the source, existing streams untouched
php apicall-v2.php 192.0.2.1 $K $S autoscan "cont=tponder&key=DEMO11794&kA=autoscan&k1=2&k3=true&k4=true&k5=false&k6=no&k7=no&k8=yes&k10=false&k11=ign&k12=ign&k13=ign"
```

The scan is synchronous: the call returns when the transponder has been tuned and every
program has been created or updated (about five seconds per program). The answer holds
an empty tree plus the scan report as text data. The report is meant for a human or a
log; the machine-readable result is the program list afterwards (`channels` filtered on
the transponder, section 8.6):

```xml
<operation><type>response</type><status>ok</status><version>2</version>
  <args><atr n="cont">tponder</atr></args>
</operation>
<data model="tree"><elm></elm></data>
<data model="text">
> DVB API 5, 511, Adapter(2/0)
  Frontend: DVB-S/S2 tuner card
  Adapter: /dev/dvb/adapter2/frontend0
> Frequency: 11794000, BIS: 1194000, band(6)
> DiSEqC sat(1), port(0x0)
  Frontend 9 set
~ TS sync
> Processing PAT
  SID List: 10600,10601,10603,10604,10629,10630,10631
  TSID: 43105
  7 programs listed
> Processing PMT
  Service ID: 10601, Program PID: 33, PCR PID: 1601
  ES PIDs: 1601[Vid,mpeg4/h264,PCR], 1701[Aud,mpeg1], 2003[Sub,mpeg2], 1120[Txt,mpeg2]
  Languages A[tur:0], S[tur:12:0x7d3], T[tur:1:100:0x460]
  Count V:1 A:1 S:1 T:1
  ...
 > Creating program Demo News
 > Creating program Demo Sport
  ...
</data>
```

A transponder that cannot be tuned answers the same way, with the report stopping at
the tuning step ("Could't get configuration!") and no programs created. Each created
program is one line in the headend's system log. Apply Changes afterwards. The same
flow serves MPTS inputs with `cont=mptsin`. Deleting a transponder deletes its programs
with it.

### 8.6 DVB programs

```
php apicall-v2.php 192.0.2.1 $K $S channels "srt=satnum&dir=asc"
php apicall-v2.php 192.0.2.1 $K $S channels "dsb=dname&src=News"
php apicall-v2.php 192.0.2.1 $K $S channels "dsb=sattpn&src=DEMO11794"      # the programs of one transponder
php apicall-v2.php 192.0.2.1 $K $S channels "act=view&key=DEMO11794_t10601"

# service name, LCN, state, multicast and SRT delivery, CDN push profile, HLS output profile
php apicall-v2.php 192.0.2.1 $K $S channels "act=save&key=DEMO11794_t10601&dname=Demo News&satnum=100&active=1&bren=yes&srcen=yes&rtmppro=&outpr=Hospitality"
```

### 8.7 HDMI encoding profiles

```
php apicall-v2.php 192.0.2.1 $K $S hdmiepro
php apicall-v2.php 192.0.2.1 $K $S hdmiepro "act=save&key=HDH2643Mbps&hcemode=H264&hcebrta=vbr&hcebrtm=2"
```

### 8.8 API keys

```
php apicall-v2.php 192.0.2.1 $K $S apikeys
php apicall-v2.php 192.0.2.1 $K $S apikeys "act=add&name=player-keys&dname=HLS players&active=1&apuse=hls"
php apicall-v2.php 192.0.2.1 $K $S apikeys "act=save&key=player-keys&active=0"
php apicall-v2.php 192.0.2.1 $K $S apikeys "act=del&key=player-keys"
```

The secret of a key is shown in the web UI only; the API never returns it.

### 8.9 System

```
php apicall-v2.php 192.0.2.1 $K $S systool                       # lists the tools as resources
php apicall-v2.php 192.0.2.1 $K $S systool "act=sres,reboot"
php apicall-v2.php 192.0.2.1 $K $S systool "act=sres,shutdown"
php apicall-v2.php 192.0.2.1 $K $S monitor                       # engine status
php apicall-v2.php 192.0.2.1 $K $S cmssumm                       # configuration summary
```

## 9. Migrating a v1 integration

1. Create a management key; replace the login/logout calls with the four signature fields.
2. Add `<version>2</version>` to the operation element.
3. Take one view of every page the integration uses and read the `k` attribute of the
   fieldmap: it is the old index, the `name` is the new name. Replace `kN` in saves, adds
   and `res,kN` calls. Resources use the `name` from the `resources` block.
4. Check the `reason` element on failures instead of guessing from the text.

Module profile pages (pre-processing, post-processing and HLS output profiles) name
their fields `item<N>` after the module's argument list; use the fieldmap titles to find
the right one, and expect these names to follow the module version.

## 10. Notes

- One call at a time per key is the safe pattern; the nonce store is per key.
- Keep the key secret out of URLs and logs; it never needs to travel.
- The headend's clock must be within five minutes of the client's. Use NTP on both.
- The `apicalls` list is role-dependent: an operator key sees the status and network
  pages, an admin key everything the web UI shows an admin.
