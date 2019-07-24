<?php
namespace SBIM\Core\Helper;
use ReflectionClass;
/**
 * Reflection Helper
 * 
 * http://yuml.me/diagram/scruffy/class/samples
 */
class ReflectionHelper{

    // ---- yUML ----
    
    public static function generateYumlClassDiagramDefinitionFromSQLSchema($schema){
		$definition = "";
        $in_table = false;
        $sql_file_as_lines = explode("\n",$schema);
        foreach ($sql_file_as_lines as $line) {
            switch(true){
                // START TABLE SCHEMA
                case preg_match("/CREATE TABLE IF NOT EXISTS `*([a-zA-Z0-9_]+)`*/", $line, $matches):
                    $table_name = $matches[1];
                    $in_table = true;
                    $definition .= "[$table_name|";
                    break;
                // END TABLE SCHEMA
                case preg_match("/\) ENGINE=/", $line, $matches):
                    $in_table = false;
                    $definition .= "],";
                    break;
                case !$in_table : 
                // INDEX
                case preg_match("/KEY `([a-zA-Z0-9_]+)` ([a-zA-Z0-9_\(\)]+)/", $line, $matches):
                    break;
                // FIELD
                case preg_match("/`([a-zA-Z0-9_]+)` ([a-zA-Z0-9_\(\)]+)/", $line, $matches):
                    $field_name = $matches[1];
                    $field_type = $matches[2];
                    $definition .= $field_name." ".$field_type.";";
                    break;
                default:
                    break;
            }
        }
        return $definition;
    }
}