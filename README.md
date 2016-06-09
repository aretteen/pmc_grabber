# pmc_grabber

PMC_Grabber is a utility to be used with the NIH PubMed API interfaces. 
It will pull metadata records from the API and convert the metadata into valid
MODS records.


## MODS/Variable Alignment

**titleinfo**

title (full)

sortTitle

nonSort

**name**

namepart give

namepart family

affiliation

for each author

**abstract**

Abstract

**identifier**

identifiers: doi, etc

**relatedItem**

Title of Journal

Abbreviated Title of Journal?

     Part

         Pages

         Volume

         Issue

ISSN

ESSN


**note**

Keywords

Publication Note

Grant IDs?


**subject**

Mesh Subject Headings Descriptor and Qualifiers


Rough variables we have coming in from eSummary:

 ```
    uid
    source (abbreviated journal title)
    authors ARRAY
    title & sorttitle
    volume
    issue
    pages ###-##
    lang ARRAY (coded "eng")
    issn
    essen
    pubtype ARRAY "Journal Article", "Multicenter Study", etc
    articleids ARRAY idtype/idtypen/value (can access pmc-id here)
    attributes ARRAY - indicates whether it has abstract
    fulljournalname
    viewcount (interesting for metric absorbtion?)
    publishername and publisherlocation (may be blank)
    sortpubdate - W3c date compliant
    sortfirstauthor
    
    Variables we could have access to through eFetch, and some serious XML parsing:
    from PubmedArticleSet->PubmedArticle->MedlineCitation:
    article->journal->issn/title/abbreviation (probably more reliable)
    Abstract *** broken down into Array in PHP for each paragraph. easy enough
    AuthorList->Author->AffiliationInfo->Affiliation
    GrantList->GrantID/Agency/Country
    KeywordList->Keyword ****
    
    from  PubmedArticleSet->PubmedArticle->PubmedData:
    ArticleIdList->ArticleId (can grab pmc id easily)

```

#Tracked Variables

These are the variables that are going to be passed through to the Records Array

```
Direct Variables:
$issn
$volume
$issue
$journalTitle
$journalAbrTitle
$articleTitle
$sortTitle
$pages
$essnESum
$articleIdESum
$sortPubDate

Processed Array Variables:
$abstractString
$authorArray  - array($fname,$lname,$fullname,$authAffil);
$grantIDString
$keywordString
$articleIdArray[$idtype] = array($value);
$parsedTitleArray = array($nonsort,$sortTitle,$startTitle,$subTitle,$articleTitle);


$originInfo - array(dateIssued,publisher)

NOTE: 
<location displayLabel="Fulltext">
<url>http://dx.doi.org/DOI</url>
</location>

can be used to display a clickable link to the DOI on record!!
```