<?php
namespace MC2\RedCap;
use MC2\Core\Helper\ArrayHelper;
class RCDictionnary{

	const FIELD_NAME_INDEX = 'Variable / Field Name';
	const FORM_NAME_INDEX = 'Form Name';
	const SECTION_HEADER_INDEX = 'Section Header';
	const FIELD_TYPE_INDEX = 'Field Type';
	const FIELD_LABEL_INDEX = 'Field Label';
	const CHOICES_INDEX = 'Choices, Calculations, OR Slider Labels';
	const FIELD_NOTE_INDEX = 'Field Note';
	const TEXT_VALIDATION_TYPE_INDEX = 'Text Validation Type OR Show Slider Number';
	const TEXT_VALIDATION_MIN_INDEX = 'Text Validation Min';
	const TEXT_VALIDATION_MAX_INDEX = 'Text Validation Max';
	const INDETIFIER_INDEX = 'Identifier?';
	const BRANCHING_INDEX = 'Branching Logic (Show field only if...)';
	const REQUIRED_INDEX = 'Required Field?';
	const ALIGNMENT_INDEX = 'Custom Alignment';
	const QUESTION_NUMBER_INDEX = 'Question Number (surveys only)';
	const MATRIX_GROUP_INDEX = 'Matrix Group Name';
	const MATRIX_RANKING_INDEX = 'Matrix Ranking?';
	const ANNOTATION_INDEX = 'Field Annotation';

    public $dsp_id;
	public $items;
	public $project;

    public function __construct($dsp_id){
        $this->dsp_id = $dsp_id;
        $this->items = array();
    }
	
	public static function createFromItemsAndRCProject($dsp_id, array $mc_items, $rc_project){
		$rc_dictionnay = new self($dsp_id);
		$rc_dictionnay->project = $rc_project;
		
		$where = array('ITEM_ID' => $rc_project->main_instrument->item_names);
		// set order of dictionnary fields
        $ordered_dsp_items = array_merge(
            // main instrument shared fields 
            self::getSharedItems($dsp_id, $rc_project->main_instrument->name, $rc_project->longitudinal),
            // main instrument fields
            ArrayHelper::updateValuesOfKey(ArrayHelper::filter($mc_items,null,$where,false), 'PAGE_LIBELLE', $rc_project->main_instrument->name),
            // other fields
            ($rc_project->main_instrument_only === true ? [] : ArrayHelper::filter($mc_items,null,$where,true))
        );
        $redcap_data = array();
        $main_instrument_section_headers = array();
        $other_instruments_section_headers = array();
        foreach ($ordered_dsp_items as $item) {
			// set section headers for first field of bloc (for both main instrument and other instruments)
			if(!in_array($item['BLOC_LIBELLE'], $other_instruments_section_headers) && $item['PAGE_LIBELLE'] !== $rc_project->main_instrument->name && $rc_project->main_instrument_only === false){
                $section_header = $item['BLOC_LIBELLE'];
                $other_instruments_section_headers[] = $section_header;
            }elseif(!in_array($item['BLOC_LIBELLE'], $main_instrument_section_headers) && $item['PAGE_LIBELLE'] === $rc_project->main_instrument->name) {
                $section_header = $item['BLOC_LIBELLE'];
                $main_instrument_section_headers[] = $section_header;
            }else{
                $section_header = '';
            }
            $rc_item = new RCItem($item,$section_header);
            $rc_dictionnay->items[$rc_item->id] = $rc_item->toArray();   
        }
        return $rc_dictionnay;
	}

	public static function createFromItemsAndRCProjectWithPages($dsp_id, array $mc_items, $rc_project){
		$rc_dictionnay = new self($dsp_id);
		$rc_dictionnay->project = $rc_project;
		
		$where = array('ITEM_ID' => $rc_project->main_instrument->item_names);
		// set order of dictionnary fields
        $ordered_dsp_items = array_merge(
            // main instrument shared fields 
            self::getSharedItems($dsp_id, $rc_project->main_instrument->name, $rc_project->longitudinal),
            // main instrument fields
            ArrayHelper::updateValuesOfKey(ArrayHelper::filter($mc_items,null,$where,false), 'PAGE_LIBELLE', $rc_project->main_instrument->name),
            // other fields
            ($rc_project->main_instrument_only === true ? [] : ArrayHelper::filter($mc_items,null,$where,true))
		);

        $redcap_data = array();
        $main_instrument_section_headers = array();
        $other_instruments_section_headers = array();
        foreach ($ordered_dsp_items as $item) {
			// set section headers for first field of bloc (for both main instrument and other instruments)
			if(!in_array($item['BLOC_LIBELLE'], $other_instruments_section_headers) && $item['PAGE_LIBELLE'] !== $rc_project->main_instrument->name && $rc_project->main_instrument_only === false){
                $section_header = $item['BLOC_LIBELLE'];
                $other_instruments_section_headers[] = $section_header;
            }elseif(!in_array($item['BLOC_LIBELLE'], $main_instrument_section_headers) && $item['PAGE_LIBELLE'] === $rc_project->main_instrument->name) {
                $section_header = $item['BLOC_LIBELLE'];
                $main_instrument_section_headers[] = $section_header;
            }else{
                $section_header = '';
            }
            $rc_item = new RCItem($item,$section_header);
			$rc_dictionnay->items[] = $rc_item->toArray();
        }
        return $rc_dictionnay;
	}

	// Retourne toutes les colonnes du data dictionnnary
	public function getColumnNames(){
		$result = array();
		foreach($this->items as $key => $value){
			if($this->items[$key][self::FIELD_TYPE_INDEX] === 'checkbox'){
				$choices = self::getChoiceValues($this->items[$key][self::CHOICES_INDEX]);
				foreach ($choices as $k => $v)
					$result[] = self::cleanItemName($this->items[$key][self::FIELD_NAME_INDEX])."___".$k;
			}else{
				$result[] = self::cleanItemName($this->items[$key][self::FIELD_NAME_INDEX]);
			}
		}
		return array_unique($result);
	}

	public static function cleanItemName($var_name){
		$result = str_replace(" ",'_',$var_name);
		$result = str_replace(['à','é','è',"'"],'',$result);
		return $result;
	}

	public function getFormNames(){
		return array_unique(array_column($this->items, 'Form Name'));
	}

	public static function getChoiceValues($liste_values){
		$result = array();
		$choices = explode('|', $liste_values);
		foreach ($choices as $choice) {
			if(preg_match("/([^,]+), (.*)/",$choice,$matches) > 0)
				$result[$matches[1]] = $matches[2];
		}
		return $result;
	}
	
	public static function getIndexInValues($value,$liste_values){
		$value = is_null($value) ? "" : $value;
		$choices = explode('|', $liste_values);
		foreach ($choices as $choice) {
			if(preg_match("/([^,]+), (.*)/",$choice,$matches) > 0 && $matches[2] === $value)
				return $matches[1];
		}
		return null;
	}

	public function searchItem($var_name,$document_type = null){
		if($this->project->event_as_document_type === false){
			return $this->items[$var_name];
		}else{
			$var_name_event = $var_name. "_".$document_type;
			foreach ($this->items as $item) {
				$item_found = ($var_name === $item[self::FIELD_NAME_INDEX])
					|| (substr($item[self::FIELD_NAME_INDEX], 0, strlen($var_name_event)) === $var_name_event)
					|| (substr($item[self::FIELD_NAME_INDEX], 0, strlen($var_name)) === $var_name);
				if($item_found === true)
					return $item;
			}
		}
		return null;
	}

    // ---- Private Helpers

	// TODO refactor this 
	private static function getSharedItems($dsp_id, $main_instrument_name, $longitudinal = true){
		$bloc_no = '1';
		// order for longitudinal RC project
		$shared_instruments_items = ($longitudinal === true) 
			? array(
				array('page_name' => 'SHAREDINSTRUMENT', 'page_lib' => 'Patient', 'items' => array(
					'IPP' => 'IPP',
					'NIP' => 'NIP',
					'NOM' => 'Nom',
					'PRENOM' => 'Prénom',
					'DATNAI' => 'Date de naissance',
					'SEXE' => 'Sexe'
				)),
				array('page_name' => 'MAININSTRUMENT', 'page_lib' => $main_instrument_name, 'items' => array(
					'NIPRO' => 'NIPRO',
					'AGE' => 'Age',
					'POIDS' => 'Poids',
					'TAILLE' => 'Taille',
					'TYPE_EXAM' => 'Type Examen',
					'VENUE' => 'N° Venue',
					'DATE_EXAM' => 'Date Examen',
					'DATE_MAJ' => 'Date MAJ',
					'OPER' => 'Opérateur'
				))
			)
			: array(
				array('page_name' => 'SHAREDINSTRUMENT', 'page_lib' => $main_instrument_name, 'items' => array(
					'NIPRO' => 'NIPRO',
					'IPP' => 'IPP',
					'NIP' => 'NIP',
					'NOM' => 'Nom',
					'PRENOM' => 'Prénom',
					'DATNAI' => 'Date de naissance',
					'SEXE' => 'Sexe',
					'AGE' => 'Age',
					'POIDS' => 'Poids',
					'TAILLE' => 'Taille',
					'TYPE_EXAM' => 'Type Examen',
					'VENUE' => 'N° Venue',
					'DATE_EXAM' => 'Date Examen',
					'DATE_MAJ' => 'Date MAJ',
					'OPER' => 'Opérateur'
				))
			);
		$mcgv = array();
		foreach($shared_instruments_items as $instrument_items){
			$page_name = $instrument_items['page_name'];
			$page_lib = $instrument_items['page_lib'];
			foreach($instrument_items['items'] as $item_name => $item_libelle){
				$options = (substr($item_libelle, 0, 4) === "Date") ? "D_CALENDAR": '';
				$mcgv [] = array(
					'DOSSIER_ID' => $dsp_id,
					'PAGE_NOM' => $page_name,
					'PAGE_LIBELLE' => $page_lib,
					'BLOC_NO' => $bloc_no,
					'BLOC_LIBELLE' => '',
					'LIGNE' => '', 
					'ITEM_ID' => $item_name,
					'TYPE' => 'ITEMCHAR2',
					'MCTYPE' => 'TXT', 
					'LIBELLE' => $item_libelle,
					'LIBELLE_BLOC' => $item_libelle,
					'LIBELLE_SECONDAIRE' => $item_libelle,
					'DETAIL' => '',
					'TYPE_CONTROLE' => '',
					'FORMULE' => '',
					'OPTIONS' => $options,
					'LIST_NOM' => '',
					'LIST_VALUES' => ''
				);
			}
		}
		return $mcgv;
	}
}