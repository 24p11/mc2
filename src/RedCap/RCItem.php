<?php
namespace SBIM\RedCap;
class RCItem{

    // Variable / Field Name
    public $id;
    // Form Name
    public $form_name;
    // Section Header
    public $section_header;
    // Field Type
    public $type;
    // Field Label
    public $label;
    // Choices, Calculations, OR Slider Labels
    public $choices;
    // Field Note
    public $note;
    // Text Validation Type OR Show Slider Number
    public $validation;
    // Text Validation Min
    public $validation_min = '';
    // Text Validation Max
    public $validation_max = '';
    // Identifier?
    public $identifier = '';
    // Branching Logic (Show field only if...)
    public $branching_logic = '';
    // Required Field?
    public $required = '';
    // Custom Alignment
    public $custom_alignment = '';
    // Question Number (surveys only)
    public $question_number = '';
    // Matrix Group Name
    public $matrix_group_name = '';
    // Matrix Ranking?
    public $matrix_ranking = '';
    // Field Annotation
    public $annotation = '';

    public $is_empty = false;

    public function __construct($mc_item,$section_header){
        $this->id = $mc_item['ITEM_ID'];
        $this->form_name = str_replace("'",'',iconv('UTF-8','ASCII//TRANSLIT',$mc_item['PAGE_LIBELLE']));
        $this->section_header = $section_header;
        $this->label = $mc_item['LIBELLE_BLOC'];
        $this->validation_min = '';
        $this->validation_max = '';
        $this->identifier = '';
        $this->branching_logic = '';
        $this->required = '';
        $this->custom_alignment = '';
        $this->question_number = '';
        $this->matrix_group_name = '';
        $this->matrix_ranking = '';
        $this->annotation = '';

        $rc_type = self::mctype_to_rctype($mc_item['MCTYPE'], $mc_item['OPTIONS'], $mc_item['LIST_VALUES']);
        $this->type = $rc_type['type'];
        $this->choices = $rc_type['choices'];
        $this->note = $rc_type['note'];
        $this->validation = $rc_type['validation'];
    }

    public static function create_from_db_item($db_item,$section_header){
    }

    // TODO lower case Variable names & Form name to remove warning in RC when importing DD
    public function to_array(){
        return [
            RCDictionnary::FIELD_NAME_INDEX => $this->id,
            RCDictionnary::FORM_NAME_INDEX => str_replace(' ','_',$this->form_name),
            RCDictionnary::SECTION_HEADER_INDEX => $this->section_header,
            RCDictionnary::FIELD_TYPE_INDEX => $this->type,
            RCDictionnary::FIELD_LABEL_INDEX => $this->label,
            RCDictionnary::CHOICES_INDEX => $this->choices,
            RCDictionnary::FIELD_NOTE_INDEX => $this->note,
            RCDictionnary::TEXT_VALIDATION_TYPE_INDEX => $this->validation,
            RCDictionnary::TEXT_VALIDATION_MIN_INDEX => $this->validation_min,
            RCDictionnary::TEXT_VALIDATION_MAX_INDEX => $this->validation_max,
            RCDictionnary::INDETIFIER_INDEX => $this->identifier,
            RCDictionnary::BRANCHING_INDEX => $this->branching_logic,
            RCDictionnary::REQUIRED_INDEX => $this->required,
            RCDictionnary::ALIGNMENT_INDEX => $this->custom_alignment,
            RCDictionnary::QUESTION_NUMBER_INDEX => $this->question_number,
            RCDictionnary::MATRIX_GROUP_INDEX => $this->matrix_group_name,
            RCDictionnary::MATRIX_RANKING_INDEX => $this->matrix_ranking,
            RCDictionnary::ANNOTATION_INDEX => $this->annotation
        ];
    }

    /**
	 * Transcode un type MiddleCare vers un type RedCap.
	 * 
	 * @param string $mc_type
	 * @param string $mc_options 
	 * @param string $liste_val
	 */
	private static function mctype_to_rctype($mc_type, $mc_options, $liste_val){
		$rc_type = ['type' => '', 'note' => '', 'validation' => '', 'choices' => ''];
		switch ($mc_type) {
			case 'BAC':
				$rc_type['type'] = 'yesno';
				break;
			case 'BT':
			case 'RESUME':
			case 'NOTES':
				$rc_type['type'] = 'notes';
				break;
			case 'LD':
				if(empty($liste_val)){
					$rc_type['type'] = 'text';
				}else{
					$rc_type['type'] = 'dropdown';
					$rc_type['choices'] = $liste_val;
				}
				break;
			case 'LDM':
				$rc_type['type'] = 'checkbox';
				$rc_type['choices'] = $liste_val;
				break;
			case 'TXT':
				$rc_type['type'] = 'text';
				if($mc_options === 'D_CALENDAR'){
					$rc_type['note'] = 'YYYY-MM-DD';
					$rc_type['validation'] = 'date_ymd';
				}
				break;
			case 'SEC':
				$rc_type['type'] = 'descriptive';
				break;
			default: 
				break;
		}
		return $rc_type;
	}

}