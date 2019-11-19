<?php
namespace SBIM\DSP;
use \PDO;
use SBIM\Core\Helper\DateHelper;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Connection;
/**
 * Document Repository
 * 
 * Persistence des documents et de leurs données
 * 
 */
class DocumentRepository{

    const DEFAULT_DOCUMENT_TABLE = "mcdsp_document";
    const DOCUMENT_COLUMNS = "nipro,patient_id,dossier_id,site,type,venue,patient_age,patient_poids,patient_taille,date_creation,date_modification,revision,extension,operateur,provisoire,categorie,service,created,modified,version,deleted";// = *

    const DEFAULT_ITEM_VALUE_TABLE = "mcdsp_item_value";
    const ITEM_VALUE_COLUMNS = "nipro,patient_id,dossier_id,site,page_nom,var,val,created,modified,version,deleted";// = *

    private $db = null;
    private $logger;
    private $site = null;

    private $document_table = null;
    private $item_value_table = null;

    public $base_url = '';


    public function get_document_table(){
        return ($this->document_table === null) ? self::DEFAULT_DOCUMENT_TABLE : $this->document_table;
    }

    public function get_item_value_table(){
        return ($this->item_value_table === null) ? self::DEFAULT_ITEM_VALUE_TABLE : $this->item_value_table;
    }

    /**
     * @param string $params DSN DSP (MySQL)
     * @param Monolog\Logger $logger
     */
    public function __construct($params,$logger,$site,$doc_base_url){
        $this->db = DriverManager::getConnection($params['doctrine']['dbal']);
        $this->document_table = $params['tables']['document'];
        $this->item_value_table = $params['tables']['item_value'];
        $this->base_url = $doc_base_url;
        $this->logger = $logger;
        $this->site = $site;
    }

    public function checkConnection(){
        try{
            if ($this->db->connect())
                return true;
        } 
        catch (\Exception $e) {
            $this->logger>addError("Can't connect to DocumentRepository DB", array('exception' => $e));
            return false;
        }
    }

    // -------- Query

    public function findDocument($nipro){
        $query = "SELECT ".self::DOCUMENT_COLUMNS." FROM ".$this->get_document_table()." WHERE nipro = :nipro AND site = :site AND deleted = 0 ORDER BY nipro";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue("nipro", $nipro);
        $stmt->bindValue("site", $this->site);
        $stmt->execute();
        $result = array();
        while($row = $stmt->fetch(PDO::FETCH_ASSOC))
            $result[] = new Document($this->base_url,$row);
        return $result;
    }

    public function findAllDocument(){
        $query = "SELECT ".self::DOCUMENT_COLUMNS." FROM ".$this->get_document_table()." WHERE deleted = 0 AND site = :site ORDER BY nipro";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue("site", $this->site);
        $stmt->execute();
        $result = array();
        while($row = $stmt->fetch(PDO::FETCH_ASSOC))
            $result[] = new Document($this->base_url,$row);
        return $result;
    }

    public function findDocumentByDossierId($dossier_id,$date_debut, $date_fin,array $patient_ids = null){
        $query_patients = ($patient_ids === null || count($patient_ids) < 1) 
            ? "" : "AND patient_id in(".join(',',array_map(function($v){ return "'".$v."'"; },$patient_ids)).")";

        $query = "SELECT ".self::DOCUMENT_COLUMNS." 
            FROM ".$this->get_document_table()." 
            WHERE dossier_id = :dossier_id 
            AND deleted = 0 
            AND date_creation >= :date_debut
            AND date_creation < :date_fin
            AND site = :site
            {$query_patients}
            ORDER BY nipro";
            
        $stmt = $this->db->prepare($query);
        $stmt->bindValue("dossier_id", $dossier_id);
        $stmt->bindValue("date_debut", $date_debut->format(DateHelper::MYSQL_FORMAT));
        $stmt->bindValue("date_fin", $date_fin->format(DateHelper::MYSQL_FORMAT));
        $stmt->bindValue("site", $this->site);
        $stmt->execute();
        $result = array();
        while($row = $stmt->fetch(PDO::FETCH_ASSOC))
            $result[] = new Document($this->base_url,$row);
        return $result;
    }

    public function findDocumentWithItemValues($dossier_id, $date_debut, $date_fin, array $item_names = null, $page_name = null, array $patient_ids = null,$type_doc = null){
        $query_patients = ($patient_ids === null || count($patient_ids) < 1) 
            ? "" : "AND patient_id in(".join(',',array_map(function($v){ return "'".$v."'"; },$patient_ids)).")";
        $query_type_doc = ($type_doc === null || empty($type_doc)) ? "" : "AND type = '".$type_doc."'";
        $query = "SELECT ".self::DOCUMENT_COLUMNS." 
            FROM ".$this->get_document_table()." 
            WHERE dossier_id = :dossier_id 
            AND deleted = 0 
            AND date_creation >= :date_debut
            AND date_creation < :date_fin
            AND site = :site
            {$query_patients}
            {$query_type_doc}
            ORDER BY patient_id, date_creation";

        $stmt = $this->db->prepare($query);
        $stmt->bindValue("dossier_id", $dossier_id);
        $stmt->bindValue("date_debut", $date_debut->format(DateHelper::MYSQL_FORMAT));
        $stmt->bindValue("date_fin", $date_fin->format(DateHelper::MYSQL_FORMAT));
        $stmt->bindValue("site", $this->site);
        $stmt->execute();
        
        // for every documents fetch all item_values
        $documents = array();
        while($row = $stmt->fetch(PDO::FETCH_ASSOC)){
            $document = new Document($this->base_url,$row);
            $document->item_values = $this->findItemValuesByNipro($document->id,$item_names);
            $documents[] = $document;
        }
        return $documents;
    }

    public function findItemValuesByNipro($nipro, array $item_names = null){
        $query_items = ($item_names === null || count($item_names) < 1) 
            ? "" : "AND var in(".join(',',array_map(function($v){ return "'".$v."'"; },$item_names)).")";
        $query = "SELECT ".self::ITEM_VALUE_COLUMNS." 
            FROM ".$this->get_item_value_table()." 
            WHERE nipro = :nipro 
            AND deleted = 0 
            AND site = :site
            {$query_items}";

        $stmt = $this->db->prepare($query);
        $stmt->bindValue("nipro", $nipro);
        $stmt->bindValue("site", $this->site);
        $stmt->execute();

        $result = array();
        while($row = $stmt->fetch(PDO::FETCH_ASSOC))
            $result[] = new ItemValue($row);
        return $result;
    }

    public function findAllPatientId($dossier_id, $date_debut, $date_fin){
        $query = "SELECT DISTINCT patient_id FROM ".$this->get_document_table()." 
            WHERE dossier_id = :dossier_id 
            AND deleted = 0 
            AND date_creation >= :date_debut
            AND date_creation < :date_fin
            AND site = :site";

        $stmt = $this->db->prepare($query);
        $stmt->bindValue("dossier_id", $dossier_id);
        $stmt->bindValue("date_debut", $date_debut->format(DateHelper::MYSQL_FORMAT));
        $stmt->bindValue("date_fin", $date_fin->format(DateHelper::MYSQL_FORMAT));
        $stmt->bindValue("site", $this->site);
        $stmt->execute();
        
        $patient_ids = array();
        while($row = $stmt->fetch(PDO::FETCH_ASSOC))
            $patient_ids[] = $row['patient_id'];
        return $patient_ids;
    }

    // -------- Command

    public function upsertDocument($document){
        $query = "INSERT INTO ".$this->get_document_table()." (".self::DOCUMENT_COLUMNS.") 
            VALUES(:document_id, :patient_id, :dossier_id, :site, :type, :venue, :patient_age, :patient_poids, :patient_taille, :date_creation, :date_modification, :revision, :extension, :operateur, NOW(), NOW(), 0, 0)
            ON DUPLICATE KEY UPDATE patient_id = VALUES(patient_id), type = VALUES(type), venue = VALUES(venue), patient_age = VALUES(patient_age), patient_poids = VALUES(patient_poids), patient_taille = VALUES(patient_taille), date_creation = VALUES(date_creation), date_modification = VALUES(date_modification), revision = VALUES(revision), extension = VALUES(extension), operateur = VALUES(operateur), provisoire = VALUES(provisoire), categorie = VALUES(categorie), service = VALUES(service), modified = VALUES(modified), version = version + 1, deleted = 0";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue('document_id', $document->id);
        $stmt->bindValue('patient_id', $document->patient_id);
        $stmt->bindValue('dossier_id', $document->dossier_id);
        $stmt->bindValue('site', $this->site);
        $stmt->bindValue('type', $document->type);
        $stmt->bindValue('venue', $document->venue);
        $stmt->bindValue('patient_age', $document->patient_age);
        $stmt->bindValue('patient_poids', $document->patient_poids);
        $stmt->bindValue('patient_taille', $document->patient_taille);
        $stmt->bindValue('date_creation', $document->date_creation->format(DateHelper::MYSQL_FORMAT));
        $stmt->bindValue('date_modification', $document->date_modification->format(DateHelper::MYSQL_FORMAT));
        $stmt->bindValue('revision', $document->revision);
        $stmt->bindValue('extension', $document->extension);
        $stmt->bindValue('operateur', $document->operateur);
        $stmt->bindValue('provisoire', $document->provisoire);
        $stmt->bindValue('categorie', $document->categorie);
        $stmt->bindValue('service', $document->service);
        $count = $stmt->execute();
        return $count;
    }

    public function upsertDocuments($documents){
        if(count($documents) === 0)
            return 0;

        $query = "INSERT INTO ".$this->get_document_table()." (".self::DOCUMENT_COLUMNS.") VALUES";
        $count_document = count($documents);
        foreach($documents as $document){
            $query .= "('"
                .$document->id."','"
                .$document->patient_id."','"
                .$document->dossier_id."','"
                .$this->site."','"
                .$document->type."','"
                .$document->venue."','"
                .$document->patient_age."','"
                .$document->patient_poids."','"
                .$document->patient_taille."','"
                .$document->date_creation->format(DateHelper::MYSQL_FORMAT)."','"
                .$document->date_modification->format(DateHelper::MYSQL_FORMAT)."','"
                .$document->revision."','"
                .$document->extension."','"
                .$document->operateur."','"
                .$document->provisoire."','"
                .$document->categorie."','"
                .$document->service."',"
                ." NOW(), NOW(), 0, 0)";
            $count_document--;
            $query .= ($count_document === 0) ? "" : ",";
        }
        $query .= " ON DUPLICATE KEY UPDATE patient_id = VALUES(patient_id), type = VALUES(type), venue = VALUES(venue), patient_age = VALUES(patient_age), patient_poids = VALUES(patient_poids), patient_taille = VALUES(patient_taille), date_creation = VALUES(date_creation), date_modification = VALUES(date_modification), revision = VALUES(revision), extension = VALUES(extension), operateur = VALUES(operateur), provisoire = VALUES(provisoire), categorie = VALUES(categorie), service = VALUES(service), modified = VALUES(modified), version = version + 1, deleted = 0";
        
        $stmt = $this->db->prepare($query); 
        $count = $stmt->execute();
        return $count;
    }

    public function upsertItemValue($item_value){
        $query = "INSERT INTO ".$this->get_item_value_table()." (".self::ITEM_VALUE_COLUMNS.") 
            VALUES(:item_value_id, :patient_id, :dossier_id, :site, :page_nom, :var, :val, NOW(), NOW(), 0, 0)
            ON DUPLICATE KEY UPDATE page_nom = VALUES(page_nom), var = VALUES(var), val = VALUES(val), modified = VALUES(modified), version = version + 1, deleted = 0";
        $stmt = $this->db->prepare($query); 
        $stmt->bindValue('item_value_id', $item_value->id);
        $stmt->bindValue('patient_id', $item_value->patient_id);
        $stmt->bindValue('dossier_id', $item_value->dossier_id);
        $stmt->bindValue('site', $this->site);
        $stmt->bindValue('page_nom', $item_value->page_nom);
        $stmt->bindValue('var', $item_value->var);
        $stmt->bindValue('val', $item_value->val);
        $count = $stmt->execute();
        return $count;
    }

    public function upsertItemValues(array $item_values){
        if(count($item_values) === 0)
            return 0;

        $query = "INSERT INTO ".$this->get_item_value_table()." (".self::ITEM_VALUE_COLUMNS.") VALUES";
        $count_value = count($item_values);
        foreach($item_values as $item_value){
            $query .= "('"
                .$item_value->id."','"
                .$item_value->patient_id."','"
                .$item_value->dossier_id."','"
                .$this->site."','"
                .$item_value->page_nom."','"
                .$item_value->var."',"
                .$this->db->quote($item_value->val)
                .", NOW(), NOW(), 0, 0)";
            $count_value--;
            $query .= ($count_value === 0) ? "" : ",";
        }
        $query .= " ON DUPLICATE KEY UPDATE page_nom = VALUES(page_nom), var = VALUES(var), val = VALUES(val), modified = VALUES(modified), version = version + 1, deleted = 0";
        
        $stmt = $this->db->prepare($query); 
        $count = $stmt->execute();
        return $count;
    }

    public function deleteDocumentsAndItemValues(array $nipros, array $item_names = null){
        $query_items = ($item_names === null || count($item_names) < 1) 
            ? "" : "AND var in(".join(',',array_map(function($v){ return "'".$v."'"; },$item_names)).")";
        $query_deleted_document = "UPDATE ".$this->get_document_table()." SET deleted = 1 WHERE site = :site AND nipro IN (:nipros)";
        $query_deleted_item_values = "UPDATE ".$this->get_item_value_table()." SET deleted = 1 WHERE site = :site AND nipro IN(:nipros) {$query_items}";
        $values = array('site' => $this->site, 'nipros' => $nipros);
        $types = array('site' => PDO::PARAM_STR, 'nipros' => Connection::PARAM_INT_ARRAY);
        $stmt = $this->db->executeQuery($query_deleted_document,$values,$types);
        $stmt = $this->db->executeQuery($query_deleted_item_values,$values,$types);
    }

    // -------- Table Creation

    public function getCreateTableDocumentQuery(){
        $query = "CREATE TABLE IF NOT EXISTS ".$this->get_document_table()." (
            `nipro` bigint(20) NOT NULL,
            `patient_id` bigint(20) DEFAULT NULL,
            `dossier_id` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
            `site` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
            `type` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
            `venue` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
            `patient_age` int(11) DEFAULT NULL,
            `patient_poids` int(11) DEFAULT NULL,
            `patient_taille` int(11) DEFAULT NULL,
            `date_creation` datetime DEFAULT NULL,
            `date_modification` datetime DEFAULT NULL,
            `revision` int(11) DEFAULT '1',
            `extension` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
            `operateur` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
            `provisoire` tinyint(4) DEFAULT NULL,
            `categorie` int(11) DEFAULT NULL,
            `service` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
            `created` datetime DEFAULT NULL,
            `modified` datetime DEFAULT NULL,
            `version` int(11) DEFAULT NULL,
            `deleted` tinyint(4) DEFAULT '0',
            PRIMARY KEY (`nipro`,`dossier_id`,`site`),
            KEY `INDEX1` (`nipro`,`dossier_id`,`date_creation`,`patient_id`,`type`,`deleted`),
            KEY `INDEX_SITE` (`site`),
            KEY `INDEX_NIPRO` (`nipro`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;";
        return $query;
    }

    public function getCreateTableItemValueQuery(){
        $query = "CREATE TABLE IF NOT EXISTS ".$this->get_item_value_table()." (
            `nipro` bigint(20) NOT NULL,
            `patient_id` bigint(20) NOT NULL,
            `dossier_id` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
            `site` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
            `page_nom` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
            `var` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
            `val` text COLLATE utf8_unicode_ci,
            `created` datetime DEFAULT NULL,
            `modified` datetime DEFAULT NULL,
            `version` int(11) DEFAULT NULL,
            `deleted` tinyint(4) DEFAULT '0',
            PRIMARY KEY (`nipro`,`patient_id`,`dossier_id`,`var`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;";
        return $query;
    }

    public function createTableDocument(){
        $query = $this->getCreateTableDocumentQuery();
        $stmt = $this->db->prepare($query);
        return $stmt->execute();
    }

    public function createTableItemValue(){
        $query = $this->getCreateTableItemValueQuery();
        $stmt = $this->db->prepare($query);
        return $stmt->execute();
    }
}
    