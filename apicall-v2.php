<?php
/*
 * Kylone MicroCMS XML API v2 — reference client (Management API, API-key HMAC)
 *
 *   php apicall-v2.php <host> <key name> <key secret> <function> [argstring] [export_name]
 *
 *   php apicall-v2.php 10.75.2.43 mgmt1 ABCD...52chars apicalls
 *   php apicall-v2.php 10.75.2.43 mgmt1 ABCD...52chars network "act=view&key=lan2"
 *
 * Differences from the 2018 v1 client (apicall-example.php):
 *   - no login/logout: every request authenticates itself with the API key
 *     (Management > API Keys, Use = "Management API v2"), no session cookie
 *   - <operation><version>2</version> marks the request as v2
 *   - HTTPS by default (CLPLAIN=yes for plain HTTP against a headend with var/apiplain,
 *     CLINSECURE=yes to accept a self-signed certificate)
 *
 * Test hooks: CLTS=<unix time> and CLNONCE=<hex> pin the timestamp / nonce (expired, replayed).
 *
 * Signature: four POST fields next to "xml"
 *   akey    key name          ats  unix time        anonce  16 random hex chars
 *   asig    hex HMAC-SHA256(secret, "kylone-api-v2\n" akey "\n" ats "\n" anonce "\n" sha256hex(xml))
 * The secret is the base32 string exactly as the portal shows it (ASCII bytes).
 * A failed request answers HTTP 4xx and <status>failed</status><reason>code</reason>;
 * reason "expired" carries the server time in the data element for a clock resync.
 */

function doc_post($url, $doc, $keyname, $secret, &$httpcode)
{
   // CLTS / CLNONCE override the timestamp and nonce: test hooks for the expired/replayed cases
   $ts = (getenv("CLTS") != "") ? getenv("CLTS") : (string)time();
   $nonce = (getenv("CLNONCE") != "") ? getenv("CLNONCE") : bin2hex(random_bytes(8));
   $msg = "kylone-api-v2\n".$keyname."\n".$ts."\n".$nonce."\n".hash("sha256", $doc);
   $sig = hash_hmac("sha256", $msg, $secret);
   $ct = curl_init();
   curl_setopt($ct, CURLOPT_RETURNTRANSFER, TRUE);
   curl_setopt($ct, CURLOPT_CONNECTTIMEOUT, 30);
   curl_setopt($ct, CURLOPT_TIMEOUT, 60);
   curl_setopt($ct, CURLOPT_URL, $url);
   curl_setopt($ct, CURLOPT_POST, 1);
   curl_setopt($ct, CURLOPT_POSTFIELDS, array("xml" => $doc, "akey" => $keyname, "ats" => $ts, "anonce" => $nonce, "asig" => $sig));
   curl_setopt($ct, CURLOPT_USERAGENT, "API Client, MicroCMS-XML-API/v2.0");
   curl_setopt($ct, CURLOPT_ENCODING, "gzip");
   if (getenv("CLINSECURE") == "yes") {
      curl_setopt($ct, CURLOPT_SSL_VERIFYPEER, FALSE);
      curl_setopt($ct, CURLOPT_SSL_VERIFYHOST, FALSE);
   }
   $res = curl_exec($ct);
   $httpcode = curl_getinfo($ct, CURLINFO_HTTP_CODE);
   if ($res === false)
      $res = "curl: ".curl_error($ct);
   curl_close($ct);
   return $res;
}

// 'act=view&key=lan2' -> <args><atr name="act">view</atr><atr name="key">lan2</atr></args>
function arg_construct($argstr)
{
   $l = "";
   $v = explode("&", $argstr);
   foreach ($v as $item) {
      if ($item == "")
         continue;
      $x = explode("=", $item, 2);
      if (count($x) == 2)
         $l .= '<atr name="'.htmlspecialchars($x[0], ENT_QUOTES).'">'.htmlspecialchars($x[1], ENT_NOQUOTES).'</atr>';
   }
   return '<args>'.$l.'</args>';
}

function doc_construct($fname, $argstr)
{
   $x = '<?xml version="1.0"'.'?'.'>';
   $x .= '
<cLst>
  <container>
    <operation>
       <type>request</type>
       <version>2</version>
    </operation>
    <data model="struct">
      <request>'.htmlspecialchars($fname, ENT_QUOTES).'</request>
      '.arg_construct($argstr).'
    </data>
  </container>
</cLst>
';
   return $x;
}

if (!isset($argv[4])) {
   echo "Usage: [CLPLAIN=yes] [CLINSECURE=yes] [CLQUITE=yes] php ".$argv[0]." <host> <key name> <key secret> <function> [argstring] [export_name]\n";
   exit(2);
}
$url = $argv[1];
if (strstr($url, "http") === false)
   $url = ((getenv("CLPLAIN") == "yes") ? "http" : "https")."://".$argv[1]."/portal/";
$fname = $argv[4];
$argstr = isset($argv[5]) ? $argv[5] : "";
$xfile = isset($argv[6]) ? $argv[6] : false;

$doc = doc_construct($fname, $argstr);
$httpcode = 0;
$res = doc_post($url, $doc, $argv[2], $argv[3], $httpcode);
if ($xfile !== false) {
   file_put_contents($xfile."_".$fname."_request.xml", $doc);
   file_put_contents($xfile."_".$fname."_response.xml", $res);
}

$status = "failed";
$reason = "";
$flags = LIBXML_COMPACT | LIBXML_NOBLANKS | LIBXML_NOCDATA | LIBXML_NOEMPTYTAG | LIBXML_NONET | LIBXML_PARSEHUGE;
$xobj = @simplexml_load_string($res, "SimpleXMLElement", $flags);
if ($xobj && isset($xobj->container->operation->status)) {
   $status = (string)$xobj->container->operation->status;
   if (isset($xobj->container->operation->reason))
      $reason = (string)$xobj->container->operation->reason;
}
if (getenv("CLQUITE") == "yes") {
   echo $status.(($reason != "") ? " (".$reason.")" : "")."\n";
} else {
   echo "HTTP ".$httpcode.", status ".$status.(($reason != "") ? ", reason ".$reason : "")."\n";
   if ($xfile !== false)
      echo "Request and response exported to ".$xfile."_".$fname."_*.xml\n";
   else
      echo "Request:\n".$doc."\nResponse:\n".$res."\n";
}
exit(($status == "ok") ? 0 : 1);
?>
