<?php
ini_set('max_execution_time', 300); // 5 minute execution time on script
$sleepVar = 10; // seconds to sleep, use with sleep();

// Construct a valid search that brings back results associated with
// the Grant # HD052120 and Affiliations: [Florida State University; FSU; 
// Florida Center for Reading Research; FCRR]
// The search will return a list of matching IDs; use those IDs to iterate 
// through another API call to get the specific info per article, like PMCID 
// for manuscript harvesting

$combined_search = "http://eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi?db=pubmed&retmode=json&retmax=1000&tool=FSU_IR&email=aretteen@fsu.edu&term=(HD052120%5BGrant+Number%5D+AND+FCRR%5BAffiliation%5D)+OR+(HD052120%5BGrant+Number%5D+AND+(Florida+Center+for+Reading+Research%5BAffiliation%5D))+OR+(HD052120%5BGrant+Number%5D+AND+FSU%5BAffiliation%5D)+OR+(HD052120%5BGrant+Number%5D+AND+(Florida+State+University%5BAffiliation%5D))";
$response_search = file_get_contents($combined_search) or die("Problem with eSearch");
$json_response = json_decode($response_search);

$count = $json_response->esearchresult->count; // Number of results fetched

// Create the ID List String to pass to eSummary
// Store in:  $idList

$idList = "";
$i = "";
for ($i = 0; $i < $count; $i++){
    $idList .= "{$json_response->esearchresult->idlist[$i]}";
    if($i != ($count - 1)){
    $idList .= ",";
    }
}

sleep($sleepVar); // Give the server some time to rest

// Construct eSummary request & decode the JSON
$eSum = "http://eutils.ncbi.nlm.nih.gov/entrez/eutils/esummary.fcgi?db=pubmed&retmode=json&id={$idList}";
$eSumResponse = file_get_contents($eSum) or die("Problem with eSummary");
$json_eSum = json_decode($eSumResponse);

// At some point, add interaction between the script and a file db of IDs to
// skip already-ingested objects

//
// DEV TEST
//
print "<h1>Results from eSummary</h1>";
print "<pre>";
print_r($json_eSum);
print "</pre>";


print "<h1>Results from eSearch</h1>";

print "<h2>Combined Search</h2>";
print "<pre>";
print_r($json_response);
print "</pre>";
//

/* DEV GRAVEYARD
 *****file get contents timeout****
 * $ctx = stream_context_create(array(
    'http' => array(
        'timeout' => 60
        )
    ));
 
 *
 * 
 * 
 * ****Code to use to grab the PDF from the server*****

$pathPDF = "http://www.ncbi.nlm.nih.gov/pmc/articles/PMC4750400/pdf/nihms723722.pdf";

$PDF = file_get_contents($pathPDF) or die("Could not get file");

file_put_contents("test.pdf", $PDF);
 * 
 **** SEARCH STRINGS BROKEN UP ****
$search_FCRR = "http://eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi?db=pubmed&retmode=json&retmax=1000&tool=FSU_IR&email=aretteen@fsu.edu&term=(((HD052120%5BGrant%20Number%5D)%20AND%20FCRR%5BAffiliation%5D))"; // Grant Number & "FCRR"
$search_FCRR_long = "http://eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi?db=pubmed&retmode=json&retmax=1000&tool=FSU_IR&email=aretteen@fsu.edu&term=((HD052120%5BGrant+Number%5D)%20AND%20Florida+Center+for+Reading+Research%5BAffiliation%5D)"; // Grant Number & "Florida Center for Reading Research"
$search_FSU_long= "http://eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi?db=pubmed&retmode=json&retmax=1000&tool=FSU_IR&email=aretteen@fsu.edu&term=((HD052120%5BGrant%20Number%5D)%20AND%20Florida%20State%20University%5BAffiliation%5D)"; // Grant Number & "Florida State University"
$search_FSU = "http://eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi?db=pubmed&retmode=json&retmax=1000&tool=FSU_IR&email=aretteen@fsu.edu&term=((HD052120%5BGrant+Number%5D)%20AND%20Florida+State+University%5BAffiliation%5D)"; // Grant Number & "FSU"

 * 
 * 
 */
?>
