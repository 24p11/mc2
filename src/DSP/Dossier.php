<?php 
namespace SBIM\DSP;
use \DateTime;
use SBIM\Core\Helper\DateHelper;
class Dossier{

    public $id;
    public $site;
    public $nom;
    public $libelle;
    public $uhs;
    
    public $created;
    public $modified;
    public $version;

    public function __construct($data = null){
        if(is_array($data)){
            if(isset($data['dossier_id']))
                $this->id = $data['dossier_id'];
            $this->site = $data['site'];
            $this->nom = $data['nom'];
            $this->libelle = $data['libelle'];
            $this->uhs = $data["uhs"];
            $this->created = DateTime::createFromFormat(DateHelper::MYSQL_FORMAT,$data['created']);
            $this->modified = DateTime::createFromFormat(DateHelper::MYSQL_FORMAT,$data['modified']);
            $this->version = $data['version'];
        }
    }

    public static function createFromMCData($mc_data){
        $dossier = new Dossier();
        if(is_array($mc_data)){
            $dossier->id = $mc_data['DOSSIER_ID'];
            $dossier->site = $mc_data['SITE'];
            $dossier->nom = $mc_data['NOM'];
            $dossier->libelle = $mc_data['LIBELLE'];
            $dossier->uhs = $mc_data['UHS'];
        }
        return $dossier;
    }

    public function toMCArray(){
        $result = array();
        $result['DOSSIER_ID'] = $this->id;
        $result['SITE'] = $this->site;
        $result['NOM'] = $this->nom;
        $result['LIBELLE'] = $this->libelle;
        $result['UHS'] = $this->uhs;
        return $result;
    }
}
