<?php
namespace MC2\DSP;
use \PDO;
use MC2\Core\Helper\DateHelper;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use \InvalidArgumentException;

/**
 * Document Repository
 * 
 * Persistence des documents et de leurs données
 * 
 */
class DocumentRepository{

    const DEFAULT_DOCUMENT_TABLE = "mcdsp_document";
    const DOCUMENT_COLUMNS = "nipro,patient_id,dossier_id,site,type,venue,patient_age,patient_poids,patient_taille,date_creation,date_modification,revision,extension,operateur,provisoire,categorie,service,text,created,modified,version,deleted";// = *

    const DEFAULT_ITEM_VALUE_TABLE = "mcdsp_item_value";
    const ITEM_VALUE_COLUMNS = "nipro,patient_id,dossier_id,site,page_nom,var,val,created,modified,version,deleted";// = *

    const DEFAULT_ITEM_TABLE = "mcdsp_item";

    private $db = null;
    private $logger;
    private $site = null;

    private $document_table = null;
    private $item_value_table = null;
    private $item_table = null;

    public $base_url = '';

    public function getDocumentTable(){
        return $this->document_table;
    }

    public function setDocumentTable($document_table){
        $this->document_table = $document_table === null ? self::DEFAULT_DOCUMENT_TABLE : $document_table;
    }

    public function getItemValueTable(){
        return $this->item_value_table;
    }
    
    public function setItemValueTable($item_value_table){
        $this->item_value_table = $item_value_table === null ? self::DEFAULT_ITEM_VALUE_TABLE : $item_value_table;
    }

    public function getItemTable(){
        return $this->item_table;
    }

    public function setItemTable($item_table){
        $this->item_table = $item_table === null ? self::DEFAULT_ITEM_TABLE : $item_table;
    }

    public function setSite($site){
        $this->site = $site;
    }

    public function setDocBaseURL($doc_base_url){
        $this->base_url = $doc_base_url;
    }

    /**
     * @param array $configuration configuration that should contains MC2 DSN (doctrine compatible)
     * @param Psr\Log\LoggerInterface $logger
     */
    public function __construct(array $configuration,LoggerInterface $logger){
        if(isset($configuration['mc2']['doctrine']['dbal']) === false)
            throw new InvalidArgumentException("MC2 DSN was not found in given configuration");
            
        $this->db = DriverManager::getConnection($configuration['mc2']['doctrine']['dbal']);
        $this->setDocumentTable($configuration['mc2']['tables']['document']);
        $this->setItemValueTable($configuration['mc2']['tables']['item_value']);
        $this->setItemTable($configuration['mc2']['tables']['item']);
        $this->logger = $logger;
    }

    public function checkConnection(){
        try{
            if($this->db->connect())
                return true;
        } 
        catch (\Exception $e) {
            $this->logger->error("Can't connect to DocumentRepository DB", array('exception' => $e));
            return false;
        }
    }

    // -------- Query

    public function removeFullTextIndexes(){
        try{
            $this->db->executeQuery('ALTER TABLE mcdsp_item_value DROP INDEX `FULLTEXT_VAL`');
            $this->db->executeQuery('ALTER TABLE mcdsp_document DROP INDEX `FULLTEXT_TEXT`');
        }
        catch (\Exception $e) {
            $this->logger->error("Can't remove full-text indexes", array('exception' => $e));
        }
    }
    
    public function addFullTextIndexes(){
        try{
            $this->db->executeQuery('ALTER TABLE mcdsp_item_value ADD FULLTEXT INDEX `FULLTEXT_VAL` (`val`)');
            $this->db->executeQuery('ALTER TABLE mcdsp_document ADD FULLTEXT INDEX `FULLTEXT_TEXT` (`text`)');
        } 
        catch (\Exception $e) {
            $this->logger->error("Can't add full-text indexes", array('exception' => $e));
        }
    }

    public function findDocumentByDossierId($dossier_id,$date_debut, $date_fin,array $patient_ids = null){
        $query_patients = ($patient_ids === null || count($patient_ids) < 1) 
            ? "" : "AND patient_id in(".join(',',array_map(function($v){ return "'".$v."'"; },$patient_ids)).")";

        $query = "SELECT ".self::DOCUMENT_COLUMNS." 
            FROM ".$this->getDocumentTable()." 
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
            FROM ".$this->getDocumentTable()." 
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
            FROM ".$this->getItemValueTable()." 
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
        $query = "SELECT DISTINCT patient_id FROM ".$this->getDocumentTable()." 
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
        $query = "INSERT INTO ".$this->getDocumentTable()." (".self::DOCUMENT_COLUMNS.") 
            VALUES(:document_id, :patient_id, :dossier_id, :site, :type, :venue, :patient_age, :patient_poids, :patient_taille, :date_creation, :date_modification, :revision, :extension, :operateur, :provisoire, :categorie, :service,'', NOW(), NOW(), 0, 0)
            ON DUPLICATE KEY UPDATE patient_id = VALUES(patient_id), type = VALUES(type), venue = VALUES(venue), patient_age = VALUES(patient_age), patient_poids = VALUES(patient_poids), patient_taille = VALUES(patient_taille), date_creation = VALUES(date_creation), date_modification = VALUES(date_modification), revision = VALUES(revision), extension = VALUES(extension), operateur = VALUES(operateur), provisoire = VALUES(provisoire), categorie = VALUES(categorie), service = VALUES(service), text = VALUES(text), modified = VALUES(modified), version = version + 1, deleted = 0";
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

        $query = "INSERT INTO ".$this->getDocumentTable()." (".self::DOCUMENT_COLUMNS.") VALUES";
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
                .$document->operateur."',"
                .$document->provisoire.",'"
                .$document->categorie."','"
                .$document->service."',"
                ."'', NOW(), NOW(), 0, 0)";
            $count_document--;
            $query .= ($count_document === 0) ? "" : ",";
        }
        $query .= " ON DUPLICATE KEY UPDATE patient_id = VALUES(patient_id), type = VALUES(type), venue = VALUES(venue), patient_age = VALUES(patient_age), patient_poids = VALUES(patient_poids), patient_taille = VALUES(patient_taille), date_creation = VALUES(date_creation), date_modification = VALUES(date_modification), revision = VALUES(revision), extension = VALUES(extension), operateur = VALUES(operateur), provisoire = VALUES(provisoire), categorie = VALUES(categorie), service = VALUES(service),  text = VALUES(text), modified = VALUES(modified), version = version + 1, deleted = 0";
        
        $stmt = $this->db->prepare($query); 
        $count = $stmt->execute();
        return $count;
    }

    public function upsertItemValue($item_value){
        $query = "INSERT INTO ".$this->getItemValueTable()." (".self::ITEM_VALUE_COLUMNS.") 
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

        $query = "INSERT INTO ".$this->getItemValueTable()." (".self::ITEM_VALUE_COLUMNS.") VALUES";
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

    public function updateDocumentsFullText(array $nipros){
        if($nipros === null || count($nipros) < 1)
            return null;

        $query_in_nipros = "AND doc.nipro in(".join(',',array_map(function($v){ return "'".$v."'"; },$nipros)).")";
        $sql = "SELECT DISTINCT doc.nipro, group_concat(distinct concat('[',it.page_libelle,'.',it.libelle_bloc,'|',item.var,']=',item.val) separator '; ') as fulltxt
            FROM ".$this->getDocumentTable()." as doc 
            JOIN ".$this->getItemValueTable()." as item ON item.nipro = doc.nipro AND item.deleted = '0'
            JOIN ".$this->getItemTable()." as it ON it.dossier_id = item.dossier_id AND it.site = item.site AND it.item_id = item.var
            WHERE doc.provisoire = '0' AND doc.deleted = '0'
            {$query_in_nipros}
            GROUP BY doc.nipro";

        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $result = [];
        while($row = $stmt->fetch(PDO::FETCH_ASSOC)){
            $row['fulltxt'] = html_entity_decode(strip_tags(str_replace(["<br>","<br />","\r\n","\r"]," ",$row['fulltxt'])));
            $result[] = $row;
        }
        $count = 0;
        foreach($result as $r){
            $query_update_text = "UPDATE ".$this->getDocumentTable()." SET text = :text WHERE deleted = 0 AND nipro = :nipro";
            $stmt = $this->db->prepare($query_update_text); 
            $stmt->bindValue('text', $r['fulltxt']);
            $stmt->bindValue('nipro', $r['nipro']);
            $count += $stmt->execute();
        }
        return $count;
    }

    public function deleteDocumentsAndItemValues($dsp_id, array $nipros, array $item_names = null){
        $query_items = ($item_names === null || count($item_names) < 1) 
            ? "" : "AND var in(".join(',',array_map(function($v){ return "'".$v."'"; },$item_names)).")";
        $query_deleted_document = "UPDATE ".$this->getDocumentTable()." SET deleted = 1 WHERE dossier_id = :dossier_id AND site = :site AND nipro IN (:nipros)";
        $query_deleted_item_values = "UPDATE ".$this->getItemValueTable()." SET deleted = 1 WHERE dossier_id = :dossier_id AND site = :site AND nipro IN(:nipros) {$query_items}";

        $values = array('dossier_id'=> $dsp_id, 'site' => $this->site, 'nipros' => $nipros);
        $types = array('dossier_id'=> PDO::PARAM_STR, 'site' => PDO::PARAM_STR, 'nipros' => Connection::PARAM_INT_ARRAY);
        $stmt = $this->db->executeUpdate($query_deleted_document,$values,$types);
        $stmt = $this->db->executeUpdate($query_deleted_item_values,$values,$types);
    }

    // -------- Table Creation

    public function getCreateTableDocumentQuery(){
        $query = "CREATE TABLE IF NOT EXISTS ".$this->getDocumentTable()." (
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
            `text` mediumtext COLLATE utf8_unicode_ci,
            `created` datetime DEFAULT NULL,
            `modified` datetime DEFAULT NULL,
            `version` int(11) DEFAULT NULL,
            `deleted` tinyint(4) DEFAULT '0',
            PRIMARY KEY (`nipro`,`dossier_id`,`site`),
            KEY `INDEX1` (`nipro`,`dossier_id`,`date_creation`,`patient_id`,`type`,`deleted`),
            KEY `INDEX_SITE` (`site`),
            KEY `INDEX_NIPRO` (`nipro`),
            FULLTEXT KEY `FULLTEXT_TEXT` (`text`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
        return $query;
    }

    public function getCreateTableItemValueQuery(){
        $query = "CREATE TABLE IF NOT EXISTS ".$this->getItemValueTable()." (
            `nipro` bigint(20) NOT NULL,
            `patient_id` bigint(20) NOT NULL,
            `dossier_id` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
            `site` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
            `page_nom` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
            `var` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
            `val` longtext COLLATE utf8_unicode_ci,
            `created` datetime DEFAULT NULL,
            `modified` datetime DEFAULT NULL,
            `version` int(11) DEFAULT NULL,
            `deleted` tinyint(4) DEFAULT '0',
            PRIMARY KEY (`nipro`,`patient_id`,`dossier_id`,`var`,`site`)
            KEY `INDEX_NIP` (`patient_id`),
            KEY `INDEX_PAGE` (`page_nom`,`dossier_id`,`deleted`,`patient_id`),
            KEY `INDEX_VAL` (`var`,`nipro`,`deleted`),
            KEY `INDEX_SITE` (`site`),
            KEY `INDEX_NIPRO` (`nipro`),
            FULLTEXT KEY `FULLTEXT_VAL` (`val`)
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
    