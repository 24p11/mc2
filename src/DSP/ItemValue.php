<?php 
namespace SBIM\DSP;
use \DateTime;
use SBIM\Core\Helper\DateHelper;
class ItemValue{

    public $id; // NIPRO
    public $patient_id;
    public $dossier_id;
    public $page_nom;
    public $var;
    public $val;
    
    public $created;
    public $modified;
    public $version;

    public function __construct($data = null){
        if(is_array($data)){
            if(isset($data['nipro']))
                $this->id = $data['nipro'];
                
            $this->patient_id = $data['patient_id'];
            $this->dossier_id = $data['dossier_id'];
            $this->page_nom = $data['page_nom'];
            $this->var = $data['var'];
            $this->val = $data['val'];

            $this->created = DateTime::createFromFormat(DateHelper::MYSQL_FORMAT,$data['created']);
            $this->modified = DateTime::createFromFormat(DateHelper::MYSQL_FORMAT,$data['modified']);
            $this->version = $data['version'];
        }
    }

    public static function createFromMCData($dsp_id,$item,$mc_data){
        $item_value = new ItemValue();
        $item_value->dossier_id = $dsp_id;
        if(is_array($mc_data)){
            $item_value->id = $mc_data['NIPRO'];
            $item_value->patient_id = $mc_data['NIP'];
            $item_value->page_nom = $item['PAGE_NOM'];
            $item_value->var = $item['ITEM_ID'];
            $item_value->val = $mc_data[$item['ITEM_ID']];
        }
        return $item_value;
    }
}
