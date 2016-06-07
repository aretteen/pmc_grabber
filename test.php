<?php

$fetch = "http://eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi?db=pubmed&retmode=xml&id=26869730,23725549";

//$results = file_get_contents($fetch);

$xml = simplexml_load_file($fetch) or die ("Could not load xml stream from EFetch");

// VARIABLES FROM EFETCH. coming from XML stream:

// MedlineCitation Vars -- keep in mind that a loop structure has to be added to iterate between multiple articles
$pmid = $xml->PubmedArticle[0]->MedlineCitation->PMID->__toString();
$issn = $xml->PubmedArticle[0]->MedlineCitation->Article->Journal->ISSN->__toString();
$volume = $xml->PubmedArticle[0]->MedlineCitation->Article->Journal->JournalIssue->Volume->__toString();
$issue = $xml->PubmedArticle[0]->MedlineCitation->Article->Journal->JournalIssue->Issue->__toString();
$journalTitle = $xml->PubmedArticle[0]->MedlineCitation->Article->Journal->Title->__toString();
$journalAbrTitle = $xml->PubmedArticle[0]->MedlineCitation->Article->Journal->ISOAbbreviation->__toString();
$articleTitle = $xml->PubmedArticle[0]->MedlineCitation->Article->ArticleTitle->__toString(); // This is a full title, inclusive of SubTitle. May have to explode out on Colon
$abstract = $xml->PubmedArticle[0]->MedlineCitation->Article->Abstract->AbstractText; // may return array to iterate for multiple paragraphs
$authors = $xml->PubmedArticle[0]->MedlineCitation->Article->AuthorList; // will return Array of authors. Contains Affiliation info as well, which is an object
$affiliationSample = $xml->PubmedArticle[0]->MedlineCitation->Article->AuthorList->Author[0]->AffiliationInfo->Affiliation; // just a sample, testing double array within object chain, gonna have to build into the author loop
$grants = $xml->PubmedArticle[0]->MedlineCitation->Article->GrantList; // returns an array with objects containing GrantID, Acronym, Agency, Country
$publicationType = $xml->PubmedArticle[0]->MedlineCitation->Article->PublicationTypeList->PublicationType; // may return an array? otherwise, just "JOURNAL ARTICLE"
$keywords = $xml->PubmedArticle[0]->MedlineCitation->KeywordList->Keyword; // returns an array which can be iterated for all keywords #woot

// PubmedData chain has variables too, but mostly redundant and incomplete compared to the JSON variable
$articleIds = $xml->PubmedArticle[0]->PubmedData->ArticleIdList->ArticleId; // returns array of IDs keyed by number, so not helpful in extrapolating what the ID is. BUT, can iterate this and check for PMC####### and NIHMS###### rows

// Not all articles have it, but there are some with Mesh arrays (Medical Subject Headings)
$mesh = $xml->PubmedArticle[1]->MedlineCitation->MeshHeadingList; // returns array with objects for elements

$abstractTwo = $xml->PubmedArticle[1]->MedlineCitation->Article->Abstract->AbstractText; // may return array to iterate for multiple paragraphs

// VARIABLES FROM ESUMMARY, coming from JSON Stream decoded into PHP array
sleep(5);
$eSum = "http://eutils.ncbi.nlm.nih.gov/entrez/eutils/esummary.fcgi?db=pubmed&retmode=json&id=26869730,23725549";
$results = file_get_contents($eSum) or die("Could not gt contents from ESummary");
$decoded = json_decode($results);

$uid = $decoded->result->uids[0]; // uids is an array that can be iterated over the "count" to get all uids
$sortTitle = $decoded->result->$uid->sorttitle;
$volumeESum = $decoded->result->$uid->volume;
$issueESum = $decoded->result->$uid->issue;
$pages = $decoded->result->$uid->pages;
$lang = $decoded->result->$uid->lang; // returns array
$issnESum = $decoded->result->$uid->issn;
$essnESum = $decoded->result->$uid->essn;
$pubTypeESum = $decoded->result->$uid->pubtype; // returns an array
$articleIdESum = $decoded->result->$uid->articleids; // returns an array
$viewCount = $decoded->result->$uid->viewcount;
$sortPubDate = $decoded->result->$uid->sortpubdate;



// Format extracted data -- at this stage, format what will be object arrays in string arrays for easier manipulation into XML...

    // publicationType parsing - they should all be journal articles, this may not be necessary
    // 
    // Mesh parsing



// Abstract Parse
// Whether text is contained in a single element or not, it will always return as an array.
// The following code expects $abstract to be presented as already parsed to the array level
unset($abstractString);
for($i = 0; $i < count($abstract); $i++){
    // Add "new line" functionality?
    $abstractString .= $abstract[$i]->__toString() ." ";
} 

// Grant Number Parsing
// Presents an iterative object full of "Grant" arrays
unset($grantIDString);
for ($i = 0; $i < count($grants->Grant); $i++){
    $grantIDString .= $grants->Grant[$i]->GrantID->__toString();
    if($i != (count($grants->Grant) - 1)){
        $grantIDString .= ", ";
    }
}

// Keyword Parsing
// Presents a direct array ready for iteration
unset($keywordString);
for ($i = 0; $i < count($keywords); $i++){
    $keywordString .= ucfirst($keywords[$i]->__toString());  // to comply with first character UC ... <3 Bryan
    if($i != (count($keywords) -1)){
        $keywordString .= ", ";
    }
}

// Author Parsing
// Given an Author array with various sub-arrays. Goal is to prepare
// a new Author Array which will be processed upon XML generation
// This should return an Array full of Author Arrays following: FirstName, LastName, Fullname, Affiliation
// Check in with Bryan about normalization of Author metadata in order to have it match the named authority files by Annie

$authorArray = array();
for ($i = 0; $i < count($authors->Author); $i++){
    $fname = $authors->Author[$i]->ForeName->__toString(); // Will return a string of Firstname + Middle Initial if given...
    $lname = $authors->Author[$i]->LastName->__toString();
    $fullname = $fname . " " . $lname;
    $authAffil = $authors->Author[$i]->AffiliationInfo->Affiliation->__toString(); // Test to see how many sample records have >1 Affil, but this will at least capture the first one listed
    
    
    $authorArray[$i] = array($fname,$lname,$fullname,$authAffil);
}

// ArticleID Parsing
// When sent here, var will be an array of object-arrays
$articleIdArray = array();
for ($i = 0; $i < (count($articleIdESum) - 1); $i++){
    // this is where we will do the leg-work of selecting which IDs we are interested in
    // and then pass those values to a keyed array, which can be looped through to
    // create individual <identifier> MODS tags
    $idtype = $articleIdESum[$i]->idtype;
    $value = $articleIdESum[$i]->value;
    
    if($idtype == "doi" || $idtype == "pmc" || $idtype == "mid" || $idtype == "rid" || $idtype == "eid" || $idtype == "pii"){
      $articleIdArray[$i] = array($idtype,$value);
    }
    
}

// Article Title Parsing
// Title variables are available from the XML stream and the JSON stream
// Goal is to parse what we have returned into 
// NonSort, sortTitle, startTitle, subTitle, fullTitle
// and store that in a Title Array to be parsed for MODS generation
// The following using the XML Stream "Article Title" as basis for everything else

    // Generate nonsort var

    $nonsorts = array("A","An","The");
    $title_array = explode(" ", $articleTitle);
    if (in_array($title_array[0], $nonsorts)){
        $nonsort = $title_array[0];
        $sortTitle = implode(" ", array_slice($title_array, 1)); // rejoins title array starting at first element
    } else {
        $nonsort = FALSE;
        $sortTitle = $articleTitle;
    }
    
    // Generate subTitle and startTitle from fullTitle string
    $subTitleArray = explode(": ",$articleTitle);
    // now $subTitleArray[0] will be startTitle & [1] will be subTitle
    $startTitle = $subTitleArray[0];
    $subTitle = $subTitleArray[1];
    
    // Combine it all into one master title array to be parsed for MODS Record
    $parsedTitleArray = array($nonsort,$sortTitle,$startTitle,$subTitle,$articleTitle);
    
// Mesh Subject Terms Parsing
// Some records will have an object array of Subject Terms
// for use in <subject authority="mesh"><topic></topic></subject>

// Put this on hold until talk with Matt M about how this will be ingested
    

// Print out results to test variable chains

print "<h2>Results from ESummary Variables and JSON Parsing</h2>";

print "UID: ";
print $uid;
print " " . gettype($uid);
print "<br>";
print "Sort Title: ";
print $sortTitle;
print " " . gettype($sortTitle);
print "<br>";
print "Volume: ";
print $volumeESum;
print " " . gettype($volumeESum);
print "<br>";
print "Issue: ";
print $issueESum;
print " " . gettype($issueESum);
print "<br>";
print "Pages: ";
print $pages;
print " " . gettype($pages);
print "<br>";
print "<hr>";
print "<h3>lang</h3>";
print "<pre>";
print_r($lang);
print "</pre>";
print "<hr>";
print "<br>";
print "ISSN: ";
print $issnESum;
print " " . gettype($issnESum);
print "<br>";
print "ESSN: ";
print $essnESum;
print " " . gettype($essnESum);
print "<br>";
print "<hr>";
print "<h3>Pub Type</h3>";
print "<pre>";
print_r($pubTypeESum);
print "</pre>";
print "<hr>";
print "<h3>Article Ids</h3>";
print "<pre>";
print_r($articleIdESum);
print "</pre>";
print "<br><br>";
print "Parsed Article ID Array:<br>";
print "<pre>";
print_r($articleIdArray);
print "</pre>";
print "<hr>";
print "<br>";
print "<hr>";
print "View Count: ";
print $viewCount;
print "<br>";
print "Sort Pub Date: ";
print $sortPubDate;
print " " . gettype($sortPubDate);

print "<h2>Results from EFetch Variables and XML Parsing</h2>";
print "PMID: ";
print $pmid;
print " " . gettype($pmid);
print "<br>";
print "ISSN: ";
print $issn;
print " " . gettype($issn);
print "<br>";
print "Volume: ";
print $volume;
print " " . gettype($volume);
print "<br>";
print "Issue: ";
print $issue;
print " " . gettype($issue);
print "<br>";
print "Journal Title: ";
print $journalTitle;
print " " . gettype($journalTitle);
print "<br>";
print "Abr Title: ";
print $journalAbrTitle;
print " " . gettype($journalAbrTitle);
print "<br>";
print "<hr>";
print "Article Title: ";
print $articleTitle;
print " " . gettype($articleTitle);
print "<br><br>";
print "<h3>Parsed Article Title Array</h3>";
print "<pre>";
print_r($parsedTitleArray);
print "</pre>";
print "<br>";
print "<hr>";
print "<h3>abstract</h3>";
print "<pre>";
print_r($abstract);
print "<br><br><br>";
print_r($abstractTwo);
print "</pre>";
print "<br><br>";
print "Parsed Abstract:<br>";
print $abstractString;
print "<br>";
print "<hr>";
print "<h3>authors</h3>";
print "<pre>";
print_r($authors);
print "</pre>";
print "<br>";
print "<br>";
print "Affiliation for Author 1: ";
print $affiliationSample;
print "<br>";
print "<br>";
print "Parsed Author Array: ";
print "<br>";
print "<pre>";
print_r($authorArray);
print "</pre>";
print "<hr>";
print "<h3>grants</h3>";
print "<pre>";
print_r($grants);
print "</pre>";
print "<br>";
print "Grant ID String: ";
print $grantIDString;
print "<br><br>";
print "<hr>";
print "Publication Type: ";
print $publicationType;
print " " . gettype($publicationType);
print "<br>";
print "<br>";
print "<hr>";
print "<h3>keywords</h3>";
print "<pre>";
print_r($keywords);
print "</pre>";
print "<br><br>";
print "Keyword String: ";
print $keywordString;
print "<hr>";
print "<h3>Mesh Headings</h3>";
print "<pre>";
print_r($mesh);
print "</pre>";

print "<h3>verify results here</h3>";
print "<pre>";
print_r($xml);
print "</pre>";
 
 
/* SAMPLE CODE FOR WRITING MODS DOCUMENT FROM PARSED VARIABLES
      //
      // Create MODS object
      /////////////////////
      $xml = new SimpleXMLElement('<mods xmlns="http://www.loc.gov/mods/v3" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:mods="http://www.loc.gov/mods/v3" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:etd="http://www.ndltd.org/standards/metadata/etdms/1.0/" xmlns:flvc="info:flvc/manifest/v1" xsi:schemaLocation="http://www.loc.gov/standards/mods/v3/mods-3-4.xsd" version="3.4"></mods>');
      // Build title
      $xml->addChild('titleInfo');
      $xml->titleInfo->addAttribute('lang', 'eng');
      $xml->titleInfo->addChild('title', htmlspecialchars($title));
      if ($nonsort) { $xml->titleInfo->addChild('nonSort', htmlspecialchars($nonsort)); }
      if ($submission_subtitle) { $xml->titleInfo->addChild('subTitle', htmlspecialchars($submission_subtitle)); }
      // Build authors
      unset($cpauthors);
      unset($cpauthors_array);
      foreach ($author_array as $author) {
        $a = $xml->addChild('name');
        $a->addAttribute('type', 'personal');
        $a->addAttribute('authority', 'local');
        if ($author['middle_name']) {
          $a->addChild('namePart', htmlspecialchars("{$author['first_name']} {$author['middle_name']}"))->addAttribute('type', 'given');
          $cpauthors_array[] = "{$author['first_name']} {$author['middle_name']} {$author['last_name']}";
        }
        else {
          $a->addChild('namePart', htmlspecialchars("{$author['first_name']}"))->addAttribute('type', 'given');
          $cpauthors_array[] = "{$author['first_name']} {$author['last_name']}";
        }
        $a->addChild('namePart', htmlspecialchars("{$author['last_name']}"))->addAttribute('type', 'family');
        if ($author['institution']) {
          $a->addChild('affiliation', htmlspecialchars("{$author['institution']}"));
        }
        $a->addChild('role');
        $r1 = $a->role->addChild('roleTerm', 'author'); 
        $r1->addAttribute('authority', 'rda');
        $r1->addAttribute('type', 'text');
        $r2 = $a->role->addChild('roleTerm', 'aut'); 
        $r2->addAttribute('authority', 'marcrelator');
        $r2->addAttribute('type', 'code');
      }
      if (count($cpauthors_array) == 1) {
        $cpauthors = implode("", $cpauthors_array);
      }
      elseif (count($cpauthors_array) == 2) {
        $cpauthors = implode(" and ", $cpauthors_array);
      }
      else {
        $cpauthors = implode(", ", array_slice($cpauthors_array, 0, -2)) . ", " . implode(" and ", array_slice($cpauthors_array, -2)); 
      }
      // Origin Info
      $xml->addChild('originInfo');
      $dateIssued = $xml->originInfo->addChild('dateIssued', htmlspecialchars($submission_publication_date));
      $dateIssued->addAttribute('encoding', 'w3cdtf');
      $dateIssued->addAttribute('keyDate', 'yes');
      // Abstract
      if ($submission_abstract) { $xml->addChild('abstract', htmlspecialchars($submission_abstract)); }
      // Add identifiers (IID, DOI)
      $xml->addChild('identifier', $submission_iid)->addAttribute('type', 'IID');
      if ($submission_doi) { $xml->addChild('identifier', $submission_doi)->addAttribute('type', 'DOI'); }
      // Add related item
      if ($submission_publication_title) {
        $xml->addChild('relatedItem')->addAttribute('type', 'host');
        $xml->relatedItem->addChild('titleInfo');
        $xml->relatedItem->titleInfo->addChild('title', htmlspecialchars($submission_publication_title));
        if ($submission_publication_volume OR $submission_publication_issue OR $submission_publication_page_range) {
          $xml->relatedItem->addChild('part');
          if ($submission_publication_volume) { 
            $volume = $xml->relatedItem->part->addChild('detail');
            $volume->addAttribute('type', 'volume');
            $volume->addChild('number', htmlspecialchars($submission_publication_volume));
            $volume->addChild('caption', 'vol.');  
          }
          if ($submission_publication_issue) { 
            $issue = $xml->relatedItem->part->addChild('detail');
            $issue->addAttribute('type', 'issue');
            $issue->addChild('number', htmlspecialchars($submission_publication_issue));
            $issue->addChild('caption', 'iss.');  
          }
          if ($submission_publication_page_range) { 
            $e = $xml->relatedItem->part->addChild('extent');
            $e->addAttribute('unit', 'page');
            if (strpos($submission_publication_page_range, '-')) {
              $page_range_array = explode('-', $submission_publication_page_range);
              $xml->relatedItem->part->extent->addChild('start', htmlspecialchars($page_range_array[0]));
              $xml->relatedItem->part->extent->addChild('end', htmlspecialchars($page_range_array[1]));
            }
            else {
              $e->addChild('start', htmlspecialchars($submission_publication_page_range));
            }
          }
        }
      }
      // Notes (keywords, publication note, preferred citation)
      if ($submission_keywords) { $xml->addChild('note', htmlspecialchars($submission_keywords))->addAttribute('displayLabel', 'Keywords'); }
      if ($submission_publication_note) { $xml->addChild('note', htmlspecialchars($submission_publication_note))->addAttribute('displayLabel', 'Publication Note'); }
      if ($submission_preferred_citation) { $xml->addChild('note', htmlspecialchars($submission_preferred_citation))->addAttribute('displayLabel', 'Preferred Citation'); }
      if ($submission_grant_number) { $xml->addChild('note', htmlspecialchars($submission_grant_number))->addAttribute('displayLabel', 'Grant Number'); }
      // Add FLVC extensions
      $flvc = $xml->addChild('extension')->addChild('flvc:flvc', '', 'info:flvc/manifest/v1');
      $flvc->addChild('flvc:owningInstitution', 'FSU');
      $flvc->addChild('flvc:submittingInstitution', 'FSU');
      // Add static elements
      $xml->addChild('typeOfResource', 'text');
      $xml->addChild('genre', 'text')->addAttribute('authority', 'rdacontent');
      $xml->addChild('language');
      $l1 = $xml->language->addChild('languageTerm', 'English');
      $l1->addAttribute('type', 'text');
      $l2 = $xml->language->addChild('languageTerm', 'eng');
      $l2->addAttribute('type', 'code');
      $l2->addAttribute('authority', 'iso639-2b');
      $xml->addChild('physicalDescription');
      $rda_media = $xml->physicalDescription->addChild('form', 'computer');
      $rda_media->addAttribute('authority', 'rdamedia'); 
      $rda_media->addAttribute('type', 'RDA media terms');
      $rda_carrier = $xml->physicalDescription->addChild('form', 'online resource');
      $rda_carrier->addAttribute('authority', 'rdacarrier'); 
      $rda_carrier->addAttribute('type', 'RDA carrier terms');
      $xml->physicalDescription->addChild('extent', '1 online resource');
      $xml->physicalDescription->addChild('digitalOrigin', 'born digital');
      $xml->physicalDescription->addChild('internetMediaType', 'application/pdf');
      $xml->addChild('recordInfo');
      $xml->recordInfo->addChild('recordCreationDate', date('Y-m-d'))->addAttribute('encoding', 'w3cdtf');
      $xml->recordInfo->addChild('descriptionStandard', 'rda');
      echo "Writing MODS for $submission_title\n";
      // Format XML and write to file
      $package_path = "{$package_dir}/{$submission_iid}";
      if (file_exists($package_path)) {
        shell_exec("rm -rf {$package_path}");
      }
      shell_exec("mkdir {$package_path}");
      $output = fopen("{$package_path}/{$submission_iid}.xml", "w");
      $dom = new DOMDocument('1.0');
      $dom->preserveWhiteSpace = false;
      $dom->formatOutput = true;
      $dom->loadXML($xml->asXML());
      fwrite($output, $dom->saveXML());
      fclose($output);
 */
?>

