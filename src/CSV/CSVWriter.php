<?php
namespace MC2\Core\CSV;
use \DateTime;
class CSVWriter{

    private $output_folder = null;
    private $options = null;
    
    public function __construct($options, $logger){
		$this->output_folder =  __DIR__."/../../data";
		$this->setOptions($options);
		$this->logger = $logger;
	}

	/**
	 * @params \SBIM\Core\CSV\CSVOption $options 
	 */
	public function setOptions($options){
		$this->options = ($options === null) ? self::getDefaultOptions() : $options; 
	}

	/**
	 * @return array options par défaut
	 */
	public static function getDefaultOptions(){
		return new CSVOption();
    }
    
    /**
	 * @return string file_name 
	 */
    public function save($csv){
        $excel_friendly = $this->options->excel_friendly;
        $delimiter = $excel_friendly ? ";" : ",";
        $enclosure = '"';
        
        $now = new DateTime();
        $file_name = $csv->file_name_prefix."_v1_".$now->format('Y-m-d_His').($excel_friendly === true ? "_e" : "").".csv";
        $file_path = $this->output_folder."/{$file_name}";

        $csv_file = @fopen($file_path, 'wb');
        // add BOM to force UTF-8 in Excel
        if($excel_friendly === true)
            @fputs($csv_file, $bom = (chr(0xEF).chr(0xBB).chr(0xBF)));

        $lines = $csv->lines;
        $count_lines = count($lines);
        if($count_lines > 0){
            if($csv->concatenate === false){
                // insert headers in first line
                $first_element = array_slice($lines, 0,1);
                $keys = array_keys(array_pop($first_element));
                $keys = array_map('strtolower', $keys);	
                @fputcsv(
                    $csv_file, 
                    $excel_friendly ? array_map(function($v){ return '="'.$v.'"';}, $keys) : $keys,
                    $delimiter, 
                    $enclosure
                );
            }
            // insert data
            foreach ($lines as $line) {
                $clean_line = array();
                foreach($line as $key => $val){
                    $clean_string = str_replace("\r\n"," ",$val);
                    $clean_string = str_replace("\r"," ",$clean_string);
                    if($this->options->remove_html === true){
                        $clean_string = strip_tags($clean_string);
                    }
                    $clean_line[$key] = $clean_string;
                }
                @fputcsv(
                    $csv_file, 
                    $excel_friendly ? array_map(function($v){ return '="'.$v.'"';}, $clean_line) : $clean_line,
                    $delimiter, 
                    $enclosure
                );
            }
        }
        @fclose($csv_file);
        return $file_name;
    }
}