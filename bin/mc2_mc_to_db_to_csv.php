#!/usr/bin/php
<?php
/** 
 * ================================================================================================================
 * Extract data from mc database to mc2 database to spreadsheets files (CSV)
 * @author jvigneron
 * ================================================================================================================
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
    echo "
    NAME 
    mc2_mc_to_db_to_csv.php - Extraction depuis MiddleCare vers DB locale (mc2) puis vers fichier(s) CSV

    SYNOPSIS
    php mc2_mc_to_db_to_csv.php --site <sls|lrb> --dsp <dsp_id> --deb <date_debut> --fin <date_fin> 

    DESCRIPTION
    Extraction des données de MiddleCare vers base de données locale mc2 (cf. mc2.yaml) puis vers fichier(s) CSV
    
    OPTIONS
    - site : sls | lrb
    - dsp : identifiant du DSP (ex: DSP2)
    - deb (optionnal) : date de début au format YYYYMMDD
    - fin (optionnal) : date de fin au format YYYYMMDD
    - period (optionnal) : période des fichiers CSV ex: 1 fichier CSV par mois = P1M, 1 fichier CSV pour 2 ans : P2Y etc
    - excel (optionnal): CSV excel friendly (BOM,UTF8 etc)
    - nohtml : supprimer les balises HTML dans les valeurs des items
    
    EXAMPLES
    - Extraire les données d'un DSP pour une période donnée depuis MiddleCare vers la base locale puis vers un ou plusieurs fichier(s) CSV, avec filtrage sur le type de document:
    > php mc2_mc_to_db_to_csv.php --site sls --dsp DSP2 --deb 20180101 --fin 20190101 --period P1M
    ";
    exit(1);
}

$longopts  = array("site:","dsp:","deb:","fin:","excel","period:","nohtml");
$options = getopt("", $longopts);

$now = new DateTime();

$configuration = Yaml::parse(file_get_contents(__DIR__."/../config/mc2.yaml"));
$logger = LoggerFactory::create_logger("mc2_mc_to_db_to_csv", __DIR__.'/../log');
$site = isset($options['site']) ? $options['site'] : 'sls';
$period = isset($options['period']) ? $options['period'] : 'P1M';
$excel_friendly = isset($options['excel']);
$nohtml = isset($options['nohtml']);
$base_url = $configuration['middlecare'][$site]['doc_base_url'];

$mc_repo = new MCRepository($configuration,$logger,$site);
$dossier_repo = new DossierRepository($configuration,$logger,$site);
$document_repo = new DocumentRepository($configuration,$logger,$site,$base_url);
$patient_repo = new PatientRepository($configuration,$logger);
$csv_options = new CSVOption($excel_friendly,$nohtml);
$csv_writer = new CSVWriter($csv_options,$logger);
$mc_extracter = new MCExtractManager(MCExtractManager::SRC_LOCAL_DB,$site,$mc_repo,$dossier_repo,$document_repo,$patient_repo, $csv_writer, $logger);

// -----------------------------------------------------------------------------------------

// ----- Document / Item Value / Patient 
if(isset($options['dsp']) && isset($options['deb']) && isset($options['fin'])){
    $dsp_id = $options['dsp'];
    $date_debut = new DateTime($options['deb']);
    $date_fin = new DateTime($options['fin']);
    $item_names = null;
    $page_name = null;
    $type_doc = null;

    // ---- DSP Dictionnary
    $mc_extracter->importDSPDictionnary($dsp_id);
    $mc_extracter->exportDSPDictionnaryToCSV($dsp_id,$item_names);
    
    // ---- DSP Data
    $date_debut = new DateTime($options['deb']);
    $date_fin = new DateTime($options['fin']);
    $mc_extracter->importDSPData($dsp_id,$date_debut,$date_fin,$item_names);
    $mc_extracter->exportDSPDataToCSV($dsp_id, $date_debut, $date_fin,$item_names,$page_name,$type_doc,$period);

    // TODO DELETE DSP DATA ?    
}else{
    $logger->info("Unknown parameters",array('options' => $options));
}
$logger->info("Ended after ".$now->diff(new DateTime())->format('%H:%I:%S'));