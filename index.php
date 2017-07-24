<?php
ini_set('max_execution_time', 4800); // 80 minute execution time on script. You can calculate what to set this at, depending on the # of search results. 10sec+10sec+(3 * NumberOfResults)+60sec = total processing time
date_default_timezone_set('America/New_York');
error_reporting(E_ERROR);
$sleepVar = 5; // seconds to sleep, use with sleep();
$currDate = date("Ymd"); // Store current date in YYYYMMDD format

// FILL IN THE VARIABLES BELOW TO CONSTRUCT YOUR SEARCH

$searchTerm = "Florida State University[Affiliation] AND College of Human Sciences[Affiliation]"; // To make things easier, enter an unencoded search term here. The script will URL encode the term and pass it to the API
$searchNamespace = "Human_Sciences"; // Enter a descriptive name for the search here. MODS files and PDFs will be stored in /output/namespace folder

// SQLite DB information

    $db_filename = __DIR__ . "/database.sqlite"; // Change this variable to have script interact with different databases
    $db_handle = new SQLite3($db_filename) or die ("Could not open SQLite database");

    // CREATE TABLES IF NOT EXIST
    try{
        
        // create embargo table if it doesn't exist
        $embargoTable = 'CREATE TABLE IF NOT EXISTS embargo (uid INTEGER NOT NULL, "embargo-date" VARCHAR(10), "query-date" VARCHAR(10), "record-title" VARCHAR(255), \'term\' TEXT, PRIMARY KEY (uid))';
        $processedTable = 'CREATE TABLE IF NOT EXISTS processed (uid INTEGER NOT NULL, "query-date" VARCHAR(10), "record-title" VARCHAR(255), iid VARCHAR(255), \'term\' TEXT, PRIMARY KEY (uid))';
        $protectedTable = 'CREATE TABLE IF NOT EXISTS protected (uid INTEGER NOT NULL, "query-date" VARCHAR(10), "record-title" VARCHAR(255), \'term\' TEXT, PRIMARY KEY (uid))';
        
        $db_handle->exec($embargoTable);
        $db_handle->exec($processedTable);
        $db_handle->exec($protectedTable);
        
    } catch (PDOException $e) {
        echo $e->getMessage();
    }


// Introduction Layer that allows user to load index.php and then click a button to start the process.

if (!isset($_POST['submit'])){
echo "<h2>PMC Grabber</h2>";
echo "<p>This tool is used to create MODS records from a Pub Med Central Search.  Use of this tool is subject to the terms and conditions of the NIH and PubMed regarding their various APIs and use of their servers.</p>";
echo "<br><br>";
echo "<a href=\"admin.php\">View the admin page</a> to see what PMC Grabber has already processed.";
echo "<br><br>";
echo "The following search terms have been run and stored in the database:<br>";

// Query DB for search terms used and stored in DB

$termHistoryQuery = "SELECT DISTINCT term FROM processed";
$termHistoryResult = $db_handle->query($termHistoryQuery);

$termHistoryArray = array();
while($result = $termHistoryResult->fetchArray(SQLITE3_ASSOC)){
    array_push($termHistoryArray, $result);
}

$termI = 0;
$termArrayCount = count($termHistoryArray);
while($termI < $termArrayCount){
    print "<li>{$termHistoryArray[$termI]['term']}</li>";
    $termI++;
}

echo "<h2>Required Information Before Running Utility</h2>";

echo "<p>Before running the utility, make sure to edit <b>index.php</b> and update the <b>combined_search</b> variable to reflect your Tool, Email, and Search Term.</p>";
echo "<p>The current configured settings are:<br><b>Search Term:</b> {$searchTerm}<br><b>Output folder:</b> /output/{$searchNamespace}</p>";
echo "<br><p>See the <a href=\"https://www.ncbi.nlm.nih.gov/books/NBK25499/#_chapter4_General_Usage_Guidelines_\" target=\"_blank\">General Usage Guidelines</a> provided by the NIH for more information</p>";

echo "<form action=\"index.php\" method=\"POST\">";
echo "<br><br><input type=\"submit\" name=\"submit\" value=\"Run Script!\">";
echo "</form>";
} else {

// Construct a valid search that brings back matched results.
// The search will return a list of matching IDs; use those IDs to iterate through another API call to get the specific info per article

$searchTermEncoded = urlencode($searchTerm);
$combinedSearch = "https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi?db=pubmed&retmode=json&retmax=1000&tool=FSU_IR&email=aretteen@fsu.edu&term={$searchTermEncoded}";
$responseSearch = file_get_contents($combinedSearch) or die("Problem with eSearch");
$jsonResponse = json_decode($responseSearch);

$count = $jsonResponse->esearchresult->count; // Number of results fetched

// Create the ID List String to pass to eSummary (must be comma-separated with no spaces)

$idList = "";
$i = "";
for ($i = 0; $i < $count; $i++){
    $idList .= "{$jsonResponse->esearchresult->idlist[$i]}";
    if($i != ($count - 1)){
    $idList .= ",";
    }
}

// BUILD CHECK AGAINST DB OF IDS THAT HAVE ALREADY BEEN PROCESSED/ARE EMBARGOED AND REMOVE THOSE FROM IDLIST

    // Get processed UIDs
    
    $processedQuery = "SELECT * FROM processed";
    $processedCheck = $db_handle->query($processedQuery);
    
    $processedArray = array();
    $i=0; // this var is important for a few loops below
    while ($row = $processedCheck->fetchArray()){
        $processedArray[$i] = $row['uid'];
        $i++;
    }
    
    // Get protected UIDs
    
    $protectedQuery = "SELECT * FROM protected";
    $protectedCheck = $db_handle->query($protectedQuery);
    
    while ($row = $protectedCheck->fetchArray()){
        $processedArray[$i] = $row['uid'];
        $i++;
    }
    
    // Purge the embargo table of records if embargo date has passed
    
    $embargoQueryString = 'DELETE FROM embargo WHERE "embargo-date" < :date AND term=:term';
    $embargoQuery = $db_handle->prepare($embargoQueryString);
    $embargoQuery->bindValue(':date', $currDate, SQLITE3_TEXT);
    $embargoQuery->bindValue(':term', $searchTerm, SQLITE3_TEXT);
    $embargoQuery->execute();
    
    // Get remaining valid embargoed records
    
    $embargoQuerySelect = "SELECT * FROM embargo";
    $embargoSelectCheck = $db_handle->query($embargoQuerySelect);
    
    while ($row = $embargoSelectCheck->fetchArray()){
        $processedArray[$i] = $row['uid'];
        $i++;
    }
    
    // purge idList, so that the only IDs that remain are valid to be processed
    
    $idListPurge = explode(",",$idList);
    
    $cleanArray = array_diff($idListPurge,$processedArray);
    
    $idList = implode(",",$cleanArray);
 
sleep($sleepVar); // Give the server some time to rest

// Construct eSummary request & decode the JSON
$eSum = "https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esummary.fcgi?db=pubmed&retmode=json&id={$idList}";
$eSumResponse = file_get_contents($eSum) or die("Problem with eSummary");
$jsonESum = json_decode($eSumResponse);

sleep($sleepVar); // Sleep time between server calls

// Construct eFetch request and store in XML variable (there is no JSON return from eFetch)
$eFetch = "https://eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi?db=pubmed&retmode=xml&id={$idList}";
$eFetchXML = simplexml_load_file($eFetch) or die ("Problem with loading XML from eFetch");

// The JSON array is Keyed via the UID, but the XML array is NOT, it is queued up in the order of IDs passed to it.
// If you base BOTH retrieval systems on $i and $i++, they should all maintain horizontal consistency

// Create an array from $idList and use that as the index value to sort through both datastreams at once
$idListArray = explode(",",$idList);

$recordsArray = array();
for($index = 0; $index < count($idListArray); $index++){
// This loop pulls out desired metadata, stores each processed record in DB and outputs MODS XML
    
// VARIABLES FROM EFETCH. coming from XML stream

// MedlineCitation Vars
    
    // direct variables - don't need to be processed really
    $issn = $eFetchXML->PubmedArticle[$index]->MedlineCitation->Article->Journal->ISSN->__toString();
    $volume = $eFetchXML->PubmedArticle[$index]->MedlineCitation->Article->Journal->JournalIssue->Volume->__toString();
    $issue = $eFetchXML->PubmedArticle[$index]->MedlineCitation->Article->Journal->JournalIssue->Issue->__toString();
    $journalTitle = $eFetchXML->PubmedArticle[$index]->MedlineCitation->Article->Journal->Title->__toString();
    $journalAbrTitle = $eFetchXML->PubmedArticle[$index]->MedlineCitation->Article->Journal->ISOAbbreviation->__toString();
    $articleTitle = $eFetchXML->PubmedArticle[$index]->MedlineCitation->Article->ArticleTitle->__toString(); // This is a full title, inclusive of SubTitle. May have to explode out on Colon
    
    // array variables - returns an array, so we need to iterate and process what we want from it
    $abstract = $eFetchXML->PubmedArticle[$index]->MedlineCitation->Article->Abstract->AbstractText; // may return array to iterate for multiple paragraphs
    $authors = $eFetchXML->PubmedArticle[$index]->MedlineCitation->Article->AuthorList; // will return Array of authors.
    $grants = $eFetchXML->PubmedArticle[$index]->MedlineCitation->Article->GrantList; // returns an array with objects containing GrantID, Acronym, Agency, Country
    $keywords = $eFetchXML->PubmedArticle[$index]->MedlineCitation->KeywordList->Keyword; // returns an array which can be iterated for all keywords

    // Not all articles have it, but there are some with Mesh arrays (Medical Subject Headings)
    $mesh = $eFetchXML->PubmedArticle[$index]->MedlineCitation->MeshHeadingList; // returns array with objects for elements
    
// VARIABLES FROM ESUMMARY, coming from JSON stream

    $uid = $jsonESum->result->uids[$index]; // Important hook for rest of variables in JSON Tree
    
    // direct variables to be passed to Records Array
    $sortTitle = $jsonESum->result->$uid->sorttitle;
    $pages = $jsonESum->result->$uid->pages;
    $essnESum = $jsonESum->result->$uid->essn;
    $sortPubDate = $jsonESum->result->$uid->sortpubdate;
    
    // array variables, we need to iterate and process
    $articleIdESum = $jsonESum->result->$uid->articleids; // returns an array
    
    //   $volumeESum = $jsonESum->result->$uid->volume; // Duplicate variable
    //   $issueESum = $jsonESum->result->$uid->issue; // Duplicate variable, ideal world would check both streams and take the non-empty one if any are empty
    //   $lang = $jsonESum->result->$uid->lang; // returns array
    //   $issnESum = $jsonESum->result->$uid->issn; // Duplicate variable
    //   $viewCount = $jsonESum->result->$uid->viewcount; // We don't need, but its cool to have

// PREPARE GRABBED DATA FOR PASSING TO RECORDS ARRAY

    // Abstract Parse- Whether text is contained in a single element or not, it will always return as an array.
        $abstractString = "";
        for($i = 0; $i < count($abstract); $i++){
            // Add "new line" functionality? Otherwise multiple paragraphs wil just be combined
            $abstractString .= $abstract[$i]->__toString() ." ";
        } 

    // Author Parsing - Transform original author array into new author array containing FirstName, LastName, Fullname
        $authorArray = array();
        for ($i = 0; $i < count($authors->Author); $i++){
            $fname = $authors->Author[$i]->ForeName->__toString(); // Will return a string of Firstname + Middle Initial if given
            $lname = $authors->Author[$i]->LastName->__toString();
            $fullname = $fname . " " . $lname;
            $authorArray[$i] = array("Firstname"=>$fname,"Lastname"=>$lname,"Fullname"=>$fullname);
        }

    // Grant Number Parsing
        $grantIDString = "";
        for ($i = 0; $i < count($grants->Grant); $i++){
            $grantIDString .= $grants->Grant[$i]->GrantID->__toString();
            if($i != (count($grants->Grant) - 1)){
                $grantIDString .= ", ";
            }
        }
    
    // Keyword Parsing
        $keywordString = "";
        for ($i = 0; $i < count($keywords); $i++){
            $keywordString .= ucfirst($keywords[$i]->__toString());  // to comply with first character UC ... <3 Bryan
            if($i != (count($keywords) -1)){
                $keywordString .= ", ";
            }
        }
    
    // ArticleID Parsing
        $articleIdArray = array();
        for ($i = 0; $i < count($articleIdESum); $i++){
            $idtype = $articleIdESum[$i]->idtype;
            $value = $articleIdESum[$i]->value;

            // Here is where we pick out which IDs we are interested in for inclusion in MODS record
            // Any idtype not here will not be captured going forward
            if(
                    $idtype == "doi" || 
                    $idtype == "pmc" || 
                    $idtype == "mid" || 
                    $idtype == "rid" || 
                    $idtype == "eid" || 
                    $idtype == "pii" ||
                    $idtype == "pmcid"){
              $articleIdArray[$idtype] = $value;
            }
            
            // Generate IID value from the PubMed UID
            $iid = "FSU_pmch_{$uid}";
            $articleIdArray["iid"] = $iid;
            
            // Generate PDF link & Check for Embargo & Flag Embargo Status
            if($idtype == "pmcid"){
                // When a record has a PMCID, it means there is a manuscript (although not necessarily a PDF)
                // The manuscript can be emargoed or not. If embargoed, we need to flag that. 
                // Sometimes a record is embargo'd but this metadata isn't in the record - this is captured on the PDF grab
            $needle = "embargo-date";
                if(strpos($value,$needle)){
                    // if this returns true, then there is an embargo date
                    $articleIdArray["embargo"] = TRUE;
                    $articleIdArray["pdf"] = "embargoed";
                    
                } else {
                        $articleIdArray["embargo"] = FALSE;
                        $articleIdArray["pdf"] = "https://www.ncbi.nlm.nih.gov/pmc/articles/{$articleIdArray["pmc"]}/pdf"; // If a PDF for this PMCID exists, this link will resolve to it
                }
            }
        }
    
    // Article Title Parsing
    // Goal is to parse what we have returned into nonSort, sortTitle, startTitle, subTitle, fullTitle and store in a titleArray

        // Generate nonsort var

        $nonsorts = array("A","An","The");
        $titleArray = explode(" ", $articleTitle);
        if (in_array($titleArray[0], $nonsorts)){
            $nonsort = $titleArray[0];
            $sortTitle = implode(" ", array_slice($titleArray, 1)); // rejoins title array starting at first element
        } else {
            $nonsort = FALSE;
            $sortTitle = $articleTitle;
        }
    
        // Generate subTitle and startTitle from fullTitle string
        $subTitleArray = explode(": ",$sortTitle);
            // now $subTitleArray[0] will be startTitle & [1] will be subTitle
            $startTitle = $subTitleArray[0];
            if(isset($subTitleArray[1])){
                $subTitle = $subTitleArray[1];
            }
            else{
                $subTitle = FALSE;
            }
    
        // Combine it all into one master title array to be parsed for MODS Record
        $parsedTitleArray = array("nonsort"=>$nonsort,"sort"=>$sortTitle,"start"=>$startTitle,"subtitle"=>$subTitle,"fulltitle"=>$articleTitle);
           
        // Parse the sortPubDate to throw away the timestamp
        // sortPubDate format is consistently: YYYY/MM/DD 00:00, and needs to become YYYY-MM-DD
        $pubDateDirty = substr($sortPubDate,0,10);
        $stringA = explode("/",$pubDateDirty);
        $pubDateClean = implode("-",$stringA);
        
        // Parse the page ranges for passing to MODS easily
        // Case: "217-59" needs to be understood as "217" and "259" for <start>217</start><end>259</end>
        $pagesArray = explode("-",$pages);
        if(isset($pagesArray[1])){ // Checks to make sure there was a - in the page range. If not, then an invalid page range existed and script skips this element
            if( strlen($pagesArray[0]) == 3 && strlen($pagesArray[1]) == 2  ){ // Case: 152-63, needs to be 152-163
                $append = substr($pagesArray[0],0,1);
                $pagesCorrect = $append . $pagesArray[1];

                $pages = $pagesArray[0] . "-" . $pagesCorrect;
            } else if (strlen($pagesArray[0]) == 3 && strlen($pagesArray[1]) == 1){ // Case: 152-5, needs to be 152-155
                $append = substr($pagesArray[0],0,2);
                $pagesCorrect = $append . $pagesArray[1];

                $pages = $pagesArray[0] . "-" . $pagesCorrect;
            } else if (strlen($pagesArray[0]) == 4 && strlen($pagesArray[1]) == 3){ // Case: 1555-559, needs to be 1555-1559
                $append = substr($pagesArray[0],0,1);
                $pagesCorrect = $append . $pagesArray[1];

                $pages = $pagesArray[0] . "-" . $pagesCorrect;
            } else if (strlen($pagesArray[0]) == 4 && strlen($pagesArray[1]) == 2){ // Case: 1555-59, needs to be 1555-1559
                $append = substr($pagesArray[0],0,2);
                $pagesCorrect = $append . $pagesArray[1];

                $pages = $pagesArray[0] . "-" . $pagesCorrect;
            } else if (strlen($pagesArray[0]) == 4 && strlen($pagesArray[1]) == 1){ // Case: 1555-9, needs to be 1555-1559
                $append = substr($pagesArray[0],0,3);
                $pagesCorrect = $append . $pagesArray[1];

                $pages = $pagesArray[0] . "-" . $pagesCorrect;
            }
        }
        else {
            $pages = ""; // At times the metadata for pages is simply incorrect (referring to issue or article # instead). Going to only parse page ranges if entered properly with a range, and ignore the rest
        }

    // Mesh Subject Terms Parsing
    // Some records will have an object array of Subject Terms for use in <subject authority="mesh"><topic></topic></subject>
    // This will parse the object-array of Mesh subject terms into Descriptor -- Qualifier for individul <topic> elements
    if($mesh){
        $meshArray = array();
        for ($i = 0; $i < count($mesh->MeshHeading);$i++){
           $meshSubArray = array();
           $descriptor = $mesh->MeshHeading[$i]->DescriptorName.""; // seems to always to be just one per

           if($mesh->MeshHeading[$i]->QualifierName){ // can be a single qualifier or a set of qualifers for the descriptor
               for($xi=0;$xi<count($mesh->MeshHeading[$i]->QualifierName); $xi++){
                   $meshSubArray[$xi] = $descriptor . "/" . $mesh->MeshHeading[$i]->QualifierName[$xi].""; 
                   $meshArray[$i] = implode("||,||",$meshSubArray);
               }
            } else {
                // Only descriptorname, so pass it on
                $meshArray[$i] = $descriptor;
            }
        }
    } else{
        $meshArray = FALSE;
    }
    
// Build sub-array structures with the various metadata variables for easier processing later, structured by the MODS top level elements
        
    $titleInfoMODS = $parsedTitleArray;
    $nameMODS = $authorArray;
    $originInfoMODS = array("date"=>$pubDateClean,"journal"=>$journalTitle);
    
    if(!empty($abstractString)){
        $abstractMODS = $abstractString;
    }
    else {
        $abstractMODS = "";
    }
        
    $noteMODS = array("keywords"=>$keywordString,"grants"=>$grantIDString);
    $subjectMODS = $meshArray; // Will either be an array of subject terms, or false
    $relatedItemMODS = array("journal"=>$journalTitle,"volume"=>$volume,"issue"=>$issue,"pages"=>$pages,"issn"=>$issn,"essn"=>$essnESum);
    $identifierMODS = $articleIdArray; // See above, all process done. Renaming
        
    // Set static MODS elements
    $typeOfResourceMODS = "text";
    $genreMODS = "text";
    $languageMODS = array("text"=>"English","code"=>"eng");
    $physicalDescriptionMODS = array("computer","online resource","1 online resource","born digital","application/pdf");
    $extensionMODS = array("owningInstitution"=>"FSU","submittingInstitution"=>"FSU");
    $date = date("Y-m-d");
    $recordInfoMODS = array("dateCreated"=>$date,"descriptionStandard"=>"rda");
      
   // pass processed stuff into here and it will be stored, keyed to the UID
    $recordsArray[$uid] = array(
        "titleInfo" => $titleInfoMODS,
        "name" => $nameMODS,
        "originInfo" => $originInfoMODS,
        "abstract" => $abstractMODS,
        "note" => $noteMODS,
        "subject" => $subjectMODS,
        "relatedItem" => $relatedItemMODS,
        "identifier" => $identifierMODS,
        "recordInfo" => $recordInfoMODS);
}

// INSERT EMBARGO RECORD INTO DB AND PURGE FROM IDLIST SO MODS RECORD NOT CREATED
$dateQueried = date('Ymd');
$idListPurge = array();

foreach($cleanArray as $val){
    if(isset($recordsArray[$val]['identifier']['embargo'])){
        if($recordsArray[$val]['identifier']['embargo'] == TRUE){
            
            $idListPurge[] = $val;
            
            $embargoStringRaw = $recordsArray[$val]['identifier']['pmcid'];
            $embargoDateUnprocessed = strstr($embargoStringRaw, "embargo-date: ", 0); // will pull out only the string relevant to embargo-date, which resolves any problem of the raw string changing character length
            $embargoDateSlashes = substr($embargoDateUnprocessed, 14, 10);
            $embargoDate = str_replace("/","",$embargoDateSlashes); // For easier date comparison with SQLITE3, dates are entered as "YYYYMMDD" strings

            $recordTitle = $recordsArray[$val]['titleInfo']['fulltitle'];
            
            $dbEmbargoQueryString = "INSERT OR REPLACE INTO embargo VALUES (:uid, :embargo, :querydate, :title, :term)";
            
            $dbEmbargoQuery = $db_handle->prepare($dbEmbargoQueryString);
            
            $dbEmbargoQuery->bindValue(':uid', $val, SQLITE3_INTEGER);
            $dbEmbargoQuery->bindValue(':embargo', $embargoDate, SQLITE3_TEXT);
            $dbEmbargoQuery->bindValue(':querydate', $dateQueried, SQLITE3_TEXT);
            $dbEmbargoQuery->bindValue(':title', $recordTitle, SQLITE3_TEXT);
            $dbEmbargoQuery->bindValue(':term', $searchTerm, SQLITE3_TEXT);
           
            $embargoResults = $dbEmbargoQuery->execute();
        }
    }

// INSERT UNRETRIEVABLE RECORDS INTO DB AND PURGE FROM IDLIST
    
    if(empty($recordsArray[$val]['identifier']['pmcid'])){
        
        $recordTitle = $recordsArray[$val]['titleInfo']['fulltitle'];
        
        $protString = "INSERT OR REPLACE INTO protected VALUES (:uid, :date, :title, :term)";
        $protQuery = $db_handle->prepare($protString);
       
        $protQuery->bindValue(':uid', $val, SQLITE3_INTEGER);
        $protQuery->bindValue(':date', $dateQueried, SQLITE3_TEXT);
        $protQuery->bindValue(':title', $recordTitle, SQLITE3_TEXT);
        $protQuery->bindValue(':term', $searchTerm, SQLITE3_TEXT);
        
        $protResults = $protQuery->execute();
        
        $idListPurge[] = $val;
    }
}

// Create new ID array with IDs left for MODS processing

$newIdArray = array_diff($cleanArray,$idListPurge);


if(empty($newIdArray)){ // This will stop MODS record creation if script has just run and there are no IDs to process that are new.
    print "There are no IDs that are left to process.  View <a href=\"/admin.php\">admin page</a> to manage processed items.";
} else {

foreach($newIdArray as $modsRecord){
// GENERATE MODS RECORD FOR EACH UID REMAINING

$sampleRecord = $recordsArray[$modsRecord];
     
$xml = new SimpleXMLElement('<mods xmlns="http://www.loc.gov/mods/v3" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:mods="http://www.loc.gov/mods/v3" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:etd="http://www.ndltd.org/standards/metadata/etdms/1.0/" xmlns:flvc="info:flvc/manifest/v1" xsi:schemaLocation="http://www.loc.gov/standards/mods/v3 http://www.loc.gov/standards/mods/v3/mods-3-4.xsd" version="3.4"></mods>');
      
      // Build Title
      
      $xml->addChild('titleInfo');
      $xml->titleInfo->addAttribute('lang','eng');
      $xml->titleInfo->addChild('title', htmlspecialchars($sampleRecord['titleInfo']['start']));
      if ($sampleRecord['titleInfo']['nonsort']){ $xml->titleInfo->addChild('nonSort', htmlspecialchars($sampleRecord['titleInfo']['nonsort'])); }
      if ($sampleRecord['titleInfo']['subtitle']){ $xml->titleInfo->addChild('subTitle', htmlspecialchars($sampleRecord['titleInfo']['subtitle'])); }
      
      // Build Name
      foreach($sampleRecord['name'] as $value){
          $a = $xml->addChild('name');
          $a->addAttribute('type', 'personal');
          $a->addAttribute('authority','local');
          $a->addChild('namePart',htmlspecialchars($value['Firstname']))->addAttribute('type','given');
          $a->addChild('namePart',htmlspecialchars($value['Lastname']))->addAttribute('type','family');

          $a->addChild('role');
          $r1 = $a->role->addChild('roleTerm', 'author'); 
          $r1->addAttribute('authority', 'rda');
          $r1->addAttribute('type', 'text');
          $r2 = $a->role->addChild('roleTerm', 'aut'); 
          $r2->addAttribute('authority', 'marcrelator');
          $r2->addAttribute('type', 'code');      
      }
      
      // Build originInfo
      
      $xml->addChild('originInfo');
      $xml->originInfo->addChild('dateIssued',  htmlspecialchars($sampleRecord['originInfo']['date']));
      $xml->originInfo->dateIssued->addAttribute('encoding','w3cdtf');
      $xml->originInfo->dateIssued->addAttribute('keyDate','yes');
      
      // Build abstract, if field is not empty
      
      if(!empty($sampleRecord['abstract'])){ 
          $xml->addChild('abstract',  htmlspecialchars($sampleRecord['abstract']));
          }
         
      // Build identifiers
      
        // IID
        $xml->addChild('identifier',$sampleRecord['identifier']['iid'])->addAttribute('type','IID');
      
        // DOI
        if(!empty($sampleRecord['identifier']['doi'])){
            $xml->addChild('identifier',$sampleRecord['identifier']['doi'])->addAttribute('type','DOI');
        }
        
        // OMC
        if(!empty($sampleRecord['identifier']['pmc'])){
            $xml->addChild('identifier',$sampleRecord['identifier']['pmc'])->addAttribute('type','PMCID');
        }
      
        // RID
        if(!empty($sampleRecord['identifier']['rid'])){
            $xml->addChild('identifier',$sampleRecord['identifier']['rid'])->addAttribute('type','RID');
        }
      
        // EID
        if(!empty($sampleRecord['identifier']['eid'])){
            $xml->addChild('identifier',$sampleRecord['identifier']['eid'])->addAttribute('type','EID');
        }
      
        // PII
        if(!empty($sampleRecord['identifier']['pii'])){
            $xml->addChild('identifier',$sampleRecord['identifier']['pii'])->addAttribute('type','PII');
        }
     
      // Build Related Item
      
        if(!empty($sampleRecord['relatedItem']['journal'])){
            $xml->addChild('relatedItem')->addAttribute('type','host');
            $xml->relatedItem->addChild('titleInfo');
            $xml->relatedItem->titleInfo->addChild('title',  htmlspecialchars($sampleRecord['relatedItem']['journal']));
            
            if(!empty($sampleRecord['relatedItem']['issn'])){
                $xml->relatedItem->addChild('identifier',$sampleRecord['relatedItem']['issn'])->addAttribute('type','issn');
            }
            
            if(!empty($sampleRecord['relatedItem']['essn'])){
                $xml->relatedItem->addChild('identifier',$sampleRecord['relatedItem']['essn'])->addAttribute('type','essn');
            }
            
            if(!empty($sampleRecord['relatedItem']['volume']) || !empty($sampleRecord['relatedItem']['issue']) || !empty($sampleRecord['relatedItem']['pages'])){
                $xml->relatedItem->addChild('part');
                
                if(!empty($sampleRecord['relatedItem']['volume'])){
                    $volXML = $xml->relatedItem->part->addChild('detail');
                    $volXML->addAttribute('type','volume');
                    $volXML->addChild('number',  htmlspecialchars($sampleRecord['relatedItem']['volume']));
                    $volXML->addChild('caption','vol.');
                }
                
                if(!empty($sampleRecord['relatedItem']['issue'])){
                    $issXML = $xml->relatedItem->part->addChild('detail');
                    $issXML->addAttribute('type','issue');
                    $issXML->addChild('number',  htmlspecialchars($sampleRecord['relatedItem']['issue']));
                    $issXML->addChild('caption','iss.');
                }
                
                if(!empty($sampleRecord['relatedItem']['pages'])){
                    $pagXML = $xml->relatedItem->part->addChild('extent');
                    $pagXML->addAttribute('unit','page');
                    $page_array = explode("-",$sampleRecord['relatedItem']['pages']);
                    $xml->relatedItem->part->extent->addChild('start',  htmlspecialchars($page_array[0]));
                    if(isset($page_array[1])){$xml->relatedItem->part->extent->addChild('end',  htmlspecialchars($page_array[1]));}
                }
            }
        }
      
      // Build Subject
        $subjectNeedle = "||,||";
        if(!empty($sampleRecord['subject'])){
            for($i=0;$i<count($sampleRecord['subject']);$i++){           
                if( strpos($sampleRecord['subject'][$i],$subjectNeedle) ){
                    // If true, there are multiple subject terms on one line here
                    $termsArray = explode("||,||",$sampleRecord['subject'][$i]);
                    for($subIndex=0;$subIndex<count($termsArray);$subIndex++){
                        $subXML = $xml->addChild('subject');
                        $subXML->addAttribute('authority','mesh');
                        $subXML->addChild('topic',  htmlspecialchars($termsArray[$subIndex]));
                    }
                } else {
                    // If above is not true, then there is only term per line
                    $subXML = $xml->addChild('subject');
                    $subXML->addAttribute('authority','mesh');
                    $subXML->addChild('topic',  htmlspecialchars($sampleRecord['subject'][$i]));
                }
            }
        }
        
      // Build Notes
        
        if(!empty($sampleRecord['note']['keywords'])){
            $xml->addChild('note', htmlspecialchars($sampleRecord['note']['keywords']))->addAttribute('displayLabel','Keywords');
        }
        
        if(!empty($sampleRecord['note']['grants'])){
            $xml->addChild('note', htmlspecialchars($sampleRecord['note']['grants']))->addAttribute('displayLabel','Grant Number');
        }
        
        $PMCLocation = "https://www.ncbi.nlm.nih.gov/pmc/articles/{$sampleRecord['identifier']['pmc']}";
        $pubNoteString = "This NIH-funded author manuscript originally appeared in PubMed Central at {$PMCLocation}.";
        
        $xml->addChild('note', $pubNoteString)->addAttribute('displayLabel','Publication Note');
      
     // Build FLVC extensions
        
        $flvc = $xml->addChild('extension')->addChild('flvc:flvc', '', 'info:flvc/manifest/v1');
        $flvc->addChild('flvc:owningInstitution', 'FSU');
        $flvc->addChild('flvc:submittingInstitution', 'FSU');

     // Add other static elements
        $xml->addChild('typeOfResource', 'text');

        $genre = $xml->addChild('genre', 'journal article');
        $genre->addAttribute('authority', 'coar');
        $genre->addAttribute('authorityURI', 'http://purl.org/coar/resource_type');
        $genre->addAttribute('valueURI', 'http://purl.org/coar/resource_type/c_6501');

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
//
// WRITE MODS FILE
//

// Make subdirectory in output folder for different search namespaces

$directory = __DIR__ . "/output/{$searchNamespace}";
$directoryUngrabbed = __DIR__ . "/output/{$searchNamespace}/ungrabbed"; // Directory for records with full text available but no PDF

if(!is_dir($directory)){
    mkdir($directory, 0755, true);
}

$handle = __DIR__ . "/output/{$searchNamespace}/{$sampleRecord['identifier']['iid']}.xml";
$output = fopen($handle,"w");

$dom = new DOMDocument('1.0');
$dom->preserveWhiteSpace = false;
$dom->formateOutput = true;
$dom->loadXML($xml->asXML());
fwrite($output,$dom->saveXML());
fclose($output);

// GRAB PDF AND SAVE TO OUTPUT FOLDER

$pdfSleepVar = 3;
sleep($pdfSleepVar); // sleep between PDF grabs

$PDF = file_get_contents($sampleRecord['identifier']['pdf']);

if(!$PDF){
    if(!is_dir($directoryUngrabbed)){
        mkdir($directoryUngrabbed, 0755, true);
    }
    $handleUngrabbed = __DIR__ . "/output/{$searchNamespace}/ungrabbed/{$sampleRecord['identifier']['iid']}.xml";
    rename($handle,$handleUngrabbed); // Moves the XML file to the ungrabbed folder when a PDF is not grabbed
    print "<b>Could not grab PDF for IID {$sampleRecord['identifier']['iid']}</b><br>";
} else {
    $fileNamePDF = __DIR__ . "/output/" . $searchNamespace . "/" . $sampleRecord['identifier']['iid'] . ".pdf";
    file_put_contents($fileNamePDF, $PDF);
}

// ADD TO PROCESSED TABLE IN DB

$queryDate = date('Ymd');
$recordTitle = $sampleRecord['titleInfo']['fulltitle'];
$iid = $sampleRecord['identifier']['iid'];

$insertProc = "INSERT INTO processed VALUES (:uid, :querydate,  :title, :iid, :term)";

$insertProcQuery = $db_handle->prepare($insertProc);

$insertProcQuery->bindValue(':uid', $modsRecord, SQLITE3_INTEGER);
$insertProcQuery->bindValue(':querydate', $queryDate, SQLITE3_TEXT);
$insertProcQuery->bindValue(':title', $recordTitle, SQLITE3_TEXT);
$insertProcQuery->bindValue(':iid', $iid, SQLITE3_TEXT);
$insertProcQuery->bindValue(':term', $searchTerm, SQLITE3_TEXT);
$insertProcResults = $insertProcQuery->execute();

print "Processed {$iid}! <a href=\"/pmc_grabberv2/output/{$searchNamespace}/{$iid}.xml\">View XML</a><br>";
}
}
//
// DEV TEST
print "<br>";
print "<hr>";
print "<h1>Debugging Information</h1>";
print "<p>Total number of results before database checking: {$count}</p>";
print "<h2>Raw array of records processed (after the purges)</h2>";
print "<pre>";
print_r($recordsArray);
print "</pre>";

print "<hr>";

print "<h2>Results from eSummary (list of IDs)</h2>";
print "<pre>";
print_r($jsonESum);
print "</pre>";


print "<h2>Results from eSearch</h2>";

print "<h2>eSearch JSON Response</h2>";
print "<pre>";
print_r($jsonResponse);
print "</pre>";

print "<h2>Results from eFetch XML Load</h2>";
print "<pre>";
print_r($eFetchXML);
print "</pre>";

}
$db_handle = NULL;
?>