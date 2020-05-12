<?php
namespace MC2\MiddleCare;
use \DateTime;
use \DateInterval;
use Psr\Log\LoggerInterface;
use MC2\Core\CSV\CSVFile;
use MC2\Core\Helper\DateHelper;
use MC2\Core\Helper\ArrayHelper;
use MC2\DSP\Dossier;
use MC2\DSP\Document;
use MC2\DSP\ItemValue;
use MC2\DSP\Patient;
use MC2\DSP\Page;
use MC2\DSP\Item;
use MC2\RedCap\RCDictionnary;
/**
 * MCExtractManager
 * 
 * - importAllDSPMetadata()
 * - importDSPDictionnary($dsp_id)
 * - importDSPData($dsp_id, $date_debut, $date_fin, array $item_names = null,$date_update = false)
 * - importDSPDocumentData(dsp_id, $nipro, array $item_names = null)
 * 
 * - exportAllDSPMetadataToCSV()
 * - exportDSPDictionnaryToCSV($dsp_id, array $item_names = null)
 * - exportDSPDataToCSV($dsp_id, $date_debut, $date_fin, array $item_names = null, $page_name = null,$type_doc = null, $interval = null)
 * 
 * - exportDSPDictionnaryToRedcapCSV($file_name_prefix, $dsp_id, $rc_project)
 * - exportDSPDataToRedcapCSV($file_name_prefix, $dsp_id, $date_debut, $date_fin, $rc_project)
 * 
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

	// tableau des categories de documents à prendre (tout prendre si null)
	// ex: public $categories_selection = ['120','201','402'];// ne prendre que les CRH d'hospit, les CR de consultation et les CRO
	public $categories_selection = null;

	// interval de chargement des données (a affiner en fonction de la quantité de document du DSP) 1 semaine = P7D, 1 mois = P1M, 2 mois = P2M, 60 jours =  P60D
	public $import_interval = "P7D"; 

	public $upsert_max_document = 1000;
	public $upsert_max_item = 200;
	public $upser_max_patient = 1000;

	public function __construct($source, $site, $mc_repository, $dossier_repository, $document_repository, $patient_repository, $csv_writer, LoggerInterface $logger){
		$this->source = $source;
		$this->site = $site;
		$this->mc_repository = $mc_repository;
		$this->dossier_repository = $dossier_repository;
		$this->document_repository = $document_repository;
		$this->patient_repository = $patient_repository;
		$this->csv_writer = $csv_writer;
		$this->logger = $logger;
	}

	// -------- MC to DB (MiddleCare to MySQL)

	/**
	 * Importe la liste des DSP d'un site depuis MC vers DB.
	 */
	public function importAllDSPMetadata(){
		$log_info = array('site' => $this->site);
		$this->logger->info("Importing all DSP metadata",$log_info);
        $all_dsp = $this->mc_repository->getAllDSP();
        foreach ($all_dsp as $key => $dsp_row)
			$this->dossier_repository->upsertDossier(Dossier::createFromMCData($dsp_row));
		$this->logger->info("Imported all DSP metadata",$log_info);
	}

	/**
	 * Importe le dictionnaire (items + pages) d'un DSP depuis MC vers DB.
	 * 
	 * @param string $dsp_id identifiant du DSP, ex: 'DSP2'
	 */
	public function importDSPDictionnary($dsp_id){
		$log_info = array('site' => $this->site, 'dsp_id' => $dsp_id);
		$this->logger->info("Importing DSP Dictionnary",$log_info);
        $all_dsp_item = $this->mc_repository->getDSPItems($dsp_id);
        foreach ($all_dsp_item as $key => $dsp_item_row)
            $this->dossier_repository->upsertItem(Item::createFromMCData($dsp_item_row));
        $all_dsp_page = $this->mc_repository->getDSPPages($dsp_id);
        foreach ($all_dsp_page as $key => $dsp_page_row)
			$this->dossier_repository->upsertPage(Page::createFromMCData($dsp_id,$dsp_page_row));
		$this->logger->info("Imported DSP Dictionnary",$log_info);
	}

	/**
	 * Importe les données d'un DSP depuis MC vers DB.
	 * 
	 * @param string $dsp_id identifiant du DSP, ex: 'DSP2'
	 * @param \DateTime $date_debut
	 * @param \DateTime $date_fin
	 * @param array $item_names
	 * @param boolean $date_update si true ne prendre que les données mise à jour entre date_début et date_fin (sinon on se base sur la date de creation du document)
	 */
	public function importDSPData($dsp_id, $date_debut, $date_fin, array $item_names = null, $date_update = false){
		$log_info = array(
			'site' => $this->site, 
			'dsp_id' => $dsp_id,
			'date_debut' => $date_debut->format(DateHelper::MYSQL_FORMAT), 
			'date_fin' => $date_fin->format(DateHelper::MYSQL_FORMAT), 
			'item_names' => $item_names
		);
		$this->logger->info("Importing DSP data",$log_info);
		$interval_max = new DateInterval($this->import_interval);
		if($date_debut->diff($date_fin) < $interval_max){
			$this->loadDSPDataFromMCtoDB($dsp_id, $date_debut, $date_fin,$item_names, $date_update);
		}else{
			$date1 = clone $date_debut;
			$date2 = clone $date1;
			$date2->add($interval_max);
			while($date2 < $date_fin){
				$this->loadDSPDataFromMCtoDB($dsp_id, $date1, $date2,$item_names, $date_update);
				$date1 = clone $date2;
				$date2->add($interval_max);
			}
			$this->loadDSPDataFromMCtoDB($dsp_id, $date1, $date_fin,$item_names, $date_update);
		}
		$this->logger->info("Imported DSP data",$log_info);
	}

	public function importDSPDocumentData($dsp_id, $nipro, array $item_names = null){
		$log_info = array(
			'site' => $this->site, 
			'dsp_id' => $dsp_id,
			'nipro' => $nipro, 
			'item_names' => $item_names
		);
		$this->logger->info("Importing DSP data",$log_info);
		$this->loadDSPDocumentDataFromMCtoDB($dsp_id, $nipro,$item_names);
		$this->logger->info("Imported DSP data",$log_info);
	}

	// -------- CSV
	
	/**
	 * Exporte la liste des DSP (depuis MiddleCare ou la base Locale) vers fichier CSV.
	 * 
	 * @return string nom du CSV généré
	 */
	public function exportAllDSPMetadataToCSV(){
		$log_info = array(
			'source' => $this->getSourceName(),
			'site' => $this->site
		);
		$this->logger->info("Exporting list of DSP to CSV file",$log_info);
		$all_dsp = $this->isSourceMiddleCare() 
			? $this->mc_repository->getAllDSP()
			: array_map(function($v){ return $v->toMCArray(); }, $this->dossier_repository->findAllDossier());
		$file_name = $this->csv_writer->save(new CSVFile("DSP_".strtoupper($this->site),$all_dsp));
		$log_info['file'] = $file_name;
		$this->logger->info("Exported list of DSP to CSV file",$log_info);
		return $file_name;
	}

	/**
	 * Exporte le dictionnaire d'un DSP (depuis MiddleCare ou la base Locale) vers un fichier CSV.
	 * 
	 * @param string $dsp_id identifiant du DSP, ex: 'DSP2'
	 * @param string[] $item_names (option) liste de nom d'items
	 * @return string nom du CSV généré
	 */
	public function exportDSPDictionnaryToCSV($dsp_id, array $item_names = null){
		$log_info = array(
			'source' => $this->getSourceName(),
			'site' => $this->site, 
			'dsp_id' => $dsp_id,
			'item_names' => $item_names
		);
		$this->logger->info("Exporting DSP dictionnary to CSV file", $log_info);
		$item_infos = $this->isSourceMiddleCare() ?
			$this->mc_repository->getDSPItems($dsp_id,$item_names)
			: array_map(function($v){ return $v->toMCArray(); }, $this->dossier_repository->findItemByDossierId($dsp_id,$item_names));
		$file_subtitle = ($item_names === null || count($item_names) < 1) ? "" : "_partial";
		$file_name = $this->csv_writer->save(new CSVFile("DSP_".strtoupper($this->site)."_{$dsp_id}_dictionnary{$file_subtitle}",$item_infos));
		$log_info['items_count'] = count($item_infos);
		$this->logger->info("Exported DSP dictionnary to CSV file", $log_info);
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
	public function exportDSPDataToCSV($dsp_id, $date_debut, $date_fin, array $item_names = null, $page_name = null,$type_doc = null, $interval = null){
		$log_info = array(
			'source' => $this->getSourceName(),
			'site' => $this->site, 
			'dsp_id' => $dsp_id,
			'date_debut' => $date_debut->format(DateHelper::MYSQL_FORMAT), 
			'date_fin' => $date_fin->format(DateHelper::MYSQL_FORMAT), 
			'item_names' => $item_names,
			'page_name' => $page_name,
			'type_doc' => $type_doc,
			'interval' => $interval
		);
		$this->logger->info("Exporting DSP data to CSV file(s)",$log_info);
		$file_names = array();
		$interval = $interval === null ? "P1M" : $interval;
		$interval_max = new DateInterval($interval);
		if($date_debut->diff($date_fin) < $interval_max){
			$file_names[] = $this->exportDSPDataToCSVChunck($dsp_id,$date_debut,$date_fin,$item_names,$page_name,$type_doc);
		}else{
			$date1 = clone $date_debut;
			$date2 = clone $date1;
			$date2->add($interval_max);
			while($date2 < $date_fin){
				$file_names[] = $this->exportDSPDataToCSVChunck($dsp_id,$date1,$date2,$item_names,$page_name,$type_doc);
				$date1 = clone $date2;
				$date2->add($interval_max);
			}
			$file_names[] = $this->exportDSPDataToCSVChunck($dsp_id,$date1,$date_fin,$item_names,$page_name,$type_doc);
		}
		$log_info['files'] = join(",",$file_names);
		$this->logger->info("Exported DSP data to CSV file(s)",$log_info);
		return $file_names;
	}

	// -------- REDCAP CSV (dictionnary.csv + data.csv)

	/**
	 * Exporte un Data Dictonnary RedCap pour un DSP donné (depuis MiddleCare ou la base Locale) au format CSV.
	 * 
	 * @param string $file_name 
	 * @param string $dsp_id identifiant du DSP, ex: 'DSP2'
	 * @param \SBIM\RedCap\RCProject $rc_project
	 */
	public function exportDSPDictionnaryToRedcapCSV($file_name_prefix, $dsp_id, $rc_project){
		$log_info = array(
			'source' => $this->getSourceName(),
			'site' => $this->site, 
			'dsp_id' => $dsp_id,
			'file_name_prefix' => $file_name_prefix,
			'main_instrument.name' => $rc_project->main_instrument->name, 
			'main_instrument.item_names' => join(',',$rc_project->main_instrument->item_names)
		);
		$this->logger->info("Exporting RC data dictionnary to CSV file",$log_info);
		if($rc_project->event_as_document_type === false){
			$mc_items = $this->getItems($dsp_id);
			$rc_dictionnary = RCDictionnary::createFromItemsAndRCProject($dsp_id, $mc_items, $rc_project);
		}else{
			$mc_items = $this->getItems($dsp_id,$rc_project->event_as_document_type);
			$rc_dictionnary = RCDictionnary::createFromItemsAndRCProjectWithPages($dsp_id, $mc_items, $rc_project);
		}
		$file_name = $this->csv_writer->save(new CSVFile($file_name_prefix,$rc_dictionnary->items));
		$log_info['item_count'] = count($rc_dictionnary->items);
		$log_info['file_name'] = $file_name;
		$this->logger->info("Exported RC data dictionnary to CSV file",$log_info);
		return $file_name;
	}

	/**	
	 * Exporte les données RedCap d'un DSP depuis la base locale pour tous les patients venus sur une période donnée vers fichier RedCap  CSV.
	 * 
	 * IMPORTANT : 
	 * Si les données RedCap doivent être mise à jour après le chargement initial, il est important de garder la même date de début et si besoin de ne modifier que la date de fin de la période à charger.
	 * En effet, dans le cas des projets longitudinaux, l'API RedCap nous contraint à assigner un numéro "redcap_repeat_instance" à un document qui doit rester le même d'un chargement à un autre. 
	 * Ici, le document le plus ancien prend la valeur redcap_repeat_instance=1 et les documents suivants incrementent cette valeur. 
	 * Si on change la date de début après le premier chargement, l'ancien document le plus ancien risque de ne plus la même valeur redcap_repeat_instance, ce qui risque de provoquer des incohérences dans les données.
	 * 
	 * @param string $file_name_prefix préfix du nom fichier résultat
	 * @param string $dsp_id identifiant du DSP, ex: 'DSP2'
	 * @param \DateTime $date_debut
     * @param \DateTime $date_fin
	 * @param \SBIM\RedCap\RCProject $rc_project
	 */
	public function exportDSPDataToRedcapCSV($file_name_prefix, $dsp_id, $date_debut, $date_fin, $rc_project){
		$log_info = array(
			'site' => $this->site, 
			'dsp_id' => $dsp_id,
			'date_debut' => $date_debut->format(DateHelper::MYSQL_FORMAT), 
			'date_fin' => $date_fin->format(DateHelper::MYSQL_FORMAT), 
			'file_name_prefix' => $file_name_prefix
		);
		$this->logger->info("Exporting RC data by patient from local DB to CSV",$log_info);

		// Get RC dictionnary
		$rc_dictionnary = null;
		if($rc_project->event_as_document_type === false){
			$mc_items = $this->getItems($dsp_id);
			$rc_dictionnary = RCDictionnary::createFromItemsAndRCProject($dsp_id, $mc_items, $rc_project);
		}else{
			$mc_items = $this->getItems($dsp_id,$rc_project->event_as_document_type);
			$rc_dictionnary = RCDictionnary::createFromItemsAndRCProjectWithPages($dsp_id, $mc_items, $rc_project);
		}
		$data_column_names = $rc_dictionnary->getColumnNames();

		// Get all patient from period
		$patient_ids = $this->document_repository->findAllPatientId($dsp_id, $date_debut, $date_fin);
		
		$file_names = array();
		$CHUNK_COUNT = 10;
		$chunks_of_patientids = array_chunk($patient_ids, $CHUNK_COUNT);
		$chunk_i = 0;
		foreach($chunks_of_patientids as $patientids_chunk){
			$chunk_i++;

			// Get MC Data
			$mc_data = array();
			$items = $this->dossier_repository->findItemByDossierId($dsp_id,$rc_project->main_instrument->item_names);
			$documents = $this->document_repository->findDocumentWithItemValues($dsp_id, $date_debut, $date_fin, $rc_project->main_instrument->item_names, null,$patientids_chunk);
			foreach ($documents as $document){
				$patients = $this->patient_repository->findPatient($document->patient_id);
				$document->patient = count($patients) > 0 ? $patients[0] : null;
				$mc_data[] = $document->toMCArray($items);
			}

			// Transcoding MC data to RC data
			$rc_data = array();
			if($rc_project->longitudinal === false){
				foreach($mc_data as $data){
					$rc_data[] = ArrayHelper::reorderColumns(
						$this->transcodeDSPDataToRedcapData($data,$rc_dictionnary,$rc_project->event_as_document_type),
						$data_column_names
					);
				}
			}else{
				
				$shared_event_name = $rc_project->getUniqueSharedEventName();
				$repeatable_event_name = $rc_project->getUniqueRepeatableEventName();
				$shared_event_variable_count = $rc_project->shared_event_variable_count;
				
				$columns_completed = array_map(
					function($v){ 
						return preg_replace('/^[0-9]/','', str_replace('__','_',str_replace(' ','_',strtolower($v))),1).'_complete';
					}, 
					$rc_dictionnary->getFormNames()
				);
				$completes = array();
				foreach($columns_completed as $value)
					$completes[$value] = null;
				
				$patients_redcap_repeat_instance = array();
				foreach($mc_data as $data){
					$tmp = ArrayHelper::reorderColumns(
						$this->transcodeDSPDataToRedcapData($data,$rc_dictionnary,$rc_project->event_as_document_type),
						$data_column_names
					);

					// If new patient, add row for patient event (IPP,redcap_event_name,redcap_repeat_instrument,redacap_repeat_instance...)
					if(!array_key_exists($tmp['IPP'],$patients_redcap_repeat_instance)){
						$patient = array_slice($tmp, 0, 1, true) 
							+ array('redcap_event_name' => $shared_event_name,'redcap_repeat_instrument'=> null, 'redcap_repeat_instance' => null) 
							+ array_slice($tmp, 1, $shared_event_variable_count, true)
							+ array_map(function() {}, array_slice($tmp, $shared_event_variable_count , null, true))
							+ $completes;
						$patients_redcap_repeat_instance[$tmp['IPP']] = 1;
						$rc_data[] = $patient;
					}
					
					// Add row for repeatable event
					$redcap_repeat_instance = $patients_redcap_repeat_instance[$tmp['IPP']];
					$rc_data[] = array_slice($tmp, 0, 1, true) 
						+ array('redcap_event_name' => $repeatable_event_name,'redcap_repeat_instrument'=> null, 'redcap_repeat_instance' => $redcap_repeat_instance)
						+ array_map(function() {}, array_slice($tmp, 1, $shared_event_variable_count, true))
						+ array_slice($tmp, $shared_event_variable_count, null, true)
						+ $completes;

					// Increment 'redcap_repeat_instance' for each repeatable event
					$patients_redcap_repeat_instance[$tmp['IPP']]++;
				}
			}
			
			// Write CSV
			$prefix = "{$file_name_prefix}_".$date_debut->format('Ymd')."-".$date_fin->format('Ymd')."_".$chunk_i;
			$file_name = $this->csv_writer->save(new CSVFile($prefix, $rc_data));
			$file_names[] = $file_name;
			$this->logger->info("Exported RC data from local DB to {$file_name}, chunck {$chunk_i}-".($chunk_i + $CHUNK_COUNT), array('row_count' => count($rc_data)));
		}
		return $file_names;
	}

	// -------- Helpers 

	private function isSourceMiddleCare(){
		return $this->source === self::SRC_MIDDLECARE;
	}

	private function getSourceName(){
		return $this->source === self::SRC_MIDDLECARE ? "MiddleCare" : "Local DB";
	}

	/**
	 * Retourne les items d'un DSP (depuis MiddleCare ou la base Locale)
	 * 
	 * @param string $dsp_id identifiant du DSP, ex: 'DSP2'
	 */
	private function getItems($dsp_id,$event_as_document = false){
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

	private function exportDSPDataToCSVChunck($dsp_id, $date_debut, $date_fin, array $item_names = null, $page_name = null,$type_doc = null, $date_update = false){
		if($this->isSourceMiddleCare()){
			$items = $this->mc_repository->getDSPItems($dsp_id,$item_names,$page_name);
			$data = $this->mc_repository->getDSPData($dsp_id,$date_debut,$date_fin,$items, $date_update);
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
		$data = $this->addItemsDetailsToDSPData($data, $dsp_id);
		$prefix = "DSP_".strtoupper($this->site)."_{$dsp_id}_data_".$date_debut->format('Ymd')."-".$date_fin->format('Ymd');
		$file_name = $this->csv_writer->save(new CSVFile($prefix, $data));
		$this->logger->debug("Exported DSP data chunk to CSV file",array('file' => $file_name));
		return $file_name;
	}
	
	private function loadDSPDataFromMCtoDB($dsp_id, $date_debut, $date_fin, array $item_names = null, $date_update = false){
		$this->logger->debug("loadDSPDataFromMCtoDB ".$date_debut->format(DateHelper::MYSQL_FORMAT)." to ".$date_fin->format(DateHelper::MYSQL_FORMAT));
		$now = new DateTime();
		// get documents by category 
		$mc_documents = [];
		$categories = $this->mc_repository->getCategoriesOfPeriod($dsp_id,$date_debut,$date_fin,$date_update);
		foreach($categories as $category){
			if($this->categories_selection !== null && count($this->categories_selection) > 0 && !in_array($category,$this->categories_selection))
				continue;
			$this->logger->debug("Loading documents from category: $category");
			$items = $this->mc_repository->getDSPItemsFromDocumentCategory($dsp_id,$category);
			$this->logger->debug("Items count : ".count($items));
			$tmp_mc_documents = $this->mc_repository->getDSPData($dsp_id,$date_debut,$date_fin,$items,$category,$date_update);
			$this->logger->debug("Getting DSP data took ".$now->diff(new DateTime())->format('%H:%I:%S'));

			// Delete document and item values
			$nipros = array_unique(array_column($tmp_mc_documents, 'NIPRO'));
			$this->logger->debug(join(",",$nipros));
			$this->document_repository->deleteDocumentsAndItemValues($dsp_id, $nipros, $item_names);
			$this->logger->debug("Deleted documents and item values after ".$now->diff(new DateTime())->format('%H:%I:%S'));

			$mc_documents = array_merge($mc_documents,$tmp_mc_documents);
			
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
			
				// Upsert documents every upsert_max_document documents
				if($i % $this->upsert_max_document === 0){
					$this->document_repository->upsertDocuments($documents);
					$this->logger->debug("Upserted ".count($documents)." documents");
					$documents = array();
				}
				// Upsert item values every upsert_max_item item values
				if($i % $this->upsert_max_item === 0){
					$this->document_repository->upsertItemValues($item_values);
					$this->logger->debug("Upserted ".count($item_values)." item values");
					$item_values = array();
				}
				// Upsert patients every upser_max_patient patients
				if($i % $this->upser_max_patient === 0){
					$this->patient_repository->upsertPatients($patients);
					$this->logger->debug("Upserted ".count($patients)." patients");
					$patients = array();
				}
				$i++;
			}
			$this->document_repository->upsertDocuments($documents);
			$this->document_repository->upsertItemValues($item_values);
			$this->patient_repository->upsertPatients($patients);
			$this->logger->debug("Upserted ".count($documents)." documents");
			$this->logger->debug("Upserted ".count($item_values)." item values");
			$this->logger->debug("Upserted ".count($patients)." patients");

			$this->document_repository->updateDocumentsFullText($nipros);
			$this->logger->debug("Updated ".count($nipros)." documents fulltext");
		}
	}

	private function loadDSPDocumentDataFromMCtoDB($dsp_id, $nipro){
		$this->logger->debug("loadDSPDocumentDataFromMCtoDB ".$nipro);
		$now = new DateTime();
		// Get data 
		$document_category = $this->mc_repository->getCategoryOfDocument($nipro);
		$items = $this->mc_repository->getDSPItemsFromDocumentCategory($dsp_id,$document_category);
		$mc_documents = $this->mc_repository->getDSPDataFromNIPRO($dsp_id, $nipro, $items);
		$this->logger->debug("Getting DSP data took ".$now->diff(new DateTime())->format('%H:%I:%S'));
		$patients = array();
		// Delete document and item values
		$nipros = array_unique(array_column($mc_documents, 'NIPRO'));
		$this->document_repository->deleteDocumentsAndItemValues($dsp_id, $nipros);
		$this->logger->debug("Deleted documents and item values after ".$now->diff(new DateTime())->format('%H:%I:%S'));
		
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
		
			// Upsert documents every upsert_max_document documents
			if($i % $this->upsert_max_document === 0){
				$this->document_repository->upsertDocuments($documents);
				$this->logger->debug("Upserted ".count($documents)." documents");
				$documents = array();
			}
			// Upsert item values every upsert_max_item items
			if($i % $this->upsert_max_item === 0){
				$this->document_repository->upsertItemValues($item_values);
				$this->logger->debug("Upserted ".count($item_values)." item values");
				$item_values = array();
			}
			// Upsert patients every upser_max_patient patients
			if($i % $this->upser_max_patient === 0){
				$this->patient_repository->upsertPatients($patients);
				$this->logger->debug("Upserted ".count($patients)." patients");
				$patients = array();
			}
			$i++;
		}
		$this->document_repository->upsertDocuments($documents);
		$this->document_repository->upsertItemValues($item_values);
		$this->patient_repository->upsertPatients($patients);
		$this->logger->debug("Upserted ".count($documents)." documents");
		$this->logger->debug("Upserted ".count($item_values)." item values");
		$this->logger->debug("Upserted ".count($patients)." patients");
		
		$this->document_repository->updateDocumentsFullText($nipros);
		$this->logger->debug("Updated ".count($nipros)." documents fulltext");
	}

	// ---- CSV Helpers

	/**
	 * Insère des informations complémentaires sur les types des items sur les premières lignes du tableau des données.
	 */
	private function addItemsDetailsToDSPData(array $rows, $dsp_id){
		$new_rows = array();
		if(count($rows) > 0){
			$item_info = $this->getItems($dsp_id);
			$item_lib = array();
			$item_page = array();
			$item_type = array();
			$item_liste_values = array();
			$items_keys = array_keys($rows[0]);
			foreach ($items_keys as $key){
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
	private static function transcodeDSPDataToRedcapData($mc_data_row,$rc_dictionnary,$event_as_document_type){
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
				$rc_dictionnary_item = $rc_dictionnary->searchItem($var_name,$document_type);
				if($rc_dictionnary_item === null)
					continue;
				
				$var_name = RCDictionnary::cleanItemName($rc_dictionnary_item[RCDictionnary::FIELD_NAME_INDEX]);
				switch($rc_dictionnary_item[RCDictionnary::FIELD_TYPE_INDEX]){
					case 'dropdown': 
						// suppression de la valeur (= '') quand valeur vide
						$new_row[$var_name] = empty($value) ? '' : RCDictionnary::getIndexInValues($value, $rc_dictionnary_item[RCDictionnary::CHOICES_INDEX]);	
						break;
					case 'checkbox': 
						// pour une  liste donnée ex : var_name = 'surlacommode', value ='OUI#NON'
						// recuperer tableau des valeurs possibles, ex : ["1" => "", "2" => "OUI", "3" => "NON"]
						$choices = RCDictionnary::getChoiceValues($rc_dictionnary_item[RCDictionnary::CHOICES_INDEX]);
						// recuperer tableau des valeurs mises, ex ["OUI","NON"]
						$values = explode('#',$value);
						// pour chacunes des valeurs possibles, ajouter un element au tableau de cle 'surlacommode___x' et y mettre la valeur 1 ou 0 si le tableau des valeurs possible possede in index === x
						foreach ($choices as $k => $v){
							// suppression de la valeur (= '') quand case non cochée
							$new_row[$var_name."___".$k] = !empty($v) && in_array($v, $values) ? 1 : 0;
						}
						break;
					case 'yesno': 
						// suppression de la valeur (= '') quand case non coché
						$new_row[$var_name] = $value === "on" ? 1 : '';
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
				$rc_dictionnary_item = $rc_dictionnary->searchItem($var_name,$document_type);
				if($rc_dictionnary_item === null)
					continue;
				
				$var_name = RCDictionnary::cleanItemName($rc_dictionnary_item[RCDictionnary::FIELD_NAME_INDEX]);
				switch($rc_dictionnary_item[RCDictionnary::FIELD_TYPE_INDEX]){
					case 'dropdown': 
						$new_row[$var_name] = RCDictionnary::getIndexInValues($value, $rc_dictionnary_item[RCDictionnary::CHOICES_INDEX]);	
						break;
					case 'checkbox': 
						// pour une  liste donnée ex : var_name = 'surlacommode', value ='OUI#NON'
						// recuperer tableau des valeurs possibles, ex : ["1" => "", "2" => "OUI", "3" => "NON"]
						$choices = RCDictionnary::getChoiceValues($rc_dictionnary_item[RCDictionnary::CHOICES_INDEX]);
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