# pmc_grabber

PMC_Grabber is a PHP-based utility to be used with the NIH PubMed API interfaces. It pulls metadata from the eSummary and eFetch APIs and converts the metadata into valid MODS records.

## Using PMC_Grabber

1. If this is the first time running the script, make sure the database.sql file is deleted from the directory.
  * Also, edit index.php directly to change the search string to your desired search string. The string must be HTML-Encoded. You can use the [Advance Search tool on PubMed](http://www.ncbi.nlm.nih.gov/pubmed/advanced) to build a complex string of searches.
  * Be aware of the maximum execution time of the script. The script builds in sleep time for each API call and PDF grabs. Allow 20 seconds + 10 seconds per record + 300 seconds to give 5 minutes extra time to be safe.
  * Review the overview below to get an understanding of how to re-tool PMC_Grabber for use at your institution. You will want to change static elements in the MODS record at the very least. Becomming familiar with the structure of PubMed's data output through eSummary and eFetch is highly recommended.

2. Launch index.php in a browser (or through the command line if you want finer control of stopping the script from running).
  * Development and testing of this script was done using a local webserver. If executing the script in a browser from a local webserver, you can stop the script by stopping the httpd service on your machine.

3. Once the script is finished running, you can launch admin.php in a browser and view the contents of the local database through the PHPLiteAdmin administration layer.
  * Control of what IDs get processed is done through querying the local database.
  * The default password is **pmc_admin**.

4. Review the MODS records and ingest PDFs into your repository.

5. The script is built to be run multiple times over a period of time.  You can run the script at any point again in the future; you should coordinate any subsequent runs with the embargo table in PHPLiteAdmin to ensure the capture and processing of new MODS records.
  * Aside from picking up expired embargo dates, the script will also pick up any new articles added to the PubMed database satisfying the original search criteria. Most new articles will have some embargo on them, usually for a year.

### A Note About PubMed's Restriction on Systematic Downloading of Articles

For anyone wishing to utilize PMC Grabber's feature that downloads the manuscript PDF of a specific article identified by PMC Grabber, please be aware of PubMed's stated [restrictions on systematic downloading of articles](https://www.ncbi.nlm.nih.gov/pmc/about/copyright). Based on my reading of this policy, I believe that using PMC Grabber to retrieve the PDFs is not prohibited. PMC Grabber does not crawl the manuscript database and is not a completely automated process; the workflow requires identifying a qualifying sub-set of articles, processes downloads 1 at a time with forced pauses between each download, and is supported by our institution being granted permission by the authors to upload their PMC article manuscript into the institutional repository. The script accesses the PDF copies through the public-facing URL and basically accomplishes what a person would normally be able to do manually, but in a more efficient way. Throughout my documentation, I stress the importance of users to implement courtesy in their efforts to harvest metadata and articles through an API so as to not overload servers or otherwise negatively impact the availability of the service for others. So far I have not received any complaints from PubMed after using PMC Grabber multiple times, but I remain open to updating the code upon request.

## Overview of Script Process

1. Initial steps
  * ini_set is used to set a maximum execution time for the script so that it does not time out. PMC_Grabber has sleep() functions built in to slow down the query process and to not bog down the NIH servers. Allow 10 seconds for each record you anticipate retrieving.
  * date_default_timezone_set is used to set the server's timezone for creation of date values. You may not need to use this on your server.

2. eSearch API Call
  * The first API call is to eSearch. The $combined_search variable contains an HTML-encoded string representing the search you wish to conduct.
  * eSearch returns only a list of IDs that is used in subsequent API calls for metadata on a per-record basis.
  * **Note that you can construct multiple different searches across different fields, combine them into one search string, and then pass only one API call for a complex results list.** When using this script, please keep in mind that the fewer times the API is called, the better the load handled by NIH's server.
  * Using PubMed's own Advanced Search tool is helpful in creating long, complex search strings. You can use a free HTML encoding tool from there to generate a valid, html-encoded complex search string.
  * It is helpful to note that if the same record ID would be returned multiple times from a complex string, the API will only return that ID once. Thus, you do not have to worry about duplicate IDs being fed into the subsequent API calls.
 
3. Local Database Query
  * This script utilizes SQLite3 to manage the records processed by the script. The table structure by default is:
     1. embargo - for records with an embargo date noted in the metadata. These records will not be processed until the embargo date has passed and an author manuscript is publicly available.
     2. protected - for records that do not have an embargo date or an author manuscript ID. The full-text article associated with these records are locked behind a publisher paywall, and therefor these records will not be processed at all.
     3. processed - for records that have an author manuscript ID and are not under embargo. The script will generate XML MODS Records Files for each record, as well as pull the public-facing PDF copy of the record. **Note: Make sure you or your institution has proper permissions from the authors whose manuscripts you are pulling.**
  * The logical narrative behind the database infrastructure is to check against the stored IDs in "embargo", "protected", and "processed" tables and exclude those IDs from being processed into MODS records. Every time the script is run, it will purge the "embargo" table of all records with an expired embargo-date (based on the date the script is run).
  * The SQLite database is stored locally as "database.sqlite". The script will create this database and empty tables upon loading if the database file does not exist, **so when starting fresh, make sure to delete any existing database.sqlite file in the directory.**
  * Administrators can access the PHPLiteAdmin tool by launching the "admin.php" file.  The default password is **pmc_admin**, which can be changed by editing the admin.php file with a text editor.

4. eSummary & eFetch API calls
  * Once the ID list has been filtered to contain only the IDs that have not been processed, are not embargoed, and are not protected, the script passes the IDs to the eSearch and eFetch APIs
  * **Note that these APIs support comma-separated strings of IDs. Using this method will reduce 200 separate API calls to ONE, drastically limiting the strain on the server.  Please program responsibly to ensure you are not putting undue strain on the PubMed servers!**
  * PubMed notes that if more than about 200 UIDs are provided at once, the request should be made using the HTTP POST method.  This script has not been tested on a set of records larger than 200 yet.
  * For our purposes, calling both eSummary and eFetch was necessary to get at all of the relevant metadata we wanted to use in creating a MODS record.  To get a feel for which API returns what information, you should pick an ID and invoke the two APIs in two separate tabs on your browser. Keep in mind that eSummary will return JSON or XML (set through the retmode parameter), but eFetch will not return JSON and only XML (along with plain text). Thankfully, PHP can parse JSON and XML data structures with relative ease.

5. Raw Metadata Collection
  * The eSummary and eFetch API calls will return JSON and XML data structures. The JSON data is organized by UID, while the XML data (once parsed by SimpeXML in PHP) is organized in the order the IDs were passed to it.  Using a for loop with an incrementing index value starting at "0", the script can store data from both API calls for each record and ensure horizontal consistency (that is, the records will not be mixed up).
  * During this process, data from each record is stored in loop variables and at the end of each loop passed into an array. Thus, at the end of the loop process, the script is left with an array of records, each containing an array of data.
  * Not all variables were needed for our use, so to get the full benefit of accessing this data, you should review raw data outputs from the API to see what data you actually want.
  * As of now, the script stores the following pieces of metadata from the eFetch API:
    * ISSN of journal
    * Volume of journal
    * Issue of journal
    * Title of journal
    * Abbreviated title of journal
    * Title of article
    * Abstract text for article
    * Authors for article (which includes First Name + Middle Initial, Last Name, and Affiliation (but see note on Affiliation below)
    * Grant numbers associated with article
    * Keywords associated with article
    * Identifiers associated with article (doi, pmid, etc)
    * Mesh subject heading Descriptors and Qualifiers associated with article
    * We found the following available variables from eFetch not useful for our purposes:
       1. Publication Type (e.g., "JOURNAL ARTICLE")
       2. Article identifiers (the JSON structure had more information and is relatively easier to access)
  * As of now, the script stores the following pieces of metadata from the eSummary API:
    * UID of article (a PubMed-created unique ID)
    * Page range of article
    * ESSN of journal
    * Publication Date of article
    * The following pieces of metadata are available from eSummary, but also duplicative from eFetch or were not useful for our purposes:
       1. Volume of journal
       2. Issue of journal
       3. Language (was always english for our set)
       4. ISSN of journal
       5. Publication type
       6. View count (interesting to be able to grab, but we could not use it in our repository)
  * The above list of metadata available from PubMed is **not exhaustive**, however it contains the relevant data needed to form a valid MODS record.

6. Parse Raw Data
  * The raw data collected from PubMed is a collection of data strings and arrays, some of which needs to be parsed in order to be validly passed into a MODS record.
  * The following data required parsing:
     1. Abstract - Raw must be combined from paragraph arrays into a single string.
     2. Authors & Affiliation - Raw data needs to be understood. "First name" for PubMed is actually "First name + Middle Initial", which does translate nicely to MODS "given" format. However, the Affiliation string is not tightly controlled by PubMed and the contents of each author's affiliation string varies from nothing to including department names, addresses, and e-mails.  We abandoned the attempt to programmatically parse Affiliation string because there is no pattern of what to expect and it is always better to err on the side of creating a slightly incomplete, but valid MODS record instead of creating a complete, error prone record.
     3. Grant Numbers - Raw array is combined to create a comma-separated string.
     4. Keywords - Raw array is combined to create a comma-separated string.
     5. Article IDs - There are more IDs associated to an article than necessary for inclusion in a repository. In this step, we select the IDs we care about and store in the array, as well as create institutional ids (IID) for use in our repository system. This step also checks the article for embargo status and sets relevant variables depending on that check.
     6. Article Title - Raw required parsing to more easily generate MODS compliant "title" fields, checking for Non Sort and SubTitle and storing relevant pieces of the title in variables for easy translation to MODS
     7. Publication Date - PubMed does not store the date in W3CDTF form, so it must be parsed for it
     8. Pages - MODS requires a <start> and <end> value, which presents a problem for raw page ranges such as "235-45". I wrote a script to detect this form and fix abbreviated page ranges.
     9. Mesh Subject Terms - The raw data here is tricky to parse properly, especially since the MeSH subject strings do not really match the MODS <subject> hierarchy. We decided to combine Descriptor/Qualifier pairs into a single string for each pair. We plan to update this in the future to also check against the MeSH authority DTD file to produce a valueURI for the MODS record.

7. Store Parsed Data into Records Array
  * Once the raw data is parsed for each article, the data is passed to an array that stores all data for all records. The script uses this array to populate the MODS record for each UID.

8. Populate Local Database with Embargoed or Protected IDs
  * At this point, the script is left with an array of records that are flagged as embargoed or protected, so the script populates the local database with these IDs and purges these IDs from the remaining ID Array, leaving only records that are valid to process into MODS.

9. Generate MODS Record
  * The next step is to dynamically create a MODS record for each record stored in the Records Array. Not all records will have the same metadata available, so empty checks are used in order to produce a valid MODS record for each article.
  * Every time a MODS record is generated for an ID, that ID is then stored in the "processed" table in SQLite, so when script is run again the ID will not be processed.
  * Note that at the end of the MODS Record Generation portion of this script, a number of static MODS elements are included. This was created for our institution's circumstances, so you should review and make sure to change any information not relevant for your repository.

10. Writing Files
  * The last step is for the script to write the MODS file to the /output/ folder using iid.xml as a naming convention
    



