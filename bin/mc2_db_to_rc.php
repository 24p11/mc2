#!/usr/bin/php
<?php
/** 
 * ================================================================================================================
 * Sends data from local database to RedCap API (via RedCap CSV format)
 * @author jvigneron
 * ================================================================================================================
 * -------- HISTORY
 * ---- Hemato Onco / CarTCells
 * - CRH Hemato Onco : DSP22	HEMATO-ONCOLOGIE	Service d''hématologie-oncologie C6
 * > php mc2_db_to_rc.php --dict --site sls --dsp DSP22
 * > php mc2_db_to_rc.php --site sls --dsp DSP22 --deb 20190101 --fin 20190201
 * - Suivi des Dossiers Car : T DSP96	CAR-T	Dossier suivi patients CAR-T
 * > php mc2_db_to_rc.php --dict --site sls --dsp DSP96 --long --excel
 * > php mc2_db_to_rc.php --dict --site sls --dsp DSP96 --long --inst "Indicateur CartT DSP96" --inst_only --items "DEB_HOSP FIN_HOSP UH VAR1 VAR2 VAR83 VAR84 VAR8 VAR3 VAR4 VAR5 VAR6 VAR7 VAR9 VAR10 VAR11 VAR73 VAR74 VAR78 VAR77 VAR12 VAR81 VAR13 VAR14 VAR15 VAR82 VAR79 VAR80 VAR69 VAR75 VAR72 VAR76" --excel
 * > php mc2_db_to_rc.php --site sls --dsp DSP96 --deb 20190401 --fin 20190501 --excel
 * > php mc2_db_to_rc.php --site sls --dsp DSP96 --deb 20190401 --fin 20190501 --long --inst_only --inst "Indicateur CartT DSP96" --items "DEB_HOSP FIN_HOSP UH VAR1 VAR2 VAR83 VAR84 VAR8 VAR3 VAR4 VAR5 VAR6 VAR7 VAR9 VAR10 VAR11 VAR73 VAR74 VAR78 VAR77 VAR12 VAR81 VAR13 VAR14 VAR15 VAR82 VAR79 VAR80 VAR69 VAR75 VAR72 VAR76" --excel
 * CHAPITRE 5 "VAR1 VAR2 VAR83 VAR84 VAR8 VAR3 VAR4 VAR5 VAR6 VAR7 VAR9 VAR10 VAR11 VAR73 VAR74 VAR78 VAR77 VAR12 VAR81 VAR13 VAR14 VAR15 VAR82 VAR79 VAR80 VAR69 VAR75 VAR72 VAR76"
 * ---- Rea Med Toxico LRB
 * > php mc2_db_to_rc.php --dict --site lrb --dsp DSP127 --long --inst_only --items "DEB_HOSP FIN_HOSP UH VAR1 VAR10 VAR11 VAR12 VAR13 VAR14 VAR15 VAR16 VAR17 VAR18 VAR19 VAR2 VAR20 VAR21 VAR23 VAR24 VAR25 VAR26 VAR27 VAR28 VAR29 VAR3 VAR30 VAR32 VAR34 VAR35 VAR36 VAR37 VAR38 VAR39 VAR4 VAR40 VAR41 VAR42 VAR43 VAR44 VAR45 VAR46 VAR47 VAR48 VAR49 VAR5 VAR50 VAR51 VAR52 VAR53 VAR54 VAR55 VAR56 VAR57 VAR58 VAR59 VAR6 VAR60 VAR61 VAR62 VAR63 VAR64 VAR65 VAR66 VAR67 VAR68 VAR69 VAR7 VAR70 VAR71 VAR72 VAR73 VAR74 VAR75 VAR76 VAR77 VAR79 VAR8 VAR80 VAR81 VAR82 VAR9"
 * > php mc2_db_to_rc.php --site lrb --dsp DSP127 --deb 20170101 --fin 20190601 --long --inst_only --items "DEB_HOSP FIN_HOSP UH VAR1 VAR10 VAR11 VAR12 VAR13 VAR14 VAR15 VAR16 VAR17 VAR18 VAR19 VAR2 VAR20 VAR21 VAR23 VAR24 VAR25 VAR26 VAR27 VAR28 VAR29 VAR3 VAR30 VAR32 VAR34 VAR35 VAR36 VAR37 VAR38 VAR39 VAR4 VAR40 VAR41 VAR42 VAR43 VAR44 VAR45 VAR46 VAR47 VAR48 VAR49 VAR5 VAR50 VAR51 VAR52 VAR53 VAR54 VAR55 VAR56 VAR57 VAR58 VAR59 VAR6 VAR60 VAR61 VAR62 VAR63 VAR64 VAR65 VAR66 VAR67 VAR68 VAR69 VAR7 VAR70 VAR71 VAR72 VAR73 VAR74 VAR75 VAR76 VAR77 VAR79 VAR8 VAR80 VAR81 VAR82 VAR9"
 * test Event as DocType
 * > php mc2_mc_to_db.php --site sls --dict --dsp DSP2
 * > php mc2_db_to_rc.php --dict --site sls --dsp DSP2 --long --bydoctype
 * > php mc2_db_to_rc.php --site sls --dsp DSP2 ---deb 20190101 --fin 20190105 --long --noapicall --excel
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
use SBIM\DSP\DocumentRepository;
use SBIM\DSP\Document;
use SBIM\DSP\ItemValue;
use SBIM\DSP\PatientRepository;
use SBIM\DSP\Patient;
use SBIM\RedCap\RCInstrument;
use SBIM\RedCap\RCService;
use SBIM\RedCap\RCProject;
use Symfony\Component\Yaml\Yaml;

date_default_timezone_set('Europe/Paris');

if ($argc < 2) {
    echo "Usage : \n
   
    NAME 
    mc2_db_to_rc.php - Extraction depuis DB locale (mc2) vers projet RedCAP

    SYNOPSIS
    php mc2_db_to_rc.php [--dict] --site <sls|lrb> --dsp <dsp_id> --deb <date_debut> --fin <date_fin> [--items <items>] [--long] [--inst <custom_intrument_name>] [--inst_only] [--bydoctype] [--noapicall]

    DESCRIPTION
    Extraction de la base de données locale mc2 (cf. config_db_mc2.yml) vers un projet RedCap via l'API RedCap
    
    OPTIONS
    - dict (optionnal) : ne récupérer que les dictionnaires (et non les données)
    - site : sls | lrb
    - dsp : identifiant du DSP (ex: DSP2)
    - deb (optionnal) : date de début au format YYYYMMDD
    - fin (optionnal) : date de fin au format YYYYMMDD
    - items (optionnal) : liste des items à récupérer ex : 'VAR1290 VAR1312 VAR1333'
    - long (optionnal) : a destination d'un projet redcap longitudinal
    - inst (optionnal) : nom de l'instrument custom ex: 'Indicateurs Sénologie'
    - inst_only (optionnal) : ne prendre que les données partagé et l'instrument custom (ne pas prendre tous les autres items)
    - bydoctype (optionnal) : un instrument par type de document
    - noapicall (optionnal) : n'appelle pas l'API après avoir généré les fichiers CSV

    EXAMPLES
    - Extraire depuis la base locale le data dictionnary RedCap d'un DSP donné pour un projet longitudinal :
    > php mc2_db_to_rc.php --dict --site sls --dsp DSP2 --long

    - Extraire depuis la base locale le data dictionnary RedCap d'un DSP donné pour un projet longitudinal, avec filtrage des variables/items :
    > php mc2_db_to_rc.php --dict --site sls --dsp DSP96 --long --inst 'Document CarT' --inst_only --items 'DEB_HOSP FIN_HOSP UH VAR1 VAR2'
    
    - Extraire les données d'un DSP pour une période donnée depuis la base locale vers fichier(s) CSV RedCap et les envoyer vers l'API RedCap(cf. config_redcap.yml):
    > php mc2_db_to_rc.php --site sls --dsp DSP2 --deb 20180101 --fin 20190101 --long --inst_only --inst 'Indicateurs Séno' --items 'DEB_HOSP FIN_HOSP UH VAR1 VAR2 VAR83 VAR84 ...'
    ";
    exit(1);
}

$longopts  = array("dict", "dsp:", "deb:", "fin:", "items:", "page:", "excel", "inst:","inst_only","long","noapicall","site:","bydoctype");
$options = getopt("", $longopts);

$now = new DateTime();

$logger = LoggerFactory::create_logger("mc2_db_to_csv", __DIR__.'/../log');
$config_db_middlecare = Yaml::parse(file_get_contents(__DIR__."/../config/config_db_middlecare.yml"));
$config_db_dsp = Yaml::parse(file_get_contents(__DIR__."/../config/config_db_mc2.yml"));
$config_redcap = Yaml::parse(file_get_contents(__DIR__."/../config/config_redcap.yml"));
$input_folder = __DIR__."/../data";
$site = isset($options["site"]) ? $options["site"] : 'sls';

$mc_repo = new MCRepository($config_db_middlecare[$site],$logger,$site);
$dossier_repo = new DossierRepository($config_db_dsp,$logger,$site);
$document_repo = new DocumentRepository($config_db_dsp,$logger,$site);
$patient_repo = new PatientRepository($config_db_dsp,$logger);
$excel_friendly = isset($options['excel']);
$csv_options = new CSVOption($excel_friendly);
$csv_writer = new CSVWriter($csv_options,$logger);
$mc_extracter = new MCExtractManager(MCExtractManager::SRC_LOCAL_DB,$site,$mc_repo,$dossier_repo,$document_repo,$patient_repo, $csv_writer, $logger);
$rc_service = new RCService($input_folder,$config_redcap['redcap']['api_url'],$config_redcap['redcap']['api_token'],$logger);

// ----- RC Data Dictionnary
if(isset($options['dict']) && isset($options['dsp'])){
    $dsp_id = $options['dsp'];
    $item_names = isset($options["items"]) ? explode(" ",$options["items"]) : null;
    $main_instrument_name = isset($options["inst"]) ? $options["inst"] :'Document';
    
    $main_instrument = new RCInstrument($main_instrument_name, $item_names);
    $rc_project = new RCProject('RC_'.$dsp_id,$main_instrument);
    $rc_project->main_instrument_only = isset($options["inst_only"]);
    $rc_project->arm_name = $config_redcap['redcap']['arm_name'];
    $rc_project->shared_event_name = $config_redcap['redcap']['shared_event_name'];
    $rc_project->repeatable_event_name = $config_redcap['redcap']['repeatable_event_name'];
    $rc_project->longitudinal = isset($options["long"]);
    $rc_project->event_as_document_type = isset($options["bydoctype"]);

    $file_name = "RC_dictionnary";
    $file_name .= $rc_project->main_instrument_only === true ? "_indic" : "";
    $file_name .= $rc_project->longitudinal === true ? "" : "_flat";
    $file_name .= "_".strtoupper($site)."_{$dsp_id}";

    $mc_extracter->export_redcap_dictionnary($file_name,$dsp_id,$rc_project);
    
}else{
    // ----- RC Data
    if(isset($options['dsp']) && isset($options['deb']) && isset($options['fin'])){
        $dsp_id = $options['dsp'];
        $date_debut = new DateTime($options['deb']);
        $date_fin = new DateTime($options['fin']);
        $item_names = isset($options["items"]) ? explode(" ",$options["items"]) : null;
        $main_instrument_name = isset($options["inst"]) ? $options["inst"] :'Document';
        
        $main_instrument = new RCInstrument($main_instrument_name, $item_names);
        $rc_project = new RCProject('RC_'.$dsp_id,$main_instrument);
        $rc_project->main_instrument_only = isset($options["inst_only"]);
        $rc_project->arm_name = $config_redcap['redcap']['arm_name'];
        $rc_project->shared_event_name = $config_redcap['redcap']['shared_event_name'];
        $rc_project->repeatable_event_name = $config_redcap['redcap']['repeatable_event_name'];
        $rc_project->longitudinal = isset($options["long"]);
        $rc_project->event_as_document_type = isset($options["bydoctype"]);

        $file_name = "RC_DATA";
        $file_name .= $rc_project->main_instrument_only === true ? "_indic": "";
        $file_name .= $rc_project->longitudinal === true ? "": "_flat";
        $file_name .= "_".strtoupper($site)."_{$dsp_id}";
        
        $nips = null; 
        $file_names = $mc_extracter->export_redcap_data_by_patient_from_db($file_name,$dsp_id,$date_debut,$date_fin,$nips,$rc_project);

        // ---- RC Data -> RC API 
        $no_api_call = isset($options["noapicall"]);
        if($no_api_call === false){
            $overwrite = true;
            foreach($file_names as $file){
                $rc_service->import_data_file($file, $overwrite);
            }
        }
    }else{
        $logger->addInfo("Parametres inconnus");
    }
}
$logger->addInfo("-------- Finished after ".$now->diff(new DateTime())->format('%H:%I:%S'));