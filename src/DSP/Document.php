<?php 
namespace MC2\DSP;
use \DateTime;
use MC2\Core\Helper\DateHelper;
class Document{

    public $id; // NIPRO
    public $patient_id;
    public $dossier_id;
    public $site;
    public $type;
    public $venue;
    public $patient_age;
    public $patient_poids;
    public $patient_taille;
    public $date_creation;
    public $date_modification;
    public $operateur;
    public $revision;
    public $extension;
    public $provisoire;
    public $categorie;
    public $service;

    public $base_url;

    public $created;
    public $modified;
    public $version;

    // lazy loaded
    public $item_values;
    public $patient;

    public function __construct($base_url, $data = null){
        $this->base_url = $base_url;
        if(is_array($data)){
            if(isset($data['nipro']))
                $this->id = $data['nipro'];
            $this->patient_id = $data['patient_id'];
            $this->dossier_id = $data['dossier_id'];
            $this->site = $data['site'];
            $this->type = $data['type'];
            $this->venue = $data['venue'];
            $this->patient_age = $data['patient_age'];
            $this->patient_poids = $data['patient_poids'];
            $this->patient_taille = $data['patient_taille'];
            $this->date_creation = new DateTime($data['date_creation']);
            $this->date_modification = new DateTime($data['date_modification']);
            $this->operateur = $data['operateur'];
            $this->revision = $data['revision'];
            $this->extension = $data['extension'];
            $this->provisoire = $data['provisoire'];
            $this->categorie = $data['categorie'];
            $this->service = $data['service'];
            
            $this->created = DateTime::createFromFormat(DateHelper::MYSQL_FORMAT,$data['created']);
            $this->modified = DateTime::createFromFormat(DateHelper::MYSQL_FORMAT,$data['modified']);
            $this->version = $data['version'];

            $this->item_values = array();
        }
    }

    public static function createFromMCData($base_url,$site,$dsp_id, $mc_data){
        $document = new Document($base_url);
        $document->site = $site;
        $document->dossier_id = $dsp_id;
        if(is_array($mc_data)){
            $document->id = $mc_data['NIPRO'];
            $document->patient_id = $mc_data['NIP'];
            $document->type = $mc_data['TYPE_EXAM'];
            $document->venue = $mc_data['VENUE'];
            $document->patient_age = isset($mc_data['AGE']) ? $mc_data['AGE'] : '';
            $document->patient_poids = isset($mc_data['POIDS']) ? $mc_data['POIDS'] : '';
            $document->patient_taille = isset($mc_data['TAILLE']) ? $mc_data['TAILLE'] : '';
            $document->date_creation = new DateTime($mc_data['DATE_EXAM']);
            $document->date_modification = new DateTime($mc_data['DATE_MAJ']);
            $document->operateur = $mc_data['OPER'];
            $document->revision = $mc_data['REVISION'];
            $document->extension = $mc_data['EXTENSION'];
            $document->provisoire = isset($mc_data['CR_PROVISOIRE']) && $mc_data['CR_PROVISOIRE'] !== null ? $mc_data['CR_PROVISOIRE'] : 0;
            $document->categorie = $mc_data['CATEG'];
            $document->service = $mc_data['SERVICE'];
        }
        return $document;
    }

    public function toMCArray($items){
        $no_patient_loaded = $this->patient === null;
        $result = array();
        // Note : no site & no dossier_id
        $result['NIPRO'] = $this->id;
        $result['IPP'] = $no_patient_loaded ? "" : $this->patient->ipp;
        $result['NIP'] = $this->patient_id;
        $result['NOM'] = $no_patient_loaded ? "" : $this->patient->nom;
        $result['PRENOM'] = $no_patient_loaded ? "" : $this->patient->prenom;
        $result['DATNAI'] = $no_patient_loaded ? "" : $this->patient->ddn->format(DateHelper::SHORT_MYSQL_FORMAT);
        $result['SEXE'] = $no_patient_loaded ? "" : $this->patient->sexe;
        $result['TYPE_EXAM'] = $this->type;
        $result['VENUE'] = $this->venue;
        $result['AGE'] = $this->patient_age;
        $result['POIDS'] = empty($this->patient_poids) ? "" : $this->patient_poids;
        $result['TAILLE'] = empty($this->patient_taille) ? "" : $this->patient_taille;
        $result['DATE_EXAM'] = $this->date_creation->format(DateHelper::SHORT_MYSQL_FORMAT);
        $result['DATE_MAJ'] = $this->date_modification->format(DateHelper::SHORT_MYSQL_FORMAT);
        $result['OPER'] = $this->operateur;
        $result['REVISION'] = $this->revision;
        $result['EXTENSION'] = $this->extension;
        $result['CR_PROVISOIRE'] = $this->provisoire;
        $result['CATEG'] = $this->categorie;
        $result['SERVICE'] = $this->service;
        $result['URL_DOC'] = $this->getURL();

        foreach($items as $item){
            $value = "";
            foreach($this->item_values as $item_value){
                if($item->id === $item_value->var){
                    $value = $item_value->val;
                    break;
                }
            }
            $result[$item->id] = $value;
        }
        return $result;
    }

    // FILE_URL : CS.INTNIP || '/' || CS.CDPROD || '/' || CS.INTNIPRO || '_' || CS.REVISION || CS.EXTENSION AS FILE_URL
    public function getURL($revision = null){
        $revision = ($this->revision === 0)
            ? ""
            : (($revision === null || $revision < 1 || $revision > $this->revision) ? $this->revision : $revision);
        return $this->base_url."/".$this->patient_id."/".$this->dossier_id."/".$this->id."_".$revision.$this->extension;
    }
}
