<?php
namespace MC2\RedCap;
class RCInstrument{

    public $name = '';
    public $item_names = null;

    public function __construct($name, array $item_names = null){
        $this->name = $name;
        $this->item_names = $item_names === null ? [] : $item_names;
    }
}