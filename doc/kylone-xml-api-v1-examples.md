# Kylone XML API v1 — Usage Examples

Revision 2026-09-19. The v1 API is the contract described in the 2018 guide
(`kylone-xml-api.pdf`): password login with a session cookie, fields addressed by
position (`k<N>`). It keeps working unchanged on every headend release. New integrations
should use v2 (`kylone-xml-api-v2-guide.md`); this document collects examples for
integrations that stay on v1.

Positions are those of the page as shipped at the time of writing. A page can gain a
field in a later release, which shifts the positions after it. Take one `view` of the
page after an upgrade and compare the fieldmap before relying on stored indices; a v2
view shows both the index and the name of every field.

## Reference client

```
php apicall-v1.php <host or URL> <username> <password> <function> [argstring] [export_name]
```

A bare host means `http://<host>/portal/`. Give the full `https://` URL for a headend on
TLS, with `CLINSECURE=yes` for a self-signed certificate. `CLQUITE=yes` prints only
`ok` or `failed`. The exit status is 0 when the call succeeded. `export_name` writes the
request and response documents to `<export_name>_<function>_*.xml`.

```
H=192.0.2.1; U=admin; P='<password>'
```

## Discovery

```
php apicall-v1.php $H $U "$P" apicalls
php apicall-v1.php $H $U "$P" commit             # description of a function: fieldmap and resources
```

## Network interfaces

```
php apicall-v1.php $H $U "$P" network
php apicall-v1.php $H $U "$P" network "act=view&key=lan2"
php apicall-v1.php $H $U "$P" network "act=save&key=lan2&k4=-&k5=10.0.0.1&k6=255.255.255.0&k7=0.0.0.0&k8=10&k9=0.0.0.0&k10=0.0.0.0&k11=1500&k12=v2&k13=0&k14=UP"
php apicall-v1.php $H $U "$P" network "act=res,k15&key=lan2"      # commit the interface
```

k4 bond group (`-` none), k5 address, k6 netmask, k7 gateway, k8 metric, k9/k10 DNS,
k11 MTU, k12 IGMP version, k13 reverse path filter, k14 admin status.

## Apply changes

```
php apicall-v1.php $H $U "$P" commit "act=res,k1"                  # configuration only
php apicall-v1.php $H $U "$P" commit "act=res,k1&rsrv=sync"        # update the streaming engine
php apicall-v1.php $H $U "$P" commit "act=res,k1&rsrv=kill"        # restart the streaming engine
php apicall-v1.php $H $U "$P" commit "act=res,k1&rsrv=sync&rstb=true"   # and restart set-top boxes
```

## Live stream advanced settings

SRT listener block (k16 enable, k17 port, k19 interface, k20 listen on all, k22
passphrase, k23 key length, k24 latency), HTTP-TS TLS at k27:

```
php apicall-v1.php $H $U "$P" advconf
php apicall-v1.php $H $U "$P" advconf "act=save&k16=yes&k17=5618&k19=lan1&k20=true&k22=<passphrase>&k23=16&k24=500"
```

## DVB transponders: add, change, scan

```
# add: record name, display name, system, state (k1 name, k2 display name, k3 system, k7 state on the add form)
php apicall-v1.php $H $U "$P" tponders "act=add&k1=DEMO11794&k2=Demo Mux 11794&k3=DVBS2&k7=1"

# tuning (view form): k4 frequency, k5 symbol rate, k6 polarity, k7 modulation, k8 FEC, k13 satellite number, k14 switch port, k18 frontend, k19 LNB power
php apicall-v1.php $H $U "$P" tponders "act=view&key=DEMO11794"
php apicall-v1.php $H $U "$P" tponders "act=save&key=DEMO11794&k4=11794000&k5=30000000&k6=v&k7=psk_8&k8=999&k13=1&k14=0&k18=none&k19=1"

# scan form, then the scan: k1 tuner input; new streams: k3 one per PMT, k4 video or audio required,
# k5 one per audio track (true/false), k6 filter unknown PIDs (yes/no), k7 encrypted streams
# (yes = disabled, no = enabled), k8 LCN (yes = from the source, no = automatic);
# existing streams: k10 update names and logos (true/false), k11..k13 as k6..k8 or ign = keep
php apicall-v1.php $H $U "$P" autoscan "cont=tponder&key=DEMO11794&kA=autoask"
php apicall-v1.php $H $U "$P" autoscan "cont=tponder&key=DEMO11794&kA=autoscan&k1=2&k3=true&k4=true&k5=false&k6=no&k7=no&k8=yes&k10=false&k11=ign&k12=ign&k13=ign"
```

Note that the add form and the view form of a transponder number their fields
differently: the add form holds the system choice only, the tuning fields appear once
the record exists. Take the positions from each form's own fieldmap.

## DVB programs

```
php apicall-v1.php $H $U "$P" channels "srt=satnum&dir=asc"
php apicall-v1.php $H $U "$P" channels "act=view&key=DEMO11794_t10601"
# k2 service name, k5 LCN, k7 state, k15 multicast, k22 SRT, k28 CDN profile, k41 HLS output profile
php apicall-v1.php $H $U "$P" channels "act=save&key=DEMO11794_t10601&k2=Demo News&k5=100&k7=1&k15=yes&k22=yes&k41=Hospitality"
```

## HDMI encoders

```
php apicall-v1.php $H $U "$P" hdmienc
php apicall-v1.php $H $U "$P" hdmienc "act=reload"
php apicall-v1.php $H $U "$P" hdmienc "act=save&k2=HDH2643MbpsT"                 # default encoding profile
php apicall-v1.php $H $U "$P" hdmiport "act=view&key=<port record>"
php apicall-v1.php $H $U "$P" hdmiport "act=save&key=<port record>&k2=The Service Name&k9=100&k12=1&k15=&k21=true&k22=yes&k29=yes&k35=rtmp&k44=yes"
php apicall-v1.php $H $U "$P" hdmiepro "act=save&key=HDH2643Mbps&k6=H264&k10=vbr&k11=2"   # codec, bitrate control, bitrate
```

## System

```
php apicall-v1.php $H $U "$P" systool
php apicall-v1.php $H $U "$P" systool "act=sres,k12"      # reboot
php apicall-v1.php $H $U "$P" systool "act=sres,k13"      # shutdown
```

## Media players and Kylone devices

Media player ranges (`peers`: k6 from, k7 to, k4 state), set-top boxes (`stbs`, key =
UUID, resources by position from the view), messages (`txtscr`, `insmsg`, `emerg`) follow
the same pattern: view the page, take the positions from its fieldmap, save, then call
the resource by its position. The v2 addenda describe the same pages by field name.
