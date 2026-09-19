# Kylone XML API v2 — Kylone CPE Addendum

Revision 2026-09-19. Read `kylone-xml-api-v2-guide.md` first. This addendum covers the
functions of a headend licensed with Kylone's own client framework: set-top boxes with
the Kylone SDK, TV sets running the Kylone XML application, mobile devices with the
Kylone app. These functions exist only on such headends; `apicalls` lists them when the
licence includes them.

The three device kinds share one database table and one field set. They are separate
functions because the web UI presents them separately and because each kind supports a
different subset of actions:

| function | devices |
|---|---|
| `stbs` | Set Top Boxes using Kylone SDK |
| `xaps` | TV Sets using Kylone XML API |
| `mobiles` | Mobile Devices using Kylone App/API |

The record key is the device's `uuid`. `mac` is the device ID shown in the UI.

## 1. Device records

```
php apicall-v2.php 192.0.2.1 $K $S stbs
php apicall-v2.php 192.0.2.1 $K $S stbs "dsb=name&src=Room 1"
php apicall-v2.php 192.0.2.1 $K $S stbs "act=view&key=<uuid>"
php apicall-v2.php 192.0.2.1 $K $S stbs "act=save&key=<uuid>&name=Room 101&dname=Ms Guest&spack=Standard&plang=en&maxvol=80&active=1"
```

| field | meaning |
|---|---|
| `name`, `dname`, `gend` | room or device name, guest name, title |
| `active` | `1` active, `0` disabled |
| `spack` | service package (`packs`) |
| `orient`, `plang`, `maxvol`, `remaid`, `funic` | screen orientation, GUI language, volume limit, remote controller profile, force unicast |
| `scsid`, `scpwd` | Chromecast SSID and passphrase |
| `pmsuid`, `checkin`, `checkout` | property management system UID and the stay dates (dates are read-only, set by check-in) |
| `popmsg`, `msg` | pop-up message text and welcome message text (see section 2) |
| `tssize`, `tsmarg`, `tscolt`, `tscolb`, `tsnumt`, `tsnuml`, `tspeed`, `tstext` | per-device scrolling text settings and text |

Devices register themselves through the Device API when they start; creating a device
record by hand is not part of this API.

## 2. Device actions

The resources of a device record, all `mode="res"` (they need `key=<uuid>`):

| resource | effect |
|---|---|
| `popup` | show the pop-up message (`popmsg`) on the device now |
| `refresh` | update the welcome message (`msg`) |
| `scroll` | start the scrolling text (`tstext` and its settings) |
| `passcode` | reset the media access passcode |
| `purgepurc` | reset the purchase/viewing history |
| `clearapp` | clear the application data on the device |
| `reload` | restart the application |
| `clean` | update the device software (STB software update, `stbupg`) |
| `shot` | take a screenshot |

Actions that need text read it from the record, so save first, then call the resource:

```
php apicall-v2.php 192.0.2.1 $K $S stbs "act=save&key=<uuid>&popmsg=Your taxi is waiting at the lobby."
php apicall-v2.php 192.0.2.1 $K $S stbs "act=res,popup&key=<uuid>"

php apicall-v2.php 192.0.2.1 $K $S stbs "act=save&key=<uuid>&msg=Welcome back, Ms Guest"
php apicall-v2.php 192.0.2.1 $K $S stbs "act=res,refresh&key=<uuid>"

php apicall-v2.php 192.0.2.1 $K $S stbs "act=res,reload&key=<uuid>"
```

`xaps` and `mobiles` list the subset of these resources their devices support; the
`resources` block of the view is authoritative.

## 3. Messages to every device

Three page-level functions act on all devices at once. Each holds one record; save the
fields, then call `send`, `start` or `stop`:

```
# scrolling text
php apicall-v2.php 192.0.2.1 $K $S txtscr "act=save&tstext=Breakfast is served until 10:30&tspeed=3&tstdir=rl&tsnuml=3"
php apicall-v2.php 192.0.2.1 $K $S txtscr "act=res,send"

# instant message
php apicall-v2.php 192.0.2.1 $K $S insmsg "act=save&insmsg=Fire drill at 11:00, no action needed"
php apicall-v2.php 192.0.2.1 $K $S insmsg "act=res,send"

# emergency mode: alert text and sound on every screen until stopped
php apicall-v2.php 192.0.2.1 $K $S emerg "act=save&emerg=Please leave the building by the nearest exit&emergau=yes"
php apicall-v2.php 192.0.2.1 $K $S emerg "act=res,start"
php apicall-v2.php 192.0.2.1 $K $S emerg "act=res,status"
php apicall-v2.php 192.0.2.1 $K $S emerg "act=res,stop"
```

The view of each page lists the fields (`tssize`, `tsmarg`, `tscolt`, `tscolb`,
`tsnumt`, `tsnuml`, `tspeed`, `tstdir`, `tstext` for the scrolling text; `insmsg`;
`emerg`, `emergau`) with their options: `tspeed` runs from `24` (slowest) to `0.7`
(fastest) with `3` as normal, `tstdir` is `rl` or `lr`, `emergau` is `yes` or `no`.

## 4. Service packages and the guest interface

`packs` defines what a device shows: main menu type, language, content restriction,
banner, logo and background, player options. The assignment pages (`packtvs`,
`packrads`, `packvods`, `packclip`, `packmusic`, `packitem`, `packlocs`, `packpref`,
`packcomm`) attach channels, VOD titles, clips, music, menu items, weather locations,
settings items and advertisements to a package. A device takes a package through its
`spack` field.

```
php apicall-v2.php 192.0.2.1 $K $S packs
php apicall-v2.php 192.0.2.1 $K $S packs "act=view&key=Standard"
php apicall-v2.php 192.0.2.1 $K $S packs "act=save&key=Standard&agerat=16&country=en"
```

The interface itself is configured by `guidefs` and `visual` (user interface settings),
`items` and `subitems` (menu structure), `catmov`, `catmus`, `catset` (categories),
`edtvod`, `edtclip`, `edtadv` (content) and `locs` (weather locations). Each is an
ordinary list-and-record function; read the fieldmap of the view to see the fields.

## 5. Device software and access points

- `stbupg` holds the software update settings of Kylone set-top boxes; a device is
  updated with its `clean` resource.
- `wap` holds STB access-point profiles (Wi-Fi settings pushed to the devices).
- `pms`, `pma`, `mdm` configure the property management system, the PMS integration
  and the mobile device management links.

## 6. Device API

Devices themselves talk to the headend through the Device API (`xapop`), a challenge
and response protocol with positional attributes. It is unchanged by API v2 and stays
on the v1 contract; a named-field revision follows separately.
