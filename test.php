<?php

$fetch = "http://eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi?db=pubmed&retmode=xml&id=26869730,23725549";

//$results = file_get_contents($fetch);

$xml = simplexml_load_file($fetch) or die ("Could not load xml stream");
print "<pre>";
print_r($xml);
print "</pre>";
?>

