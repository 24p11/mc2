<?php
namespace MC2\RedCap;
class RCService{

	private $input_folder;
    private $api_token;
    private $api_url;
    private $logger;

    /**
     * @param string $api_url
     * @param string $api_token
     * @param Psr\Log\LoggerInterface $logger
     */
    public function __construct($input_folder,$api_url,$api_token,$logger){
		$this->input_folder = $input_folder;
        $this->api_url = $api_url;
        $this->api_token = $api_token;
        $this->logger = $logger;
    }

	public function import_data_file($file_name, $overwrite = false){
		$this->logger->info("-------- RCAPI importing file $file_name");
		$data = array(
			'token' => $this->api_token,
			'content' => 'record',
			'format' => 'csv',
			'type' => 'flat',
			'overwriteBehavior' => ($overwrite === true) ? 'overwrite' : 'normal',
			'forceAutoNumber' => 'false',
			'data' => '',
			'dateFormat' => 'DMY',
			'returnContent' => 'count',
			'returnFormat' => 'json'
		);
		$data['data'] = file_get_contents($this->input_folder."/{$file_name}");
		$this->logger->info("RCAPI import file $file_name", array());//'data' => $data
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $this->api_url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_VERBOSE, 0);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_AUTOREFERER, true);
		curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
		curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data, '', '&'));
		$output = curl_exec($ch);
		print $output;
		curl_close($ch);
	}
}