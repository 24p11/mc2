<?php
namespace MC2\MiddleCare;
use \PDO;
use \InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Doctrine\DBAL\DriverManager;
use MC2\Core\Helper\DateHelper;
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

    private $db_configuration;
    private $db_middlecare = null;
    private $logger;
    private $site;

    /**
     * @param array $configuration configuration that should contains MiddleCare DSN(s) (compatible with Doctrine)
     * @param Psr\Log\LoggerInterface $logger
     */
    public function __construct($configuration,LoggerInterface $logger){
        $this->db_configuration = $configuration['middlecare'];
        $this->logger = $logger;
    }

    public function getAvailableSites(){
        return array_keys($this->db_configuration);
    }

    public function connect($site){
        $this->site = $site;
        if(isset($this->db_configuration[$site]['doctrine']['dbal']) === false)
            throw new InvalidArgumentException("MiddleCare DSN for site '$site' was not found in given configuration");
        $this->db_middlecare = DriverManager::getConnection($this->db_configuration[$site]['doctrine']['dbal']);
    }

    public function checkConnection(){
        try{
            if ($this->db_middlecare->connect())
                return true;
        } 
        catch (\Exception $e) {
            $this->logger->error("Can't connect to MCRepository DB", array('exception' => $e));
            return false;
        }
    }

    /**
     * Retourne les DSP (DSP, DDS et DSC) existants dans MiddleCare.
     * 
     * @return array [DOSSIER_ID, NOM, LIBELLE, SITE, UHS]
     */
    public function getAllDSP(){
        $query = "SELECT '".$this->site."' SITE, CD_DOSSIER DOSSIER_ID, NOM NOM, DESCRIPTION LIBELLE, lower(SUBSTR(CD_HOP,1,3)) SITE, CD_UF UHS 
            FROM middlecare.DOSSIER 
            WHERE CD_DOSSIER LIKE 'D%' ORDER BY CD_DOSSIER";
        return $this->executeQuery($query);
    }
    
    /**
     * Retourne les pages disponibles par type de document pour un DSP.
     * 
     * @return array [SITE, DOSSIER_ID, DOCUMENT_TYPE, PAGE_LIBELLE, PAGE_CODE, PAGE_ORDRE]
     */
    public function getDSPPages($dsp_id,$document_type = null){
        $query_document = ($document_type === null) ? "" : "WHERE PROCEDURE = '{$document_type}'";
        $query = "SELECT '".$this->site."' SITE, 
            upper('{$dsp_id}') DOSSIER_ID, 
            PROCEDURE DOCUMENT_TYPE, 
            CHAPITRE PAGE_LIBELLE, 
            CD_PGE PAGE_CODE, 
            ORDRE_LISTE PAGE_ORDRE
            FROM {$dsp_id}.CHAPITRE
            {$query_document}
            ORDER BY DOCUMENT_TYPE, PAGE_ORDRE";
        return $this->executeQuery($query);
    }

    public function getPageNamesFromCategory($dsp_id,$category){
        $query = "SELECT DISTINCT URL AS PAGE_NOM
            FROM {$dsp_id}.CHAPITRE
            WHERE PROCEDURE IN
            (SELECT DISTINCT LIBEXAM
            FROM MIDDLECARE.CONSULTATION 
            WHERE CDPROD = '{$dsp_id}'
            AND CR_PROVISOIRE = '0'
            AND CATEG = '{$category}')";
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
    public function getDSPItems($dsp_id, array $item_names = null, array $page_names = null){
        $query_items = ($item_names === null || count($item_names) < 1) 
            ? "" : "AND all_col.column_name in(".join(',',array_map(function($v){ return "'".$v."'"; },$item_names)).")";
        $query_pages = ($page_names === null || count($page_names) < 1)
            ? "" : " AND pag.NM_PGE in(".join(',',array_map(function($v){ return "'".$v."'"; },$page_names)).")";;
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
            {$query_pages}
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
            AND blc.NOM_TABLE <> 'detail_patient'
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

    public function getCategoryOfDocument($nipro){
        $query = "SELECT CATEG FROM MIDDLECARE.CONSULTATION WHERE INTNIPRO = '{$nipro}'";
        $result = $this->executeQuery($query);
        return $result[0]['CATEG'];
    }

    public function getCategoriesOfPeriod($dsp_id,$date_debut,$date_fin, $date_update = false){
        $query = ($date_update === false) 
            ? "SELECT DISTINCT CATEG FROM MIDDLECARE.CONSULTATION 
            WHERE CDPROD = '{$dsp_id}'
            AND DATEXAM >= to_date('".$date_debut->format("d-m-Y")."','DD-MM-YYYY')
            AND DATEXAM < to_date('".$date_fin->format("d-m-Y")."','DD-MM-YYYY')
            AND REVISION > 0"
            : "SELECT DISTINCT CATEG FROM MIDDLECARE.CONSULTATION 
            WHERE CDPROD = '{$dsp_id}'
            AND DATEPUB >= to_date('".$date_debut->format("d-m-Y")."','DD-MM-YYYY')
            AND DATEPUB < to_date('".$date_fin->format("d-m-Y")."','DD-MM-YYYY')
            AND REVISION > 0";
        $result = $this->executeQuery($query);
        return array_unique(array_column($result,'CATEG'));
    }

    /**
     * Retourne les items pour une categorie de document (type de document Mediweb : 120 = CRH) d'un DSP.
     * 
     * @param string $dsp_id identifiant du DSP, ex: 'DSP2'
     * @param string $document_category, ex: '120' (CRH)
     * @return array [DSP_ID,PAGE_NOM,PAGE_LIB,BLOC_NO,BLOC_LIB,ITEM_LIGNE,ITEM_ID,ITEM_TYPE,ITEM_MCTYPE,ITEM_LIB,ITEM_LIB_BLOC,ITEM_LIB_SECONDAIRE,DETAIL,TYP_CRTL,FORMULE,OPTIONS,LISTE_NOM]
     */
    public function getDSPItemsFromDocumentCategory($dsp_id, $document_category){
        $pages = $this->getPageNamesFromCategory($dsp_id,$document_category);
        $page_names =  array_unique(array_column($pages, 'PAGE_NOM'));;
        return $this->getDSPItems($dsp_id,null,$page_names);
    }

    /**
     * Retourne les différentes valeurs d'une Liste (item de type liste).
     * 
     * @param string $dsp_id identifiant du DSP, ex: 'DSP2'
     * @param string $liste_name
     * @return array [LISTE_NOM, LISTE_DESCRIPTION, LISTE_VAL, LISTE_VAL_INDEX, LISTE_VAL_IS_DEFAULT]
     */
    public function getListeValues($dsp_id, $liste_name){
        if(mb_substr($liste_name, 0, 1 ) !== "D")
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

    public function getDSPfromIPP($ipps){
        $dsps = [];
        $starting_with = ["DCS","DDS","DSP","SER"];
        $query_in_ipps = "'".join("','",$ipps)."'";
        $query = "SELECT *
            FROM middlecare.INCLUSION INC
            LEFT JOIN middlecare.INCLUSION_ETB ETB ON INC.NIP = ETB.INTNIP
            WHERE ETB.ID_PATIENT_ETB IN ({$query_in_ipps})";
        $rows = $this->executeQuery($query);
        foreach($rows as $row){
            foreach($row as $key => $value){
                if (in_array(substr($key,0,3),$starting_with) && !in_array($key,$dsps) && $value === "1")
                    $dsps[] = $key;
            }
        }
        return $dsps;
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
        $this->logger->info("Retrieved all IPPs", array('dsp_id' => $dsp_id, 'row_count' => count($result)));
        return $result;
    }

    /**
     * Retourne les données d'un DSP pour une période donnée.
     * 
     * @param string $dsp_id identifiant du DSP, ex: 'DSP2'
     * @param \DateTime $date_debut
     * @param \DateTime $date_fin
     * @param string[] $item_names (option)
     * @param string $category
     * @return array [NIPRO,IPP,NIP,NOM,PRENOM,DATNAI,SEXE,AGE,POIDS,TAILLE,TYPE_EXAM,VENUE,DATE_EXAM,DATE_MAJ,OPER,REVISION,EXTENSION,CATEG,CR_PROVISOIRE,SERVICE,...ITEMS DU DSP...]
     */
    public function getDSPData($dsp_id, $date_debut, $date_fin, array $items, $category = null, $date_update = false){    
        $max_items = 200;
        // split items in array of max_items items
        $items_chunks = array_chunk($items, $max_items, true);
        $this->logger->debug('getDSPData : chunking done', ['items_chunks_count' => count($items_chunks)]);
        // get data for each array of items
        $data = [];
        foreach ($items_chunks as $items_chunk)
            $data[] = $this->getDSPDataChunk($dsp_id,$date_debut,$date_fin,$items_chunk,$category, $date_update);
        $data_count = count($data);
        $this->logger->debug('getDSPData : getting data done, data_count = '. $data_count);
        // merge datas for each nipro
        $result = [];
        if(count($data) > 0){
            $nipros = array_unique(array_column($data[0], 'NIPRO'));
            foreach($nipros as $nipro){
                $nipro_data = [];
                foreach($data as $d){
                    foreach($d as $row){
                        if($row['NIPRO'] === $nipro){
                            $nipro_data = array_merge($nipro_data,$row);
                            break;
                        }
                    }
                }
                $result[] = $nipro_data;
            }
        }
        $this->logger->debug('getDSPData : merging data done');
        $this->logger->info("Retrieved DSP data for DSP_ID={$dsp_id}", array('dsp_id' => $dsp_id, 'date_debut' => $date_debut->format(DateHelper::MYSQL_FORMAT), 'date_fin' => $date_fin->format(DateHelper::MYSQL_FORMAT), 'category' => $category, 'row_count' => count($result)));
		return $result;
    }
    
    // TODO merger / factoriser les 3 ou 4 methodes suivantes ...
    /**
     * Retourne les données d'un document dans un DSP
     * 
     * @param string $dsp_id identifiant du DSP, ex: 'DSP2'
     * @param string $nipro identifiant du document
     * @param string[] $item_names (option)
     * @return array [NIPRO,IPP,NIP,NOM,PRENOM,DATNAI,SEXE,AGE,POIDS,TAILLE,TYPE_EXAM,VENUE,DATE_EXAM,DATE_MAJ,OPER,REVISION,EXTENSION,CATEG,CR_PROVISOIRE,SERVICE,...ITEMS DU DSP...]
     */
    public function getDSPDataFromNIPRO($dsp_id, $nipro, array $items){    
        $every_page = array_unique(array_column($items, 'PAGE_NOM'));
        
        $query_items_select = "";
        foreach($items as $item)
            $query_items_select .= ", {$dsp_id}.{$item['PAGE_NOM']}.{$item['ITEM_ID']}";
        
        $query_items_from = "";
        foreach($every_page as $p_name)
            $query_items_from .= " LEFT JOIN {$dsp_id}.{$p_name} ON {$dsp_id}.{$p_name}.NIPRO = IP.NIPRO AND INCL.NIP = {$dsp_id}.{$p_name}.NIP";

        $query_get_dsp = "SELECT IP.NIPRO, 
            INCLETB.ID_PATIENT_ETB AS IPP, 
            INCL.NIP, 
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
            WHERE IP.NIPRO = '{$nipro}'
            ORDER BY IP.NIP";

        $this->logger->debug("query_get_dsp", array('query' => $query_get_dsp));
        $result = $this->executeQuery($query_get_dsp);
        $this->logger->info("Retrieved DSP data for DSP_ID={$dsp_id}", array('dsp_id' => $dsp_id, 'nipro' => $nipro, 'row_count' => count($result)));
		return $result;
    }
    
    public function getDocumentFromNDA(array $ndas){
        $query_in_ndas = "'".join("','",$ndas)."'";
        $query = "SELECT CS.CDPROD AS DSP_ID,
            CS.INTNIPRO AS NIPRO, 
            INCLETB.ID_PATIENT_ETB AS IPP, 
            INCL.NIP, 
            INCL.NOM, 
            INCL.PNOM AS PRENOM, 
            to_char(INCL.DATNAI,'YYYY-MM-DD') AS DATNAI,
            INCL.SEXE,
            CS.LIBEXAM AS TYPE_EXAM,
            CS.NUM_VENU AS VENUE, 
            to_char(CS.DATEXAM,'YYYY-MM-DD') AS DATE_EXAM, 
            to_char(CS.DATEPUB,'YYYY-MM-DD') AS DATE_MAJ, 
            CS.AUTEUR AS OPER,
            CS.REVISION, 
            CS.EXTENSION, 
            CS.CATEG, 
            CS.CR_PROVISOIRE, 
            CS.AUTORISE as SERVICE
            FROM MIDDLECARE.INCLUSION INCL
            LEFT JOIN MIDDLECARE.INCLUSION_ETB INCLETB ON INCLETB.INTNIP = INCL.INTNIP
            LEFT JOIN MIDDLECARE.CONSULTATION CS ON CS.INTNIP = INCL.INTNIP
            WHERE CS.NUM_VENU IN({$query_in_ndas})
            ORDER BY INCL.NIP";

        $this->logger->debug("getDocumentFromNDA", array('query' => $query));
        $result = $this->executeQuery($query);
        $this->logger->info("Retrieved DSP data by NDA", array('row_count' => count($result)));
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
            $query_items_from .= " LEFT JOIN {$dsp_id}.{$p_name} ON {$dsp_id}.{$p_name}.NIPRO = IP.NIPRO AND INCL.NIP = {$dsp_id}.{$p_name}.NIP AND {$dsp_id}.{$p_name}.DT_MAJ IS NOT NULL";

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
            JOIN MIDDLECARE.CONSULTATION CS ON CS.INTNIPRO = IP.INTNIPRO AND CS.CDPROD = '{$dsp_id}' AND CS.REVISION > 0
            {$query_items_from} 
            WHERE INCLETB.ID_PATIENT_ETB IN ({$query_in_ipps})
            ORDER BY INCLETB.ID_PATIENT_ETB, IP.DT_PRO";
        
        $result = array('dsp_id' => $dsp_id, 'items' => $items, 'data' => $this->executeQuery($query_get_data));
        $this->logger->info("Retrieved DSP data of IPPs={$query_in_ipps} for DSP_ID={$dsp_id}", array('dsp_id' => $dsp_id,'IPPs' => $query_in_ipps, 'page_name' => $page_name, 'row_count' => count($result['data'])));
		return $result;
    }

    // ---- Helpers

    private function getDSPDataChunk($dsp_id, $date_debut, $date_fin, array $items, $category,$date_update = false){    
        $query_items_select = "";
        foreach($items as $item)
            $query_items_select .= ", {$dsp_id}.{$item['PAGE_NOM']}.{$item['ITEM_ID']}";
        
        $every_page = array_unique(array_column($items, 'PAGE_NOM'));
        $query_items_from = "";
        foreach($every_page as $p_name){
            $query_items_from .= " LEFT JOIN {$dsp_id}.{$p_name} ON {$dsp_id}.{$p_name}.NIPRO = IP.NIPRO AND INCL.NIP = {$dsp_id}.{$p_name}.NIP AND {$dsp_id}.{$p_name}.DT_MAJ IS NOT NULL";
        }

        $query_period = ($date_update === false)
            ? " IP.DT_PRO >= to_date('".$date_debut->format("d-m-Y")."','DD-MM-YYYY') AND IP.DT_PRO < to_date('".$date_fin->format("d-m-Y")."','DD-MM-YYYY')"
            : " IP.DT_MAJ >= to_date('".$date_debut->format("d-m-Y")."','DD-MM-YYYY') AND IP.DT_MAJ < to_date('".$date_fin->format("d-m-Y")."','DD-MM-YYYY')";

        $query_category = $category === null ? '' : "AND CS.CATEG = '{$category}'"; 
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
            JOIN MIDDLECARE.CONSULTATION CS ON CS.INTNIPRO = IP.INTNIPRO AND CS.CDPROD = '{$dsp_id}' AND CS.REVISION > 0
            {$query_items_from} 
            WHERE 
            {$query_period}
            {$query_category}
            ORDER BY IP.NIP";

        $this->logger->debug("query_get_dsp", array('query' => $query_get_dsp));
        $result = $this->executeQuery($query_get_dsp);
        $this->logger->debug("Retrieved DSP data for DSP_ID={$dsp_id}", array('dsp_id' => $dsp_id, 'date_debut' => $date_debut->format(DateHelper::MYSQL_FORMAT), 'date_fin' => $date_fin->format(DateHelper::MYSQL_FORMAT), 'items_count'=> count($items), 'row_count' => count($result)));
		return $result;
    }

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