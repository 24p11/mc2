<?php
namespace SBIM\DSP;
use \PDO;
use SBIM\Core\Helper\DateHelper;
use Doctrine\DBAL\DriverManager;
/**
 * Patient Repository
 */
class PatientRepository{

    const DEFAULT_PATIENT_TABLE = "mcdsp_patient";
    const PATIENT_COLUMNS = "patient_id, ipp, nom, prenom, ddn, sexe, created, modified, version"; // = *

    private $db = null;
    private $logger;
    private $patient_table = null;

    public function get_patient_table(){
        return ($this->patient_table === null) ? self::DEFAULT_PATIENT_TABLE : $this->patient_table;
    }

    /**
     * @param string $params DSN DSP (MySQL)
     * @param Monolog\Logger $logger
     */
    public function __construct($params,$logger){
        $this->db = DriverManager::getConnection($params['doctrine']['dbal']);
        $this->patient_table = $params['tables']['patient'];
        $this->logger = $logger;
    }

    public function checkConnection(){
        try{
            if ($this->db->connect())
                return true;
        } 
        catch (\Exception $e) {
            $this->logger->error("Can't connect to PatientRepository DB", array('exception' => $e));
            return false;
        }
    }

    // -------- Query

    public function findPatient($patient_id){
        $query = "SELECT ".self::PATIENT_COLUMNS." FROM ".$this->get_patient_table()." WHERE patient_id = :patient_id ORDER BY created DESC";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue("patient_id", $patient_id);
        $stmt->execute();
        $result = array();
        while($row = $stmt->fetch(PDO::FETCH_ASSOC))
            $result[] = new Patient($row);
        return $result;
    }

    public function findPatientFromIPP($ipp){
        $query = "SELECT ".self::PATIENT_COLUMNS." FROM ".$this->get_patient_table()." WHERE ipp = :ipp ORDER BY patient_id";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue("ipp", $ipp);
        $stmt->execute();
        $result = array();
        while($row = $stmt->fetch(PDO::FETCH_ASSOC))
            $result[] = new Patient($row);
        return $result;
    }

    public function findAllPatient(){
        $query = "SELECT ".self::PATIENT_COLUMNS." FROM ".$this->get_patient_table()." ORDER BY patient_id";
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        $result = array();
        while($row = $stmt->fetch(PDO::FETCH_ASSOC))
            $result[] = new Patient($row);
        return $result;
    }

    // -------- Command
      
    public function upsertPatient($patient){
        $query = "INSERT INTO ".$this->get_patient_table()." (".self::PATIENT_COLUMNS.") VALUES(:patient_id, :ipp, :nom, :prenom, :ddn, :sexe, NOW(), NOW(), 0)
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

        $query = "INSERT INTO ".$this->get_patient_table()." (".self::PATIENT_COLUMNS.") VALUES";
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
        return $conn->delete($this->get_patient_table(), array('patient_id' => $patient_id, 'ipp' => $ipp));
    }
    
    // -------- Table Creation

    public function getCreateTablePatientQuery(){
        $query = "CREATE TABLE IF NOT EXISTS ".$this->get_patient_table()." (
            `patient_id` bigint(20) NOT NULL COMMENT 'NIP',
            `ipp` bigint(20) NOT NULL DEFAULT '0',
            `nom` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
            `prenom` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
            `ddn` datetime DEFAULT NULL,
            `sexe` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
            `created` datetime DEFAULT NULL,
            `modified` datetime DEFAULT NULL,
            `version` int(11) DEFAULT NULL,
            PRIMARY KEY (`patient_id`,`ipp`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;";
        return $query;
    }

    public function createTablePatient(){
        $query = $this->getCreateTablePatientQuery();
        $stmt = $this->db->prepare($query);
        return $stmt->execute();
    }
}