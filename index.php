<?php
ini_set('max_execution_time', 300); // 5 minute execution time on script
$sleepVar = 10; // seconds to sleep, use with sleep();

// Construct a valid search that brings back results associated with
// the Grant # HD052120 and Affiliations: [Florida State University; FSU; 
// Florida Center for Reading Research; FCRR]
// The search will return a list of matching IDs; use those IDs to iterate 
// through another API call to get the specific info per article

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

sleep($sleepVar); // Sleep time between server calls

// Construct eFetch request and store in XML variable
// eFetch does not support returning JSON unfortunately
$eFetch = "http://eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi?db=pubmed&retmode=xml&id={$idList}";
$eFetchXML = simplexml_load_file($eFetch) or die ("Problem with loading XML from eFetch");

// IMPORTANT TO REALIZE AT THIS POINT
// The JSON array is Keyed via the UID, but the XML array is NOT, it is queued 
// up in the order of IDs passed to it.
// If you base BOTH retrieval systems on $i and $i++, they should all maintain 
// horizontal consistency

// Create an array from the CSV $idList and use that as the index value to sort 
// through both datastreams at once
$idListArray = explode(",",$idList);

$recordsArray = array();
for($index = 0; $index < (count($idListArray) - 1); $index++){
    // This Loop will allow us to go through each record and pull out what we 
    // want and we can store each processed record as part of an array that gets 
    // checked against DB and processed into MODS XML format.
    
 //****   // Store in the array the PDF URL String?
    
//    
// VARIABLES FROM EFETCH. coming from XML stream:
//

    // MedlineCitation Vars -- keep in mind that a loop structure has to be
    //added to iterate between multiple articles
    $pmid = $eFetchXML->PubmedArticle[$index]->MedlineCitation->PMID->__toString();
    $issn = $eFetchXML->PubmedArticle[$index]->MedlineCitation->Article->Journal->ISSN->__toString();
    $volume = $eFetchXML->PubmedArticle[$index]->MedlineCitation->Article->Journal->JournalIssue->Volume->__toString();
    $issue = $eFetchXML->PubmedArticle[$index]->MedlineCitation->Article->Journal->JournalIssue->Issue->__toString();
    $journalTitle = $eFetchXML->PubmedArticle[$index]->MedlineCitation->Article->Journal->Title->__toString();
    $journalAbrTitle = $eFetchXML->PubmedArticle[$index]->MedlineCitation->Article->Journal->ISOAbbreviation->__toString();
    $articleTitle = $eFetchXML->PubmedArticle[$index]->MedlineCitation->Article->ArticleTitle->__toString(); // This is a full title, inclusive of SubTitle. May have to explode out on Colon
    $abstract = $eFetchXML->PubmedArticle[$index]->MedlineCitation->Article->Abstract->AbstractText; // may return array to iterate for multiple paragraphs
    $authors = $eFetchXML->PubmedArticle[$index]->MedlineCitation->Article->AuthorList; // will return Array of authors. Contains Affiliation info as well, which is an object
    $affiliationSample = $eFetchXML->PubmedArticle[$index]->MedlineCitation->Article->AuthorList->Author[0]->AffiliationInfo->Affiliation; // just a sample, testing double array within object chain, gonna have to build into the author loop
    $grants = $eFetchXML->PubmedArticle[$index]->MedlineCitation->Article->GrantList; // returns an array with objects containing GrantID, Acronym, Agency, Country
    $publicationType = $eFetchXML->PubmedArticle[$index]->MedlineCitation->Article->PublicationTypeList->PublicationType; // may return an array? otherwise, just "JOURNAL ARTICLE"
    $keywords = $eFetchXML->PubmedArticle[$index]->MedlineCitation->KeywordList->Keyword; // returns an array which can be iterated for all keywords #woot

    // PubmedData chain has variables too, but mostly redundant and incomplete 
    // compared to the JSON variable
    $articleIds = $eFetchXML->PubmedArticle[$index]->PubmedData->ArticleIdList->ArticleId; // returns array of IDs keyed by number, so not helpful in extrapolating what the ID is. BUT, can iterate this and check for PMC####### and NIHMS###### rows

    // Not all articles have it, but there are some with Mesh arrays (Medical Subject Headings)
    $mesh = $eFetchXML->PubmedArticle[$index]->MedlineCitation->MeshHeadingList; // returns array with objects for elements
    
//
// VARIABLES FROM ESUMMARY, coming from JSON stream
//

    $uid = $json_eSum->result->uids[$index]; // Important Hook for rest of Variables in JSON Tree
    
    $sortTitle = $json_eSum->result->$uid->sorttitle;
    $volumeESum = $json_eSum->result->$uid->volume;
    $issueESum = $json_eSum->result->$uid->issue;
    $pages = $json_eSum->result->$uid->pages;
    $lang = $json_eSum->result->$uid->lang; // returns array
    $issnESum = $json_eSum->result->$uid->issn;
    $essnESum = $json_eSum->result->$uid->essn;
    $pubTypeESum = $json_eSum->result->$uid->pubtype; // returns an array
    $articleIdESum = $json_eSum->result->$uid->articleids; // returns an array
    $viewCount = $json_eSum->result->$uid->viewcount;
    $sortPubDate = $json_eSum->result->$uid->sortpubdate;

//
// PREPARE GRABBED DATA FOR PASSING TO AUTHOR ARRAY
//

    
    
    
    
    print $index;
    print "     ";
    print $uid;
    print "     ";
    print $pmid;
    print "<br>";
    
    $recordsArray[$uid] = array(); // pass processed stuff into here and it will be stored, keyed to the UID
    
}

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

print "<h1>Results from eFetch XML Load</h1>";
print "<pre>";
print_r($eFetchXML);
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
