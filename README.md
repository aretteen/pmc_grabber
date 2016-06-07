# pmc_grabber

PMC_Grabber is a utility to be used with the NIH PubMed API interfaces. 
It will pull metadata records from the API and convert the metadata into valid
MODS records.

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