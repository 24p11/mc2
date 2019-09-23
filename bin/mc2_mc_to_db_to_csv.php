#!/usr/bin/php
<?php
/** 
 * ================================================================================================================
 * Extract data from local database to spreadsheets files (CSV)
 * @author jvigneron
 * ================================================================================================================
 * HISTORY
 * > php .\mc2_db_to_csv.php --site sls --dsp DSP22 --deb 20180101 --fin 20190715 --type_doc 'CRH hémato-oncologie CART' --excel --period P2Y
 * > php .\mc2_db_to_csv.php --site sls --dsp DSP96 --deb 20180101 --fin 20190715 --excel --period P2Y
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
    echo "
    NAME 
    mc2_mc_to_db_to_csv.php - Extraction depuis MiddleCare vers DB locale (mc2) puis vers fichier(s) CSV

    SYNOPSIS
    php mc2_mc_to_db_to_csv.php --site <sls|lrb> --dsp <dsp_id> --deb <date_debut> --fin <date_fin> 

    DESCRIPTION
    Extraction des données de MiddleCare (cf. config_db_middlecare.yml) vers base de données locale mc2 (cf. config_db_mc2.yml) puis vers fichier(s) CSV
    
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

$logger = LoggerFactory::create_logger("mc2_mc_to_db_to_csv", __DIR__.'/../log');
$config_db_middlecare = Yaml::parse(file_get_contents(__DIR__."/../config/config_db_middlecare.yml"));
$config_db_dsp = Yaml::parse(file_get_contents(__DIR__."/../config/config_db_mc2.yml"));
$site = isset($options['site']) ? $options['site'] : 'sls';
$period = isset($options['period']) ? $options['period'] : 'P1M';

$mc_repo = new MCRepository($config_db_middlecare[$site],$logger,$site);
$dossier_repo = new DossierRepository($config_db_dsp,$logger,$site);
$document_repo = new DocumentRepository($config_db_dsp,$logger,$site);
$patient_repo = new PatientRepository($config_db_dsp,$logger);
$excel_friendly = isset($options['excel']);
$nohtml = isset($options['nohtml']);
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
    $mc_extracter->import_dsp_dictionnary($dsp_id);
    $mc_extracter->export_dsp_dictionnary_to_csv($dsp_id,$item_names);
    
    // ---- DSP Data
    $date_debut = new DateTime($options['deb']);
    $date_fin = new DateTime($options['fin']);
    $mc_extracter->import_dsp_data($dsp_id,$date_debut,$date_fin,$item_names);
    $mc_extracter->export_dsp_data_to_csv($dsp_id, $date_debut, $date_fin,$item_names,$page_name,$type_doc,$period);

    // TODO DELETE DSP DATA ?    
}else{
    $logger->addInfo("Unknown parameters",array('options' => $options));
}
$logger->addInfo("Ended after ".$now->diff(new DateTime())->format('%H:%I:%S'));