<?php
namespace SBIM\Core\Helper;
class ArrayHelper{

	public static function issetor($var,$default = ""){
		return isset($var) ? $var : $default;
	}
	public static function filter(array $array_to_filter, array $keys_to_keep = null, array $where = null, $not = false){
		$result = array();
		foreach($array_to_filter as $row) {
			// filtering rows by value
			if($where !== null){
				foreach ($where as $key => $values){
					if(!key_exists($key,$row) || (!in_array($row[$key], $values) === !$not))
						continue 2; // skip the current $row
				}	
			}
			// filtering columns
			if($keys_to_keep === null){
				$result[] = $row;
			}else{
				$tmp = array();
				foreach($keys_to_keep as $key_to_keep){
					if(key_exists($key_to_keep,$row))
						$tmp[$key_to_keep] = $row[$key_to_keep];
				}
				$result[] = $tmp;
			}
		}
		return $result;
	}

	public static function reorderColumns(array $input, array $ordered_column_names){
		$ordered_array = array();
		foreach($ordered_column_names as $k){
			$ordered_array[$k] = array_key_exists($k, $input)
				? $input[$k]
				: '';
		}
		return $ordered_array;
	}

	public static function updateValuesOfKey(array $array_to_update,$key_to_update,$new_value){
		array_walk($array_to_update, function (&$item, $k) use($key_to_update, $new_value) {
			$item[$key_to_update] = $new_value;
		});
		return $array_to_update;
	}
}