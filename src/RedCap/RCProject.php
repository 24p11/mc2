<?php
namespace MC2\RedCap;
class RCProject{

    public $name = '';
    public $longitudinal = true;
    public $main_instrument = null;
    public $main_instrument_only = false;
    public $arm_name = 'arm_1';
    public $shared_event_name = 'patient_arm_1';
    public $repeatable_event_name = 'cs_senologie_arm_1';
    public $shared_event_variable_count = 5; // nip, nom, prenom, datnai, sexe
    public $event_as_document_type = false;

    public function __construct($name,$main_instrument){
        $this->name = $name;
        $this->main_instrument = $main_instrument;
    }

    public function getUniqueSharedEventName(){
        return self::toLowerUnderscore($this->shared_event_name)."_".$this->arm_name;
    }

    public function getUniqueRepeatableEventName(){
        return self::toLowerUnderscore($this->repeatable_event_name)."_".$this->arm_name;
    }

    private static function toLowerUnderscore($string_to_lower){
        return str_replace(' ','_',mb_strtolower($string_to_lower));
    }
}