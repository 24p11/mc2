<?php 
namespace MC2\DSP;
use \DateTime;
use MC2\Core\Helper\DateHelper;
class ItemValue{

    public $id; // NIPRO
    public $patient_id;
    public $dossier_id;
    public $page_nom;
    public $var;
    public $val;
    public $list_index;
    
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
            $this->list_index = $data['list_index'];

            $this->created = DateTime::createFromFormat(DateHelper::MYSQL_FORMAT,$data['created']);
            $this->modified = DateTime::createFromFormat(DateHelper::MYSQL_FORMAT,$data['modified']);
            $this->version = $data['version'];
        }
    }

    // NEW now filling list_index when necessary 
    public static function createFromMCData($dsp_id,$item_mc_data,$mc_data, $list_item_as_value = true){
        $item_value = new ItemValue();
        $item_value->dossier_id = $dsp_id;
        if(is_array($mc_data)){
            $item_value->id = $mc_data['NIPRO'];
            $item_value->patient_id = $mc_data['NIP'];
            $item_value->page_nom = $item_mc_data['PAGE_NOM'];
            $item_value->var = $item_mc_data['ITEM_ID'];
            $item_value->val = isset($mc_data[$item_mc_data['ITEM_ID']]) ? $mc_data[$item_mc_data['ITEM_ID']] : null;
            // NEW si $item est de type list, mettre index dans list_index
            if($list_item_as_value === true && $item_mc_data['MCTYPE'] === 'LD'){
                $item_value->list_index = Item::getIndexFromChoiceValue($item_value->val, $item_mc_data['LIST_VALUES']);
            }
        }
        return $item_value;
    }
}
