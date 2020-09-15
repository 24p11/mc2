#!/usr/bin/php
<?php
/** 
 * ================================================================================================================
 * Extract documents as PDF from middlecare via a CSV listing
 * ================================================================================================================
 * usage : 
 * php mc2_controle_t2a.php --site sls --in ../data/controle_t2a_2019_light.csv
 */
require_once __DIR__.'/../vendor/autoload.php';
use MC2\Core\Log\LoggerFactory;
use MC2\MiddleCare\MCRepository;
use MC2\DSP\Document;
use Symfony\Component\Yaml\Yaml;
use MC2\Core\Helper\DocumentHelper;

date_default_timezone_set('Europe/Paris');

if ($argc < 2) {
    echo "\n";
    exit(1);
}

function open_csv($file_path){
    $file =  fopen($file_path, "r");
    $all_rows = [];
    $header = fgetcsv($file,0,';');
    while ($row = fgetcsv($file,0,';'))
      $all_rows[] = array_combine($header, $row);
    fclose($file);
    return $all_rows;
}

$longopts = array("site:","in:");
$options = getopt("", $longopts);

$now = new DateTime();

$configuration = Yaml::parse(file_get_contents(__DIR__."/../config/mc2.yaml"));
$logger = LoggerFactory::create_logger("mc2_controle_t2a", __DIR__.'/../log');
$site = isset($options["site"]) ? $options["site"] : 'sls';
$mc_repo = new MCRepository($configuration,$logger);
$mc_repo->connect($site);
$dl_all_revision = false;
$base_url = $configuration['middlecare'][$site]['doc_base_url'];

$controles = isset($options['in']) 
    ? open_csv(__DIR__."/".$options['in'])
    : open_csv(__DIR__."/../data/controle_t2a_2019_clean.csv");

$ndas = array_column($controles, 'nas');

// ---- Get documents 
$mc_documents = $mc_repo->getDocumentFromNDA($ndas);
$documents = [];
foreach ($mc_documents as $mc_document)
    $documents[] = Document::createFromMCData($base_url,$site,$mc_document['DSP_ID'],$mc_document);

// ---- Download documents 
$count_documents = count($documents);
$i = 1;
$doc_type_count = [];
$doc_types = [];
foreach($documents as $document){
    $downloaded = array();
    $revision = $dl_all_revision === false ? $document->revision : 1;
    if($document->revision > 0){
        for ($revision; $revision <= $document->revision; $revision++) {    
            $url = $document->getURL($revision);
            if(in_array($url,$downloaded) || empty($document->extension))
                continue;
            $nda = $document->venue;
            $document_controle = array_filter($controles, function($r) use($nda) { 
                return $r["nas"] === $nda; 
            });
            $key = array_key_first($document_controle);
            $ipp = $document_controle[$key]['ipp'];
            $ogc = $document_controle[$key]['ogc'];
            $downloaded[] = $url;
            $file_name = $document->venue."_".$document->date_creation->format("Ymd")."_".$document->categorie."_".preg_replace('/[^\wùûüÿàâæçéèêëïîôœ]/', ' ',$document->type)."_".basename($url);
            $output_folder = __DIR__."/../data/pdf/{$site}/{$ogc}_{$ipp}_{$nda}/";
            if (!file_exists($output_folder))
                @mkdir($output_folder, 0777, true);
            $context = array(
                "ssl" => array(
                    "verify_peer"=>false,
                    "verify_peer_name"=>false,
                )
            );
            file_put_contents("{$output_folder}{$file_name}",file_get_contents($url,false, stream_context_create($context)));
            $logger->info("downloading {$i}/{$count_documents} : {$file_name}",array('url' => $url));
            if(!isset($doc_type_count[$ogc]))
                $doc_type_count[$ogc] = [];
            if(!isset($doc_type_count[$ogc][$document->categorie]))
                $doc_type_count[$ogc][$document->categorie] = 0;
            $doc_type_count[$ogc][$document->categorie]++;
            if(!in_array($document->categorie,$doc_types))
                $doc_types[] = $document->categorie;
        }       
    }
    $i++;
}

$doc_categories = DocumentHelper::getCategoriesLibelles();

$fp = fopen(__DIR__."/../data/pdf/controle_t2a_doc_count_types_{$site}.csv", 'w');
$delimiter = ';';
$headers = ['ogc'];
foreach($doc_types as $doc_type)
    $headers[] = $doc_type;

$headers_libelle = array_map(function($doc_type) use($doc_categories) { 
    return array_key_exists($doc_type,$doc_categories) ? $doc_categories[$doc_type]." ({$doc_type})" : $doc_type; 
},$headers);
fputcsv($fp, $headers_libelle, $delimiter);
foreach ($doc_type_count as $ogc => $counts) {
    $fields = [];
    foreach($headers as $header){
        $fields[] = ($header === 'ogc')
            ? $ogc
            : (array_key_exists($header,$counts) ? $counts[$header] : 0);
    }
    fputcsv($fp, $fields, $delimiter);
}
fclose($fp);
$logger->info("Ended after ".$now->diff(new DateTime())->format('%H:%I:%S'));
