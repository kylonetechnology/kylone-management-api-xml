<?php
/*
 * upgrade-url.php — upgrade a Kylone headend from a package URL and check the result.
 *
 *   php upgrade-url.php <host> <key name> <key secret> <kind> <url> <digest>
 *
 *   kind     app (system upgrade package), os (OS update package),
 *            mod (module install package), modupd (module update package)
 *   url      http(s) address the headend downloads the package from
 *   digest   SHA-1 (40 hex) or SHA-256 (64 hex) of the file, as the package pipeline prints it
 *
 * The call is the Tools page's "Install from URL" resource (guide, section 8.9): the headend
 * answers "started" at once and does the rest itself (download with digest check, upgrade,
 * engine restart). The API cannot read the upgrade log, so this utility watches the unit
 * instead: for app and os it polls `version` until the portal has been away and is back
 * (the build changes for an app package), then runs the post-checks; for mod and modupd
 * it polls the Modules page until the module is listed. Exit 0 when every check passed.
 * Set CLINSECURE=yes for a self-signed certificate. One unit at a time; run the next unit
 * after this one reports.
 */
if (count($argv) < 7) {
   echo "usage: php upgrade-url.php <host> <key name> <key secret> <app|os|mod|modupd> <url> <digest>\n";
   exit(1);
}
function up_post($url, $doc, $key, $secret, &$code) {
   $ts = (string)time(); $nonce = bin2hex(random_bytes(8));
   $sig = hash_hmac("sha256", "kylone-api-v2\n".$key."\n".$ts."\n".$nonce."\n".hash("sha256", $doc), $secret);
   $c = curl_init();
   curl_setopt_array($c, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_URL => $url, CURLOPT_POST => 1,
      CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 60,
      CURLOPT_POSTFIELDS => array("xml" => $doc, "akey" => $key, "ats" => $ts, "anonce" => $nonce, "asig" => $sig),
      CURLOPT_USERAGENT => "upgrade-url/1.0", CURLOPT_ENCODING => "gzip"));
   if (getenv("CLINSECURE") == "yes") { curl_setopt($c, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($c, CURLOPT_SSL_VERIFYHOST, false); }
   $r = curl_exec($c); $code = curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
   return $r;
}
// one call; false when the portal did not answer a v2 envelope (fused during the upgrade, down)
function up_call($host, $key, $secret, $fn, $args, &$code) {
   $a = "";
   if ($args !== "") foreach (explode("&", $args) as $kv) { $p = explode("=", $kv, 2); $a .= '<atr name="'.htmlspecialchars($p[0]).'">'.htmlspecialchars(isset($p[1]) ? $p[1] : "").'</atr>'; }
   $doc = '<?xml version="1.0"?><cLst><container><operation><type>request</type><version>2</version></operation><data model="struct"><request>'.$fn.'</request><args>'.$a.'</args></data></container></cLst>';
   $r = up_post("https://".$host."/portal/", $doc, $key, $secret, $code);
   $x = ($r === false) ? false : @simplexml_load_string($r);
   return ($x && isset($x->container->operation->status)) ? $x : false;
}
function up_version($host, $key, $secret) {
   $code = 0;
   $x = up_call($host, $key, $secret, "version", "", $code);
   if (!$x || ((string)$x->container->operation->status !== "ok") || !isset($x->container->data->build)) return false;
   return array("version" => (string)$x->container->data->version, "build" => (string)$x->container->data->build,
                "product" => (string)$x->container->data->product);
}
function up_say($s) { echo date("H:i:s")." ".$s."\n"; }

$host = $argv[1]; $key = $argv[2]; $secret = $argv[3]; $kind = $argv[4]; $url = $argv[5]; $sha = strtolower($argv[6]);
if (!in_array($kind, array("app", "os", "mod", "modupd"))) { echo "kind must be app, os, mod or modupd\n"; exit(1); }
if (!preg_match('/^([0-9a-f]{40}|[0-9a-f]{64})$/', $sha)) { echo "digest must be 40 (SHA-1) or 64 (SHA-256) hex digits\n"; exit(1); }

// 1. where the unit stands
$before = up_version($host, $key, $secret);
if ($before === false) { up_say("version call failed: wrong key, no HTTPS, or the unit is busy"); exit(1); }
up_say("unit: ".$before["product"]." ".$before["version"]." build ".$before["build"]);
$expect = "";
if (($kind === "app") && preg_match('/-(\d{10})-/', basename($url), $m)) $expect = $m[1];   // the build number in the package name

// 2. start
$code = 0;
$x = up_call($host, $key, $secret, "systool", "act=sres,dlurl&dlkind=".$kind."&dlurl=".$url."&dlsha=".$sha, $code);
if (!$x) { up_say("start failed (HTTP ".$code.")"); exit(1); }
$st = (string)$x->container->operation->status;
$txt = isset($x->container->data) ? trim((string)$x->container->data) : "";
if (($st !== "ok") || ($txt !== "started")) {
   $why = isset($x->container->operation->reason) ? (string)$x->container->operation->reason : $txt;
   if ($why === "unknown-target") $why .= " (this headend has no Install from URL: an older build, the function not shipped, or a key role without the Tools page)";
   up_say("not started: ".$why);      // "error: the address must start with http:// or https://" for a refused argument
   exit(1);
}
up_say("started: ".$kind." from ".preg_replace('#//[^/@]*@#', '//', $url));

// 3. wait: a mod/modupd package keeps the portal up, an app/os package takes the portal away and brings it back
$limit = time() + 1800;      // 30 minutes covers a slow link; the download alone can be the larger part
$away = false; $after = false;
if (($kind === "mod") || ($kind === "modupd")) {
   $name = preg_replace('/\.(tar\.gz|tgz|tar)$/', '', basename(parse_url($url, PHP_URL_PATH)));   // the package is <module>.tar.gz
   sleep(10);
   while (time() < $limit) {
      $p = up_call($host, $key, $secret, "plugins", "", $code);
      if ($p && isset($p->container->data->elm)) {
         foreach ($p->container->data->elm as $e) foreach ($e->atr as $v) if ((string)$v === $name) { $after = true; break 2; }
      }
      if ($after) break;
      sleep(10);
   }
   if (!$after) { up_say("module ".$name." not listed after the wait: read the Modules page's operation log on the web UI"); exit(1); }
   up_say("module ".$name." listed on the Modules page");
   exit(0);
}
sleep(15);
while (time() < $limit) {
   $v = up_version($host, $key, $secret);
   if ($v === false) {
      if (!$away) up_say("portal away: downloading and upgrading");
      $away = true;
   } else if ($away || (($expect !== "") && ($v["build"] === $expect) && ($v["build"] !== $before["build"]))) {
      $after = $v; break;
   }
   sleep(10);
}
if ($after === false) { up_say("no result within 30 minutes: read the Operation Log on the web UI (System > Tools)"); exit(1); }
up_say("portal back: ".$after["product"]." ".$after["version"]." build ".$after["build"]);

// 4. post-checks
$ok = true;
if ($kind === "app") {
   if (($expect !== "") && ($after["build"] !== $expect)) { up_say("FAIL build ".$after["build"].", expected ".$expect." (a digest or package failure leaves the old build: see the Operation Log)"); $ok = false; }
   else if ($after["build"] === $before["build"]) up_say("note: build unchanged (".$after["build"]."), the package carried the build already installed");
   else up_say("ok   build ".$before["build"]." -> ".$after["build"]);
}
sleep(20);                                   // the engine restarts after the portal is back
$m = up_call($host, $key, $secret, "dvbstat_clear", "psi=0", $code);
if ($m && ((string)$m->container->operation->status === "ok")) up_say("ok   engine status answers (dvbstat_clear)");
else { up_say("FAIL engine status (dvbstat_clear) does not answer"); $ok = false; }
$c = curl_init();
curl_setopt_array($c, array(CURLOPT_URL => "https://".$host."/portal/?app=metrics", CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
   CURLOPT_HTTPAUTH => CURLAUTH_BASIC, CURLOPT_USERPWD => $key.":".$secret, CURLOPT_USERAGENT => "upgrade-url/1.0"));
if (getenv("CLINSECURE") == "yes") { curl_setopt($c, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($c, CURLOPT_SSL_VERIFYHOST, false); }
curl_exec($c); $mc = curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
if ($mc == 200) up_say("ok   metrics endpoint answers");
else { up_say("FAIL metrics endpoint HTTP ".$mc); $ok = false; }
up_say($ok ? "done" : "done with failures");
exit($ok ? 0 : 2);
?>
