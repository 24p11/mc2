#!/usr/bin/php
<?php
/** 
 * ================================================================================================================
 * Sends data from mc2 database to RedCap API (via RedCap CSV format)
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
use MC2\RedCap\RCService;
use MC2\RedCap\RCProject;
use Symfony\Component\Yaml\Yaml;

date_default_timezone_set('Europe/Paris');

if ($argc < 2) {
    echo "
    NAME 
    mc2_db_to_rc.php - Extraction depuis DB locale (mc2) vers projet RedCAP

    SYNOPSIS
    php mc2_db_to_rc.php [--dict] --site <sls|lrb> --dsp <dsp_id> --deb <date_debut> --fin <date_fin> [--items <items>] [--long] [--inst <custom_intrument_name>] [--inst_only] [--bydoctype] [--noapicall]

    DESCRIPTION
    Extraction de la base de données locale mc2 (cf. mc2.yaml) vers un projet RedCap via l'API RedCap
    
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
    - nohtml : supprimer les balises HTML dans les valeurs des items

    EXAMPLES
    - Extraire depuis la base locale le data dictionnary RedCap d'un DSP donné pour un projet longitudinal :
    > php mc2_db_to_rc.php --dict --site sls --dsp DSP2 --long

    - Extraire depuis la base locale le data dictionnary RedCap d'un DSP donné pour un projet longitudinal, avec filtrage des variables/items :
    > php mc2_db_to_rc.php --dict --site sls --dsp DSP96 --long --inst 'Document CarT' --inst_only --items 'DEB_HOSP FIN_HOSP UH VAR1 VAR2'
    
    - Extraire les données d'un DSP pour une période donnée depuis la base locale vers fichier(s) CSV RedCap et les envoyer vers l'API RedCap(cf. mc2.yaml):
    > php mc2_db_to_rc.php --site sls --dsp DSP2 --deb 20180101 --fin 20190101 --long --inst_only --inst 'Indicateurs Séno' --items 'DEB_HOSP FIN_HOSP UH VAR1 VAR2 VAR83 VAR84 ...'
    ";
    exit(1);
}

$longopts  = array("dict", "dsp:", "deb:", "fin:", "items:", "page:", "excel", "inst:","inst_only","long","noapicall","site:","bydoctype","nohtml");
$options = getopt("", $longopts);

$now = new DateTime();

$config_mc2 = Yaml::parse(file_get_contents(__DIR__."/../config/mc2.yaml"));
$logger = LoggerFactory::create_logger("mc2_db_to_rc", __DIR__.'/../log');
$input_folder = __DIR__."/../data";
$site = isset($options["site"]) ? $options["site"] : 'sls';
$excel_friendly = isset($options['excel']);
$nohtml = isset($options['nohtml']);
$base_url = $config_mc2['middlecare'][$site]['doc_base_url'];

$mc_repo = null;
$dossier_repo = new DossierRepository($config_mc2,$logger,$site);
$document_repo = new DocumentRepository($config_mc2,$logger,$site,$base_url);
$patient_repo = new PatientRepository($config_mc2,$logger);
$csv_options = new CSVOption($excel_friendly);
$csv_writer = new CSVWriter($csv_options,$logger);
$mc_extracter = new MCExtractManager(MCExtractManager::SRC_LOCAL_DB,$site,$mc_repo,$dossier_repo,$document_repo,$patient_repo, $csv_writer, $logger);
$rc_service = new RCService($input_folder,$config_mc2['redcap']['api_url'],$config_mc2['redcap']['api_token'],$logger);

// ----- RC Data Dictionnary
if(isset($options['dict']) && isset($options['dsp'])){
    $dsp_id = $options['dsp'];
    $item_names = isset($options["items"]) ? explode(" ",$options["items"]) : null;
    $main_instrument_name = isset($options["inst"]) ? $options["inst"] :'Document';
    
    $main_instrument = new RCInstrument($main_instrument_name, $item_names);
    $rc_project = new RCProject('RC_'.$dsp_id,$main_instrument);
    $rc_project->main_instrument_only = isset($options["inst_only"]);
    $rc_project->arm_name = $config_mc2['redcap']['arm_name'];
    $rc_project->shared_event_name = $config_mc2['redcap']['shared_event_name'];
    $rc_project->repeatable_event_name = $config_mc2['redcap']['repeatable_event_name'];
    $rc_project->longitudinal = isset($options["long"]);
    $rc_project->event_as_document_type = isset($options["bydoctype"]);

    $file_name = "RC_dictionnary";
    $file_name .= $rc_project->main_instrument_only === true ? "_indic" : "";
    $file_name .= $rc_project->longitudinal === true ? "" : "_flat";
    $file_name .= "_".strtoupper($site)."_{$dsp_id}";

    $mc_extracter->exportDSPDictionnaryToRedcapCSV($file_name,$dsp_id,$rc_project);
    
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
        $rc_project->arm_name = $config_mc2['redcap']['arm_name'];
        $rc_project->shared_event_name = $config_mc2['redcap']['shared_event_name'];
        $rc_project->repeatable_event_name = $config_mc2['redcap']['repeatable_event_name'];
        $rc_project->longitudinal = isset($options["long"]);
        $rc_project->event_as_document_type = isset($options["bydoctype"]);

        $file_name = "RC_DATA";
        $file_name .= $rc_project->main_instrument_only === true ? "_indic": "";
        $file_name .= $rc_project->longitudinal === true ? "": "_flat";
        $file_name .= "_".strtoupper($site)."_{$dsp_id}";
        
        $file_names = $mc_extracter->exportDSPDataToRedcapCSV($file_name,$dsp_id,$date_debut,$date_fin,$rc_project);

        // ---- RC Data -> RC API 
        $no_api_call = isset($options["noapicall"]);
        if($no_api_call === false){
            $overwrite = true;
            foreach($file_names as $file){
                $rc_service->import_data_file($file, $overwrite);
            }
        }
    }else{
        $logger->info("Unknown parameters",array('options' => $options));
    }
}
$logger->info("Ended after ".$now->diff(new DateTime())->format('%H:%I:%S'));