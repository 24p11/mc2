#!/usr/bin/php
<?php
/** 
 * ================================================================================================================
 * Extract documents from mc2 database and download corresponding PDF files.
 * @author jvigneron
 * ================================================================================================================
 * HISTORY
 * > php mc2_db_to_pdf.php --dsp DSP96 --deb 20190401 --fin 20190402
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

$longopts = array("dsp:", "deb:", "fin:", "nip:", "ipp:", "site:");
$options = getopt("", $longopts);

$now = new DateTime();

$logger = LoggerFactory::create_logger("mc2_db_to_pdf", __DIR__.'/../log');
$config_db_middlecare = Yaml::parse(file_get_contents(__DIR__."/../config/config_db_middlecare.yml"));
$config_db_dsp = Yaml::parse(file_get_contents(__DIR__."/../config/config_db_mc2.yml"));
$site = isset($options["site"]) ? $options["site"] : 'sls';

$mc_repo = new MCRepository($config_db_middlecare[$site],$logger,$site);
$dossier_repo = new DossierRepository($config_db_dsp,$logger,$site);
$document_repo = new DocumentRepository($config_db_dsp,$logger,$site,$config_db_middlecare[$site]['doc_base_url']);
$patient_repo = new PatientRepository($config_db_dsp,$logger);
$excel_friendly = isset($options['excel']);
$csv_options = new CSVOption($excel_friendly);
$csv_writer = new CSVWriter($csv_options,$logger);
$mc_extracter = new MCExtractManager(MCExtractManager::SRC_LOCAL_DB,$site,$mc_repo,$dossier_repo,$document_repo,$patient_repo, $csv_writer, $logger);

// ----- Document / Item Value / Patient 
if(isset($options['dsp']) && isset($options['deb']) && isset($options['fin'])){
    $dsp_id = $options['dsp'];
    $date_debut = new DateTime($options['deb']);
    $date_fin = new DateTime($options['fin']);

    // TODO : also search by IPP
    $patient_ids = isset($options["nip"]) ? explode(" ",$options["nip"]) : null;
    
    $documents = $document_repo->findDocumentByDossierId($dsp_id,$date_debut, $date_fin,$patient_ids);
    $count_documents = count($documents);
    $i = 1;
    foreach($documents as $document){
        if($i < 20){
            $downloaded = array();
            for ($revision = 0; $revision <= $document->revision; $revision++) {    
                $url = $document->getURL($revision);
                if(in_array($url,$downloaded))
                    continue;
                $downloaded[] = $url;
                $file_name = $document->patient_id."_".$document->dossier_id."_".basename($url);
                $output_folder = __DIR__."/../data/pdf/";
                file_put_contents("{$output_folder}{$file_name}",fopen($url,'r'));
                $logger->info("telechargement {$i}/{$count_documents} : {$file_name}",array('url' => $url));
            }
        }
        $i++;
    }
}else{
    $logger->info("Unknown parameters",array('options' => $options));
}
$logger->info("Ended after ".$now->diff(new DateTime())->format('%H:%I:%S'));