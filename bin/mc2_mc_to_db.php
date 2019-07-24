#!/usr/bin/php
<?php
/** 
 * ================================================================================================================
 * Extract data from MiddleCare database to local database
 * @author jvigneron
 * ================================================================================================================
 * -------- HISTORY
 * ---- SENO SLS
 * > php mc2_mc_to_db.php --site sls --dict --dsp DSP2
 * > php mc2_mc_to_db.php --site sls --dsp DSP2 --deb 20180101 --fin 20180107
 * $item_names_seno = ['VAR1290','VAR1312','VAR1333','VAR1358','VAR1370','VAR1418','VAR1419','VAR1426','VAR1481','VAR1496','VAR1497','VAR1498','VAR1501','VAR1504','VAR1505','VAR1508','VAR1509','VAR1515','VAR1516','VAR1525','VAR1711','VAR1780','VAR1809','VAR1817','VAR1940','VAR1941','VAR1989','VAR1990','VAR1992','VAR2124','VAR2128','VAR2140'];
 * ---- RAAC SLS
 * - RAAC : Chir générale : DSP81 page « Consultation RAAC » ('CHAPITRE7' = Suivi ppatient RAAC + 'CHAPITRE5' = RAAC)
 * > php mc2_mc_to_db.php --site sls --dict --dsp DSP81
 * > php mc2_mc_to_db.php --site sls --dsp DSP81 --deb 20180101 --fin 20180107
 * $item_names_raac = ['VAR1','VAR2','VAR3','VAR4','VAR5','VAR6','VAR84','VAR7','VAR8','VAR11','VAR9','VAR10','VAR12','VAR13','VAR14','VAR15','VAR16','VAR17','VAR79','VAR18','VAR19','VAR20','VAR21','VAR22','VAR23','VAR24','VAR25','VAR26','VAR78','VAR27','VAR28','VAR29','VAR30','VAR31','VAR32','VAR33','VAR83','VAR77','VAR81','VAR82','VAR34','VAR35','VAR36','VAR37','VAR38','VAR39','VAR40','VAR41','VAR42','VAR43','VAR44','VAR45','VAR46','VAR47','VAR48','VAR49','VAR50','VAR51','VAR52','VAR53','VAR80','VAR54','VAR55','VAR56','VAR57'];
 * - RAAC : Uro DSP97 page « RAAC » ('CHAPITRE10' = Audit RAAC + 'CHAPITRE5' = RAAC)
 * > php mc2_mc_to_db.php --site sls --dict --dsp DSP97
 * > php mc2_mc_to_db.php --site sls --dsp DSP97 --deb 20180101 --fin 20180107
 * $item_names_raac = ['VAR1','VAR2','VAR3','VAR4','VAR5','VAR6','VAR7','VAR48','VAR56','VAR47','VAR49','VAR50','VAR51','VAR52','VAR53','VAR54','VAR55','VAR57','VAR8','VAR9','VAR11','VAR12','VAR13','VAR14','VAR15','VAR16','VAR17','VAR18','VAR19','VAR20','VAR21','VAR22','VAR24','VAR25','VAR23','VAR10','VAR26','VAR27','VAR28','VAR29','VAR30','VAR31','VAR32','VAR33','VAR34','VAR35','VAR36','VAR37','VAR38','VAR39','VAR40','VAR41','VAR42','VAR43','VAR46','VAR44','VAR45'];
 * ---- Hemato Onco / CarTCells SLS
 * - CRH Hemato Onco : DSP22	HEMATO-ONCOLOGIE	Service d''hématologie-oncologie C6
 * > php mc2_mc_to_db.php --site sls --dict --dsp DSP22
 * > php mc2_mc_to_db.php --site sls --dsp DSP22 --deb 20190101 --fin 20190201
 * - Suivi des Dossiers Car : T DSP96	CAR-T	Dossier suivi patients CAR-T
 * > php mc2_mc_to_db.php --site sls --dsp DSP22 --deb 20180101 --fin 2019060
 * ---- Rea Med Toxico LRB (creation mi 2017)
 * > php mc2_mc_to_db.php --site lrb --dict --dsp DSP127
 * > php mc2_mc_to_db.php --site lrb --dsp DSP127 --deb 20100101 --fin 20190201
 * -----------------------------------------------------------------------------------------
 */
require_once __DIR__.'/../vendor/autoload.php';
use SBIM\Core\Helper\DateHelper;
use SBIM\Core\Log\LoggerFactory;
use SBIM\Core\CSV\CSVWriter;
use SBIM\Core\CSV\CSVOption;
use SBIM\MiddleCare\MCRepository;
use SBIM\MiddleCare\MCExtractManager;
use SBIM\DSP\DossierRepository;
use SBIM\DSP\Dossier;
use SBIM\DSP\Page;
use SBIM\DSP\Item;
use SBIM\DSP\DocumentRepository;
use SBIM\DSP\Document;
use SBIM\DSP\ItemValue;
use SBIM\DSP\PatientRepository;
use SBIM\DSP\Patient;
use Symfony\Component\Yaml\Yaml;

date_default_timezone_set('Europe/Paris');

if ($argc < 2) {
    echo "
    NAME 
    mc2_mc_to_db.php - Extraction depuis MiddleCare vers DB locale (mc2)

    SYNOPSIS
    php mc2_mc_to_db.php [--dict] --site <sls|lrb> --dsp <dsp_id> --deb <date_debut> --fin <date_fin>

    DESCRIPTION
    Extraction des données de MiddleCare vers base de données locale mc2 (cf. config_db_middlecare.yml et config_db_mc2.yml)
    
    OPTIONS
    - dict (optionnal) : ne récupérer que les dictionnaires (et non les données)
    - site : sls | lrb
    - dsp : identifiant du DSP (ex: DSP2)
    - deb (optionnal) : date de début au format YYYYMMDD
    - fin (optionnal) : date de fin au format YYYYMMDD
    
    EXAMPLES
    - Extraire la liste des DSP de MiddleCare et les enregistrer dans la base locale :
    > php mc2_mc_to_db.php --dict --site lrb 

    - Extraire le dictionnaire d'un DSP de MiddleCare donné et les enregistrer dans la base locale :
    > php mc2_mc_to_db.php --dict --site sls --dsp DSP2

    - Extraire les données d'un DSP MiddleCare pour une période donnée et les enregistrer dans la base locale :
    > php mc2_mc_to_db.php --site sls --dsp DSP2 --deb 20180101 --fin 20180201
    ";
    exit(1);
}

$longopts = array("dict", "dsp:", "deb:", "fin:","items:","site:");
$options = getopt("", $longopts);

$now = new DateTime();

$logger = LoggerFactory::create_logger("mc2_mc_to_db", __DIR__.'/../log');
$config_db_middlecare = Yaml::parse(file_get_contents(__DIR__."/../config/config_db_middlecare.yml"));
$config_db_dsp = Yaml::parse(file_get_contents(__DIR__."/../config/config_db_mc2.yml"));
$site = isset($options["site"]) ? $options["site"] : 'sls';

$mc_repo = new MCRepository($config_db_middlecare[$site],$logger,$site);
$dossier_repo = new DossierRepository($config_db_dsp,$logger,$site);
$document_repo = new DocumentRepository($config_db_dsp,$logger,$site);
$patient_repo = new PatientRepository($config_db_dsp,$logger);
$excel_friendly = true;
$csv_options = new CSVOption($excel_friendly);
$csv_writer = new CSVWriter($csv_options,$logger);
$mc_extracter = new MCExtractManager(MCExtractManager::SRC_MIDDLECARE,$site,$mc_repo,$dossier_repo,$document_repo,$patient_repo, $csv_writer, $logger);

if(isset($options['dict'])){
    // ----- DSP 
    if(!isset($options['dsp'])){
        $all_dsp = $mc_repo->getAllDSP();
        foreach ($all_dsp as $key => $dsp_row)
            $dossier_repo->upsertDossier(Dossier::createFromMCData($dsp_row));
        $all_dossiers = $dossier_repo->findAllDossier();
    }
    // ----- DSP Items / Page
    else{
        $dsp_id = $options['dsp'];
        // items
        $all_dsp_item = $mc_repo->getDSPItems($dsp_id);
        foreach ($all_dsp_item as $key => $dsp_item_row)
            $dossier_repo->upsertItem(Item::createFromMCData($dsp_item_row));
        $all_items = $dossier_repo->findItemByDossierId($dsp_id);
        // pages
        $all_dsp_page = $mc_repo->getDSPPages($dsp_id);
        foreach ($all_dsp_page as $key => $dsp_page_row)
            $dossier_repo->upsertPage(Page::createFromMCData($dsp_id,$dsp_page_row));
    }
}else{
    // ----- Document / Item Value / Patient 
    if(isset($options['dsp']) && isset($options['deb']) && isset($options['fin'])){
        $dsp_id = $options['dsp'];
        $date_debut = new DateTime($options['deb']);
        $date_fin = new DateTime($options['fin']);
        $item_names = isset($options["items"]) ? explode(" ",$options["items"]) : null;
        $mc_extracter->export_mc_dsp_data_to_db($dsp_id, $date_debut, $date_fin,$item_names);
    }else{
        $logger->AddInfo("Parametres inconnus");
    }
}
$logger->addInfo("-------- Finished after ".$now->diff(new DateTime())->format('%H:%I:%S'));