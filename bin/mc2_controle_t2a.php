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
use MC2\Core\Helper\DateHelper;
use MC2\Core\Log\LoggerFactory;
use MC2\Core\CSV\CSVWriter;
use MC2\Core\CSV\CSVOption;
use MC2\MiddleCare\MCRepository;
use MC2\MiddleCare\MCExtractManager;
use MC2\DSP\DossierRepository;
use MC2\DSP\Dossier;
use MC2\DSP\DocumentRepository;
use MC2\DSP\Document;
use MC2\DSP\ItemValue;
use MC2\DSP\PatientRepository;
use MC2\DSP\Patient;
use MC2\RedCap\RCInstrument;
use Symfony\Component\Yaml\Yaml;

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
$mc_repo = new MCRepository($configuration,$logger,$site);
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
            $document_controle = array_filter($controles, function($r) use($nda) { return $r["nas"] === $nda; });
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

// TODO move this in a document related static method / helper
$mappin_categorie = [
    "120" => "CR de sejour hospitalier",
    "201" => "CR (ou fiche) de consultation",
    "301" => "CR d'anatomo-pathologie",
    "402" => "CR operatoire, CR d'accouchement",
    "309" => "CR d'acte diagnostique (autres)",
    "119" => "Synthese d'episode",
    "111" => "Lettre de sortie",
    "319" => "Resultat d'examen (autres)",
    "801" => "Autre document, source medicale",
    "302" => "CR de radiologie/imagerie",
    "521" => "Notification, Certificat",
    "409" => "CR d'acte therapeutique (autres)",
    "421" => "Prescription de medicaments",
    "429" => "Prescription, autre",
    "511" => "Demande d'examen",
    "422" => "Prescription de soins",
    "431" => "Dispensation de medicaments",
    "311" => "Resultats de biologie",
    "401" => "CR d'anesthesie",
    "203" => "CR de consultation d'anesthesie",
    "411" => "Pathologie(s) en cours",
    "439" => "Dispensation, autre"
];

$fp = fopen(__DIR__."/../data/pdf/controle_t2a_doc_count_types_{$site}.csv", 'w');
$delimiter = ';';
$headers = ['ogc'];
foreach($doc_types as $doc_type)
    $headers[] = $doc_type;
$headers_libelle = array_map(function($doc_type) use($mappin_categorie) { return array_key_exists($doc_type,$mappin_categorie) ? $mappin_categorie[$doc_type]." ({$doc_type})" : $doc_type; },$headers);
fputcsv($fp, $headers_libelle, $delimiter);
foreach ($doc_type_count as $ogc => $counts) {
    $fields = [];
    foreach($headers as $header){
        if($header === 'ogc')
            $fields[] = $ogc;
        else
            $fields[] = array_key_exists($header,$counts) ? $counts[$header] : 0;
    }
    fputcsv($fp, $fields, $delimiter);
}
fclose($fp);
$logger->info("Ended after ".$now->diff(new DateTime())->format('%H:%I:%S'));
