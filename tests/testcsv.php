<?php 
$file =  fopen(__DIR__."/../data/controle_t2a_2019_clean.csv", "r");
$all_rows = [];
$header = fgetcsv($file,0,';');
while ($row = fgetcsv($file,0,';'))
  $all_rows[] = array_combine($header, $row);
print_r($all_rows);
fclose($file)
?>