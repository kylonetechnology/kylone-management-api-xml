<?php
/*
 * Example PHP application to make calls on
 * Kylone MicroCMS XML API, v2.2.0
 * Revision 5 October, 2018
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
   curl_setopt($ct, CURLOPT_HTTPHEADER, array('Content-Encoding: UTF-8'));
   curl_setopt($ct, CURLOPT_ENCODING , "gzip");
   $res = curl_exec($ct);
   curl_close($ct);
   return $res;
}

// converts 'act=val&key=val'
// to '<args><atr name="act">val</atr><atr name="key">val</atr></args>'
function arg_construct($argstr)
{
   $l = "";
   $v = explode("&", str_replace(array("\\", "0x"), "", $argstr), 20);
   $c = count($v);
   for ($i = 0; $i < $c; $i++) {
      if ($v[$i] != "") {
        $x = explode("=", $v[$i], 2);
        $l .= '<atr name="'.htmlspecialchars($x[0], ENT_QUOTES).'">';
        $l .= htmlspecialchars($x[1], ENT_NOQUOTES);
        $l .= '</atr>';
      }
   }
   return '<args>'.$l.'</args>';
}

// creates XML document with target function name, parameters list
// and with session ID if given
function doc_construct($fname, $argxml, $ssnid = "", $xfile = false)
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
      '.arg_construct($argxml).'
    </data>
  </container>
</cLst>
';
   if ($xfile !== false)
      file_put_contents($xfile."_".$fname."_request.xml", $x);
   return $x;
}

// performs inquiry and returns response as it is (XML document)
function do_query_and_get_doc($url, $doc)
{
   return doc_post($url, $doc);
}

// performs inquiry and returns response as php-object
// after doing some sanitiy checks
function do_query_and_get_obj($url, $doc, $xfile)
{
   $resp = doc_post($url, $doc);
   if ($xfile !== false)
      file_put_contents($xfile."_login_response.xml", $resp);
   $flags = LIBXML_COMPACT | LIBXML_NOBLANKS | LIBXML_NOCDATA | LIBXML_NOEMPTYTAG;
   $flags |= LIBXML_NONET | LIBXML_PEDANTIC | LIBXML_PARSEHUGE;
   $xobj = simplexml_load_string($resp, "SimpleXMLElement", $flags);
   if (!isset($xobj->container->operation->type))
      return false;
   if ((string)$xobj->container->operation->type != "response")
      return false;
   if (!isset($xobj->container->operation->status))
      return false;
   if ((string)$xobj->container->operation->status != "ok")
      return false;
   return $xobj;
}

// creates login document and gets sessinid with inqury
function do_login($url, $uname, $pass, $xfile)
{
   $logindoc = doc_construct("login", "username=".$uname."&password=".$pass, "", $xfile);
   $response = do_query_and_get_obj($url, $logindoc, $xfile);
   if ($response === false)
      return false;
   if (!isset($response->container->operation->cookies))
      return false;
   $cvals = false;
   foreach ($response->container->operation->cookies->children() as $node) {
      $n = $node['n'];
      $cvals["$n"] = (string)$node;
   }
   return (isset($cvals["s"]) ? $cvals["s"] : false);
}

// performs login, apicall and logout
function do_apicall($url, $uname, $pass, $fname, $argstr, $xfile)
{
// performs login and gets sessinid if possible
   $ssnid = do_login($url, $uname, $pass, $xfile);
   if ($ssnid === false)
      return false;

// performs apicall for target function with parametes and sessionid
   $calldoc = doc_construct($fname, $argstr, $ssnid, $xfile);
   $callres = do_query_and_get_doc($url, $calldoc);
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
   echo "Usage: ".$argv[0];
   echo " <host> <username> <password> <function> <argstring> [export_name]";
   echo "\n";
   echo "php ".$argv[0];
   echo " 10.47.48.1 admin kylone cpustat \"arg1=val1&arg2=val2\" cpustat_log";
   echo "\n\n";
   exit();
}

$v = do_apicall(
        "http://".$argv[1]."/portal/",      // URL
        $argv[2],                           // Username
        $argv[3],                           // Password
        $argv[4],                           // Function name
        (isset($argv[5]) ? $argv[5]: ""),   // Parameters String
        (isset($argv[6]) ? $argv[6]: false) // export each doucments to file
     );

if (isset($argv[6])) {
   echo "All requests and responses are exported to ".$argv[6]."_*.xml\n";
} else {
   echo "Request:\n".$v[0]."\nResponse:\n".$v[1]."\n";
}

?>
