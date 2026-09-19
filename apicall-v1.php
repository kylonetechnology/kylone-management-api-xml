<?php
/*
 * Example PHP application to make calls on
 * Kylone MicroCMS XML API v1 (guide v2.2.0, 2018)
 * Revision 19 September, 2026: robustness for current PHP releases, wire format unchanged
 *
 *   php apicall-v1.php <host or URL> <username> <password> <function> [argstring] [export_name]
 *
 * Environment: CLQUITE=yes prints only ok/failed, CLINSECURE=yes accepts a self-signed
 * certificate on an https URL. A bare host name means http://<host>/portal/; give the full
 * URL for https. Exit status is 0 when the function call succeeded.
 */

// posts data with cURL and get XML document as response
function doc_post($url, $doc)
{
   $ct = curl_init();
   curl_setopt($ct, CURLOPT_RETURNTRANSFER, TRUE);
   curl_setopt($ct, CURLOPT_FOLLOWLOCATION, TRUE);
   curl_setopt($ct, CURLOPT_AUTOREFERER, TRUE);
   curl_setopt($ct, CURLOPT_CONNECTTIMEOUT, 30);
   curl_setopt($ct, CURLOPT_TIMEOUT, 20);
   curl_setopt($ct, CURLOPT_MAXREDIRS, 2);
   curl_setopt($ct, CURLOPT_URL, $url);
   curl_setopt($ct, CURLOPT_POST, 1);
   curl_setopt($ct, CURLOPT_POSTFIELDS, array("xml" => $doc));
   curl_setopt($ct, CURLOPT_USERAGENT, "API Client, MicroCMS-XML-API/v2.2.0");
   curl_setopt($ct, CURLOPT_ENCODING , "gzip");
   // keep the POST body across an http -> https redirect (a headend forcing SSL answers
   // 301/302; without this curl re-issues the redirected request as a GET and the login fails)
   curl_setopt($ct, CURLOPT_POSTREDIR, 3);
   if (getenv("CLINSECURE") == "yes") {
      curl_setopt($ct, CURLOPT_SSL_VERIFYPEER, FALSE);
      curl_setopt($ct, CURLOPT_SSL_VERIFYHOST, FALSE);
   }
   $res = curl_exec($ct);
   if ($res === false) {
      fwrite(STDERR, "curl: ".curl_error($ct)."\n");
      $res = "";
   }
   curl_close($ct);
   return $res;
}

// parses a response document; false when it is not a response or the status is not ok
function doc_parse($resp)
{
   if (!is_string($resp) || (trim($resp) === ""))
      return false;
   $flags = LIBXML_COMPACT | LIBXML_NOBLANKS | LIBXML_NOCDATA | LIBXML_NOEMPTYTAG;
   $flags |= LIBXML_NONET | LIBXML_PEDANTIC | LIBXML_PARSEHUGE;
   $xobj = @simplexml_load_string($resp, "SimpleXMLElement", $flags);
   if (($xobj === false) || !isset($xobj->container->operation->type))
      return false;
   if ((string)$xobj->container->operation->type != "response")
      return false;
   if (!isset($xobj->container->operation->status))
      return false;
   if ((string)$xobj->container->operation->status != "ok")
      return false;
   return $xobj;
}

// converts 'act=val&key=val'
// to '<args><atr name="act">val</atr><atr name="key">val</atr></args>'
function arg_construct($argstr, $sep = "&")
{
   $l = "";
   // no sanitising and no element limit: values are XML-escaped below, and a save with more
   // than twenty fields must not have its tail folded into the twentieth value
   $v = explode($sep, $argstr);
   $c = count($v);
   for ($i = 0; $i < $c; $i++) {
      if ($v[$i] != "") {
        $x = explode("=", $v[$i], 2);
        if (count($x) == 2) {
           $l .= '<atr name="'.htmlspecialchars($x[0], ENT_QUOTES).'">';
           $l .= htmlspecialchars($x[1], ENT_NOQUOTES);
           $l .= '</atr>';
        }
      }
   }
   return '<args>'.$l.'</args>';
}

// creates XML document with target function name, parameters list
// and with session ID if given
function doc_construct($fname, $argxml, $ssnid = "", $xfile = false, $sep = "&")
{
   $s = ($ssnid != "") ? '<atr name="s">'.$ssnid.'</atr>' : '';
   $x = '<?xml version="1.0"'.'?'.'>';
   $x .= '
<cLst>
  <container>
    <operation>
       <type>request</type>
       <cookies>'.$s.'</cookies>
    </operation>
    <data model="struct">
      <request>'.htmlspecialchars($fname, ENT_QUOTES).'</request>
      '.arg_construct($argxml, $sep).'
    </data>
  </container>
</cLst>
';
   if ($xfile !== false)
      file_put_contents($xfile."_".$fname."_request.xml", $x);
   return $x;
}

// performs inquiry and returns response as it is (XML document)
function do_query_and_get_doc($url, $doc, $isq = false)
{
   global $lastcallok;
   $resp = doc_post($url, $doc);
   $lastcallok = (doc_parse($resp) !== false);
   if ($isq)
      echo ($lastcallok ? "ok" : "failed")."\n";
   return $resp;
}

// performs inquiry and returns response as php-object
// after doing some sanitiy checks
function do_query_and_get_obj($url, $doc, $xfile)
{
   $resp = doc_post($url, $doc);
   if ($xfile !== false)
      file_put_contents($xfile."_login_response.xml", $resp);
   return doc_parse($resp);
}

// creates login document and gets sessinid with inqury
function do_login($url, $uname, $pass, $xfile, $isq)
{
   $logindoc = doc_construct("login", "username=".$uname.chr(27)."password=".$pass, "", $xfile, chr(27));
   $response = do_query_and_get_obj($url, $logindoc, $xfile);
   if ($response === false) {
      if ($isq)
         echo "failed\n";
      return false;
   }
   if (!isset($response->container->operation->cookies)) {
      if ($isq)
         echo "failed\n";
      return false;
   }
   $cvals = array();
   foreach ($response->container->operation->cookies->children() as $node) {
      $n = $node['n'];
      $cvals["$n"] = (string)$node;
   }
   return (isset($cvals["s"]) ? $cvals["s"] : false);
}

// performs login, apicall and logout
function do_apicall($url, $uname, $pass, $fname, $argstr, $xfile)
{
   $isq = (getenv("CLQUITE") == "yes");
// performs login and gets sessinid if possible
   $ssnid = do_login($url, $uname, $pass, $xfile, $isq);
   if ($ssnid === false)
      return false;

// performs apicall for target function with parametes and sessionid
   $calldoc = doc_construct($fname, $argstr, $ssnid, $xfile);
   $callres = do_query_and_get_doc($url, $calldoc, $isq);
   if ($xfile !== false)
      file_put_contents($xfile."_".$fname."_response.xml", $callres);
    
// performs logout without considering the previous result
   $logoutdoc = doc_construct("logout", "", $ssnid, $xfile);
   $logoutres= do_query_and_get_doc($url, $logoutdoc);
   if ($xfile !== false)
      file_put_contents($xfile."_logout_response.xml", $logoutres);

// returns request and response document in array for the target function
   return array($calldoc, $callres);
}

if (!isset($argv[4])) {
   echo "Usage: CLQUITE=yes ".$argv[0];
   echo " <host> <username> <password> <function> <argstring> [export_name]";
   echo "\n";
   echo "php ".$argv[0];
   echo " 10.47.48.1 admin kylone cpustat \"arg1=val1&arg2=val2\" cpustat_log";
   echo "\n\n";
   exit();
}

$url = $argv[1];
if (strstr($url, "http") === false) {
   $url = "http://".$argv[1]."/portal/";
}
$pwd = $argv[3];
if (($argv[2] == "test") && ($pwd === "-")) {
   $pwd = "test";
}
$lastcallok = false;

$v = do_apicall(
        $url,                               // URL
        $argv[2],                           // Username
        $pwd,                               // Password
        $argv[4],                           // Function name
        (isset($argv[5]) ? $argv[5]: ""),   // Parameters String
        (isset($argv[6]) ? $argv[6]: false) // export each doucments to file
     );

if ($v === false) {
   // login failed or the headend did not answer: nothing to print
   if (getenv("CLQUITE") == "")
      echo "login failed or no answer from ".$url."\n";
   exit(1);
}
if (getenv("CLQUITE") == "") {
   if (isset($argv[6])) {
      echo "All requests and responses are exported to ".$argv[6]."_*.xml\n";
   } else {
      echo "Request:\n".$v[0]."\nResponse:\n".$v[1]."\n";
   }
}
exit($lastcallok ? 0 : 1);

?>
