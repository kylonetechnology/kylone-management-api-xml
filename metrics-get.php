<?php
/*
 * metrics-get.php — read a Kylone headend's metrics endpoint (Prometheus text format)
 *
 *   php metrics-get.php <host> <key name> <key secret>
 *
 * GET https://<host>/portal/?app=metrics with HTTP basic authentication: the user is the
 * Management API key's name, the password its secret as displayed. A read-only key is the
 * fitting one. HTTPS only (set CLINSECURE=yes for a self-signed certificate). Prints the
 * text as the headend sends it; a Prometheus server scrapes the same address with
 * basic_auth in its scrape configuration (guide, section 10).
 */
if (count($argv) < 4) {
   echo "usage: php metrics-get.php <host> <key name> <key secret>\n";
   exit(1);
}
$c = curl_init();
curl_setopt($c, CURLOPT_URL, "https://".$argv[1]."/portal/?app=metrics");
curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
curl_setopt($c, CURLOPT_CONNECTTIMEOUT, 15);
curl_setopt($c, CURLOPT_TIMEOUT, 60);
curl_setopt($c, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
curl_setopt($c, CURLOPT_USERPWD, $argv[2].":".$argv[3]);
curl_setopt($c, CURLOPT_USERAGENT, "metrics-get/1.0");
if (getenv("CLINSECURE") == "yes") {
   curl_setopt($c, CURLOPT_SSL_VERIFYPEER, false);
   curl_setopt($c, CURLOPT_SSL_VERIFYHOST, false);
}
$body = curl_exec($c);
$code = curl_getinfo($c, CURLINFO_HTTP_CODE);
if ($body === false)
   $body = "curl: ".curl_error($c);
curl_close($c);
if ($code != 200) {
   echo "HTTP ".$code."\n".$body."\n";
   exit(1);
}
echo $body;
exit(0);
?>
