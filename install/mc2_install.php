#!/usr/bin/php
<?php
/** 
 * ================================================================================================================
 * Helper CLI for local database table creation, documentation generation etc
 * @author jvigneron
 * ================================================================================================================
 */
require_once __DIR__.'/../vendor/autoload.php';
use SBIM\Core\Helper\DateHelper;
use SBIM\Core\Helper\ReflectionHelper;
use SBIM\Core\Log\LoggerFactory;
use SBIM\MiddleCare\MCRepository;
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
    - Verifier la configuration des connexions vers les bases MiddleCare, mc2 et RedCap :\n
    > php mc2_install.php --check\n
    - Créer les tables de la base mc2 si elles n'existent pas déja (cf. config_db_mc2.yml pour les noms des tables)\n
    > php mc2_install.php --install\n
    - Génerer fichier markdown avec diagramme des tables de la base mc2 :\n
    > php mc2_install.php --yuml\n";
    exit(1);
}

$longopts  = array("check","install","yuml","site:");
$options = getopt("", $longopts);
$now = new DateTime();

$logger = LoggerFactory::create_logger("install", __DIR__.'/../log');
$config_db_middlecare = Yaml::parse(file_get_contents(__DIR__."/../config/config_db_middlecare.yml"));
$config_db_dsp = Yaml::parse(file_get_contents(__DIR__."/../config/config_db_mc2.yml"));
$site = isset($options["site"]) ? $options["site"] : 'sls';

$mc_repo = new MCRepository($config_db_middlecare[$site],$logger,$site);
$dossier_repo = new DossierRepository($config_db_dsp,$logger,$site);
$document_repo = new DocumentRepository($config_db_dsp,$logger,$site);
$patient_repo = new PatientRepository($config_db_dsp,$logger);

switch(true){
    case isset($options['check']) : 
        // check connexions DB MiddleCare + Repositories (DB MySQL)
        $logger->AddInfo("connection MiddleCare : ". ($mc_repo->checkConnection() ? "successfull" : "failed"));
        $logger->AddInfo("connection DossierRepository  : ". ($dossier_repo->checkConnection() ? "successfull" : "failed"));
        $logger->AddInfo("connection DocumentRepository : ". ($document_repo->checkConnection() ? "successfull" : "failed"));
        $logger->AddInfo("connection PatientRepository  : ". ($patient_repo->checkConnection() ? "successfull" : "failed"));
        break;
    case isset($options['install']) : 
        $logger->AddInfo("creating MySQL tables");
        $logger->AddInfo("creation table ".$patient_repo->getCreateTablePatientQuery()." : ". ($patient_repo->createTablePatient() ? "successful" : "failed"));
        $logger->AddInfo("creation table ".$document_repo->getCreateTableItemValueQuery()." : ". ($document_repo->createTableItemValue() ? "successful" : "failed"));
        $logger->AddInfo("creation table ".$document_repo->getCreateTableDocumentQuery()." : ". ($document_repo->createTableDocument() ? "successful" : "failed"));
        $logger->AddInfo("creation table ".$dossier_repo->getCreateTableItemQuery()." : ". ($dossier_repo->createTableItem() ? "successful" : "failed"));
        $logger->AddInfo("creation table ".$dossier_repo->getCreateTableDossierQuery()." : ". ($dossier_repo->createTableDossier() ? "successful" : "failed"));
        break;
    case isset($options['yuml']) : 
        $logger->AddInfo("generating MySQL schema diagram");

        // get current MySQL DB schema
        $schema = '';
        $schema .= $patient_repo->getCreateTablePatientQuery()."\n";
        $schema .= $document_repo->getCreateTableItemValueQuery()."\n";
        $schema .= $document_repo->getCreateTableDocumentQuery()."\n";
        $schema .= $dossier_repo->getCreateTableItemQuery()."\n";
        $schema .= $dossier_repo->getCreateTableDossierQuery()."\n";

        // generate the yuml diagram definition
        $yuml_definition = ReflectionHelper::generateYumlClassDiagramDefinitionFromSQLSchema($schema);
        $yuml_url = "http://yuml.me/diagram/plain/class/{$yuml_definition}";

        // save image (not allowed from APHP server)
        //$img = 'schema_mysql_'.$now->format(DateHelper::SHORT_MYSQL_FORMAT).".png";
        //file_put_contents(__DIR__."/../docs/schemas/{$img}", file_get_contents($yuml_url));

        // save in md in docs/schemas/schema_mysql_[YYYY-MM-DD].md.html
        $file_name = 'schema_mysql_'.$now->format(DateHelper::SHORT_MYSQL_FORMAT).".md.html";
        $md = "<meta charset='utf-8'>
            **MySQL Schema**
            <a href='http://yuml.me/diagram/plain/class/{$yuml_definition}'><img class='' src='http://yuml.me/diagram/plain/class/{$yuml_definition}'/></a>
            <!-- Markdeep: --><style class='fallback'>body{visibility:hidden;white-space:pre;font-family:monospace}</style><script src='markdeep.min.js'></script><script src='https://casual-effects.com/markdeep/latest/markdeep.min.js'></script><script>window.alreadyProcessedMarkdeep||(document.body.style.visibility='visible')</script>";
        file_put_contents(__DIR__."/../docs/schemas/{$file_name}",$md);
        break;
    default :
        $logger->addInfo("Parametres inconnus");
}

$logger->addInfo("-------- Finished after ".$now->diff(new DateTime())->format('%H:%I:%S'));