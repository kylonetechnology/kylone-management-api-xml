# Kylone XML API v2 — Blind Scan Addendum

Revision 2026-09-21. Read `kylone-xml-api-v2-guide.md` first. This addendum covers the
functions of a DVB Carrier Monitor, a headend licensed for satellite blind scanning (an
appliance fitted with TBS DVB-S/S2 cards and the DVB Blind Scan module). These functions exist only on such
headends; `apicalls` lists them when the licence includes them.

The feature hunts carriers: the spectrum of an LNB input is acquired, every carrier found
is blind-tuned, and the tuning parameters and programme tables of each locked transponder
go into a report. The report is the product; nothing is written into the transponder or
programme tables of the headend. A **task** is a saved scan definition, a **run** is one
execution of it, and every finished run of a task is compared with the task's previous
run, so the daily question "what changed" is answered by three numbers: new, lost,
changed.

| function | records | what it holds |
|---|---|---|
| `bscan` | tasks, key = task name | the scan definition, the enabled flag and cycle order, the last run's counts |
| `bscruns` | runs, key = run id (`YYYYMMDD-HHMMSS`) | state, timings, counts; resources for the report, the diff and the log |
| `bscctl` | one record, no key | the cycle schedule; resources for the cycle and the runner's state |

## 1. Tasks

Fields of `bscan` (a `view` answers all of them, with the fixed lists in `options`):

| field | meaning | values |
|---|---|---|
| `name` | task name, the record key | letters, digits, underscore, dash; the web form derives it from the display name, an API `add` must give it |
| `dname` | display name | required, 48 characters at most |
| `active` | in the cycle | `1` enabled, `0` disabled |
| `bsord` | cycle order | number, lower runs first |
| `bskeep` | runs to keep | number, default 30 |
| `descr` | description | text |
| `bsadp` | DVB adapter | the demod that scans; must have the FFT engine (adapters 0 to 3 of a TBS 6909x); empty picks the first free one |
| `bsrfi` | RF input of the card | `0` to `3` |
| `bslay` | cable layout | `direct` (one cable on `bsrfi`) or `quattro` (four cables, the input follows polarisation and band) |
| `bslnb` | LNB | `universal`, `c`, `wideband` |
| `bsprt` | DiSEqC committed port | `none`, `0` (A) to `3` (D) |
| `bsupt` | DiSEqC uncommitted port | `none`, `0` to `15` |
| `bspol` | polarisation | `both`, `V`, `H` |
| `bsfrq`, `bsfrt` | range in MHz | empty = the whole band; a window narrower than 100 MHz is widened |
| `bsres` | spectral resolution | `100` or `50` kHz |
| `bssrc` | search range | kHz, default 10000 |
| `bsprg` | programme tables | `true` reads the tables of every locked transponder, `false` skips |
| `bslrn`, `bslrs` | last run id and result | read-only, synced from the runner |
| `bslck`, `bsnew`, `bslst`, `bschg` | last run: transponders, new, lost, changed | read-only |

Resources of a task record:

| resource | effect |
|---|---|
| `start` | queue a run of this task; the answer carries `<run>` with the run id (`409 bad-request` when the runner refuses, for example while the drivers are not ready) |
| `runs` | web only: the run list of the task |

```
php apicall-v2.php 192.0.2.1 $K $S bscan "act=add&name=nightly&dname=Nightly&active=1&bsord=10&bsadp=3&bsrfi=3&bsprt=0&bspol=both&bsprg=true"
php apicall-v2.php 192.0.2.1 $K $S bscan "act=save&key=nightly&bsfrq=11700&bsfrt=12400"
php apicall-v2.php 192.0.2.1 $K $S bscan "act=res,start&key=nightly"
php apicall-v2.php 192.0.2.1 $K $S bscan
```

Out-of-range values (`bsrfi=7`) and unknown options (`bsprt=9`) fail with `bad-arg`.
Runs of one task execute one after another, and two tasks never overlap: the runner keeps
a queue and starts the next run when the previous one finishes.

## 2. Runs

`bscruns` lists the runs newest first; `task=<record name>` restricts the list to one task's runs. Fields: `name` (run id), `bstsk` (task), `bsphs`
(phase: `queued`, `check`, `scan-V`, `scan-H`, `programs`, `report`, `done`), `bsrsl`
(result: `ok`, `failed`, `aborted`, `cancelled`, `busy`, `input-busy`, `nodrivers`,
`rfinput-refused`), `bsqtm`, `bsstm`, `bsftm` (queued, started, finished), `bsadp`,
`bsrfi`, `bsprt`, `bspol`, `bslck` (transponders locked), `bsnpr` (with programme tables),
`bsprv` (the run compared with), `bsnew`, `bslst`, `bschg`.

Resources of a run record:

| resource | answer |
|---|---|
| `report` | the report document as text data: `<blindscan>` with one `<transponder>` per lock (frequency in kHz, symbol rate in S/s, system, modulation, FEC, signal, C/N, service count, a signature, a `<stream>` child with the capture figures, and the programme tables as read by the headend's scanner), `<candidate>` rows for peaks that did not lock, and the `<diff>` block |
| `diff` | the diff block alone: `<diff prev new lost changed same>` with `<new>`, `<lost>` and `<changed>` rows (each with `onid`, `tsid`, `nfreq`, `nsr`); a changed row carries the previous values as `prev-*` attributes |
| `log` | the last 200 lines of the run's log |
| `tps` | the transponders as tab-separated rows: polarisation, nominal frequency and symbol rate, system, modulation, FEC, programmes, C/N, onid, tsid, network, nominal source, NIT check, measured frequency and symbol rate, and the stream figures of the capture made during the programme read: bitrate (kbit/s, from the PCR clock), null share, continuity errors, PCR interval median and maximum (ms), PCR jitter (us), capacity shares in percent for video, audio, subtitles, tables, CA, null and other |
| `abort` | stop the run (the queue continues) |

`act=del&key=<run id>` removes a finished run. Reports are also published as files under
the feeds tree, `feeds/blindscan/<task>/<run id>.xml` and `latest.xml`, for a plain HTTP
fetch with the access rules of the other feeds.

```
php apicall-v2.php 192.0.2.1 $K $S bscruns "act=res,diff&key=20260921-121312"
php apicall-v2.php 192.0.2.1 $K $S bscruns "act=res,report&key=20260921-121312" > report.xml
```

**Identity and nominal values.** Every transponder row also carries what the broadcaster
declares: `onid` and `tsid` (the transport identity from the SDT and PAT), `netid` and
`netname` (from the NIT), `orbit`, and the nominal `nfreq` (kHz) and `nsr` (S/s) with
`nsrc` saying where they came from: `nit` (the NIT's own entry for this transport, used
only when it agrees with the measurement within 3 MHz and 0.5 % of symbol rate; a
disagreeing entry is kept in `nitfreq` and `nitsr` with the reason in `nitchk`),
`calib` (frequency corrected by the LNB offset measured on the NIT-bearing locks of the
same polarisation, `lnboff`, then rounded to 250 kHz; symbol rate rounded to the coarsest
plausible step) or `round` (no NIT on the input). Use `onid` + `tsid` as the key of your
own records and `nfreq` / `nsr` for tuning parameters; the measured `freq` and `sr` stay
for reference.

**How the diff is made.** A transponder matches its predecessor by `onid` + `tsid` when
both sides carry them; otherwise when the polarisation is equal and the frequency lies
within a quarter of the symbol rate (at least 1.5 MHz). A match is unchanged when the
signature is equal, the frequency is within 1 MHz and the symbol rate within 1 %. The signature covers polarisation, system, modulation, FEC and
the list of programme numbers; it leaves out the exact frequency and symbol rate (they
drift by a few kHz from run to run) and the service names (on a marginal carrier they
come and go within the reading window).

## 3. Schedule and state

`bscctl` is a single record. Its field `bssch` selects the cycle schedule from a fixed
list (`none`, `daily@01:00`, `daily@03:00`, `daily@05:00`, `every@12h`, `every@6h`,
`weekly@Sun@03:00`); saving it installs or removes the headend's timer at once. The cycle
queues every enabled task in `bsord` order, one after another.

| resource | answer |
|---|---|
| `state` | the runner's state as text data, key=value lines: `status` (drivers), `adapters`, `settled`, `schedule`, `next` (epoch of the next cycle), `active` and `active.*` (the running scan: task, phase, progress, locks so far, current carrier), `queue`, `queued`, one `task.<name>` line per task, `runs` |
| `cycle` | queue every enabled task now; answers `<queued>` with the count |
| `stop` | empty the queue; argument `abort=true` also stops the active run |

```
php apicall-v2.php 192.0.2.1 $K $S bscctl "act=save&bssch=daily@03:00"
php apicall-v2.php 192.0.2.1 $K $S bscctl "act=sres,state"
php apicall-v2.php 192.0.2.1 $K $S bscctl "act=sres,cycle"
php apicall-v2.php 192.0.2.1 $K $S bscctl "act=sres,stop&abort=true"
```

## 4. Daily use

A scheduler on the operator's side needs three calls: `bscan act=res,start` (or the
headend's own schedule), `bscruns` until the run's `bsphs` is `done`, and
`bscruns act=res,report` or `act=res,diff`. The diff answers what changed; the report
carries everything needed to create a transponder record deliberately from a new carrier.

## 5. Worked examples

All responses below are excerpts of real answers from a test appliance (two TBS 6909x
cards on a 39E feed). `$K` and `$S` are the management key and its secret.

### 5.1 Define a task and run it once

The record key is the task name; an `add` must carry it. Fields left out keep their
defaults (both polarisations, universal LNB, the whole band, programme tables on).

```
php apicall-v2.php 192.0.2.1 $K $S bscan "act=add&name=Nightly&dname=Nightly&active=1&bsord=10&bsadp=3&bsrfi=3&bsprt=0&bspol=both&bsfrq=12000&bsfrt=12300"
php apicall-v2.php 192.0.2.1 $K $S bscan "act=res,start&key=Nightly"
```

The start answers the run id in `<run>`:

```
<status>ok</status> ... <data model="struct"><run>20260922-132649</run></data>
```

A second start while that run is active is queued, not refused; runs of one appliance
execute one after another. An out-of-range value is refused before anything is stored:

```
php apicall-v2.php 192.0.2.1 $K $S bscan "act=save&key=Nightly&bsrfi=7"
```
```
<status>failed</status> ... <reason>bad-arg</reason>
<data model="text">field 'bsrfi' does not accept '7'</data>
```

An `add` without `name` is not an error: the answer is `ok` with the field map of the
form, and no task is created. Check the list after an add when a script cannot tell.

### 5.2 The morning check: what changed since yesterday

List the task's runs newest first with the `task` filter; the first row is the newest.
The list shows display values (`Completed`, port `A`), the view of a record shows the raw
ones (`ok`, `0`).

```
php apicall-v2.php 192.0.2.1 $K $S bscruns "task=Nightly"
```
```
<elm><atr n="name">20260922-132649</atr><atr n="bstsk">Nightly</atr><atr n="bsphs">Finished</atr>
<atr n="bsrsl">Completed</atr> ... <atr n="bslck">10</atr><atr n="bsnpr">10</atr>
<atr n="bsnew">0</atr><atr n="bslst">0</atr><atr n="bschg">1</atr>
<atr n="bsstm">1790083609</atr><atr n="bsftm">1790083857</atr></elm>
```

One transponder changed. The diff says which and how, with the previous values:

```
php apicall-v2.php 192.0.2.1 $K $S bscruns "act=res,diff&key=20260922-132649"
```
```
<diff prev="20260921-221818" new="0" lost="0" changed="1" same="9">
  <changed pol="V" freq="12223441" sr="13381790" sys="DVBS2" mod="QPSK" fec="3/4" services="17"
           onid="8492" tsid="2" nfreq="12223500" nsr="13382000"
           prev-freq="12223493" prev-sr="13381506" prev-sys="DVBS2" prev-mod="QPSK" prev-fec="3/4" prev-services="0"/>
</diff>
```

Here the carrier at 12223.5 MHz carried no tables the night before and now carries 17
programmes: same frequency, same modulation, a different line-up. A script that only
watches `bsnew`, `bslst` and `bschg` on the newest row needs no report at all; it fetches
the diff when a count is not zero.

### 5.3 From a found carrier to a transponder record

The `tps` rows give everything a transponder record needs, in the record's own units
(kHz and symbols per second), with the identity to key on:

```
php apicall-v2.php 192.0.2.1 $K $S bscruns "act=res,tps&key=20260922-132649"
```
```
pol nfreq    nsr      sys   mod  fec services cnr   onid tsid netname               nsrc nitchk freq     sr       bitrate nullpct ccerr pcrint pcrmax pcrjit video audio sub tables ca  null other
V   12136660 30000000 DVBS2 8PSK 3/4 48       12.80 926  178  A1 BG HellasSat 39E   nit         12136530 29998962 65277   1.0     143   17.9   26.9   13     88.3  5.4   0.1 3.3    1.8 1.0  0.0
V   12175020 30000000 DVBS  QPSK 7/8 18       12.20 28   15   OROC_                 nit         12174873 29999081 48368   5.0     31    35.3   36.8   10     83.0  6.6   0.3 1.8    3.1 5.0  0.1
```

Creating the first one as a transponder of the headend, deliberately, with the nominal
values (the API guide, section 8.5, has the transponder fields):

```
php apicall-v2.php 192.0.2.1 $K $S tponders "act=add&name=HS39E12136V&dname=HellasSat 39E 12136 V&satdvb=DVBS2&active=1"
php apicall-v2.php 192.0.2.1 $K $S tponders "act=save&key=HS39E12136V&satfrq=12136660&satsrt=30000000&satpol=v&satmod=psk_8&satfec=34&satdsq=1&satdsp=0"
```

`nsrc="nit"` says the frequency and symbol rate are the network's own declaration,
which agreed with the measurement; `calib` or `round` would mean a value derived from
the measurement, good enough to tune but worth a look at the measured `freq`.

### 5.4 Reading the stream figures

The same rows carry the capture figures: this transponder delivers 65.3 Mbit/s, one
percent null packets, PCR intervals of 17.9 ms typical and 26.9 ms at most, 13 us of PCR
jitter, and 88 percent of its capacity is video. A `pcrmax` above 40 or a large `pcrjit`
on a carrier is worth reporting to whoever runs that uplink; a `ccerr` count in the low
hundreds over a 14-second line-rate capture is normal and comes from the capture itself.

### 5.5 Schedule, state and stopping

```
php apicall-v2.php 192.0.2.1 $K $S bscctl "act=save&bssch=every@6h"
php apicall-v2.php 192.0.2.1 $K $S bscctl "act=sres,state"
```
```
status=ok
drivers=neumo
adapters=32
uptime=147757
settled=true
schedule=every@6h
next=1790100000
active=
queue=
queued=0
task.Nightly=true|10|20260922-132649|ok|1790083857|10|10|0|0|1
runs=20
```

`next` is the epoch of the next cycle. During a scan `active` names the run and
`active.phase`, `active.progress` and `active.locks` follow it. To end everything at
once:

```
php apicall-v2.php 192.0.2.1 $K $S bscctl "act=sres,stop&abort=true"
```
