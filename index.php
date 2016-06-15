<?php
ini_set('max_execution_time', 1800); // 30 minute execution time on script
date_default_timezone_set('America/New_York');
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

//
// BUILD CHECK AGAINST DB OF IDS THAT HAVE ALREADY BEEN PROCESSED/ARE KNOWN EMBARGOED
// AND REMOVE THOSE FROM IDLIST
//
// THE DATABASE FILE IS SQLITE
   
    /*
CREATE TABLE embargo
(uid INTEGER NOT NULL,
"embargo-date" VARCHAR(10),
"query-date" VARCHAR(10),
"record-title" VARCHAR(255),
PRIMARY KEY (uid))

CREATE TABLE processed
(uid INTEGER NOT NULL,
"query-date" VARCHAR(10),
"record-title" VARCHAR(255),
PRIMARY KEY (uid))

CREATE TABLE protected
(uid INTEGER NOT NULL,
"query-date" VARCHAR(10),
"record-title" VARCHAR(255),
PRIMARY KEY (uid))s
 * 
 */



    $db_filename = __DIR__ . "/database.sqlite";
    $db_handle = new SQLite3($db_filename);

    
    // Get processed UIDs
    
    $processQuery = "SELECT * FROM processed";
    $processed_check = $db_handle->query($processQuery);
    
    $processArray = array();
    $i=0;
    while ($row = $processed_check->fetchArray()){
        $processArray[$i] = $row['uid'];
        $i++;
    }
    
    // Get protected UIDs
    
    $protectQuery = "SELECT * FROM protected";
    $protected_check = $db_handle->query($protectQuery);
    
    while ($row = $protected_check->fetchArray()){
        $processArray[$i] = $row['uid'];
        $i++;
    }
    
    // Purge the embargo table of records if embargo date has passed
    
    $embargoQueryString = 'DELETE FROM "embargo" WHERE "embargo-date" < ":date"';
    $embargoQuery = $db_handle->prepare($embargoQueryString);
    
    $currdate = date("Y/m/d");
    
    $embargoQuery->bindValue(':date', $currDate, SQLITE3_TEXT);
    $embargoQuery->execute();
    
    // purge idList
    
    $idListPurge = explode(",",$idList);
    
    $cleanArray = array_diff($idListPurge,$processArray);
    
    $idList = implode(",",$cleanArray);
    
 
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
for($index = 0; $index < count($idListArray); $index++){
    // This Loop will allow us to go through each record and pull out what we 
    // want and we can store each processed record as part of an array that gets 
    // checked against DB and processed into MODS XML format.
    
    
//    
// VARIABLES FROM EFETCH. coming from XML stream:
//

    // MedlineCitation Vars -- keep in mind that a loop structure has to be
    //added to iterate between multiple articles
//    $pmid = $eFetchXML->PubmedArticle[$index]->MedlineCitation->PMID->__toString();
    
    // direct variables - don't need to be processed really
    $issn = $eFetchXML->PubmedArticle[$index]->MedlineCitation->Article->Journal->ISSN->__toString();
    $volume = $eFetchXML->PubmedArticle[$index]->MedlineCitation->Article->Journal->JournalIssue->Volume->__toString();
    $issue = $eFetchXML->PubmedArticle[$index]->MedlineCitation->Article->Journal->JournalIssue->Issue->__toString();
    $journalTitle = $eFetchXML->PubmedArticle[$index]->MedlineCitation->Article->Journal->Title->__toString();
    $journalAbrTitle = $eFetchXML->PubmedArticle[$index]->MedlineCitation->Article->Journal->ISOAbbreviation->__toString();
    $articleTitle = $eFetchXML->PubmedArticle[$index]->MedlineCitation->Article->ArticleTitle->__toString(); // This is a full title, inclusive of SubTitle. May have to explode out on Colon
    
    // array variables - returns an array, so we need to iterate and process
    // what we want from it
    
    $abstract = $eFetchXML->PubmedArticle[$index]->MedlineCitation->Article->Abstract->AbstractText; // may return array to iterate for multiple paragraphs
    $authors = $eFetchXML->PubmedArticle[$index]->MedlineCitation->Article->AuthorList; // will return Array of authors. Contains Affiliation info as well, which is an object
 
    $grants = $eFetchXML->PubmedArticle[$index]->MedlineCitation->Article->GrantList; // returns an array with objects containing GrantID, Acronym, Agency, Country
    $keywords = $eFetchXML->PubmedArticle[$index]->MedlineCitation->KeywordList->Keyword; // returns an array which can be iterated for all keywords #woot
//    $publicationType = $eFetchXML->PubmedArticle[$index]->MedlineCitation->Article->PublicationTypeList->PublicationType; // may return an array? otherwise, just "JOURNAL ARTICLE"
//    $affiliationSample = $eFetchXML->PubmedArticle[$index]->MedlineCitation->Article->AuthorList->Author[0]->AffiliationInfo->Affiliation; // just a sample, testing double array within object chain, gonna have to build into the author loop
       
    
    // PubmedData chain has variables too, but mostly redundant and incomplete 
    // compared to the JSON variable
    $articleIds = $eFetchXML->PubmedArticle[$index]->PubmedData->ArticleIdList->ArticleId; // returns array of IDs keyed by number, so not helpful in extrapolating what the ID is. BUT, can iterate this and check for PMC####### and NIHMS###### rows

    // Not all articles have it, but there are some with Mesh arrays (Medical Subject Headings)
    $mesh = $eFetchXML->PubmedArticle[$index]->MedlineCitation->MeshHeadingList; // returns array with objects for elements
    
//
// VARIABLES FROM ESUMMARY, coming from JSON stream
//

    $uid = $json_eSum->result->uids[$index]; // Important Hook for rest of Variables in JSON Tree
    
    // direct variables to be passed to Records Array
    $sortTitle = $json_eSum->result->$uid->sorttitle;
    $pages = $json_eSum->result->$uid->pages;
    $essnESum = $json_eSum->result->$uid->essn;
    $sortPubDate = $json_eSum->result->$uid->sortpubdate;
    
    // array variables, we need to iterate and process
    $articleIdESum = $json_eSum->result->$uid->articleids; // returns an array
    
 //   $volumeESum = $json_eSum->result->$uid->volume; // Duplicate variable
 //   $issueESum = $json_eSum->result->$uid->issue; // Duplicate variable, ideal world would check both streams and take the non-empty one if any are empty
 //   $lang = $json_eSum->result->$uid->lang; // returns array
 //   $issnESum = $json_eSum->result->$uid->issn; // Duplicate variable
 //   $pubTypeESum = $json_eSum->result->$uid->pubtype; // returns an array
 //   $viewCount = $json_eSum->result->$uid->viewcount; // We don't need, but its cool to have


//
// PREPARE GRABBED DATA FOR PASSING TO RECORDS ARRAY
//

    // Abstract Parse
    // Whether text is contained in a single element or not, it will always return as an array.
    // The following code expects $abstract to be presented as already parsed to the array level
        unset($abstractString);
        for($i = 0; $i < count($abstract); $i++){
            // Add "new line" functionality? Otherwise multiple paragraphs wil just be combined
            $abstractString .= $abstract[$i]->__toString() ." ";
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
            $x = 0;
            
            // Author Affiliation string content varies across records
            // To add a level of control, this piece will check to see if
            // "Florida State University", "FSU", or "Florida Center for Reading Research"
            // is incorporated, and if so only pass that string on to be included in the MODS record.
            // Executive Decision made to abandon including this string because it is highly unregulated by PubMed
            // and presents too many differences to programmatically parse properly.
            
            // $authAffil = $authors->Author[$i]->AffiliationInfo->Affiliation."";
            
            

            $authorArray[$i] = array("Firstname"=>$fname,"Lastname"=>$lname,"Fullname"=>$fullname/*,"Affiliation"=>$authAffil*/);
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
    
    // ArticleID Parsing
    // When sent here, var will be an array of object-arrays
        $articleIdArray = array();
        for ($i = 0; $i < count($articleIdESum); $i++){
            $idtype = $articleIdESum[$i]->idtype;
            $value = $articleIdESum[$i]->value;

            // Here is where we pick out which IDs we are interested in.
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
            // Kind of out of place for process, but this is where it made most
            // sense to me to put this.
            // I WILL HAVE TO ADD SOMEWHERE IN THE DB PROCESS TO CHECK IF PMCID
            // EVEN EXISTS IN THE AUTHOR RECORD
           if($idtype == "pmcid"){
                // When a record has a PMCID, it means there is a manuscript.
                // The manuscript can be emargoed or not. If embargoed, we need to flag that.
            $needle = "embargo-date";
                if(strpos($value,$needle)){
                    // if this returns true, then there is an embargo date
                    $articleIdArray["embargo"] = TRUE;
                    // Can add functionality to strip out the embargo date here
                    $articleIdArray["pdf"] = "embargoed";
                    
                } else {
                    $articleIdArray["embargo"] = FALSE;
                    $articleIdArray["pdf"] = "http://www.ncbi.nlm.nih.gov/pmc/articles/{$articleIdArray["pmc"]}/pdf/{$articleIdArray["mid"]}.pdf";
                }
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
        $subTitleArray = explode(": ",$sortTitle);
            // now $subTitleArray[0] will be startTitle & [1] will be subTitle
            $startTitle = $subTitleArray[0];
            if($subTitleArray[1]){$subTitle = $subTitleArray[1];}
            else {$subTitle = FALSE;}
    
        // Combine it all into one master title array to be parsed for MODS Record
        $parsedTitleArray = array("nonsort"=>$nonsort,"sort"=>$sortTitle,"start"=>$startTitle,"subtitle"=>$subTitle,"fulltitle"=>$articleTitle);
           
        
        //
        // Parse the sortPubDate to throw away the timestamp
        // sortPubDate format is consistently: YYYY/MM/DD 00:00, and needs to
        //                                      become YYYY-MM-DD
        
        $pubDateDirty = substr($sortPubDate,0,10);
        $stringA = explode("/",$pubDateDirty);
        $pubDateClean = implode("-",$stringA);
        //
        // Parse the page ranges for passing to MODS easily
        // Case: "217-59" needs to be understood as "217" and "259" for <start>217</start><end>259</end>
        // other examples: xxx-x, xxxx-xxx, xxxx-xx, xxxx-x
        
        $pagesArray = explode("-",$pages);

        if( strlen($pagesArray[0]) == 3 && strlen($pagesArray[1]) == 2  ){
            $append = substr($pagesArray[0],0,1);
            $pagesCorrect = $append . $pagesArray[1];

            $pages = $pagesArray[0] . "-" . $pagesCorrect;
        } else if (strlen($pagesArray[0]) == 3 && strlen($pagesArray[1]) == 1){
            $append = substr($pagesArray[0],0,2);
            $pagesCorrect = $append . $pagesArray[1];

            $pages = $pagesArray[0] . "-" . $pagesCorrect;
        } else if (strlen($pagesArray[0]) == 4 && strlen($pagesArray[1]) == 3){
            $append = substr($pagesArray[0],0,1);
            $pagesCorrect = $append . $pagesArray[1];

            $pages = $pagesArray[0] . "-" . $pagesCorrect;
        } else if (strlen($pagesArray[0]) == 4 && strlen($pagesArray[1]) == 2){
            $append = substr($pagesArray[0],0,2);
            $pagesCorrect = $append . $pagesArray[1];

            $pages = $pagesArray[0] . "-" . $pagesCorrect;
        } else if (strlen($pagesArray[0]) == 4 && strlen($pagesArray[1]) == 1){
            $append = substr($pagesArray[0],0,3);
            $pagesCorrect = $append . $pagesArray[1];

            $pages = $pagesArray[0] . "-" . $pagesCorrect;
        }
        
        
    // Mesh Subject Terms Parsing
    // Some records will have an object array of Subject Terms
    // for use in <subject authority="mesh"><topic></topic></subject>
    // This will parse the object-array of Mesh subject terms into
    // Descriptor -- Qualifier for individul <topic> elements
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
    
    //
    // Build sub-array structures with the various metadata variables for 
    // easier processing later, structured by the MODS top level elements
    // Sometimes I just reassign a variable to a new name, just for cognitive
    // ease in understanding this script.
        
        $titleInfoMODS = $parsedTitleArray; // See above, all process done already. Renaming
        $nameMODS = $authorArray; // See above, all process done already. Renaming
        $originInfoMODS = array("date"=>$pubDateClean,"journal"=>$journalTitle); // fills dateIssued and Publisher (?) role
        $abstractMODS = $abstractString; // See above, all process done. Renaming
        $noteMODS = array("keywords"=>$keywordString,"grants"=>$grantIDString); // for Grant, set displayLabel="Grants"
        $subjectMODS = $meshArray; // Will either be an array of subject terms, or false
        $relatedItemMODS = array("journal"=>$journalTitle,"volume"=>$volume,"issue"=>$issue,"pages"=>$pages,"issn"=>$issn,"essn"=>$essnESum);
        $identifierMODS = $articleIdArray; // See above, all process done. Renaming
        
        // also keep in mind for another section the static MODS elements that
        // will be the same across all records
        // typeOfResource; genre; language...etc?
        
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
  //      "typeOfResource" => $typeOfResourceMODS,
  //      "genre" => $genreMODS,
        "originInfo" => $originInfoMODS,
  //      "language" => $languageMODS,
  //      "physicalDescription" => $physicalDescriptionMODS,
        "abstract" => $abstractMODS,
        "note" => $noteMODS,
        "subject" => $subjectMODS,
        "relatedItem" => $relatedItemMODS,
        "identifier" => $identifierMODS,
        "recordInfo" => $recordInfoMODS);
  //      "extension" => $extensionMODS); 
    
}

// INSERT EMBARGO RECORD INTO DB AND PURGE FROM IDLIST SO MODS RECORD NOT 
// CREATED

$idListPurge = array();
foreach($cleanArray as $val){
    
    if($recordsArray[$val]['identifier']['embargo'] == TRUE){
        
        $idListPurge[] = $val;
        
        $embargoString = $recordsArray[$val]['identifier']['pmcid'];
        $embargoDate = substr($embargoString,59,10);
        $dateCreated = $recordsArray[$val]['recordInfo']['dateCreated'];
        $recordTitle = $recordsArray[$val]['titleInfo']['fulltitle'];
        
        $dbEmbargoQueryString = "INSERT OR REPLACE INTO embargo VALUES (:uid, :embargo, :querydate, :title)";
        
        $dbEmbargoQuery = $db_handle->prepare($dbEmbargoQueryString);
        
        $dbEmbargoQuery->bindValue(':uid', $val, SQLITE3_INTEGER);
        $dbEmbargoQuery->bindValue(':embargo', $embargoDate, SQLITE3_TEXT);
        $dbEmbargoQuery->bindValue(':querydate', $dateCreated, SQLITE3_TEXT);
        $dbEmbargoQuery->bindValue(':title', $recordTitle, SQLITE3_TEXT);
        
       
        $embargoResults = $dbEmbargoQuery->execute();
    }

// INSERT UNRETRIEVABLE RECORDS INTO DB AND PURGE FROM IDLIST
    
    if(empty($recordsArray[$val]['identifier']['pmcid'])){
        
        $dateCreated = $recordsArray[$val]['recordInfo']['dateCreated'];
        $recordTitle = $recordsArray[$val]['titleInfo']['fulltitle'];
        
        $protString = "INSERT OR REPLACE INTO protected VALUES (:uid, :date, :title)";
        $protQuery = $db_handle->prepare($protString);
        
       
        $protQuery->bindValue(':uid', $val, SQLITE3_INTEGER);
        $protQuery->bindValue(':date', $dateCreated, SQLITE3_TEXT);
        $protQuery->bindValue(':title', $recordTitle, SQLITE3_TEXT);
        
        $protResults = $protQuery->execute();
        
        $idListPurge[] = $val;
        
    }
    
}

// Create new ID array with IDs left for MODS processing

$newIdArray = array_diff($cleanArray,$idListPurge);


if(empty($newIdArray)){
    // This will stop MODS record creation if script has just run and there are
    // no IDs to process that are new.
    
    print "There are no IDs that are left to process.  View <a href=\"/admin.php\">admin page</a> to manage processed items.";
} else {

foreach($newIdArray as $modsRecord){
//
//
// GENERATE MODS RECORD FOR EACH UID REMAINING
//
$sampleRecord = $recordsArray[$modsRecord];
     
$xml = new SimpleXMLElement('<mods xmlns="http://www.loc.gov/mods/v3" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:mods="http://www.loc.gov/mods/v3" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:etd="http://www.ndltd.org/standards/metadata/etdms/1.0/" xmlns:flvc="info:flvc/manifest/v1" xsi:schemaLocation="http://www.loc.gov/standards/mods/v3/" version="3.4"></mods>');
      
      // Build Title
      
      $xml->addChild('titleInfo');
      $xml->titleInfo->addAttribute('lang','eng');
      $xml->titleInfo->addChild('title', htmlspecialchars($sampleRecord['titleInfo']['start']));
      if ($sampleRecord['titleInfo']['nonsort']){ $xml->titleInfo->addChild('nonSort', htmlspecialchars($sampleRecord['titleInfo']['nonsort'])); }
      if ($sampleRecord['titleInfo']['subtitle']){ $xml->titleInfo->addChild('subTitle', htmlspecialchars($sampleRecord['titleInfo']['subtitle'])); }
      
      // Build Name
      foreach($sampleRecord['name'] as $value){
      // for($i = 0; $i < count($sampleRecord['name']); $i++){
          $a = $xml->addChild('name');
          $a->addAttribute('type', 'personal');
          $a->addAttribute('authority','local');
          
          $a->addChild('namePart',htmlspecialchars($value['Firstname']))->addAttribute('type','given');
          
          $a->addChild('namePart',htmlspecialchars($value['Lastname']))->addAttribute('type','family');
          
          // if($value['Affiliation']){$a->addChild('affiliation',htmlspecialchars($value['Affiliation']));}
          
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
      
      if($sampleRecord['abstract']){ 
          $xml->addChild('abstract',  htmlspecialchars($sampleRecord['abstract']));
          }
         
      // Build identifiers
      
        // IID
        $xml->addChild('identifier',$sampleRecord['identifier']['iid'])->addAttribute('type','iid');
      
        // DOI
        if($sampleRecord['identifier']['doi']){
            $xml->addChild('identifier',$sampleRecord['identifier']['doi'])->addAttribute('type','doi');
        }
        
        // OMC
        if($sampleRecord['identifier']['pmc']){
            $xml->addChild('identifier',$sampleRecord['identifier']['pmc'])->addAttribute('type','pmcid');
        }
      
        // RID
        if($sampleRecord['identifier']['rid']){
            $xml->addChild('identifier',$sampleRecord['identifier']['rid'])->addAttribute('type','rid');
        }
      
        // EID
        if($sampleRecord['identifier']['eid']){
            $xml->addChild('identifier',$sampleRecord['identifier']['eid'])->addAttribute('type','eid');
        }
      
        // PII
        if($sampleRecord['identifier']['pii']){
            $xml->addChild('identifier',$sampleRecord['identifier']['pii'])->addAttribute('type','pii');
        }
     
      // Build Related Item
      
        if($sampleRecord['relatedItem']['journal']){
            $xml->addChild('relatedItem')->addAttribute('type','host');
            $xml->relatedItem->addChild('titleInfo');
            $xml->relatedItem->titleInfo->addChild('title',  htmlspecialchars($sampleRecord['relatedItem']['journal']));
            
            if($sampleRecord['relatedItem']['issn']){
                $xml->relatedItem->addChild('identifier',$sampleRecord['relatedItem']['issn'])->addAttribute('type','issn');
            }
            
            if($sampleRecord['relatedItem']['essn']){
                $xml->relatedItem->addChild('identifier',$sampleRecord['relatedItem']['essn'])->addAttribute('type','essn');
            }
            
            if($sampleRecord['relatedItem']['volume'] || $sampleRecord['relatedItem']['issue'] || $sampleRecord['relatedItem']['pages']){
                $xml->relatedItem->addChild('part');
                
                if($sampleRecord['relatedItem']['volume']) {
                    $volXML = $xml->relatedItem->part->addChild('detail');
                    $volXML->addAttribute('type','volume');
                    $volXML->addChild('number',  htmlspecialchars($sampleRecord['relatedItem']['volume']));
                    $volXML->addChild('caption','vol.');
                }
                
                if($sampleRecord['relatedItem']['issue']) {
                    $issXML = $xml->relatedItem->part->addChild('detail');
                    $issXML->addAttribute('type','issue');
                    $issXML->addChild('number',  htmlspecialchars($sampleRecord['relatedItem']['issue']));
                    $issXML->addChild('caption','iss.');
                }
                
                if($sampleRecord['relatedItem']['pages']) {
                    $pagXML = $xml->relatedItem->part->addChild('extent');
                    $pagXML->addAttribute('unit','page');
                    $page_array = explode("-",$sampleRecord['relatedItem']['pages']);
                    $xml->relatedItem->part->extent->addChild('start',  htmlspecialchars($page_array[0]));
                    $xml->relatedItem->part->extent->addChild('end',  htmlspecialchars($page_array[1]));
                }
            }   
        }
      
      // Build Subject
        $subjectNeedle = "||,||";
        if($sampleRecord['subject']){
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
        
        if($sampleRecord['note']['keywords']){
            $xml->addChild('note', htmlspecialchars($sampleRecord['note']['keywords']))->addAttribute('displayLabel','Keywords');
        }
        
        if($sampleRecord['note']['grants']){
            $xml->addChild('note', htmlspecialchars($sampleRecord['note']['grants']))->addAttribute('displayLabel','Grant Number');
        }
        
        $PMCLocation = "http://www.ncbi.nlm.nih.gov/pmc/articles/{$sampleRecord['identifier']['pmc']}";
        $pubNoteString = "This NIH-funded author manuscript originally appeared in PubMed Central at {$PMCLocation}.";
        
        $xml->addChild('note', $pubNoteString)->addAttribute('displayLabel','Publication Note');
      
     // Build FLVC extensions
        
        $flvc = $xml->addChild('extension')->addChild('flvc:flvc', '', 'info:flvc/manifest/v1');
        $flvc->addChild('flvc:owningInstitution', 'FSU');
        $flvc->addChild('flvc:submittingInstitution', 'FSU');

     // Add other static elements
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

      
//
// WRITE MODS FILE
//
$handle = __DIR__ . "/output/{$sampleRecord['identifier']['iid']}.xml";
$output = fopen($handle,"w");

$dom = new DOMDocument('1.0');
$dom->preserveWhiteSpace = false;
$dom->formateOutput = true;
$dom->loadXML($xml->asXML());
fwrite($output,$dom->saveXML());
fclose($output);

//
// GRAB PDF AND SAVE TO OUTPUT FOLDER
//
$pdfSleepVar = 10;
sleep($pdfSleepVar); // sleeps for 3 seconds between grabs

$PDF = file_get_contents($sampleRecord['identifier']['pdf']) or die("Could not get file");

$fileNamePDF = __DIR__ . "/output/" . $sampleRecord['identifier']['iid'] . ".pdf";
file_put_contents($fileNamePDF, $PDF);


//
// ADD TO PROCESSED TABLE IN DB
//
$queryDate = date('Y-m-d');
$recordTitle = $sampleRecord['titleInfo']['fulltitle'];
$iid = $sampleRecord['identifier']['iid'];


$insertProc = "INSERT INTO processed VALUES (:uid, :querydate,  :title, :iid)";


$insertProcQuery = $db_handle->prepare($insertProc);

$insertProcQuery->bindValue(':uid', $modsRecord, SQLITE3_INTEGER);
$insertProcQuery->bindValue(':querydate', $queryDate, SQLITE3_TEXT);
$insertProcQuery->bindValue(':title', $recordTitle, SQLITE3_TEXT);
$insertProcQuery->bindValue(':iid', $iid, SQLITE3_TEXT);
$insertProcResults = $insertProcQuery->execute();

print "Processed {$iid}! <a href=\"/pmc_grabber/output/{$iid}.xml\">View XML</a>";
print "<br>";
}
}


//
// DEV TEST
//
print "<pre>";
print_r($recordsArray);
print "</pre>";

print "<hr>";

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


*****************
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

 * 
 * 
 */

$db_handle = NULL;
?>