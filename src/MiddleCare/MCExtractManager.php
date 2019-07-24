<?php
namespace SBIM\MiddleCare;
use \DateTime;
use \DateInterval;
use SBIM\Core\CSV\CSVFile;
use SBIM\Core\Helper\DateHelper;
use SBIM\Core\Helper\ArrayHelper;
use SBIM\DSP\DossierRepository;
use SBIM\DSP\Dossier;
use SBIM\DSP\DocumentRepository;
use SBIM\DSP\Document;
use SBIM\DSP\ItemValue;
use SBIM\DSP\PatientRepository;
use SBIM\DSP\Patient;
use SBIM\RedCap\RCDictionnary;
use SBIM\RedCap\RCItem;
use SBIM\RedCap\RCInstrument;
/**
 * MCExtractManager
 */
final class MCExtractManager{

	const SRC_MIDDLECARE = 0;
	const SRC_LOCAL_DB = 1;
	public $source = null;
	public $site = null;

	// MiddleCare DB
	private $mc_repository = null;

	// Local DB
	private $dossier_repository = null;
	private $document_repository = null;
	private $patient_repository = null;
	
	private $csv_writer = null;
	private $logger = null;
	private $output_folder = null;

	public function __construct($source, $site, $mc_repository,$dossier_repository,$document_repository,$patient_repository, $csv_writer, $logger){
		$this->source = $source;
		$this->site = $site;
		$this->mc_repository = $mc_repository;
		$this->dossier_repository = $dossier_repository;
		$this->document_repository = $document_repository;
		$this->patient_repository = $patient_repository;
		$this->csv_writer = $csv_writer;
		$this->logger = $logger;
		$this->output_folder =  __DIR__."/../data";
	}

	public function isSourceMiddleCare(){
		return $this->source === self::SRC_MIDDLECARE;
	}

	public function getSourceName(){
		return $this->source === self::SRC_MIDDLECARE ? "MiddleCare" : "Local DB";
	}

	// -------- CSV
	
	/**
	 * Exporte la liste des DSP (depuis MiddleCare ou la base Locale) vers fichier CSV.
	 * 
	 * @return string nom du CSV généré
	 */
	public function export_all_dsp_to_csv(){
		$this->logger->addInfo("-------- Exporting all DSP from ".$this->getSourceName()." of SITE=".$this->site." to CSV file");
		$all_dsp = $this->isSourceMiddleCare() 
			? $this->mc_repository->getAllDSP()
			: array_map(function($v){ return $v->toMCArray(); }, $this->dossier_repository->findAllDossier());
		$file_name = $this->csv_writer->save(new CSVFile("DSP",$all_dsp));
		$this->logger->addInfo("Exported all DSP from ".$this->getSourceName()." to CSV file {$file_name}");
		return $file_name;
	}

	/**
	 * Exporte la liste des items d'un DSP (depuis MiddleCare ou la base Locale) vers un fichier CSV.
	 * 
	 * @param string $dsp_id identifiant du DSP, ex: 'DSP2'
	 * @param string[] $item_names (option) liste de nom d'items
	 * @return string nom du CSV généré
	 */
	public function export_dsp_items_to_csv($dsp_id, array $item_names = null){
		$this->logger->addInfo("-------- Exporting SITE=".$this->site." DSP_ID={$dsp_id} items from ".$this->getSourceName()." to CSV file", ['dsp_id' => $dsp_id, 'item_names' => $item_names]);

		$item_infos = $this->isSourceMiddleCare() ?
			$this->mc_repository->getDSPItems($dsp_id,$item_names)
			: array_map(function($v){ return $v->toMCArray(); }, $this->dossier_repository->findItemByDossierId($dsp_id,$item_names));
		
		$file_subtitle = ($item_names === null || count($item_names) < 1) ? "" : "_partial";
		$file_name = $this->csv_writer->save(new CSVFile("DSP_".strtoupper($this->site)."_{$dsp_id}_dictionnary{$file_subtitle}",$item_infos));
		$this->logger->addInfo("Exported DSP_ID={$dsp_id} items from ".$this->getSourceName()." to CSV file {$file_name}", array('row_count' => count($item_infos)));
		return $file_name;
	}

	/**
	 * Exporte les données d'un DSP (depuis MiddleCare ou la base Locale) vers un fichier CSV.
	 * 
	 * @param string $dsp_id identifiant du DSP, ex: 'DSP2'
	 * @param \DateTime $date_debut
     * @param \DateTime $date_fin
	 * @param string[] $item_names (option) liste de nom d'items
	 * @param string $page_name
	 * @param string $type_doc type de document voulu
	 * @param string $interval periode comprise dans un fichier csv (1 fichier CSV par mois = P1M, par 2 ans = P2Y)
	 * @return string[] nom de(s) CSV généré(s)
	 */
	public function export_dsp_data_to_csv($dsp_id, $date_debut, $date_fin, array $item_names = null, $page_name = null,$type_doc = null, $interval = null){
		$this->logger->addInfo("-------- Exporting SITE=".$this->site." DSP_ID={$dsp_id} data from ".$this->getSourceName()." from ".$date_debut->format('Y-m-d')." to ".$date_fin->format('Y-m-d')." to CSV file", array('dsp_id' => $dsp_id, 'date_debut' => $date_debut->format(DateHelper::MYSQL_FORMAT), 'date_fin' => $date_fin->format(DateHelper::MYSQL_FORMAT), 'item_names' => $item_names, 'interval' => $interval));
		$file_names = array();
		$interval = $interval === null ? "P1M" : $interval;
		$interval_max = new DateInterval($interval);//1mois = P1M, 2 mois = P2M, 60 jours =  P60D
		if($date_debut->diff($date_fin) < $interval_max){
			$file_names[] = $this->export_dsp_data_to_csv_chunck($dsp_id,$date_debut,$date_fin,$item_names,$page_name,$type_doc);
		}else{
			$date1 = clone $date_debut;
			$date2 = clone $date1;
			$date2->add($interval_max);
			while($date2 < $date_fin){
				$file_names[] = $this->export_dsp_data_to_csv_chunck($dsp_id,$date1,$date2,$item_names,$page_name,$type_doc);
				$date1 = clone $date2;
				$date2->add($interval_max);
			}
			$file_names[] = $this->export_dsp_data_to_csv_chunck($dsp_id,$date1,$date_fin,$item_names,$page_name,$type_doc);
		}
		$this->logger->addInfo("-------- Exporting DSP_ID={$dsp_id} data from ".$this->getSourceName()." from ".$date_debut->format('Y-m-d')." to ".$date_fin->format('Y-m-d')."to CSV file", array('dsp_id' => $dsp_id, 'date_debut' => $date_debut->format(DateHelper::MYSQL_FORMAT), 'date_fin' => $date_fin->format(DateHelper::MYSQL_FORMAT)));
		return $file_names;
	}

	private function export_dsp_data_to_csv_chunck($dsp_id, $date_debut, $date_fin, array $item_names = null, $page_name = null,$type_doc = null){
		if($this->isSourceMiddleCare()){
			$items = $this->mc_repository->getDSPItems($dsp_id,$item_names,$page_name);
			$data = $this->mc_repository->getDSPData($dsp_id,$date_debut,$date_fin,$items);
		}else{
			$items = $this->dossier_repository->findItemByDossierId($dsp_id,$item_names);
			$documents = $this->document_repository->findDocumentWithItemValues($dsp_id,$date_debut,$date_fin,$item_names,$page_name,null,$type_doc);
			$data = array();
			foreach ($documents as $document){
				$patients = $this->patient_repository->findPatient($document->patient_id);
				$document->patient = count($patients) > 0 ? $patients[0] : null;
				$data[] = $document->toMCArray($items);
			}
		}
		$data = $this->insert_items_infos($data, $dsp_id);
		$prefix = "DSP_".strtoupper($this->site)."_{$dsp_id}_data_".$date_debut->format('Ymd')."-".$date_fin->format('Ymd');
		$file_name = $this->csv_writer->save(new CSVFile($prefix, $data));
		$this->logger->addInfo("Exported DSP_ID={$dsp_id} data from ".$this->getSourceName()." to CSV file {$file_name}", array());
		return $file_name;
	}

	// -------- MC to DB (MiddleCare to SBIM MySQL)

	/**
	* Exporte les données d'un DSP MiddleCare vers DB SBIM
	* 
	* @param string $dsp_id identifiant du DSP, ex: 'DSP2'
	* @param \DateTime $date_debut
	* @param \DateTime $date_fin
	* @return string 
	*/
	public function export_mc_dsp_data_to_db($dsp_id, $date_debut, $date_fin, array $item_names = null){//, $page_name = null
		$this->logger->addInfo("-------- export_mc_dsp_data_to_db from ".$date_debut->format(DateHelper::MYSQL_FORMAT)." to ".$date_fin->format(DateHelper::MYSQL_FORMAT));
		$interval_max = new DateInterval("P1M");//1mois = P1M, 2 mois = P2M, 60 jours =  P60D
		if($date_debut->diff($date_fin) < $interval_max){
			$this->get_mc_dsp_data_to_db_chunk($dsp_id, $date_debut, $date_fin,$item_names);
		}else{
			$date1 = clone $date_debut;
			$date2 = clone $date1;
			$date2->add($interval_max);
			while($date2 < $date_fin){
				$this->get_mc_dsp_data_to_db_chunk($dsp_id, $date1, $date2,$item_names);
				$date1 = clone $date2;
				$date2->add($interval_max);
			}
			$this->get_mc_dsp_data_to_db_chunk($dsp_id, $date1, $date_fin,$item_names);
		}
   	}

   	private function get_mc_dsp_data_to_db_chunk($dsp_id, $date_debut, $date_fin, array $item_names = null){
		$this->logger->addInfo("---- get_mc_dsp_data_to_db_chunk ".$date_debut->format(DateHelper::MYSQL_FORMAT)." to ".$date_fin->format(DateHelper::MYSQL_FORMAT));
		$now = new DateTime();
		// get data 
		$items = $this->mc_repository->getDSPItems($dsp_id,$item_names,null);
		$mc_documents = $this->mc_repository->getDSPData($dsp_id, $date_debut, $date_fin, $items);
		$this->logger->addInfo("getDSPData after ".$now->diff(new DateTime())->format('%H:%I:%S'));
		$patients = array();
		// delete document and item values
		$nipros = array_unique(array_column($mc_documents, 'NIPRO'));
		$this->document_repository->deleteDocumentsAndItemValues($nipros, $item_names);
		$this->logger->addInfo("deleted documents and item values after ".$now->diff(new DateTime())->format('%H:%I:%S'));
		
		// TODO set in configuration
		$batch_size_document = 1000;
		$batch_size_item = 100;
		$batch_size_patient = 1000;

		$i = 0;
		$documents = array();
		$item_values = array();
		$patients = array();
		$patient_ids = array();
		
		foreach ($mc_documents as $mc_document){
			$documents[] = Document::createFromMCData($this->document_repository->base_url,$this->site,$dsp_id,$mc_document);
			foreach($items as $item){
				$item_value = ItemValue::createFromMCData($dsp_id,$item,$mc_document);
				if(!empty($item_value->val))
					$item_values[] = $item_value;
			}
			$patient = Patient::createFromMCData($mc_document);
			if(!in_array($patient->id,$patient_ids)){
				$patients[] = $patient;
				$patient_ids[] = $patient->id;
			}
		
			// upsert documents every $batch_size_document document
			if($i % $batch_size_document === 0){
				$this->document_repository->upsertDocuments($documents);
				$this->logger->addInfo("upsert ".count($documents)." documents on document {$i}");
				$documents = array();
			}
			// upsert item values every $batch_size_item documents
			if($i % $batch_size_item === 0){
				$this->document_repository->upsertItemValues($item_values);
				$this->logger->addInfo("upsert ".count($item_values)." item values on document {$i}");
				$item_values = array();
			}
			// upsert patients every $batch_size_patient documents
			if($i % $batch_size_patient === 0){
				$this->patient_repository->upsertPatients($patients);
				$this->logger->addInfo("upsert ".count($patients)." patients on document {$i}");
				$patients = array();
			}
			$i++;
		}
		$this->document_repository->upsertDocuments($documents);
		$this->document_repository->upsertItemValues($item_values);
		$this->patient_repository->upsertPatients($patients);
		$this->logger->addInfo("upsert ".count($documents)." documents on document {$i}");
		$this->logger->addInfo("upsert ".count($item_values)." item values on document {$i}");
		$this->logger->addInfo("upsert ".count($patients)." patients on document {$i}");
	}

	// -------- REDCAP CSV (dictionnary.csv + data.csv)

	/**
	 * Exporte un Data Dictonnary RedCap pour un DSP donné (depuis MiddleCare ou la base Locale) au format CSV.
	 * 
	 * @param string $file_name 
	 * @param string $dsp_id identifiant du DSP, ex: 'DSP2'
	 * @param \SBIM\RedCap\RCProject $rc_project
	 */
	public function export_redcap_dictionnary($file_name, $dsp_id, $rc_project){
		$this->logger->addInfo("-------- Exporting SITE=".$this->site." DSP_ID={$dsp_id} RC data dictionnary from ".$this->getSourceName()." to CSV={$file_name}",array('dsp_id' => $dsp_id,'main_instrument.name' => $rc_project->main_instrument->name, 'main_instrument.item_names' => join(',',$rc_project->main_instrument->item_names)));
		if($rc_project->event_as_document_type === false){
			$mc_items = $this->get_items($dsp_id);
			$rc_dictionnary = RCDictionnary::create_from_mc_items_and_rcproject($dsp_id, $mc_items, $rc_project);
			$file_name = $this->csv_writer->save(new CSVFile($file_name,$rc_dictionnary->items));
		}else{
			$mc_items = $this->get_items($dsp_id,$rc_project->event_as_document_type);
			$rc_dictionnary = RCDictionnary::create_from_mc_items_and_rcproject_and_pages($dsp_id, $mc_items, $rc_project);
			$file_name = $this->csv_writer->save(new CSVFile($file_name,$rc_dictionnary->items));
			
		}
		$this->logger->addInfo("Exported DSP_ID={$dsp_id} RC data dictionnary from ".$this->getSourceName()." ",array('row_count' => count($rc_dictionnary->items)));
		return $file_name;
	}

	/**	
	 * Exporte les données RedCap d'un DSP depuis la base locale pour tous les patients venus sur une période donnée vers fichier RedCap  CSV.
	 * 
	 * TEMP TODO NOTE : difference principale avec from_mc : pour les patients donné on ne récupère que les documents de la période donnée, ce qui rend perilleux le chargement en plusieurs fois ! ( => a moins de toujours prendre la même date de début et de retarder la date de fin)
	 * 
	 * @param string $file_name préfix du nom fichier résultat
	 * @param string $dsp_id identifiant du DSP, ex: 'DSP2'
	 * @param \DateTime $date_debut
     * @param \DateTime $date_fin
	 * @param string[] $ipps
	 * @param \SBIM\RedCap\RCInstrument $main_instrument
	 * @param bool $longitudinal
	 */
	public function export_redcap_data_by_patient_from_db($file_name_prefix, $dsp_id, $date_debut, $date_fin, array $nips = null, $rc_project){
		$this->logger->addInfo("-------- Exporting SITE=".$this->site." DSP_ID={$dsp_id} RC data by patient from local DB to CSV={$file_name_prefix}", array('dsp_id' => $dsp_id, 'date_debut' => $date_debut->format(DateHelper::MYSQL_FORMAT), 'date_fin' => $date_fin->format(DateHelper::MYSQL_FORMAT)));
		$file_names = array();
		// Get RC dictionnary
		if($rc_project->event_as_document_type === false){
			$mc_items = $this->get_items($dsp_id);
			$rc_dictionnary = RCDictionnary::create_from_mc_items_and_rcproject($dsp_id, $mc_items, $rc_project);
		}else{
			$mc_items = $this->get_items($dsp_id,$rc_project->event_as_document_type);
			$rc_dictionnary = RCDictionnary::create_from_mc_items_and_rcproject_and_pages($dsp_id, $mc_items, $rc_project);
		}
		$data_column_names = $rc_dictionnary->get_data_column_names();
		// Get all patient from period
		$patient_ids = $this->document_repository->findAllPatientId($dsp_id, $date_debut, $date_fin);

		$CHUNK_COUNT = 10;
		$chunks_of_patientids = array_chunk($patient_ids, $CHUNK_COUNT);
		$chunk_i = 0;
		foreach($chunks_of_patientids as $patientids_chunk){
			$chunk_i++;
			// Get MC Data
			$items = $this->dossier_repository->findItemByDossierId($dsp_id,$rc_project->main_instrument->item_names);
			$documents = $this->document_repository->findDocumentWithItemValues($dsp_id, $date_debut, $date_fin, $rc_project->main_instrument->item_names, null,$patientids_chunk);
			$result = array();
			foreach ($documents as $document){
				$patients = $this->patient_repository->findPatient($document->patient_id);
				$document->patient = count($patients) > 0 ? $patients[0] : null;
				$result[] = $document->toMCArray($items);
			}
			
			$mc_data = $result;
			// Transcoding MC data to RC data
			$rc_data = array();
			if($rc_project->longitudinal === false){
				foreach($mc_data as $data){
					$rc_data[] = ArrayHelper::reorderColumns(
						$this->transcode_mc_data_to_rc_data($data,$rc_dictionnary),
						$data_column_names
					);
				}
			}else{
				$ipps = array();

				$shared_event_name = $rc_project->getUniqueSharedEventName();
				$repeatable_event_name = $rc_project->getUniqueRepeatableEventName();
				$shared_event_variable_count = $rc_project->shared_event_variable_count;// nip,nom,prenom,datnai,sexe
				
				$columns_completed = array_map(function($v){ return preg_replace('/^[0-9]/','', str_replace('__','_',str_replace(' ','_',strtolower($v))),1).'_complete';}, $rc_dictionnary->get_form_names());
				$completes = array();
				foreach($columns_completed as $value)
					$completes[$value] = null;
				foreach($mc_data as $data){
					$tmp = ArrayHelper::reorderColumns(
						$this->transcode_mc_data_to_rc_data($data,$rc_dictionnary,$rc_project->event_as_document_type),
						$data_column_names
					);
					if(!array_key_exists($tmp['IPP'],$ipps)){
						// ajouter une ligne pour l'event patient (IPP,redcap_event_name,redcap_repeat_instrument,redacap_repeat_instance...)
						$patient = array_slice($tmp, 0, 1, true) 
							+ array('redcap_event_name' => $shared_event_name,'redcap_repeat_instrument'=> null, 'redcap_repeat_instance' => null) 
							+ array_slice($tmp, 1, $shared_event_variable_count, true)
							+ array_map(function() {}, array_slice($tmp, $shared_event_variable_count , null, true))
							+ $completes;
						$ipps[$tmp['IPP']] = 1;
						$rc_data[] = $patient;
					}
					
					$redcap_repeat_instance = $ipps[$tmp['IPP']];
					// ajouter une ligne pour le repeatable form
					$rc_data[] = array_slice($tmp, 0, 1, true) 
						+ array('redcap_event_name' => $repeatable_event_name,'redcap_repeat_instrument'=> null, 'redcap_repeat_instance' => $redcap_repeat_instance)
						+ array_map(function() {}, array_slice($tmp, 1, $shared_event_variable_count, true))
						+ array_slice($tmp, $shared_event_variable_count, null, true)
						+ $completes;
					// incrementer 'redcap_repeat_instance' pour chaque event
					$ipps[$tmp['IPP']]++;
				}
			}
			// Write CSV
			$prefix = "{$file_name_prefix}_".$date_debut->format('Ymd')."-".$date_fin->format('Ymd')."_".$chunk_i;
			$file_name = $this->csv_writer->save(new CSVFile($prefix, $rc_data));
			$file_names[] = $file_name;
			$this->logger->addInfo("Exported SITE=".$this->site." DSP_ID={$dsp_id} RC data from local DB  to {$file_name}, chunck {$chunk_i}-".($chunk_i + $CHUNK_COUNT), array('row_count' => count($rc_data)));
		}
		return $file_names;
	}

	// -------- Documentation

	/**
	 * Exporter la documentation de l'ensemble des DSP de MiddleCare au format MarkDown.
	 */
	public function export_dsp_documentation_to_markdown(){
		$this->logger->addInfo("-------- Export DSP documentation (as MarkDown)");
		$all_dsp = $this->mc_repository->getAllDSP();
		$all_dsp_info = array();
		$item_infos_needed = array("BLOC_LIBELLE","ITEM_ID","TYPE","MCTYPE","LIBELLE_BLOC","LIBELLE_SECONDAIRE","LIST_NOM","LIST_VALUES");
		foreach ($all_dsp as $key => $dsp){
			$dsp_id = $dsp['DOSSIER_ID'];
			$items = $this->mc_repository->getDSPItems($dsp_id);
			$tmp = ArrayHelper::filter($items, array('PAGE_NOM','PAGE_LIBELLE'));
			$pages = array();
			foreach ($tmp as $value)
				$pages[$value['PAGE_NOM']] = $value['PAGE_LIBELLE']; 
				
			$all_dsp_info[$dsp_id] = array(
				'nom' => str_replace(["/"], ' ', $dsp['NOM']),
				'description' => $dsp['LIBELLE'],
				'pages' => $pages === null ? [] : $pages,
				'items' => $items === null ? [] : $items
			);
		}
		$helper_md_header = '<meta charset="utf-8">'.PHP_EOL;
		$helper_md_title_line = "===============================================================================".PHP_EOL;
		$helper_md_subtitle_line = "-------------------------------------------------------------------------------".PHP_EOL;
		$helper_md_markdown_js_line = '<!-- Markdeep: --><style class="fallback">body{visibility:hidden;white-space:pre;font-family:monospace}</style><script src="markdeep.min.js"></script><script src="https://casual-effects.com/markdeep/latest/markdeep.min.js"></script><script>window.alreadyProcessedMarkdeep||(document.body.style.visibility="visible")</script>'.PHP_EOL;

		// ---- MD Index File
		$main_md = array($helper_md_header);
		$main_md[] = '**MiddleCare - DSP**'.PHP_EOL;
		// pour chaque DSP
		foreach($all_dsp_info as $dsp_id => $dsp_info){
			$dsp_items_info = $dsp_info['items'];
			$dsp_title = "[{$dsp_id}] {$dsp_info['nom']}";
			$dsp_has_any_items = count($dsp_items_info) > 0;
			$dsp_pages = array_unique(array_column($dsp_items_info, 'page_nom'));
			$dsp_description = "- Description : {$dsp_info['description']}";
			$dsp_count_page = "- Nombre de pages : **".($dsp_has_any_items ? count($dsp_pages) : 0)."**";
			$dsp_count_items = "- Nombre d'items : **".($dsp_has_any_items ? count(array_unique(array_column($dsp_items_info, 'ITEM_ID'))): 0)."**";
			$dsp_file_name = 'middle_care_dsp_'.($dsp_has_any_items ? '' : 'empty_').strtolower($dsp_id."__".$dsp_info['nom']).'.md.html';

			$main_md[] = $dsp_title.PHP_EOL;
			$main_md[] = $helper_md_title_line;
			// overview
			$main_md[] = $dsp_description.PHP_EOL;
			$main_md[] = $dsp_count_page.PHP_EOL;
			$main_md[] = $dsp_count_items.PHP_EOL;
			$main_md[] = "- Détails : <a href='{$dsp_file_name}'>{$dsp_file_name}</a>".PHP_EOL;
			$main_md[] = PHP_EOL;

			// ---- MD Single DSP File
			$dsp_md = array($helper_md_header);
			$dsp_md[] = "**MiddleCare - {$dsp_title}**".PHP_EOL;
			$dsp_md[] = "Overview".PHP_EOL;
			$dsp_md[] = $helper_md_title_line;
			// overview
			$dsp_md[] = $dsp_description.PHP_EOL;
			$dsp_md[] = $dsp_count_page.PHP_EOL;
			$dsp_md[] = $dsp_count_items.PHP_EOL;
			$dsp_md[] = "Pages / Items".PHP_EOL;
			$dsp_md[] = $helper_md_title_line;
			// split item by page
			foreach($dsp_pages as $dsp_page){
				$dsp_md[] = $dsp_info['pages'][$dsp_page]." [{$dsp_page}]".PHP_EOL;
				$dsp_md[] = $helper_md_subtitle_line;
				// display items in table
				if($dsp_has_any_items === true){
					$selected_items_info = ArrayHelper::filter($dsp_items_info, $item_infos_needed, array('PAGE_NOM' => array($dsp_page)));
					$dsp_md[] = join('|',array_keys($selected_items_info[0])).PHP_EOL;
					for ($i = 0; $i < count($selected_items_info[0]) - 1; $i++)
						$dsp_md[] = "----|";
					$dsp_md[] = "----".PHP_EOL;
					foreach($selected_items_info as $key => $items){
						$dsp_md[] = join('|',array_map(function($v){ return str_replace('|', '/', htmlentities($v)); }, $items)).PHP_EOL;
					}
				}
			}
			$dsp_md[] = $helper_md_markdown_js_line;
			file_put_contents($this->output_folder."/{$dsp_file_name}",$dsp_md);
		}
		$main_md[] = $helper_md_markdown_js_line;
		file_put_contents($this->output_folder.'/middle_care_dsp.md.html',$main_md);
		$this->logger->addInfo("Exported DSP documentation (as MarkDown)");
	}

	// -------- Helpers 

	/**
	 * Retourne les items d'un DSP (depuis MiddleCare ou la base Locale)
	 * 
	 * @param string $dsp_id identifiant du DSP, ex: 'DSP2'
	 */
	private function get_items($dsp_id,$event_as_document = false){
		$item_infos = array();
		if($event_as_document === false){
			$items = $this->isSourceMiddleCare() 
				? $this->mc_repository->getDSPItems($dsp_id)
				: array_map(function($v){ return $v->toMCArray(); }, $this->dossier_repository->findItemByDossierId($dsp_id));
		}else{
			$items = array();
			// get document_type
			$pages = $this->dossier_repository->findPageByDossierId($dsp_id);
			$doc_types = array_unique(array_map(function($p){ return $p->type_document; }, $pages));
			// get pages for every document_type
			$doc_pages = array();
			$i = 0;
			foreach ($doc_types as $doc_type) {
				$doc_pages[$doc_type] = array();
				foreach ($pages as $page) {
					// do not take page_code = 4 (donnée d'inclusion)
					if($page->page_code === 4)
						continue;
					if($page->type_document === $doc_type)
						$doc_pages[$doc_type][] = $page;
				}
				usort($doc_pages[$doc_type], function($a, $b){ return strcmp($a->page_ordre, $b->page_ordre); });
				foreach($doc_pages[$doc_type] as $page){
					// get all item of page (and add current doc_type)
					$temp_items = $this->dossier_repository->findItemByDossierIdAndPage($dsp_id, $page->page_libelle);
					// add doc type to each items
					foreach ($temp_items as $key => $value){
						$temp_items[$key]->document_type = $doc_type;
						$temp_items[$key]->bloc_libelle = $temp_items[$key]->page_libelle;
						$temp_items[$key]->page_libelle = $doc_type;
						$temp_items[$key]->id = $temp_items[$key]->id."_".$doc_type;
					}
					$items = array_merge($items, $temp_items);
				}
			}
			$items = array_map(function($v){ return $v->toMCArray(); }, $items);
		}
		foreach ($items as $item){
			$item_id = $item['ITEM_ID']; 
			// Cas particulier des items séparateurs (MCTYPE = 'SEP') où l'item_id est null dans MiddleCare
			if(empty($item_id))
				$item_id = $item['MCTYPE']."_".$item['LIGNE'];
			$item_infos[] = $item;
		}
		return $item_infos;
	}

	// ---- CSV Helpers

	/**
	 * Insère des informations complémentaires sur les types des items sur les première lignes du tableau des données.
	 */
	private function insert_items_infos(array $rows, $dsp_id){
		$new_rows = array();
		if(count($rows) > 0){
			$item_info = $this->get_items($dsp_id);
			$item_lib = array();
			$item_page = array();
			$item_type = array();
			$item_liste_values = array();
			$items_keys = array_keys($rows[0]);
			foreach ($items_keys as $key){
				// get key
				$key_exist = false;
				foreach ($item_info as $value) {
					if($key === $value['ITEM_ID']){
						$item_information = $value;
						$key_exist = true;
						break;
					}
				}
				if($key_exist === true){
					$item_lib[$key] = $item_information['LIBELLE_BLOC'];
					$item_page[$key] = $item_information['PAGE_LIBELLE'];
					$item_type[$key] = $item_information['MCTYPE'];
					$item_liste_values[$key] = $item_information['LIST_VALUES'];
				}else{
					$item_lib[$key] = $key;
					$item_page[$key] = $key;
					$item_type[$key] = $key;
					$item_liste_values[$key] = $key;
				}
			}
			$new_rows[] = $item_lib;
			$new_rows[] = $item_page;
			$new_rows[] = $item_type;
			$new_rows[] = $item_liste_values;
		}
		return array_merge($new_rows,$rows);
	}

	// ---- RedCap Helpers

	// Transcode un document MiddleCare vers un event RedCap :
	// - pour les champs à liste de valeur = remplacer la valeur par l'index dans la liste (et ajouter autant de colonne que de valeurs différentes possibles)
	// - pour les checkbox simple oui/non remplacer valeur 'on' par 1 sinon 0
	// WIP
	private static function transcode_mc_data_to_rc_data($mc_data_row,$rc_dictionnary,$event_as_document_type){
		$new_row = array();
		// Si projet RC "event as doct", récupérer le type du document 
		$document_type = null;
		if($event_as_document_type === true){
			$document_type = $mc_data_row['TYPE_EXAM'];
			foreach($mc_data_row as $key => $value){
				$var_name = $key;
				// Chercher l'item correspondant à la variable (et au type de document dans le cas des projets "Event as Doct"
				// dans le data dictionnary RedCap.
				// ex: 
				// - variable de document : VAR124 -> Item VAR124 (classique) ou VAR124 -> Item "VAR124_Cr de CS"  (EaD)
				// - variable patient : IPP -> Item IPP (classique ou EaD) 
				$rc_dictionnary_item = $rc_dictionnary->search_item($var_name,$document_type);//$rc_dictionnary->items[$var_name];
				if($rc_dictionnary_item === null)
					continue;
				
				$var_name = RCDictionnary::clean_variable_name($rc_dictionnary_item[RCDictionnary::FIELD_NAME_INDEX]);
				
				switch($rc_dictionnary_item[RCDictionnary::FIELD_TYPE_INDEX]){
					case 'dropdown': 
						// TEST suppression de la valeur (= '') quand valeur vide
						$new_row[$var_name] = empty($value) ? '' : RCDictionnary::get_index_from_value($value, $rc_dictionnary_item[RCDictionnary::CHOICES_INDEX]);	
						//$new_row[$var_name] = RCDictionnary::get_index_from_value($value, $rc_dictionnary_item[RCDictionnary::CHOICES_INDEX]);	
						break;
					case 'checkbox': 
						// pour une  liste donnée ex : var_name = 'surlacommode', value ='OUI#NON'
						// recuperer tableau des valeurs possibles, ex : ["1" => "", "2" => "OUI", "3" => "NON"]
						$choices = RCDictionnary::get_choices_values($rc_dictionnary_item[RCDictionnary::CHOICES_INDEX]);
						// recuperer tableau des valeurs mises, ex ["OUI","NON"]
						$values = explode('#',$value);
						// pour chacunes des valeurs possibles, ajouter un element au tableau de cle 'surlacommode___x' et y mettre la valeur 1 ou 0 si le tableau des valeurs possible possede in index === x
						foreach ($choices as $k => $v){
							// TEST suppression de la valeur (= '') quand case non coché
							$new_row[$var_name."___".$k] = !empty($v) && in_array($v, $values) ? 1 : 0;
							// $new_row[$var_name."___".$k] = in_array($v, $values) ? 1 : 0;
						}
						break;
					case 'yesno': 
						// TEST suppression de la valeur (= '') quand case non coché
						$new_row[$var_name] = $value === "on" ? 1 : '';
						// $new_row[$var_name] = $value === "on" ? 1 : 0;
						break;
					default : 
						$new_row[$var_name] = $value;
						break;
				}
			}
		}else{
			// Pour chaque variable du document MiddleCare
			foreach($mc_data_row as $key => $value){
				$var_name = $key;
			
				// Chercher l'item correspondant à la variable (et au type de document dans le cas des projets "Event as Doct"
				// dans le data dictionnary RedCap.
				// ex: 
				// - variable de document : VAR124 -> Item VAR124 (classique) ou VAR124 -> Item "VAR124_Cr de CS"  (EaD)
				// - variable patient : IPP -> Item IPP (classique ou EaD) 
				$rc_dictionnary_item = $rc_dictionnary->search_item($var_name,$document_type);//$rc_dictionnary->items[$var_name];
				if($rc_dictionnary_item === null)
					continue;
				
				$var_name = RCDictionnary::clean_variable_name($rc_dictionnary_item[RCDictionnary::FIELD_NAME_INDEX]);
				
				switch($rc_dictionnary_item[RCDictionnary::FIELD_TYPE_INDEX]){
					case 'dropdown': 
						$new_row[$var_name] = RCDictionnary::get_index_from_value($value, $rc_dictionnary_item[RCDictionnary::CHOICES_INDEX]);	
						break;
					case 'checkbox': 
						// pour une  liste donnée ex : var_name = 'surlacommode', value ='OUI#NON'
						// recuperer tableau des valeurs possibles, ex : ["1" => "", "2" => "OUI", "3" => "NON"]
						$choices = RCDictionnary::get_choices_values($rc_dictionnary_item[RCDictionnary::CHOICES_INDEX]);
						// recuperer tableau des valeurs mises, ex ["OUI","NON"]
						$values = explode('#',$value);
						// pour chacunes des valeurs possibles, ajouter un element au tableau de cle 'surlacommode___x' et y mettre la valeur 1 ou 0 si le tableau des valeurs possible possede in index === x
						foreach ($choices as $k => $v)
							$new_row[$var_name."___".$k] = in_array($v, $values) ? 1 : 0;
						break;
					case 'yesno': 
						$new_row[$var_name] = $value === "on" ? 1 : 0;
						break;
					default : 
						$new_row[$var_name] = $value;
						break;
				}
			}
		}
		return $new_row;
	}
}