<?php
namespace MC2\DSP;
use \PDO;
use Psr\Log\LoggerInterface;
use Doctrine\DBAL\DriverManager;
use MC2\Core\Helper\DateHelper;
/**
 * Patient Repository
 */
class PatientRepository{

    const DEFAULT_PATIENT_TABLE = "mcdsp_patient";
    const PATIENT_COLUMNS = "patient_id, ipp, nom, prenom, ddn, sexe, created, modified, version"; // = *

    private $db = null;
    private $logger;
    private $patient_table = null;

    public function getPatientTable(){
        return $this->patient_table;
    }

    public function setPatientTable($patient_table){
        $this->patient_table = $patient_table === null ? self::DEFAULT_PATIENT_TABLE : $patient_table;
    }

    /**
     * @param array $configuration configuration that should contains MC2 DSN (doctrine compatible)
     * @param Psr\Log\LoggerInterface $logger
     */
    public function __construct(array $configuration, LoggerInterface $logger){
        if(isset($configuration['mc2']['doctrine']['dbal']) === false)
            throw new InvalidArgumentException("MC2 DSN was not found in given configuration");
            
        $this->db = DriverManager::getConnection($configuration['mc2']['doctrine']['dbal']);
        $this->setPatientTable($configuration['mc2']['tables']['patient']);
        $this->logger = $logger;
    }

    public function checkConnection(){
        try{
            if($this->db->connect())
                return true;
        } 
        catch (\Exception $e) {
            $this->logger->error("Can't connect to PatientRepository DB", array('exception' => $e));
            return false;
        }
    }

    // -------- Query

    public function findPatient($patient_id){
        $query = "SELECT ".self::PATIENT_COLUMNS." FROM ".$this->getPatientTable()." WHERE patient_id = :patient_id ORDER BY created DESC";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue("patient_id", $patient_id);
        $stmt->execute();
        $result = array();
        while($row = $stmt->fetch(PDO::FETCH_ASSOC))
            $result[] = new Patient($row);
        return $result;
    }

    public function findPatientFromIPP($ipp){
        $query = "SELECT ".self::PATIENT_COLUMNS." FROM ".$this->getPatientTable()." WHERE ipp = :ipp ORDER BY patient_id";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue("ipp", $ipp);
        $stmt->execute();
        $result = array();
        while($row = $stmt->fetch(PDO::FETCH_ASSOC))
            $result[] = new Patient($row);
        return $result;
    }

    public function findAllPatient(){
        $query = "SELECT ".self::PATIENT_COLUMNS." FROM ".$this->getPatientTable()." ORDER BY patient_id";
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        $result = array();
        while($row = $stmt->fetch(PDO::FETCH_ASSOC))
            $result[] = new Patient($row);
        return $result;
    }

    // -------- Command
      
    public function upsertPatient($patient){
        $query = "INSERT INTO ".$this->getPatientTable()." (".self::PATIENT_COLUMNS.") VALUES(:patient_id, :ipp, :nom, :prenom, :ddn, :sexe, NOW(), NOW(), 0)
        ON DUPLICATE KEY UPDATE nom = VALUES(nom), prenom = VALUES(prenom), ddn = VALUES(ddn), sexe = VALUES(sexe), modified = VALUES(modified), version = version + 1";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue("patient_id", $patient->id);
        $stmt->bindValue("ipp", $patient->ipp);
        $stmt->bindValue("nom", $patient->nom);
        $stmt->bindValue("prenom", $patient->prenom);
        $stmt->bindValue("ddn", $patient->ddn->format(DateHelper::MYSQL_FORMAT));
        $stmt->bindValue("sexe", $patient->sexe);
        $count = $stmt->execute();
        return $count;
    }

    public function upsertPatients(array $patients){
        if(count($patients) === 0)
            return 0;

        $query = "INSERT INTO ".$this->getPatientTable()." (".self::PATIENT_COLUMNS.") VALUES";
        $count_patient = count($patients);
        foreach($patients as $patient){
            $query .= "('"
                .$patient->id."','"
                .$patient->ipp."','"
                .$patient->nom."','"
                .$patient->prenom."','"
                .$patient->ddn->format(DateHelper::MYSQL_FORMAT)."','"
                .$patient->sexe."',"
                ." NOW(), NOW(), 0)";
            $count_patient--;
            $query .= ($count_patient === 0) ? "" : ",";
        }
        $query .= " ON DUPLICATE KEY UPDATE nom = VALUES(nom), prenom = VALUES(prenom), ddn = VALUES(ddn), sexe = VALUES(sexe), modified = VALUES(modified), version = version + 1";
        $stmt = $this->db->prepare($query); 
        $count = $stmt->execute();
        return $count;
    }
    
    public function deletePatient($patient_id, $ipp){
        return $conn->delete($this->getPatientTable(), array('patient_id' => $patient_id, 'ipp' => $ipp));
    }
    
    // -------- Table Creation

    public function getCreateTablePatientQuery(){
        $query = "CREATE TABLE IF NOT EXISTS ".$this->getPatientTable()." (
            `patient_id` bigint(20) NOT NULL COMMENT 'NIP',
            `ipp` bigint(20) NOT NULL DEFAULT '0',
            `nom` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
            `prenom` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
            `ddn` datetime DEFAULT NULL,
            `sexe` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
            `created` datetime DEFAULT NULL,
            `modified` datetime DEFAULT NULL,
            `version` int(11) DEFAULT NULL,
            PRIMARY KEY (`patient_id`,`ipp`),
            KEY `INDEX_PATIENT_ID` (`patient_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;";
        return $query;
    }

    public function createTablePatient(){
        $query = $this->getCreateTablePatientQuery();
        $stmt = $this->db->prepare($query);
        return $stmt->execute();
    }
}