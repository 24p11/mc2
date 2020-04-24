<?php 
namespace MC2\DSP;
use \DateTime;
use MC2\Core\Helper\DateHelper;
class Page{
    
    public $type_document;
    public $dossier_id;
    public $site;
    public $page_libelle;
    public $page_code;
    public $page_ordre;

    public $created;
    public $modified;
    public $version;

    public function __construct($data = null){
        if(is_array($data)){
            if(isset($data['type_document']))
                $this->type_document = $data['type_document'];
                
            $this->dossier_id = $data['dossier_id'];
            $this->site = $data['site'];
            $this->page_libelle = $data['page_libelle'];
            $this->page_code = $data['page_code'];
            $this->page_ordre = $data['page_ordre'];
          
            $this->created = DateTime::createFromFormat(DateHelper::MYSQL_FORMAT,$data['created']);
            $this->modified = DateTime::createFromFormat(DateHelper::MYSQL_FORMAT,$data['modified']);
            $this->version = $data['version'];
        }
    }

    public static function createFromMCData($dsp_id,$mc_data){
        $page = new Page();
        $page->site = $mc_data['SITE'];
        $page->dossier_id = $mc_data['DOSSIER_ID'];
        $page->type_document = $mc_data['DOCUMENT_TYPE'];
        $page->page_libelle = $mc_data['PAGE_LIBELLE'];
        $page->page_code = $mc_data['PAGE_CODE'];
        $page->page_ordre = $mc_data['PAGE_ORDRE'];
        return $page;
    }

    public function toMCArray(){
        $result = array();
        $result['SITE'] = $this->site;
        $result['DOSSIER_ID'] = $this->dossier_id;
        $result['DOCUMENT_TYPE'] = $this->type_document;
        $result['PAGE_LIBELLE'] = $this->page_libelle;
        $result['PAGE_CODE'] = $this->page_code;
        $result['PAGE_ORDRE'] = $this->page_ordre;
        return $result;
    }
}
