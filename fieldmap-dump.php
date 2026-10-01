<?php
/*
 * fieldmap-dump.php — dump every page's v2 field map of a headend, and diff two dumps.
 *
 *   php fieldmap-dump.php <host> <key> <secret> <outdir>     one file per function under <outdir>
 *   php fieldmap-dump.php diff <dirA> <dirB>                   what changed between two dumps
 *
 * A dump holds, per function, the list's columns and the first record's view (fields,
 * resources, options) as "kind name k type must ro title" lines, so the diff reads as
 * added / removed / moved (k changed) / retyped fields. Set CLINSECURE=yes for a self-signed
 * certificate. Run it on every release for the fieldmap changelog.
 */
if ((count($argv) >= 4) && ($argv[1] === "diff")) {
   $a = rtrim($argv[2], "/"); $b = rtrim($argv[3], "/");
   $fa = array_map("basename", glob($a."/*.map")); $fb = array_map("basename", glob($b."/*.map"));
   foreach (array_diff($fb, $fa) as $f) echo "+ page ".substr($f, 0, -4)."\n";
   foreach (array_diff($fa, $fb) as $f) echo "- page ".substr($f, 0, -4)."\n";
   foreach (array_intersect($fa, $fb) as $f) {
      $ma = fm_read($a."/".$f); $mb = fm_read($b."/".$f);
      $pg = substr($f, 0, -4);
      foreach ($mb as $k => $v) if (!isset($ma[$k])) echo "  ".$pg.": + ".$k." (".$v["type"].", k=".$v["k"].")\n";
      foreach ($ma as $k => $v) if (!isset($mb[$k])) echo "  ".$pg.": - ".$k." (".$v["type"].", k=".$v["k"].")\n";
      foreach ($ma as $k => $v) {
         if (!isset($mb[$k])) continue;
         $w = $mb[$k];
         if ($v["k"] !== $w["k"]) echo "  ".$pg.": ".$k." moved k=".$v["k"]." -> k=".$w["k"]."\n";
         if ($v["type"] !== $w["type"]) echo "  ".$pg.": ".$k." retyped ".$v["type"]." -> ".$w["type"]."\n";
         if (($v["must"] !== $w["must"]) || ($v["ro"] !== $w["ro"])) echo "  ".$pg.": ".$k." must/ro ".$v["must"]."/".$v["ro"]." -> ".$w["must"]."/".$w["ro"]."\n";
      }
   }
   exit(0);
}
if (count($argv) < 5) {
   echo "usage: php fieldmap-dump.php <host> <key> <secret> <outdir>  |  php fieldmap-dump.php diff <dirA> <dirB>\n";
   exit(1);
}
function fm_read($f) {
   $r = array();
   foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
      $x = explode("\t", $l);
      if (count($x) < 6) continue;
      $r[$x[0].":".$x[1]] = array("k" => $x[2], "type" => $x[3], "must" => $x[4], "ro" => $x[5]);
   }
   return $r;
}
function fm_post($url, $doc, $key, $secret) {
   $ts = (string)time(); $nonce = bin2hex(random_bytes(8));
   $sig = hash_hmac("sha256", "kylone-api-v2\n".$key."\n".$ts."\n".$nonce."\n".hash("sha256", $doc), $secret);
   $c = curl_init();
   curl_setopt_array($c, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_URL => $url, CURLOPT_POST => 1, CURLOPT_TIMEOUT => 120,
      CURLOPT_POSTFIELDS => array("xml" => $doc, "akey" => $key, "ats" => $ts, "anonce" => $nonce, "asig" => $sig),
      CURLOPT_USERAGENT => "fieldmap-dump/1.0", CURLOPT_ENCODING => "gzip"));
   if (getenv("CLINSECURE") == "yes") { curl_setopt($c, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($c, CURLOPT_SSL_VERIFYHOST, false); }
   $r = curl_exec($c); curl_close($c);
   return $r;
}
function fm_call($host, $key, $secret, $fn, $args) {
   $a = "";
   if ($args !== "") foreach (explode("&", $args) as $kv) { $p = explode("=", $kv, 2); $a .= '<atr name="'.htmlspecialchars($p[0]).'">'.htmlspecialchars(isset($p[1]) ? $p[1] : "").'</atr>'; }
   $doc = '<?xml version="1.0"?><cLst><container><operation><type>request</type><version>2</version></operation><data model="struct"><request>'.$fn.'</request><args>'.$a.'</args></data></container></cLst>';
   $r = fm_post("https://".$host."/portal/", $doc, $key, $secret);
   $x = @simplexml_load_string($r);
   return $x ? $x : false;
}
function fm_lines($x, $kind) {
   $out = "";
   if (!$x || !isset($x->container->operation)) return $out;
   $op = $x->container->operation;
   if (isset($op->fieldmap)) foreach ($op->fieldmap->atr as $f)
      $out .= $kind."\t".$f["name"]."\t".(isset($f["k"]) ? $f["k"] : "")."\t".$f["type"]."\t".(isset($f["must"]) ? $f["must"] : "")."\t".(isset($f["ro"]) ? $f["ro"] : "")."\t".str_replace(array("\t", "\n"), " ", (string)$f)."\n";
   if (isset($op->resources)) foreach ($op->resources->res as $r) {
      $out .= $kind."\tres:".$r["name"]."\t".$r["k"]."\tres-".$r["mode"]."\t\t\t".str_replace(array("\t", "\n"), " ", (string)$r["title"])."\n";
      foreach ($r->arg as $g) $out .= $kind."\tres:".$r["name"].".".$g["name"]."\t\t".$g["type"]."\t".$g["must"]."\t\t".str_replace(array("\t", "\n"), " ", (string)$g)."\n";
   }
   if (isset($op->options)) foreach ($op->options->opt as $o) {
      $vals = array();
      foreach ($o->atr as $v) $vals[] = (string)$v["val"];
      $out .= $kind."\topt:".$o["name"]."\t\toption\t\t\t".implode("|", $vals)."\n";
   }
   return $out;
}
$host = $argv[1]; $key = $argv[2]; $secret = $argv[3]; $dir = rtrim($argv[4], "/");
@mkdir($dir, 0755, true);
$calls = fm_call($host, $key, $secret, "apicalls", "");
if (!$calls || !isset($calls->container->data->elm)) { echo "apicalls failed\n"; exit(1); }
$n = 0;
foreach ($calls->container->data->elm->atr as $a) {
   $fn = (string)$a["n"];
   if ((substr($fn, -6) === "_clear") || (strpos((string)$a, "[web only]") !== false)) continue;
   $lst = fm_call($host, $key, $secret, $fn, "");
   $txt = fm_lines($lst, "list");
   // the first record's view (a page with records), or the single-record view
   $keyfield = ($lst && isset($lst->container->operation->keyfield)) ? (string)$lst->container->operation->keyfield : "";
   $first = "";
   if ($lst && isset($lst->container->data->elm)) foreach ($lst->container->data->elm[0]->atr as $v) if ((string)$v["n"] === $keyfield) { $first = (string)$v; break; }
   if ($first !== "") $txt .= fm_lines(fm_call($host, $key, $secret, $fn, "act=view&key=".$first), "view");
   file_put_contents($dir."/".$fn.".map", $txt);
   $n++;
   echo $fn.": ".substr_count($txt, "\n")." lines\n";
}
echo $n." pages dumped to ".$dir."\n";
?>
