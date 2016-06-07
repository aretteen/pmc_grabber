<?php

$fetch = "http://eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi?db=pubmed&retmode=xml&id=26869730,23725549";

//$results = file_get_contents($fetch);

$xml = simplexml_load_file($fetch) or die ("Could not load xml stream");

// MedlineCitation Vars -- keep in mind that a loop structure has to be added to iterate between multiple articles
$pmid = $xml->PubmedArticle[0]->MedlineCitation->PMID;;
$issn = $xml->PubmedArticle[0]->MedlineCitation->Article->Journal->ISSN;
$volume = $xml->PubmedArticle[0]->MedlineCitation->Article->Journal->JournalIssue->Volume;
$issue = $xml->PubmedArticle[0]->MedlineCitation->Article->Journal->JournalIssue->Issue;
$journalTitle = $xml->PubmedArticle[0]->MedlineCitation->Article->Journal->Title;
$journalAbrTitle = $xml->PubmedArticle[0]->MedlineCitation->Article->Journal->ISOAbbreviation;
$articleTitle = $xml->PubmedArticle[0]->MedlineCitation->Article->ArticleTitle; // This is a full title, inclusive of SubTitle
$abstract = $xml->PubmedArticle[0]->MedlineCitation->Article->Abstract; // may return array to iterate for multiple paragraphs
$authors = $xml->PubmedArticle[0]->MedlineCitation->Article->AuthorList; // will return Array of authors. Contains Affiliation info as well, which is an object
$affiliationSample = $xml->PubmedArticle[0]->MedlineCitation->Article->AuthorList->Author[0]->AffiliationInfo->Affiliation; // just a sample, testing double array within object chain, gonna have to build into the author loop
$grants = $xml->PubmedArticle[0]->MedlineCitation->Article->GrantList; // returns an array with objects containing GrantID, Acronym, Agency, Country
$publicationType = $xml->PubmedArticle[0]->MedlineCitation->Article->PublicationTypeList->PublicationType; // may return an array? otherwise, just "JOURNAL ARTICLE"
$keywords = $xml->PubmedArticle[0]->MedlineCitation->KeywordList->Keyword; // returns an array which can be iterated for all keywords #woot

// PubmedData chain has variables too, but mostly redundant
$articleIds = $xml->PubmedArticle[0]->PubmedData->ArticleIdList->ArticleId; // returns array of IDs keyed by number, so not helpful in extrapolating what the ID is. BUT, can iterate this and check for PMC####### and NIHMS###### rows

// Not all articles have it, but there are some with Mesh arrays
$mesh = $xml->PubmedArticle[1]->MedlineCitation->MeshHeadingList; // returns array with objects for elements

$abstractTwo = $xml->PubmedArticle[1]->MedlineCitation->Article->Abstract; // may return array to iterate for multiple paragraphs
// Print out results to test variable chains

print "PMID: ";
print $pmid;
print "<br>";
print "ISSN: ";
print $issn;
print "<br>";
print "Volume: ";
print $volume;
print "<br>";
print "Issue: ";
print $issue;
print "<br>";
print "Journal Title: ";
print $journalTitle;
print "<br>";
print "Abr Title: ";
print $journalAbrTitle;
print "<br>";
print "Article Title: ";
print $articleTitle;
print "<br>";
print "<h3>abstract</h3>";
print "<pre>";
print_r($abstract);
print "<br><br><br>";
print_r($abstractTwo);
print "</pre>";
print "<br>";
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
print "<br>";
print "<h3>grants</h3>";
print "<pre>";
print_r($grants);
print "</pre>";
print "<br><br>";
print "Publication Type: ";
print $publicationType;
print "<br>";
print "<br>";
print "<h3>keywords</h3>";
print "<pre>";
print_r($keywords);
print "</pre>";
print "<h3>Mesh Headings</h3>";
print "<pre>";
print_r($mesh);
print "</pre>";

print "<h3>verify results here</h3>";
print "<pre>";
print_r($xml);
print "</pre>";
 
 
?>

