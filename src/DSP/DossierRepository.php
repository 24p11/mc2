<?php
namespace MC2\DSP;
use \PDO;
use Psr\Log\LoggerInterface;
use Doctrine\DBAL\DriverManager;
use \InvalidArgumentException;
/**
 * Dossier Repository
 * 
 * Persistence de la STRUCTURE des Dossiers (+ Items/champs des dossiers).
 * Un dossier (DSP) et des items définissent l'apparence et le contenu possible d'un document.
 * Pas de recuperation des données contenues dans un DSP depuis cette classe (cf. DocumentRepository).
 * 
 */
class DossierRepository{

    const DEFAULT_DOSSIER_TABLE = "mcdsp_dossier";
    const DOSSIER_COLUMNS = "dossier_id, site, nom, libelle, uhs, created, modified, version"; // = *

    const DEFAULT_ITEM_TABLE = "mcdsp_item";
    const ITEM_COLUMNS = "item_id, dossier_id, site, type, mctype, ligne, libelle, libelle_bloc, libelle_secondaire, detail, type_controle, formule, options, list_nom, list_values, page_nom, page_libelle, bloc_no, bloc_libelle, created, modified, version"; // = *

    const DEFAULT_PAGE_TABLE = "mcdsp_page";
    const PAGE_COLUMNS = "type_document, dossier_id, site, page_libelle, page_code, page_ordre, created, modified, version"; // = *

    private $db = null;
    private $logger;
    private $site = null;

    private $dossier_table = null;
    private $item_table = null;
    private $page_table = null;

    public function getDossierTable(){
        return $this->dossier_table;
    }

    public function setDossierTable($dossier_table){
        $this->dossier_table = $dossier_table === null ? self::DEFAULT_DOSSIER_TABLE : $dossier_table;
    }

    public function getItemTable(){
        return $this->item_table;
    }

    public function setItemTable($item_table){
        $this->item_table = $item_table === null ? self::DEFAULT_ITEM_TABLE : $item_table;
    }

    public function getPageTable(){
        return $this->page_table;
    }

    public function setPageTable($page_table){
        $this->page_table = $page_table === null ? self::DEFAULT_PAGE_TABLE : $page_table;
    }

    public function setSite($site){
        $this->site = $site;
    }

    /**
     *  @param array $configuration configuration that should contains MC2 DSN (doctrine compatible)
     * @param Psr\Log\LoggerInterface $logger
     */
    public function __construct(array $configuration,LoggerInterface $logger){
        if(isset($configuration['mc2']['doctrine']['dbal']) === false)
            throw new InvalidArgumentException("MC2 DSN was not found in given configuration");
            
        $this->db = DriverManager::getConnection($configuration['mc2']['doctrine']['dbal']);
        $this->setDossierTable($configuration['mc2']['tables']['dossier']);
        $this->setItemTable($configuration['mc2']['tables']['item']);
        $this->setPageTable($configuration['mc2']['tables']['page']);
        $this->logger = $logger;   
    }

    public function checkConnection(){
        try{
            if($this->db->connect())
                return true;
        } 
        catch (\Exception $e) {
            $this->logger->error("Can't connect to DossierRepository DB", array('exception' => $e));
            return false;
        }
    }

    // -------- Query

    public function findDossier($dossier_id){
        $query = "SELECT ".self::DOSSIER_COLUMNS." FROM ".$this->getDossierTable()." 
            WHERE dossier_id = :dossier_id 
            AND site = :site
            ORDER BY dossier_id";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue("dossier_id", $dossier_id);
        $stmt->bindValue("site", $this->site);
        $stmt->execute();
        $result = array();
        while($row = $stmt->fetch(PDO::FETCH_ASSOC))
            $result[] = new Dossier($row);
        return $result;
    }

    public function findAllDossier(){
        $query = "SELECT ".self::DOSSIER_COLUMNS." FROM ".$this->getDossierTable()."
            WHERE site = :site
            ORDER BY dossier_id";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue("site", $this->site);
        $stmt->execute();
        $result = array();
        while($row = $stmt->fetch(PDO::FETCH_ASSOC))
            $result[] = new Dossier($row);
        return $result;
    }

    public function findPageByDossierId($dossier_id,array $document_types = null){
        $query_document_type = ($document_types === null || count($document_types) < 1) 
            ? "" : "AND document_type in(".join(',',array_map(function($v){ return "'".$v."'"; },$document_types)).")";
        $query = "SELECT ".self::PAGE_COLUMNS." 
            FROM ".$this->getPageTable()." 
            WHERE dossier_id = :dossier_id 
            AND site = :site
            {$query_document_type}
            ORDER BY type_document,page_ordre";
            
        $stmt = $this->db->prepare($query);
        $stmt->bindValue("dossier_id", $dossier_id);
        $stmt->bindValue("site", $this->site);
        $stmt->execute();
        $result = array();
        while($row = $stmt->fetch(PDO::FETCH_ASSOC))
            $result[] = new Page($row);
        return $result;
    }

    public function findItemByDossierId($dossier_id,array $item_names = null){
        $query_items = ($item_names === null || count($item_names) < 1) 
            ? "" : "AND item_id in(".join(',',array_map(function($v){ return "'".$v."'"; },$item_names)).")";
        $query = "SELECT ".self::ITEM_COLUMNS." 
            FROM ".$this->getItemTable()." 
            WHERE dossier_id = :dossier_id 
            AND site = :site
            {$query_items}
            ORDER BY page_nom, bloc_no, ligne";
            
        $stmt = $this->db->prepare($query);
        $stmt->bindValue("dossier_id", $dossier_id);
        $stmt->bindValue("site", $this->site);
        $stmt->execute();
        $result = array();
        while($row = $stmt->fetch(PDO::FETCH_ASSOC))
            $result[] = new Item($row);
        return $result;
    }

    public function findItemByDossierIdAndPage($dossier_id,$page_nom){
        $query = "SELECT ".self::ITEM_COLUMNS." 
            FROM ".$this->getItemTable()." 
            WHERE dossier_id = :dossier_id 
            AND site = :site
            AND page_libelle = :page_nom
            ORDER BY page_nom, bloc_no, ligne";
            
        $stmt = $this->db->prepare($query);
        $stmt->bindValue("dossier_id", $dossier_id);
        $stmt->bindValue("site", $this->site);
        $stmt->bindValue("page_nom", $page_nom);
        $stmt->execute();
        $result = array();
        $fiches = array();
        while($row = $stmt->fetch(PDO::FETCH_ASSOC)){
            $item = new Item($row);
            if(!empty($item->detail) && !in_array($item->detail,$fiches))
                $fiches[] = $item->detail;
            $result[] = $item;
        }

        if(count($fiches) > 0){
            $query_fiches = "AND page_nom in(".join(',',array_map(function($v){ return "'".$v."'"; },$fiches)).")";
            
            $query = "SELECT ".self::ITEM_COLUMNS." 
                FROM ".$this->getItemTable()." 
                WHERE dossier_id = :dossier_id 
                AND site = :site
                {$query_fiches}
                ORDER BY page_nom, bloc_no, ligne";
    
            $stmt = $this->db->prepare($query);
            $stmt->bindValue("dossier_id", $dossier_id);
            $stmt->bindValue("site", $this->site);
            $stmt->execute();
            $result_fiches = array();
            while($row = $stmt->fetch(PDO::FETCH_ASSOC))
                $result_fiches[] = new Item($row);
            $result = array_merge($result, $result_fiches);
        }
        return $result;
    }

    // -------- Command

    public function upsertDossier($dossier){
        $query = "INSERT INTO ".$this->getDossierTable()." (".self::DOSSIER_COLUMNS.") 
            VALUES(:dossier_id, :site, :nom, :libelle, :uhs, NOW(), NOW(), 0)
            ON DUPLICATE KEY UPDATE nom = VALUES(nom), libelle = VALUES(libelle), uhs = VALUES(uhs), modified = VALUES(modified), version = version + 1";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue("dossier_id", $dossier->id);
        $stmt->bindValue("site", $dossier->site);
        $stmt->bindValue("nom", $dossier->nom);
        $stmt->bindValue("libelle", $dossier->libelle);
        $stmt->bindValue("uhs", $dossier->uhs);
        $count = $stmt->execute();
        return $count;
    }

    public function deleteDossier($id){
        return $this->db->delete($this->getDossierTable(), array('dossier_id' => $id));
    }

    //const PAGE_COLUMNS = "type_document, dossier_id, site, page_libelle, page_code, page_ordre, created, modified, version"; // = *
    public function upsertPage($page){
        $query = "INSERT INTO ".$this->getPageTable()." (".self::PAGE_COLUMNS.") 
            VALUES(:type_document, :dossier_id, :site, :page_libelle, :page_code, :page_ordre, NOW(), NOW(), 0)
            ON DUPLICATE KEY UPDATE page_code = VALUES(page_code), page_ordre = VALUES(page_ordre), modified = VALUES(modified), version = version + 1";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue("type_document", $page->type_document);
        $stmt->bindValue("dossier_id", $page->dossier_id);
        $stmt->bindValue("site", $page->site);
        $stmt->bindValue("page_libelle", $page->page_libelle);
        $stmt->bindValue("page_code", $page->page_code);
        $stmt->bindValue("page_ordre", $page->page_ordre);
        $count = $stmt->execute();
        return $count;
    }
    
    public function upsertItem($item){
        $query = "INSERT INTO ".$this->getItemTable()." (".self::ITEM_COLUMNS.") 
            VALUES(:item_id, :dossier_id, :site, :type, :mctype, :ligne, :libelle, :libelle_bloc, :libelle_secondaire, :detail, :type_controle, :formule, :options, :list_nom, :list_values, :page_nom, :page_libelle, :bloc_no, :bloc_libelle, NOW(), NOW(), 0)
            ON DUPLICATE KEY UPDATE type = VALUES(type), mctype = VALUES(mctype), ligne = VALUES(ligne), libelle = VALUES(libelle), libelle_bloc = VALUES(libelle_bloc), libelle_secondaire = VALUES(libelle_secondaire), detail = VALUES(detail), type_controle = VALUES(type_controle), formule = VALUES(formule), options = VALUES(options), list_nom = VALUES(list_nom), list_values = VALUES(list_values), dossier_id = VALUES(dossier_id), page_nom = VALUES(page_nom), page_libelle = VALUES(page_libelle), bloc_no = VALUES(bloc_no), bloc_libelle = VALUES(bloc_libelle), modified = VALUES(modified), version = version + 1";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue("item_id", $item->id);
        $stmt->bindValue("dossier_id", $item->dossier_id);
        $stmt->bindValue("site", $this->site);
        $stmt->bindValue("type", $item->type);
        $stmt->bindValue("mctype", $item->mctype);
        $stmt->bindValue("ligne", $item->ligne);
        $stmt->bindValue("libelle", $item->libelle);
        $stmt->bindValue("libelle_bloc", $item->libelle_bloc);
        $stmt->bindValue("libelle_secondaire", $item->libelle_secondaire);
        $stmt->bindValue("detail", $item->detail);
        $stmt->bindValue("type_controle", $item->type_controle);
        $stmt->bindValue("formule", $item->formule);
        $stmt->bindValue("options", $item->options);
        $stmt->bindValue("list_nom", $item->list_nom);
        $stmt->bindValue("list_values", $item->list_values);
        $stmt->bindValue("page_nom", $item->page_nom);
        $stmt->bindValue("page_libelle", $item->page_libelle);
        $stmt->bindValue("bloc_no", $item->bloc_no);
        $stmt->bindValue("bloc_libelle", $item->bloc_libelle);
        $count = $stmt->execute();
        return $count;
    }

    public function deleteItem($id){
        return $this->db->delete($this->getItemTable(), array('item_id' => $id));
    }

    // -------- Table Creation

    public function getCreateTableDossierQuery(){
        $query = "CREATE TABLE IF NOT EXISTS ".$this->getDossierTable()." (
                `dossier_id` varchar(255) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
                `site` varchar(255) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
                `nom` varchar(255) CHARACTER SET utf8 COLLATE utf8_unicode_ci DEFAULT NULL,
                `libelle` varchar(255) CHARACTER SET utf8 COLLATE utf8_unicode_ci DEFAULT NULL,
                `uhs` text CHARACTER SET utf8 COLLATE utf8_unicode_ci,
                `created` datetime DEFAULT NULL,
                `modified` datetime DEFAULT NULL,
                `version` int DEFAULT NULL,
                PRIMARY KEY (`dossier_id`,`site`) USING BTREE
               ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
        return $query;
    }

    public function getCreateTableItemQuery(){
        $query = "CREATE TABLE IF NOT EXISTS ".$this->getItemTable()." (
            `item_id` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
            `dossier_id` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
            `site` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
            `page_nom` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
            `page_libelle` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
            `bloc_no` int(11) DEFAULT NULL,
            `bloc_libelle` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
            `type` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
            `mctype` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
            `ligne` int(11) DEFAULT NULL,
            `libelle` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
            `libelle_bloc` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
            `libelle_secondaire` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
            `detail` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
            `type_controle` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
            `formule` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
            `options` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
            `list_nom` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
            `list_values` text COLLATE utf8_unicode_ci,
            `created` datetime DEFAULT NULL,
            `modified` datetime DEFAULT NULL,
            `version` int(11) DEFAULT NULL,
            PRIMARY KEY (`item_id`,`dossier_id`,`site`),
            KEY `page_nom_index` (`page_nom`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;";
        return $query;
    }

    public function getCreateTablePageQuery(){
        $query = "CREATE TABLE IF NOT EXISTS ".$this->getPageTable()."  (
            `type_document` VARCHAR(255) NOT NULL ,
            `dossier_id` VARCHAR(255) NOT NULL ,
            `site` VARCHAR(255) NOT NULL ,
            `page_libelle` VARCHAR(255) NULL ,
            `page_code` INT NULL ,
            `page_ordre` INT NULL ,
            `created` DATETIME NULL ,
            `modified` DATETIME NULL ,
            `version` INT NULL ,
            PRIMARY KEY (`type_document`, `dossier_id`, `site`,`page_libelle`) 
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;";
        return $query;
    }

    public function createTableDossier(){
        $query = $this->getCreateTableDossierQuery();
        $stmt = $this->db->prepare($query);
        return $stmt->execute();
    }

    public function createTableItem(){
        $query = $this->getCreateTableItemQuery();
        $stmt = $this->db->prepare($query);
        return $stmt->execute();
    }

    public function createTablePage(){
        $query = $this->getCreateTablePageQuery();
        $stmt = $this->db->prepare($query);
        return $stmt->execute();
    }
}