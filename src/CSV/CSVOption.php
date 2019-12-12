<?php
namespace MC2\Core\CSV;
class CSVOption{

    // si true => CSV généré avec séparateur ; et valeur des cellules sous la forme ="valeur"
    public $excel_friendly = false;
    // si true => discard all html entities in every cell
    public $remove_html = false;

    public function __construct($excel_friendly, $remove_html = false){
        $this->excel_friendly = $excel_friendly;
        $this->remove_html = $remove_html;
    }
}