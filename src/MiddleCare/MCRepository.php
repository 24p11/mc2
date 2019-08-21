<?php
namespace SBIM\MiddleCare;
use \PDO;
use SBIM\Core\Helper\DateHelper;
use Doctrine\DBAL\DriverManager;
// ================================================================================
// class MCRepository 
// 
// Gestion de l'accès à la bdd de MiddleCare
// - récuperation de la structure et des données d'un DSP
//
// NOTE : impossible d'utiliser PDO (avec le driver pdo_oci) pour récupérer les valeurs des DSP dans MiddleCare (Oracle 10g). 
// Dès que le type des données est un CLOB, l'utilisation de fetch provoque des 
// "ORA-01405: la valeur de colonne extraite n'est pas renseignée (NULL)" et autres seg fault.
// ex: $liste_items = ["ITEM2139","ITEM1270"]; // CLOB dans DSP2.CHAPITRE48
// ================================================================================
class MCRepository{

    private $db_middlecare = null;
    private $logger;
    private $site;

    /**
     * @param string $params DSN MiddleCare (Oracle)
     * @param Monolog\Logger $logger
     */
    public function __construct($params,$logger,$site){
        $this->db_middlecare = DriverManager::getConnection($params['doctrine']['dbal']);
        $this->logger = $logger;
        $this->site = $site;
    }

    public function checkConnection(){
        try{
            if ($this->db_middlecare->connect())
                return true;
        } 
        catch (\Exception $e) {
            $this->logger>addError("Can't connect to MCRepository DB", array('exception' => $e));
            return false;
        }
    }

    /**
     * Retourne les DSP (DSP, DDS et DSC) existants dans MiddleCare.
     * 
     * @return array [DOSSIER_ID, NOM, LIBELLE]
     */
    public function getAllDSP(){
        $query = "SELECT CD_DOSSIER DOSSIER_ID, NOM NOM, DESCRIPTION LIBELLE, lower(SUBSTR(CD_HOP,1,3)) SITE, CD_UF UHS 
            FROM middlecare.DOSSIER 
            WHERE CD_DOSSIER LIKE 'D%' ORDER BY CD_DOSSIER";
        return $this->executeQuery($query);
    }
    
    /**
     * Retourne les pages disponibles par type de document pour un DSP.
     * 
     * @return array [SITE, DOSSIER_ID,DOCUMENT_TYPE, PAGE_LIBELLE, PAGE_CODE, PAGE_ORDRE]
     */
    public function getDSPPages($dsp_id){
        $query = "SELECT '".$this->site."' SITE, 
            upper('{$dsp_id}') DOSSIER_ID, 
            PROCEDURE DOCUMENT_TYPE, 
            CHAPITRE PAGE_LIBELLE, 
            CD_PGE PAGE_CODE, 
            ORDRE_LISTE PAGE_ORDRE
            FROM {$dsp_id}.CHAPITRE
            ORDER BY DOCUMENT_TYPE, PAGE_ORDRE";
        return $this->executeQuery($query);
    }

    /**
     * Retourne les items d'un DSP.
     * 
     * @param string $dsp_id identifiant du DSP, ex: 'DSP2'
     * @param string[] $item_names (option) liste des noms des items à garder (tout si null)
     * @param array $page_name (option)
     * @return array [DSP_ID,PAGE_NOM,PAGE_LIB,BLOC_NO,BLOC_LIB,ITEM_LIGNE,ITEM_ID,ITEM_TYPE,ITEM_MCTYPE,ITEM_LIB,ITEM_LIB_BLOC,ITEM_LIB_SECONDAIRE,DETAIL,TYP_CRTL,FORMULE,OPTIONS,LISTE_NOM]
     */
    public function getDSPItems($dsp_id, array $item_names = null, $page_name = null){
        $query_items = ($item_names === null || count($item_names) < 1) 
            ? "" : "AND all_col.column_name in(".join(',',array_map(function($v){ return "'".$v."'"; },$item_names)).")";
        $query_page = ($page_name === null)
            ? "" : " AND all_col.table_name = '".strtoupper($page_name)."'";
        $query = "SELECT DISTINCT '".$this->site."' SITE, all_col.owner DOSSIER_ID,
            upper(pag.NM_PGE) PAGE_NOM, 
            pag.LB_PGE PAGE_LIBELLE,
            blc.NO_BLC BLOC_NO,
            blc.NM_BLC BLOC_LIBELLE,
            blc.LIGNE LIGNE,
            all_col.column_name ITEM_ID,
            all_col.data_type TYPE,
            blc.TP_OBJ MCTYPE,
            obj.lb_obj LIBELLE, 
            blc.LB_OBJ LIBELLE_BLOC,
            blc.LB_SECONDAIRE LIBELLE_SECONDAIRE,
            upper(blc.DETAIL) DETAIL, 
            blc.TYP_CRTL TYPE_CONTROLE,
            blc.FORMULE FORMULE,
            blc.OPTIONS OPTIONS,
            blc.LD_NOM LIST_NOM
            FROM all_tab_columns all_col, {$dsp_id}.NOM_OBJET obj, {$dsp_id}.BLOC blc, {$dsp_id}.PAGE pag
            WHERE all_col.owner = '{$dsp_id}'
            AND upper(trim(all_col.COLUMN_NAME)) = upper(trim(blc.NM_OBJ))
            AND upper(trim(all_col.COLUMN_NAME)) = upper(trim(obj.NOM_OBJET))
            AND upper(pag.NM_PGE) = upper(all_col.table_name)
            {$query_items}
            {$query_page}
            ORDER BY DOSSIER_ID, PAGE_NOM, BLOC_NO, LIGNE";
        // blc.DEFAUT ITEM_DEFAUT, // PROVOQUE ERREUR ORACLE CLOB...
        
        $item_infos = $this->executeQuery($query);

        // pour chaque item associé à une fiche de détail, ajouter les items de la fiche aux autres items
        $all_details = array_unique(array_column($item_infos, 'DETAIL'));
        $fiches = array();
        foreach ($all_details as $value) {
            if(mb_substr($value, 0, 5 ) === "fiche")
                $fiches[] = $value;
        }
        $query_fiches = ($fiches === null || count($fiches) < 1) 
            ? "" : "AND upper(blc.NOM_TABLE) in(".join(',',array_map(function($v){ return "'".$v."'"; },$fiches)).")";

        $query_fiche_item = "SELECT DISTINCT '".$this->site."' SITE, all_col.owner DOSSIER_ID,
            upper(blc.NOM_TABLE) PAGE_NOM,
            blc.NM_FICHE PAGE_LIBELLE,
            blc.NO_BLCD BLOC_NO,
            blc.NM_BLCD BLOC_LIBELLE,
            blc.LIGNED LIGNE,
            upper(blc.NM_OBJD) ITEM_ID,
            all_col.data_type TYPE,
            blc.TP_OBJD MCTYPE,
            '' LIBELLE,
            blc.LB_OBJD LIBELLE_BLOC,
            blc.LB_SECONDAIRE LIBELLE_SECONDAIRE,
            '' DETAIL,
            blc.TP_CRTL TYPE_CONTROLE,
            blc.FORMULE FORMULE,
            blc.OPTIONS OPTIONS,
            blc.LD_NOMD LIST_NOM
            FROM all_tab_columns all_col, {$dsp_id}.DETAIL blc
            WHERE all_col.owner = '{$dsp_id}'
            AND upper(trim(all_col.COLUMN_NAME)) = upper(trim(blc.NM_OBJD))
            {$query_items}
            {$query_fiches}
            ORDER BY DOSSIER_ID, PAGE_NOM, BLOC_NO, LIGNE";

        $item_infos_fiche = $this->executeQuery($query_fiche_item);
        $item_infos = array_merge($item_infos, $item_infos_fiche);

        // ajouter les valeurs possibles pour les items de type liste
        foreach($item_infos as $key => $item_info){
            if(empty($item_info['LIST_NOM'])){
                $item_infos[$key]['LIST_VALUES'] = '';
                continue;
            }
            $liste_info = $this->getListeValues($dsp_id, $item_info['LIST_NOM']);
            $tmp = array();
            foreach ($liste_info['values'] as $k => $v)
                $tmp[] = "{$k}, {$v['value']}";
            $item_infos[$key]['LIST_VALUES'] = join('|', $tmp);
        }
        return $item_infos;
    }

    /**
     * Retourne les différentes valeurs d'une Liste (item de type liste).
     * 
     * @param string $dsp_id identifiant du DSP, ex: 'DSP2'
     * @param string $liste_name
     * @return array [LISTE_NOM, LISTE_DESCRIPTION, LISTE_VAL, LISTE_VAL_INDEX, LISTE_VAL_IS_DEFAULT]
     */
    public function getListeValues($dsp_id, $liste_name){
        if(mb_substr($liste_name, 0, 3 ) !== "DSP")
            return [ 'description' => $liste_name, 'values' => array() ];

        $query = "SELECT NM_LD LISTE_NOM, 
            DESCRIPTION LISTE_DESCRIPTION, 
            LB_ITM LISTE_VAL, 
            NO_ITM LISTE_VAL_INDEX, 
            DEFAUT LISTE_VAL_IS_DEFAULT
            FROM {$dsp_id}.LISTE
            WHERE NM_LD = '{$liste_name}'
            ORDER BY LISTE_VAL_INDEX";

        $rows = $this->executeQuery($query);
        $result = array('description' => '', 'values' => array());
        foreach($rows as $row){
            $result['description'] = $row['LISTE_DESCRIPTION'];
            $result['values'][$row['LISTE_VAL_INDEX']] = array(
                'value' => $row['LISTE_VAL'], 
                'is_default' => $row['LISTE_VAL_IS_DEFAULT']
            );
        }
        return $result;
    }

    /**
     * Retourne l'ensemble des IPP des patients dans un DSP et une période donnée.
     * 
     * @param string $dsp_id identifiant du DSP, ex: 'DSP2'
     * @param \DateTime $date_debut
     * @param \DateTime $date_fin
     * @return string[] liste d'IPP
     */
    public function getAllIPP($dsp_id, $date_debut, $date_fin){
        $query = "SELECT DISTINCT ETB.ID_PATIENT_ETB as IPP
            FROM {$dsp_id}.INCLUSION_PROCEDURE IP
            LEFT JOIN middlecare.INCLUSION INCL ON INCL.NIP = IP.NIP
            LEFT JOIN middlecare.INCLUSION_ETB ETB ON ETB.INTNIP = INCL.INTNIP
            WHERE IP.DT_PRO >= to_date('".$date_debut->format("d-m-Y")."','DD-MM-YYYY')
            AND IP.DT_PRO < to_date('".$date_fin->format("d-m-Y")."','DD-MM-YYYY')";
        $result = array_column($this->executeQuery($query),'IPP');
        $this->logger->addInfo("Retrieved all IPPs", array('dsp_id' => $dsp_id, 'row_count' => count($result)));
        return $result;
    }
    
    /**
     * Retourne les données d'un DSP pour une période donnée.
     * 
     * @param string $dsp_id identifiant du DSP, ex: 'DSP2'
     * @param \DateTime $date_debut
     * @param \DateTime $date_fin
     * @param string[] $item_names (option)
     * @param string $page_name (option)
     * @return array [NIPRO,IPP,NIP,NOM,PRENOM,DATNAI,SEXE,AGE,POIDS,TAILLE,TYPE_EXAM,VENUE,DATE_EXAM,DATE_MAJ,OPER,REVISION,EXTENSION,CATEG,CR_PROVISOIRE,SERVICE,...ITEMS DU DSP...]
     */
    public function getDSPData($dsp_id, $date_debut, $date_fin, array $items){    
        $every_page = array_unique(array_column($items, 'PAGE_NOM'));
        
        $query_items_select = "";
        foreach($items as $item)
            $query_items_select .= ", {$dsp_id}.{$item['PAGE_NOM']}.{$item['ITEM_ID']}";
        
        $query_items_from = "";
        foreach($every_page as $p_name)
            $query_items_from .= " LEFT JOIN {$dsp_id}.{$p_name} ON {$dsp_id}.{$p_name}.NIPRO = IP.NIPRO AND INCL.NIP = {$dsp_id}.{$p_name}.NIP";

        $query_get_dsp = "SELECT IP.NIPRO, 
            INCLETB.ID_PATIENT_ETB AS IPP, 
            IP.NIP, 
            INCL.NOM, 
            INCL.PNOM AS PRENOM, 
            to_char(INCL.DATNAI,'YYYY-MM-DD') AS DATNAI,
            INCL.SEXE, 
            IP.AGE_DTPRO AS AGE, 
            IP.POIDS, 
            IP.TAILLE, 
            IP.TP_EXM AS TYPE_EXAM, 
            IP.VENUE, 
            to_char(IP.DT_PRO,'YYYY-MM-DD') AS DATE_EXAM, 
            to_char(IP.DT_MAJ,'YYYY-MM-DD') AS DATE_MAJ, 
            IP.OPER,
            CS.REVISION, 
            CS.EXTENSION, 
            CS.CATEG, 
            CS.CR_PROVISOIRE, 
            IP.SERVICE
            {$query_items_select}
            FROM MIDDLECARE.INCLUSION INCL
            INNER JOIN {$dsp_id}.INCLUSION_PROCEDURE IP ON IP.NIP = INCL.NIP
            LEFT JOIN MIDDLECARE.INCLUSION_ETB INCLETB ON INCLETB.INTNIP = INCL.INTNIP
            LEFT JOIN MIDDLECARE.CONSULTATION CS ON CS.INTNIPRO = IP.INTNIPRO AND CS.CDPROD = '{$dsp_id}'
            {$query_items_from} 
            WHERE IP.DT_PRO >= to_date('".$date_debut->format("d-m-Y")."','DD-MM-YYYY')
            AND IP.DT_PRO < to_date('".$date_fin->format("d-m-Y")."','DD-MM-YYYY')
            ORDER BY IP.NIP";

        $this->logger->addDebug("query_get_dsp", array('query' => $query_get_dsp));
        $result = $this->executeQuery($query_get_dsp);
        $this->logger->addInfo("Retrieved DSP data for DSP_ID={$dsp_id}", array('dsp_id' => $dsp_id, 'date_debut' => $date_debut->format(DateHelper::MYSQL_FORMAT), 'date_fin' => $date_fin->format(DateHelper::MYSQL_FORMAT), 'row_count' => count($result)));
		return $result;
    }
    
    /**
     * YAGNI!
     * Retourne les données d'un DSP pour une liste d'IPP.
     * @param string $dsp_id identifiant du DSP, ex: 'DSP2'
     * @param string[] $ipps liste d'IPP
     * @param string[] $item_names (option) liste d'item à garder
     * @param string $page_name (option) nom de la page à garder
     * @return array 
     */
    public function getDSPDataFromIPP($dsp_id, array $ipps, array $item_names = null, $page_name = null){
        $items = $this->getDSPItems($dsp_id,$item_names,$page_name);
        $every_page = array_unique(array_column($items,'PAGE_NOM'));
        
        $query_items_select = "";
        foreach($items as $item)
            $query_items_select .= ", {$dsp_id}.{$item['PAGE_NOM']}.{$item['ITEM_ID']}";
        
        $query_items_from = "";
        foreach($every_page as $p_name)
            $query_items_from .= " LEFT JOIN {$dsp_id}.{$p_name} ON {$dsp_id}.{$p_name}.NIPRO = IP.NIPRO AND INCL.NIP = {$dsp_id}.{$p_name}.NIP";

        $query_in_ipps = "'".join("','",$ipps)."'";
        $query_get_data = "SELECT IP.NIPRO, 
            INCLETB.ID_PATIENT_ETB AS IPP, 
            IP.NIP, 
            INCL.NOM, 
            INCL.PNOM AS PRENOM, 
            to_char(INCL.DATNAI,'YYYY-MM-DD') AS DATNAI,
            INCL.SEXE, 
            IP.AGE_DTPRO AS AGE, 
            IP.POIDS, 
            IP.TAILLE, 
            IP.TP_EXM AS TYPE_EXAM, 
            IP.VENUE, 
            to_char(IP.DT_PRO,'YYYY-MM-DD') AS DATE_EXAM, 
            to_char(IP.DT_MAJ,'YYYY-MM-DD') AS DATE_MAJ, 
            IP.OPER,
            CS.REVISION, 
            CS.EXTENSION, 
            CS.CATEG, 
            CS.CR_PROVISOIRE, 
            IP.SERVICE
            {$query_items_select}
            FROM MIDDLECARE.INCLUSION INCL
            INNER JOIN {$dsp_id}.INCLUSION_PROCEDURE IP ON IP.NIP = INCL.NIP
            LEFT JOIN MIDDLECARE.INCLUSION_ETB INCLETB ON INCLETB.INTNIP = INCL.INTNIP
            LEFT JOIN MIDDLECARE.CONSULTATION CS ON CS.INTNIPRO = IP.INTNIPRO AND CS.CDPROD = '{$dsp_id}'
            {$query_items_from} 
            WHERE INCLETB.ID_PATIENT_ETB IN ({$query_in_ipps})
            ORDER BY INCLETB.ID_PATIENT_ETB, IP.DT_PRO";

        $result = array('dsp_id' => $dsp_id, 'items' => $items, 'data' => $this->executeQuery($query_get_data));
        $this->logger->addInfo("Retrieved DSP data of IPPs={$query_in_ipps} for DSP_ID={$dsp_id}", array('dsp_id' => $dsp_id,'IPPs' => $query_in_ipps, 'page_name' => $page_name, 'row_count' => count($result['data'])));
		return $result;
    }

    /**
     * Statistiques d'utilisation des items d'un DSP pour une période donnée.
     * 
     * @param string $dsp_id identifiant du DSP, ex: 'DSP2'
     * @param \DateTime $date_debut
     * @param \DateTime $date_fin
     * @param string $page_name
     * @return array 
     */
	public function getItemUsageOverview($dsp_id, $date_debut, $date_fin, $page_name = null){
        $items = $this->getDSPItems($dsp_id,null,$page_name);
        $item_names = array_column($items, 'ITEM_ID');
        $every_page = array_unique(array_column($items, 'PAGE_NOM'));
        
        $query_items_select = "";
        foreach($items as $item)
            $query_items_select .= ", {$dsp_id}.{$item['PAGE_NOM']}.{$item['ITEM_ID']}";
        
        $query_items_from = "";
        foreach($every_page as $p_name)
            $query_items_from .= " LEFT JOIN {$dsp_id}.{$p_name} ON {$dsp_id}.{$p_name}.NIPRO = IP.NIPRO AND INCL.NIP = {$dsp_id}.{$p_name}.NIP";

        $query_get_dsp = "SELECT IP.NIPRO, 
            INCLETB.ID_PATIENT_ETB AS IPP, 
            IP.NIP, 
            INCL.NOM, 
            INCL.PNOM AS PRENOM, 
            to_char(INCL.DATNAI,'YYYY-MM-DD') AS DATNAI,
            INCL.SEXE, 
            IP.AGE_DTPRO AS AGE, 
            IP.POIDS, 
            IP.TAILLE, 
            IP.TP_EXM AS TYPE_EXAM, 
            IP.VENUE, 
            to_char(IP.DT_PRO,'YYYY-MM-DD') AS DATE_EXAM, 
            to_char(IP.DT_MAJ,'YYYY-MM-DD') AS DATE_MAJ, 
            IP.OPER
            {$query_items_select}
            FROM MIDDLECARE.INCLUSION INCL
            INNER JOIN {$dsp_id}.INCLUSION_PROCEDURE IP ON IP.NIP = INCL.NIP
            LEFT JOIN MIDDLECARE.INCLUSION_ETB INCLETB ON INCLETB.INTNIP = INCL.INTNIP
            {$query_items_from} 
            WHERE IP.DT_PRO >= to_date('".$date_debut->format("d-m-Y")."','DD-MM-YYYY')
            AND IP.DT_PRO < to_date('".$date_fin->format("d-m-Y")."','DD-MM-YYYY')";

        $rows = $this->executeQuery($query_get_dsp);

		$item_not_null = array();
        $item_not_null_count = array();
		if(count($rows) > 0){
			foreach($rows[0] as $key => $value)
				$item_not_null_count[$key] = 0;
			foreach($rows as $row){
				foreach($row as $key => $value){
					if (!empty($value) && in_array($key,$item_names))
						$item_not_null_count[$key]++;
				}
			}
			foreach($item_not_null_count as $key => $value){
				if($value > 0)
					$item_not_null[] = $key;
			}
        }
        
        arsort($item_not_null_count);
		return array(
			'ALL_ITEM_COUNT' => $item_not_null_count,
			'ALL_ITEM_NOT_NULL' => $item_not_null
        );
    }

    // ---- Helpers

    /**
     * Exécute la requête SQL Oracle donnée et retourne le résultat.
     * 
     * @param $query requête Oracle
     * @return array
     */
	public function executeQuery($query){
        $stmt = $this->db_middlecare->prepare($query);
        $stmt->execute();
        $result = array();
        while($row = $stmt->fetch(PDO::FETCH_ASSOC))
            $result[] = $row;
        return $result;
    }
}