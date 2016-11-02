<?php
$search_term = "HD052120[Grant Number]";
$combined_search = "https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi?db=pubmed&retmode=json&retmax=1000&tool=FSU_IR&email=aretteen@fsu.edu&term={$search_term}";

print $combined_search;
?>
