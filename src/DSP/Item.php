<?php 
namespace MC2\DSP;
use \DateTime;
class Item{

    public $id;
    public $type;
    public $mctype;
    public $ligne;
    public $libelle;
    public $libelle_bloc;
    public $libelle_secondaire;
    public $detail;
    public $type_controle;
    public $formule;
    public $options;
    public $list_nom;
    public $list_values;
    public $dossier_id;
    public $site;
    public $page_nom;
    public $page_libelle;
    public $bloc_no;
    public $bloc_libelle;
    
    public $created;
    public $modified;
    public $version;

    public $document_type;

    public function __construct($data = null){
        if(is_array($data)){
            if(isset($data['item_id']))
                $this->id = $data['item_id'];
            $this->dossier_id = $data['dossier_id'];
            $this->site = $data['site'];
            $this->type = $data['type'];
            $this->mctype = $data['mctype'];
            $this->ligne = $data['ligne'];
            $this->libelle = $data['libelle'];
            $this->libelle_bloc = $data['libelle_bloc'];
            $this->libelle_secondaire = $data['libelle_secondaire'];
            $this->detail = $data['detail'];
            $this->type_controle = $data['type_controle'];
            $this->formule = $data['formule'];
            $this->options = $data['options'];
            $this->list_nom = $data['list_nom'];
            $this->list_values = $data['list_values'];
            $this->page_nom = $data['page_nom'];
            $this->page_libelle = $data['page_libelle'];
            $this->bloc_no = $data['bloc_no'];
            $this->bloc_libelle = $data['bloc_libelle'];
            $this->created = new DateTime($data['created']);
            $this->modified = new DateTime($data['modified']);
            $this->version = $data['version'];
        }
    }

    // FROM MC Repository
    // [DSP_ID, PAGE_NOM, PAGE_LIB, BLOC_NO, BLOC_LIB, ITEM_LIGNE, ITEM_ID, ITEM_TYPE, ITEM_MCTYPE, ITEM_LIB, ITEM_LIB_BLOC, ITEM_LIB_SECONDAIRE, DETAIL, TYP_CRTL, FORMULE, OPTIONS, LISTE_NOM, LISTE_VAL]
    public static function createFromMCData($data){
        $item = new Item();
        $item->id = $data['ITEM_ID'];
        $item->dossier_id = $data['DOSSIER_ID'];
        $item->site = $data['SITE'];
        $item->type = $data['TYPE'];
        $item->mctype = $data['MCTYPE'];
        $item->ligne = $data['LIGNE'];
        $item->libelle = $data['LIBELLE'];
        $item->libelle_bloc = $data['LIBELLE_BLOC'];
        $item->libelle_secondaire = $data['LIBELLE_SECONDAIRE'];
        $item->detail = $data['DETAIL'];
        $item->type_controle = $data['TYPE_CONTROLE'];
        $item->formule = $data['FORMULE'];
        $item->options = $data['OPTIONS'];
        $item->list_nom = $data['LIST_NOM'];
        $item->list_values = $data['LIST_VALUES'];
        $item->page_nom = $data['PAGE_NOM'];
        $item->page_libelle = $data['PAGE_LIBELLE'];
        $item->bloc_no = $data['BLOC_NO'];
        $item->bloc_libelle = $data['BLOC_LIBELLE'];
        return $item;
    }

    public function toMCArray(){
        $result = array();
        $result['DOSSIER_ID'] = $this->dossier_id;
        $result['SITE'] = $this->site;
        $result['PAGE_NOM'] = $this->page_nom;
        $result['PAGE_LIBELLE'] = $this->page_libelle;
        $result['BLOC_NO'] = $this->bloc_no;
        $result['BLOC_LIBELLE'] = $this->bloc_libelle;
        $result['LIGNE'] = $this->ligne;
        $result['ITEM_ID'] = $this->id;
        $result['TYPE'] = $this->type;
        $result['MCTYPE'] = $this->mctype;
        $result['LIBELLE'] = $this->libelle;
        $result['LIBELLE_BLOC'] = $this->libelle_bloc;
        $result['LIBELLE_SECONDAIRE'] = $this->libelle_secondaire;
        $result['DETAIL'] = $this->detail;
        $result['TYPE_CONTROLE'] = $this->type_controle;
        $result['FORMULE'] = $this->formule;
        $result['OPTIONS'] = $this->options;
        $result['LIST_NOM'] = $this->list_nom;
        $result['LIST_VALUES'] = $this->list_values;
        return $result;
    }

    public function isList(){
        return $this->mctype === "LD";
    }

    public static function getIndexFromChoiceValue($value, $values_as_string){
        $choices = explode('|', $values_as_string);
		foreach ($choices as $choice) {
			if(preg_match("/([^,]+), (.*)/",$choice,$matches) > 0){
                if($matches[2] === $value)
                    return $matches[1];
            }
		}
		return null;
    }
}
