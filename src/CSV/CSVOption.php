<?php
namespace SBIM\Core\CSV;
class CSVOption{

    // si true => CSV généré avec séparateur ; et valeur des cellules sous la forme ="valeur"
    public $excel_friendly = false;

    public function __construct($excel_friendly){
        $this->excel_friendly = $excel_friendly;
    }
}