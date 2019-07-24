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
    mc2_db_to_csv.php - Extraction depuis DB locale (mc2) vers fichier(s) CSV

    SYNOPSIS
    php mc2_db_to_csv.php [--dict] --site <sls|lrb> --dsp <dsp_id> --deb <date_debut> --fin <date_fin> [--items <items>] [--type_doc <doc_type>]

    DESCRIPTION
    Extraction des données de MiddleCare (cf. config_db_middlecare.yml) vers base de données locale mc2 (cf. config_db_mc2.yml)
    
    OPTIONS
    - dict (optionnal) : ne récupérer que les dictionnaires (et non les données)
    - site : sls | lrb
    - dsp : identifiant du DSP (ex: DSP2)
    - deb (optionnal) : date de début au format YYYYMMDD
    - fin (optionnal) : date de fin au format YYYYMMDD
    - items (optionnal) : liste des items à récupérer ex : 'VAR1 VAR2 DEB_HOSP'
    - type_doc (optionnal) : type de document à récupérer ex : 'Cr HDJ CMS'
    - period (optionnal) : période des fichiers CSV ex: 1 fichier CSV par mois = P1M, 1 fichier CSV pour 2 ans : P2Y etc
    - excel (optionnal): CSV excel friendly (BOM,UTF8 etc)
    
    EXAMPLES
    - Extraire la liste des DSP depuis la base locale vers un fichier CSV :
    > php mc2_db_to_csv.php --dict --site lrb 
    
    - Extraire le dictionnaire d'un DSP donné depuis la base locale vers un fichier CSV :
    > php mc2_db_to_csv.php --dict --site sls --dsp DSP2
    
    - Extraire le dictionnaire d'un DSP avec filtrage des variables/items depuis la base locale vers un fichier CSV :
    > php mc2_db_to_csv.php --dict --site sls --dsp DSP2 --items 'VAR1 VAR2 DEB_HOSP'
    
    - Extraire les données d'un DSP pour une période donnée depuis la base locale vers un ou plusieurs fichier(s) CSV, avec filtrage sur le type de document:
    > php mc2_db_to_csv.php --site sls --dsp DSP2 --deb 20180101 --fin 20190101 --type_doc 'Cr HDJ CMS' --period P1M
    ";
    exit(1);
}

$longopts  = array("dict","dsp:","deb:","fin:","items:","page:","excel","type_doc:","site:","period:");
$options = getopt("", $longopts);

$now = new DateTime();

$logger = LoggerFactory::create_logger("mc2_db_to_csv", __DIR__.'/../log');
$config_db_middlecare = Yaml::parse(file_get_contents(__DIR__."/../config/config_db_middlecare.yml"));
$config_db_dsp = Yaml::parse(file_get_contents(__DIR__."/../config/config_db_mc2.yml"));
$site = isset($options['site']) ? $options['site'] : 'sls';
$period = isset($options['period']) ? $options['period'] : 'P1M';//1mois = P1M, 2 mois = P2M, 60 jours =  P60D

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