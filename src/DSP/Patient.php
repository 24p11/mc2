<?php 
namespace SBIM\DSP;
use \DateTime;
use SBIM\Core\Helper\DateHelper;
class Patient{

    public $id; // = NIP 
    public $ipp;
    public $nom;
    public $prenom;
    public $ddn;
    public $sexe;
    public $created;
    public $modified;
    public $version;

    public function __construct($data = null){
        if(is_array($data)){
            if(isset($data['patient_id']))
                $this->id = $data['patient_id'];

            $this->ipp = $data['ipp'];
            $this->nom = $data['nom'];
            $this->prenom = $data['prenom'];
            $this->ddn = new DateTime($data['ddn']);
            $this->sexe = $data['sexe'];

            $this->created = DateTime::createFromFormat(DateHelper::MYSQL_FORMAT,$data['created']);
            $this->modified = DateTime::createFromFormat(DateHelper::MYSQL_FORMAT,$data['modified']);
            $this->version = $data['version'];
        }
    }

    public static function createFromMCData($mc_data){
        $patient = new Patient();
        if(is_array($mc_data)){
            $patient->id = $mc_data['NIP'];
            $patient->ipp = empty($mc_data['IPP']) ? 0 : $mc_data['IPP'];
            $patient->nom = $mc_data['NOM'];
            $patient->prenom = $mc_data['PRENOM'];
            $patient->ddn = new DateTime($mc_data['DATNAI']);
            $patient->sexe = $mc_data['SEXE'];
        }
        return $patient;
    }
}
