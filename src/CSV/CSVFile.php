<?php
namespace MC2\Core\CSV;
class CSVFile{

	public $file_name_prefix = null;
	public $lines = null;
	public $concatenate = false;

	public function __construct($file_name_prefix, $lines = array(), $concatenate = false){
		$this->file_name_prefix = $file_name_prefix;
		$this->lines = $lines;
		$this->concatenate = $concatenate;
	}
}