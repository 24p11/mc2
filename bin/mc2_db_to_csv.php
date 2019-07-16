#!/usr/bin/php
<?php
/** 
 * ================================================================================================================
 * Extract data from local database to spreadsheets files (CSV)
 * @author jvigneron
 * ================================================================================================================
 * HISTORY
 * > php .\mc2_db_to_csv.php --site sls --dsp DSP22 --deb 20180101 --fin 20190715 --type_doc 'CRH hémato-oncologie CART' --excel --period P2Y
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
use SBIM\DSP\DocumentRepository;
use SBIM\DSP\Document;
use SBIM\DSP\ItemValue;
use SBIM\DSP\PatientRepository;
use SBIM\DSP\Patient;
use SBIM\RedCap\RCInstrument;
use Symfony\Component\Yaml\Yaml;

date_default_timezone_set('Europe/Paris');

if ($argc < 2) {
    echo "Usage : \n
    - Extraire la liste des DSP depuis la base locale vers un fichier CSV :\n
    > php mc2_db_to_csv.php --site [sls|lrb] --dict\n
    - Extraire le dictionnaire d'un DSP donné depuis la base locale vers un fichier CSV :\n
    > php mc2_db_to_csv.php --site [sls|lrb] --dict --dsp [id dsp]\n
    - Extraire le dictionnaire d'un DSP avec filtrage des variables/items depuis la base locale vers un fichier CSV :\n
    > php mc2_db_to_csv.php --site [sls|lrb] --dict --dsp [id dsp] --items 'VAR1290 VAR1312 VAR1333 VAR1358 VAR1370 VAR1418 VAR1419 VAR1426 VAR1481 VAR1496 VAR1497 VAR1498 VAR1501 VAR1504 VAR1505 VAR1508 VAR1509 VAR1515 VAR1516 VAR1525 VAR1711 VAR1780 VAR1809 VAR1817 VAR1940 VAR1941 VAR1989 VAR1990 VAR1992 VAR2124 VAR2128 VAR2140'\n
    - Extraire les données d'un DSP pour une période donnée depuis la base locale vers un ou plusieurs fichier(s) CSV, avec filtrage sur le type de document:\n
    > php mc2_db_to_csv.php --site [sls|lrb] --dsp DSP2 --deb [YYYYMMDD] --fin [YYYYMMDD] --type_doc 'Cr HDJ CMS' \n\n";
    exit(1);
}

$longopts  = array("dict", "dsp:", "deb:", "fin:", "items:", "page:", "excel","type_doc:","site:","period:");
$options = getopt("", $longopts);

$now = new DateTime();

$logger = LoggerFactory::create_logger("mc2_db_to_csv", __DIR__.'/../log');
$config_db_middlecare = Yaml::parse(file_get_contents(__DIR__."/../config/config_middlecare.yml"));
$config_db_dsp = Yaml::parse(file_get_contents(__DIR__."/../config/config_db_mc2.yml"));
$site = isset($options["site"]) ? $options["site"] : 'sls';
$period = isset($options["period"]) ? $options["period"] : 'P1M';//1mois = P1M, 2 mois = P2M, 60 jours =  P60D

$mc_repo = new MCRepository($config_db_middlecare[$site],$logger,$site);
$dossier_repo = new DossierRepository($config_db_dsp,$logger,$site);
$document_repo = new DocumentRepository($config_db_dsp,$logger,$site);
$patient_repo = new PatientRepository($config_db_dsp,$logger);
$excel_friendly = isset($options['excel']);
$csv_options = new CSVOption($excel_friendly);
$csv_writer = new CSVWriter($csv_options,$logger);
$mc_extracter = new MCExtractManager(MCExtractManager::SRC_LOCAL_DB,$site,$mc_repo,$dossier_repo,$document_repo,$patient_repo, $csv_writer, $logger);

// -----------------------------------------------------------------------------------------

if(isset($options['dict'])){
    // ----- DSP 
    if(!isset($options['dsp'])){
        $mc_extracter->export_all_dsp_to_csv();
    }
    // ----- DSP Items
    else{
        $dsp_id = $options['dsp'];
        $item_names = isset($options["items"]) ? explode(" ",$options["items"]) : null;
        $mc_extracter->export_dsp_items_to_csv($dsp_id,$item_names);
    }
}else{
    // ----- Document / Item Value / Patient 
    if(isset($options['dsp']) && isset($options['deb']) && isset($options['fin'])){
        $dsp_id = $options['dsp'];
        $date_debut = new DateTime($options['deb']);
        $date_fin = new DateTime($options['fin']);
        $item_names = isset($options["items"]) ? explode(" ",$options["items"]) : null;
        $page_name = isset($options["page"]) ? $options["page"] : null;
        $type_doc = isset($options["type_doc"]) ? $options["type_doc"] : null;
        $mc_extracter->export_dsp_data_to_csv($dsp_id, $date_debut, $date_fin,$item_names,$page_name,$type_doc,$period);
    }else{
        $logger->AddInfo("Parametres inconnus");
    }
}
$logger->addInfo("-------- Finished after ".$now->diff(new DateTime())->format('%H:%I:%S'));