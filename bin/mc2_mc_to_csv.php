#!/usr/bin/php
<?php
/** 
 * ================================================================================================================
 * Extract data from MiddleCare database to spreadsheets files (CSV)
 * @author jvigneron
 * ================================================================================================================
 * -------- HISTORY
 * ---- SENO SLS
 * > php mc2_mc_to_csv.php --site sls --dict --dsp DSP2
 * > php mc2_mc_to_csv.php --site sls --dsp DSP2 --deb 20180101 --fin 20180107
 * $item_names_seno = ['VAR1290','VAR1312','VAR1333','VAR1358','VAR1370','VAR1418','VAR1419','VAR1426','VAR1481','VAR1496','VAR1497','VAR1498','VAR1501','VAR1504','VAR1505','VAR1508','VAR1509','VAR1515','VAR1516','VAR1525','VAR1711','VAR1780','VAR1809','VAR1817','VAR1940','VAR1941','VAR1989','VAR1990','VAR1992','VAR2124','VAR2128','VAR2140'];
 * 
 * ---- RAAC SLS
 * - RAAC : Chir générale : DSP81 page « Consultation RAAC » ('CHAPITRE7' = Suivi ppatient RAAC + 'CHAPITRE5' = RAAC)
 * > php mc2_mc_to_csv.php --site sls --dict --dsp DSP81
 * > php mc2_mc_to_csv.php --site sls --dsp DSP81 --deb 20180101 --fin 20180107
 * $item_names_raac = ['VAR1','VAR2','VAR3','VAR4','VAR5','VAR6','VAR84','VAR7','VAR8','VAR11','VAR9','VAR10','VAR12','VAR13','VAR14','VAR15','VAR16','VAR17','VAR79','VAR18','VAR19','VAR20','VAR21','VAR22','VAR23','VAR24','VAR25','VAR26','VAR78','VAR27','VAR28','VAR29','VAR30','VAR31','VAR32','VAR33','VAR83','VAR77','VAR81','VAR82','VAR34','VAR35','VAR36','VAR37','VAR38','VAR39','VAR40','VAR41','VAR42','VAR43','VAR44','VAR45','VAR46','VAR47','VAR48','VAR49','VAR50','VAR51','VAR52','VAR53','VAR80','VAR54','VAR55','VAR56','VAR57'];
 * 
 * - RAAC : Uro DSP97 page « RAAC » ('CHAPITRE10' = Audit RAAC + 'CHAPITRE5' = RAAC)
 * > php mc2_mc_to_csv.php --site sls --dict --dsp DSP97
 * > php mc2_mc_to_csv.php --site sls --dsp DSP97 --deb 20180101 --fin 20180107
 * $item_names_raac = ['VAR1','VAR2','VAR3','VAR4','VAR5','VAR6','VAR7','VAR48','VAR56','VAR47','VAR49','VAR50','VAR51','VAR52','VAR53','VAR54','VAR55','VAR57','VAR8','VAR9','VAR11','VAR12','VAR13','VAR14','VAR15','VAR16','VAR17','VAR18','VAR19','VAR20','VAR21','VAR22','VAR24','VAR25','VAR23','VAR10','VAR26','VAR27','VAR28','VAR29','VAR30','VAR31','VAR32','VAR33','VAR34','VAR35','VAR36','VAR37','VAR38','VAR39','VAR40','VAR41','VAR42','VAR43','VAR46','VAR44','VAR45'];
 * 
 * ---- Hemato Onco / CarTCells SLS
 * - CRH Hemato Onco : DSP22	HEMATO-ONCOLOGIE	Service d''hématologie-oncologie C6
 * > php mc2_mc_to_csv.php --site sls --dict --dsp DSP22
 * > php mc2_mc_to_csv.php --site sls --dsp DSP22 --deb 20190101 --fin 20190201
 * - Suivi des Dossiers Car : T DSP96	CAR-T	Dossier suivi patients CAR-T
 * > php mc2_mc_to_csv.php --site sls --dict --dsp DSP96
 * > php mc2_mc_to_csv.php --site sls --dict --dsp DSP96 --items "VAR1 VAR2 VAR83 VAR84" --excel
 * > php mc2_mc_to_csv.php --site sls --dsp DSP96 --deb 20190101 --fin 20190201 --excel
 * > php mc2_mc_to_csv.php --site sls --dsp DSP96 --deb 20190101 --fin 20190201 --items "VAR1 VAR2 VAR83 VAR84"
 * -----------------------------------------------------------------------------------------
 */
require_once __DIR__.'/../vendor/autoload.php';
use MC2\Core\Log\LoggerFactory;
use MC2\Core\Helper\DateHelper;
use MC2\MiddleCare\MCExtractManager;
use MC2\MiddleCare\MCRepository;
use MC2\Core\CSV\CSVWriter;
use MC2\Core\CSV\CSVOption;
use MC2\RedCap\RCInstrument;
use MC2\DSP\Dossier;
use MC2\DSP\Document;
use MC2\DSP\ItemValue;
use MC2\DSP\PatientRepository;
use MC2\DSP\Patient;
use Symfony\Component\Yaml\Yaml;

date_default_timezone_set('Europe/Paris');

if ($argc < 2) {
    echo "
    NAME 
    mc2_mc_to_csv.php - YAGNI! Extraction depuis MiddleCare vers fichier(s) CSV

    SYNOPSIS
    php mc2_mc_to_csv.php [--dict] --site <sls|lrb> --dsp <dsp_id> --deb <date_debut> --fin <date_fin> [--items <items>] [--type_doc <doc_type>]

    DESCRIPTION
    Extraction des données de MiddleCare vers base de données locale mc2 (cf. mc2.yaml)
    
    OPTIONS
    - dict (optionnal) : ne récupérer que les dictionnaires (et non les données)
    - site : sls | lrb
    - dsp : identifiant du DSP (ex: DSP2)
    - deb (optionnal) : date de début au format YYYYMMDD
    - fin (optionnal) : date de fin au format YYYYMMDD
    - items (optionnal) : liste des items à récupérer ex : 'VAR1290 VAR1312 VAR1333'
    - excel (optionnal): CSV excel friendly (BOM,UTF8 etc)
    - nohtml : supprimer les balises HTML dans les valeurs des items
    
    EXAMPLES
    - Extraire la liste des DSP depuis MiddleCare vers un fichier CSV :
    > php mc2_mc_to_csv.php --dict --site lrb 
    
    - Extraire le dictionnaire d'un DSP donné depuis MiddleCare vers un fichier CSV :
    > php mc2_mc_to_csv.php --dict --site sls --dsp DSP2
    
    - Extraire le dictionnaire d'un DSP avec filtrage des variables/items depuis MiddleCare vers un fichier CSV :
    > php mc2_mc_to_csv.php --dict --site sls --dsp DSP2 --items 'VAR1290 VAR1312 VAR1333 VAR1358 VAR1370 VAR1418 VAR1419 VAR1426 VAR1481 VAR1496 VAR1497 VAR1498 VAR1501 VAR1504 VAR1505 VAR1508 VAR1509 VAR1515 VAR1516 VAR1525 VAR1711 VAR1780 VAR1809 VAR1817 VAR1940 VAR1941 VAR1989 VAR1990 VAR1992 VAR2124 VAR2128 VAR2140'
    
    - Extraire les données d'un DSP pour une période donnée depuis MiddleCare vers un ou plusieurs fichier(s) CSV
    > php mc2_mc_to_csv.php --site sls --dsp DSP2 --deb 20180101 --fin 20190101 
    ";
    exit(1);
}

$longopts = array("dict", "dsp:", "deb:", "fin:", "items:", "page:", "excel","site:","nohtml");
$options = getopt("", $longopts);

$now = new DateTime();

$configuration = Yaml::parse(file_get_contents(__DIR__."/../config/mc2.yaml"));
$logger = LoggerFactory::create_logger("mc2_mc_to_csv", __DIR__.'/../log');
$site = isset($options["site"]) ? $options["site"] : 'sls';
$excel_friendly = isset($options['excel']);
$nohtml = isset($options['nohtml']);
$base_url = $configuration['middlecare'][$site]['doc_base_url'];

$mc_repo = new MCRepository($configuration,$logger);
$mc_repo->connect($site);
$dossier_repo = null;
$document_repo = null;
$patient_repo = null;
$csv_options = new CSVOption($excel_friendly);
$csv_writer = new CSVWriter($csv_options,$logger);
$mc_extracter = new MCExtractManager(MCExtractManager::SRC_MIDDLECARE,$site,$mc_repo,$dossier_repo,$document_repo,$patient_repo, $csv_writer, $logger);

if(isset($options['dict'])){
    // ----- DSP  List
    if(!isset($options['dsp'])){
        $mc_extracter->exportAllDSPMetadataToCSV();
    }
    // ----- DSP Dictionnary (Items / Pages)
    else{
        $dsp_id = $options['dsp'];
        $item_names = isset($options["items"]) ? explode(" ",$options["items"]) : null;
        $mc_extracter->exportDSPDictionnaryToCSV($dsp_id,$item_names);
    }
}else{
    // ----- DSP Data (Document / Item Value / Patient)
    if(isset($options['dsp']) && isset($options['deb']) && isset($options['fin'])){
        $dsp_id = $options['dsp'];
        $date_debut = new DateTime($options['deb']);
        $date_fin = new DateTime($options['fin']);
        $item_names = isset($options["items"]) ? explode(" ",$options["items"]) : null;
        $page_name = isset($options["page"]) ? $options["page"] : null;
        $mc_extracter->exportDSPDataToCSV($dsp_id, $date_debut, $date_fin,$item_names,$page_name);
    }else{
        $logger->info("Unknown parameters",array('options' => $options));
    }
}
$logger->info("Ended after ". $now->diff(new DateTime())->format('%H:%I:%S'));