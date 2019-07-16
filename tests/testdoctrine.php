<?php
require __DIR__.'/../vendor/autoload.php';
use Symfony\Component\Yaml\Yaml;
use Doctrine\DBAL\DriverManager;

$params = Yaml::parse(file_get_contents(__DIR__."/../config/config_db_middlecare.yml"));
var_dump($params);

$db_con = DriverManager::getConnection($params['doctrine']['dbal']);
$query = "SELECT CD_DOSSIER DSP_ID, NOM DSP_NOM, DESCRIPTION DSP_DESCRIPTION FROM middlecare.DOSSIER WHERE CD_DOSSIER LIKE 'DSP%' ORDER BY CD_DOSSIER";
$stmt = $db_con->prepare($query);
$stmt->execute();
while($row = $stmt->fetch()){
    var_dump($row);
}