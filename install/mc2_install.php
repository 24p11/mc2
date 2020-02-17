#!/usr/bin/php
<?php
/** 
 * ================================================================================================================
 * Helper CLI for local database table creation, documentation generation etc
 * @author jvigneron
 * ================================================================================================================
 */
require_once __DIR__.'/../vendor/autoload.php';
use MC2\Core\Helper\DateHelper;
use MC2\Core\Helper\ReflectionHelper;
use MC2\Core\Log\LoggerFactory;
use MC2\MiddleCare\MCRepository;
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
    echo "Usage : \n
    - Verifier la configuration des connexions vers les bases MiddleCare, mc2 et RedCap :\n
    > php mc2_install.php --check\n
    - Créer les tables de la base mc2 si elles n'existent pas déja (cf. mc2.yaml pour les noms des tables)\n
    > php mc2_install.php --install\n
    - Génerer fichier markdown avec diagramme des tables de la base mc2 :\n
    > php mc2_install.php --yuml\n";
    exit(1);
}

$longopts  = array("check","install","yuml","site:");
$options = getopt("", $longopts);
$now = new DateTime();

$configuration = Yaml::parse(file_get_contents(__DIR__."/../config/mc2.yaml"));
$logger = LoggerFactory::create_logger("install", __DIR__.'/../log');
$site = isset($options["site"]) ? $options["site"] : 'sls';
$base_url = $configuration['middlecare'][$site]['doc_base_url'];

$mc_repo = new MCRepository($configuration,$logger);
$mc_repo->connect($site);
$dossier_repo = new DossierRepository($configuration,$logger);
$dossier_repo->setSite($site);
$document_repo = new DocumentRepository($configuration,$logger);
$patient_repo = new PatientRepository($configuration,$logger);

switch(true){
    case isset($options['check']) : 
        // check connexions DB MiddleCare + Repositories (DB MySQL)
        $logger->info("connection MiddleCare : ". ($mc_repo->checkConnection() ? "successfull" : "failed"));
        $logger->info("connection DossierRepository  : ". ($dossier_repo->checkConnection() ? "successfull" : "failed"));
        $logger->info("connection DocumentRepository : ". ($document_repo->checkConnection() ? "successfull" : "failed"));
        $logger->info("connection PatientRepository  : ". ($patient_repo->checkConnection() ? "successfull" : "failed"));
        break;
    case isset($options['install']) : 
        $logger->info("creating MySQL tables");
        $logger->info("creation table ".$patient_repo->getCreateTablePatientQuery()." : ". ($patient_repo->createTablePatient() ? "successful" : "failed"));
        $logger->info("creation table ".$document_repo->getCreateTableItemValueQuery()." : ". ($document_repo->createTableItemValue() ? "successful" : "failed"));
        $logger->info("creation table ".$document_repo->getCreateTableDocumentQuery()." : ". ($document_repo->createTableDocument() ? "successful" : "failed"));
        $logger->info("creation table ".$dossier_repo->getCreateTableItemQuery()." : ". ($dossier_repo->createTableItem() ? "successful" : "failed"));
        $logger->info("creation table ".$dossier_repo->getCreateTablePageQuery()." : ". ($dossier_repo->createTablePage() ? "successful" : "failed"));
        $logger->info("creation table ".$dossier_repo->getCreateTableDossierQuery()." : ". ($dossier_repo->createTableDossier() ? "successful" : "failed"));
        break;
    case isset($options['yuml']) : 
        $logger->info("generating MySQL schema diagram");

        // get current MySQL DB schema
        $schema = '';
        $schema .= $patient_repo->getCreateTablePatientQuery()."\n";
        $schema .= $document_repo->getCreateTableItemValueQuery()."\n";
        $schema .= $document_repo->getCreateTableDocumentQuery()."\n";
        $schema .= $dossier_repo->getCreateTableItemQuery()."\n";
        $schema .= $dossier_repo->getCreateTablePageQuery()."\n";
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
        $logger->info("Parametres inconnus");
}

$logger->info("-------- Finished after ".$now->diff(new DateTime())->format('%H:%I:%S'));