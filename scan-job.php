<?php
/*
 * scan-job.php — scan one source for programs as a job and wait for its report.
 *
 *   php scan-job.php <host> <key name> <key secret> <tponders|mptsin> <record> [arg=value ...]
 *
 *   tponders   a DVB transponder record; tuner=<adapter> is required (the DVB adapter index
 *              the scan tunes, as the Configure Programs form lists the inputs)
 *   mptsin     an MPTS input record (multicast, SRT, HTTP ...); no tuner
 *   arg=value  the scan options (guide, section 8.5a): spts, avonly, multiaudio, pidfilter,
 *              scrambled, lcn, updnames, updpidfilter, updscrambled, updlcn; the form's
 *              defaults apply to the ones not given
 *
 * The record's `scanjob` resource starts the scan and answers a job id at once; the scan
 * runs on the headend while the HTTP call is over. This utility follows the job on the
 * `scanjobs` function (phase queued, tuning, programs, done), then prints the result, the
 * counts of programs created and updated, and the scan report (the same text the
 * synchronous `autoscan` answers). The programs reach the air at Apply Changes (`commit`).
 * Exit 0 when the scan ended with result ok, 1 when it was refused or failed (busy input,
 * tuning failure, lost worker), 2 on a transport or usage error. Set CLINSECURE=yes for a
 * self-signed certificate.
 */
if (count($argv) < 6) {
   echo "usage: php scan-job.php <host> <key name> <key secret> <tponders|mptsin> <record> [arg=value ...]\n";
   exit(2);
}
function sj_post($url, $doc, $key, $secret, &$code) {
   $ts = (string)time(); $nonce = bin2hex(random_bytes(8));
   $sig = hash_hmac("sha256", "kylone-api-v2\n".$key."\n".$ts."\n".$nonce."\n".hash("sha256", $doc), $secret);
   $c = curl_init();
   curl_setopt_array($c, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_URL => $url, CURLOPT_POST => 1,
      CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 60,
      CURLOPT_POSTFIELDS => array("xml" => $doc, "akey" => $key, "ats" => $ts, "anonce" => $nonce, "asig" => $sig),
      CURLOPT_USERAGENT => "scan-job/1.0", CURLOPT_ENCODING => "gzip"));
   if (getenv("CLINSECURE") == "yes") { curl_setopt($c, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($c, CURLOPT_SSL_VERIFYHOST, false); }
   $r = curl_exec($c); $code = curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
   return $r;
}
// one call: the parsed envelope, or false when the portal did not answer one
function sj_call($host, $key, $secret, $fn, $args, &$code) {
   $a = "";
   if ($args !== "") foreach (explode("&", $args) as $kv) { $p = explode("=", $kv, 2); $a .= '<atr name="'.htmlspecialchars($p[0]).'">'.htmlspecialchars(isset($p[1]) ? $p[1] : "").'</atr>'; }
   $doc = '<?xml version="1.0"?><cLst><container><operation><type>request</type><version>2</version></operation><data model="struct"><request>'.$fn.'</request><args>'.$a.'</args></data></container></cLst>';
   $r = sj_post("https://".$host."/portal/", $doc, $key, $secret, $code);
   $x = ($r === false) ? false : @simplexml_load_string($r);
   return ($x && isset($x->container->operation->status)) ? $x : false;
}
// the text of a text answer ("busy: ...", "error: ...", "record not found: ...") or ""
function sj_text($x) {
   if (!isset($x->container->data)) return "";
   $d = $x->container->data;
   return ((string)$d["model"] === "text") ? trim((string)$d) : "";
}
// the named values of a record view: atr elements with an n attribute
function sj_fields($x) {
   $f = array();
   if (!isset($x->container->data)) return $f;
   foreach ($x->container->data->xpath(".//atr[@n]") as $a) $f[(string)$a["n"]] = (string)$a;
   return $f;
}
function sj_say($s) { echo date("H:i:s")." ".$s."\n"; }

$host = $argv[1]; $key = $argv[2]; $secret = $argv[3]; $fn = $argv[4]; $rec = $argv[5];
if (!in_array($fn, array("tponders", "mptsin"))) { echo "the source kind must be tponders or mptsin\n"; exit(2); }
$args = "act=res,scanjob&key=".$rec;
$tuner = "";
for ($i = 6; $i < count($argv); $i++) {
   $p = explode("=", $argv[$i], 2);
   if ((count($p) != 2) || !preg_match('/^[a-z]+$/', $p[0])) { echo "scan options are given as name=value: ".$argv[$i]."\n"; exit(2); }
   if ($p[0] === "tuner") $tuner = $p[1];
   $args .= "&".$p[0]."=".$p[1];
}
if (($fn === "tponders") && ($tuner === "")) { echo "a transponder scan needs tuner=<adapter index>\n"; exit(2); }

// 1. start
$code = 0;
$x = sj_call($host, $key, $secret, $fn, $args, $code);
if (!$x) { sj_say("start failed (HTTP ".$code."): wrong key, no HTTPS, or the unit is busy"); exit(2); }
if ((string)$x->container->operation->status !== "ok") {
   $why = isset($x->container->operation->reason) ? (string)$x->container->operation->reason : sj_text($x);
   sj_say("refused: ".$why);         // unknown-target: no such record, or a key role without the page
   exit(1);
}
$txt = sj_text($x);
if ($txt !== "") {                   // "busy: <job id>" while a job holds the input, "error: ..." otherwise
   sj_say("not started: ".$txt);
   exit(1);
}
$id = isset($x->container->data->job) ? trim((string)$x->container->data->job) : "";
if ($id === "") { sj_say("not started: no job id in the answer"); exit(2); }
sj_say("job ".$id." queued".($tuner !== "" ? " on adapter ".$tuner : ""));

// 2. follow the job: the phase from the scanjobs record, until done
$limit = time() + 1800;              // a scan takes seconds to a few minutes; a lost worker is closed by the headend within the hour
$phase = "queued"; $j = false;
while (time() < $limit) {
   sleep(3);
   $v = sj_call($host, $key, $secret, "scanjobs", "act=view&key=".$id, $code);
   if (!$v) continue;                // a transient transport error: ask again
   if (sj_text($v) !== "") { sj_say("job ".$id." vanished: ".sj_text($v)); exit(1); }
   $j = sj_fields($v);
   if (!isset($j["sjphs"])) continue;
   if ($j["sjphs"] !== $phase) { $phase = $j["sjphs"]; sj_say("phase ".$phase); }
   if ($phase === "done") break;
}
if (($j === false) || ($phase !== "done")) { sj_say("no result within 30 minutes: read the job on Program Scan Jobs"); exit(1); }

// 3. the result and the report
sj_say("result ".$j["sjrsl"].": ".$j["sjcre"]." created, ".$j["sjupd"]." updated (started ".$j["sjstm"].", finished ".$j["sjftm"].")");
$r = sj_call($host, $key, $secret, "scanjobs", "act=res,report&key=".$id, $code);
if ($r && (sj_text($r) !== "")) echo "\n".sj_text($r)."\n\n";
if ($j["sjrsl"] !== "ok") {          // the feed's last lines say why (no lock, no stream, worker gone)
   $l = sj_call($host, $key, $secret, "scanjobs", "act=res,log&key=".$id, $code);
   if ($l && (sj_text($l) !== "")) echo "--- log\n".sj_text($l)."\n";
   exit(1);
}
sj_say("done: Apply Changes (commit) puts the programs on air");
exit(0);
?>
