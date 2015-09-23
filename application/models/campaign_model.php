<?php  if ( ! defined('BASEPATH')) exit('No direct script access allowed');


class campaign_model extends CI_Model {

	var $jurisdictions 		= array();
	var $protected_field	= null;
	var $validation_counts  = null;
	var $current_office_id  = null;
	var $validation_pointer = null;
	var $validation_log 	= null;
	var $schema 			= null;


	public function __construct(){
		parent::__construct();

		$this->load->helper('api');
        $this->load->library('DataJsonParser');

		// Determine the environment we're run from for debugging/output
		if (php_sapi_name() == 'cli') {
			if (isset($_SERVER['TERM'])) {
				$this->environment = 'terminal';
			} else {
				$this->environment = 'cron';
			}
		} else {
			$this->environment = 'server';
		}

		//$this->office					= $this->office();

	}


	public function validation_count_model() {

		$count = array(
			'http_5xx' => 0,
			'http_4xx' => 0,
			'http_3xx' => 0,
			'http_2xx' => 0,
			'http_0' => 0,			
			'pdf' => 0,
			'html' => 0,
			'format_mismatch' => 0
			);

		return $count;
	}





	public function uri_header($url, $redirect_count = 0) {

		$tmp_dir = $tmp_dir = $this->config->item('archive_dir');

		$status = curl_header($url, true, $tmp_dir);
		$status = $status['info'];	//content_type and http_code

		if($status['redirect_count'] == 0 && !(empty($redirect_count))) $status['redirect_count'] = 1;
		$status['redirect_count'] = $status['redirect_count'] + $redirect_count;

		if(!empty($status['redirect_url'])) {
			if($status['redirect_count'] == 0 && $redirect_count == 0) $status['redirect_count'] = 1;

			if ($status['redirect_count'] > 5) return $status;
			$status = $this->uri_header($status['redirect_url'], $status['redirect_count']);
		}

		if(!empty($status)) {
			return $status;
		} else {
			return false;
		}
	}



	public function validate_datajson($datajson_url = null, $datajson = null, $headers = null, $schema = null, $return_source = false, $quality = false, $component = null) {


		if ($datajson_url) {

			$datajson_header = ($headers) ? $headers : $this->campaign->uri_header($datajson_url);

			$errors = array();

			// Max file size
			$max_remote_size = $this->config->item('max_remote_size');


			// Only download the data.json if we need to
			if(empty($datajson_header['download_content_length']) || 
				$datajson_header['download_content_length'] < 0 || 
				(!empty($datajson_header['download_content_length']) && 
				$datajson_header['download_content_length'] > 0 && 
				$datajson_header['download_content_length'] < $max_remote_size)) {

				// Load the JSON
				$opts = array(
							  'http'=>array(
							    'method'=>"GET",
							    'user_agent'=>"Data.gov data.json crawler"
							  )
							);

				$context = stream_context_create($opts);
				
				$datajson = @file_get_contents($datajson_url, false, $context, -1, $max_remote_size+1);

				if ($datajson == false) {

					$datajson = curl_from_json($datajson_url, false, false);

					if(!$datajson) {
						$errors[] = "File not found or couldn't be downloaded";	
					}
					
				} 

			}


			if(!empty($datajson) && (empty($datajson_header['download_content_length']) || $datajson_header['download_content_length'] < 0)) {
				$datajson_header['download_content_length'] = strlen($datajson);
			}

			// See if it exceeds max size
			if($datajson_header['download_content_length'] > $max_remote_size) {

				//$filesize = human_filesize($datajson_header['download_content_length']);
				//$errors[] = "The data.json file is " . $filesize . " which is currently too large to parse with this tool. Sorry.";				

				// Increase the timeout limit
			    @set_time_limit(6000);	
			
				$this->load->helper('file');

				if ($rawfile = $this->archive_file('datajson-lines', $this->current_office_id, $datajson_url)) {	

			        $outfile = $rawfile . '.lines.json';

			        $stream = fopen($rawfile, 'r'); 
			        $out_stream = fopen($outfile, 'w+');

			        $listener = new DataJsonParser();
			        $listener->out_file = $out_stream;

					if ($this->environment == 'terminal' OR $this->environment == 'cron') {
						echo 'Attempting to convert to JSON lines' . PHP_EOL;
					}

			        try {
			            $parser = new JsonStreamingParser_Parser($stream, $listener);
			            $parser->parse();
			        } catch (Exception $e) {
			            fclose($stream);
			            throw $e;
			        }

			        // Get the dataset count
			        $datajson_lines_count = $listener->_array_count;

			        // Delete temporary raw source file
			        unlink($rawfile);

			        $out_stream = fopen($outfile, 'r+');	

					$chunk_cycle = 0;
					$chunk_size = 200;					
					$chunk_count = intval(ceil($datajson_lines_count/$chunk_size));
					$buffer = '';
		
					$response = array();
					$response['errors'] = array();		

					if($quality !== false) {
						$response['qa'] = array();
					}			

					echo "Analyzing $datajson_lines_count lines in $chunk_count chunks of $chunk_size lines each" . PHP_EOL;

					while($chunk_cycle < $chunk_count) {
					
						$buffer = '';
						$datajson_qa = null;
						$counter = 0; 				

						if ($chunk_cycle > 0) {
							$key_offset = $chunk_size * $chunk_cycle;
						} else {
							$key_offset = 0;
						}

						$next_offset = $key_offset + $chunk_size;
						//echo "Analyzing chunk $chunk_cycle of $chunk_count ($key_offset to $next_offset of $datajson_lines_count)" . PHP_EOL;


						if ($chunk_cycle == 0) {
							$json_header = fgets($out_stream);
						}

						while (($buffer .= fgets($out_stream)) && $counter < $chunk_size) {
					        $counter++;
					    }		

					    $buffer = $json_header . $buffer;
					    $buffer = substr($buffer, 0, strlen($buffer) - 2) . ']}';

						$validator = $this->campaign->jsonschema_validator($buffer, 'federal-v1.1');				

						if(!empty($validator['errors']) ) {

							$response['errors'] = array_merge($response['errors'], $this->process_validation_errors($validator['errors'], $key_offset));

						}

						if($quality !== false) {
							$datajson_qa = $this->campaign->datajson_qa($buffer, 'federal-v1.1', $quality, $component);	

							if(!empty($datajson_qa)) {
								$response['qa'] = array_merge_recursive($response['qa'], $datajson_qa);	
							}	

						}						

					    $chunk_cycle++;				
					}

			        // Delete json lines file
			        unlink($outfile);					

					// ###################################################################
					// Needs to be refactored into separate function
					// ###################################################################


						// Sum QA counts 
						if(!empty($response['qa'])) {


							if(!empty($response['qa']['bureauCodes'])) {
								$response['qa']['bureauCodes'] = array_keys($response['qa']['bureauCodes']);
							}

							if(!empty($response['qa']['programCodes'])) {
								$response['qa']['programCodes'] = array_keys($response['qa']['programCodes']);
							}

							$sum_array_fields = array('API_total', 
													  'downloadURL_present', 
													  'downloadURL_total', 
													  'accessURL_present', 
													  'accessURL_total', 
													  'accessLevel_public', 
													  'accessLevel_restricted', 
													  'accessLevel_nonpublic',
													  'license_present',
													  'redaction_present',
													  'redaction_no_explanation');

							foreach ($sum_array_fields as $array_field) {
								if(!empty($response['qa'][$array_field]) && is_array($response['qa'][$array_field])) {					
									$response['qa'][$array_field] = array_sum($response['qa'][$array_field]);					 
								}	
							}

							// Sum validation counts
							if (!empty($response['qa']['validation_counts']) && is_array($response['qa']['validation_counts'])) {
								foreach ($response['qa']['validation_counts'] as $validation_key => $validation_count) {

									if(is_array($response['qa']['validation_counts'][$validation_key])) {
										$response['qa']['validation_counts'][$validation_key] = array_sum($response['qa']['validation_counts'][$validation_key]);
									}

								}
							}

						}
						

						$response['valid'] = (empty($response['errors'])) ? true : false;
						$response['valid_json'] = true;

						$response['total_records'] = $datajson_lines_count;		

						if(!empty($datajson_header['download_content_length'])) {
							$response['download_content_length'] = $datajson_header['download_content_length'];
						}

						if(empty($response['errors'])) {
							$response['errors'] = false;
						}
						
						return $response;


			// ###################################################################



				} else {
					$errors[] = "File not found or couldn't be downloaded";	
				}				
		
			}



			// See if it's valid JSON 
			if(!empty($datajson) && $datajson_header['download_content_length'] < $max_remote_size) {

				// See if raw file is valid
				$raw_valid_json = is_json($datajson);

				// See if we can clean up the file to make it valid
				if(!$raw_valid_json) {
					$datajson_processed = json_text_filter($datajson);
					$valid_json 		= is_json($datajson_processed);
				} else {
					$valid_json = true;
				}

				if ($valid_json !== true) {
					$errors[] = 'The validator was unable to determine if this was valid JSON';
				}				
			}

			if(!empty($errors)) {

				$valid_json 	= (isset($valid_json)) ? $valid_json : null;
				$raw_valid_json = (isset($raw_valid_json)) ? $raw_valid_json : null;

				$response = array(
								'raw_valid_json' => $raw_valid_json,
								'valid_json' => $valid_json, 
								'valid' => false, 
								'fail' => $errors, 
								'download_content_length' => $datajson_header['download_content_length']
								);


				if($valid_json && $return_source === false) {
					$catalog = json_decode($datajson_processed);

					if ($schema == 'federal-v1.1' OR $schema == 'non-federal-v1.1') {
						$response['total_records'] = count($catalog->dataset);
					} else {
						$response['total_records'] = count($catalog);
					}

					
				}
								
				return $response;
			}

		}


		// filter string for json conversion if we haven't already
		if ($datajson && empty($datajson_processed)) {
			$datajson_processed = json_text_filter($datajson);
		}


		// verify it's valid json
		if($datajson_processed) {
			if(!isset($valid_json)) {
				$valid_json = is_json($datajson_processed);
			}
		}


		if ($datajson_processed && $valid_json) {

			$datajson_decode = json_decode($datajson_processed);

			if(!empty($datajson_decode->conformsTo) 
				&& $datajson_decode->conformsTo == 'https://project-open-data.cio.gov/v1.1/schema') {

				if($schema !== 'federal-v1.1' && $schema !== 'non-federal-v1.1' ) {

					if ($schema == 'federal') {
						$schema = 'federal-v1.1';						
					} else if ($schema == 'non-federal') {
						$schema = 'non-federal-v1.1';
					} else {
						$schema = 'federal-v1.1';
					}

				}

				$this->schema = $schema;

			} else {
				$this->schema = $schema;
			}

			if($schema == 'federal-v1.1' && empty($datajson_decode->dataset)) {				
				$errors[] = "This file does not appear to be using the federal-v1.1 schema";	
				$response = array(
								'raw_valid_json' => $raw_valid_json,
								'valid_json' => $valid_json, 
								'valid' => false, 
								'fail' => $errors
								);
				return $response;				
			}


			if(is_array($datajson_decode)) {
				$chunk_size = 500;				
				$datajson_chunks = array_chunk($datajson_decode, $chunk_size);
			} else {
				$datajson_chunks = array($datajson_decode);
			}
			

			$response = array();
			$response['errors'] = array();

			if($quality !== false) {
				$response['qa'] = array();
			}

			// save detected schema version to output
			$response['schema_version'] = $schema;			

			foreach ($datajson_chunks as $chunk_count => $chunk) {

				$chunk = json_encode($chunk);
				$validator = $this->campaign->jsonschema_validator($chunk, $schema);
			
				if(!empty($validator['errors']) ) {

					if ($chunk_count) {
						$key_offset = $chunk_size * $chunk_count;
						$key_offset = $key_offset;
					} else {
						$key_offset = 0;
					}

					$response['errors'] = $response['errors'] + $this->process_validation_errors($validator['errors'], $key_offset);
					
				}

				if($quality !== false) {
					$datajson_qa = $this->campaign->datajson_qa($chunk, $schema, $quality, $component);	

					if(!empty($datajson_qa)) {
						$response['qa'] = array_merge_recursive($response['qa'], $datajson_qa);	
					}	

				}
								
			}


			// Sum QA counts 
			if(!empty($response['qa'])) {


				if(!empty($response['qa']['bureauCodes'])) {
					$response['qa']['bureauCodes'] = array_keys($response['qa']['bureauCodes']);
				}

				if(!empty($response['qa']['programCodes'])) {
					$response['qa']['programCodes'] = array_keys($response['qa']['programCodes']);
				}

				$sum_array_fields = array('accessURL_present', 'accessURL_total', 'accessLevel_public', 'accessLevel_restricted', 'accessLevel_nonpublic');

				foreach ($sum_array_fields as $array_field) {
					if(!empty($response['qa'][$array_field]) && is_array($response['qa'][$array_field])) {					
						$response['qa'][$array_field] = array_sum($response['qa'][$array_field]);					 
					}	
				}

				// Sum validation counts
				if (!empty($response['qa']['validation_counts']) && is_array($response['qa']['validation_counts'])) {
					foreach ($response['qa']['validation_counts'] as $validation_key => $validation_count) {

						if(is_array($response['qa']['validation_counts'][$validation_key])) {
							$response['qa']['validation_counts'][$validation_key] = array_sum($response['qa']['validation_counts'][$validation_key]);
						}

					}
				}

			}

			$valid_json = (isset($raw_valid_json)) ? $raw_valid_json : $valid_json;

			$response['valid'] = (empty($response['errors'])) ? true : false;
			$response['valid_json'] = $valid_json;


			if ($schema == 'federal-v1.1' OR $schema == 'non-federal-v1.1') {
				$response['total_records'] = count($datajson_decode->dataset);
			} else {
				$response['total_records'] = count($datajson_decode);
			}			


			if(!empty($datajson_header['download_content_length'])) {
				$response['download_content_length'] = $datajson_header['download_content_length'];
			}

			if(empty($response['errors'])) {
				$response['errors'] = false;
			}

			if ($return_source) {	
				$dataset_array = ($schema == 'federal-v1.1' OR $schema == 'non-federal-v1.1') ? true : false;
				//$datajson_decode = filter_json($datajson_decode, $dataset_array);			
				$response['source'] = $datajson_decode;
			}
			
			return $response;

		} else {			
			$errors[] = "This does not appear to be valid JSON";
			$response = array(
							'valid_json' => false, 
							'valid' => false, 
							'fail' => $errors 
							);
			if(!empty($datajson_header['download_content_length'])) {
				$response['download_content_length'] = $datajson_header['download_content_length'];
			}

			return $response;
		}



	}

	public function jsonschema_validator($data, $schema = null, $chunked = null) {


		if($data) {
			
			$path = './schema/' . $schema;
			$schema_variant = $schema;

			//echo $path; exit;

			// Get the schema and data as objects
	        $retriever = new JsonSchema\Uri\UriRetriever;
	        $schema = $retriever->retrieve('file://' . realpath($path));


        	 //header('Content-type: application/json');
        	 //print $data;
        	 //exit;

    		$data = json_decode($data);

		    if(!empty($data)) {
                // If you use $ref or if you are unsure, resolve those references here
                // This modifies the $schema object
                $refResolver = new JsonSchema\RefResolver($retriever);
                $refResolver->resolve($schema, 'file://' . __DIR__ . '/../../schema/' . $schema_variant);

                // Validate
                $validator = new JsonSchema\Validator();
                $validator->check($data, $schema);

                if ($validator->isValid()) {
                    $results = array('valid' => true, 'errors' => false);
                } else {
                    $errors =  $validator->getErrors();

                    $results = array('valid' => false, 'errors' => $errors);
                }


                return $results;
            } else {
                return false;
            }

    	}



	}



	public function process_validation_errors($errors, $offset = null) {

		$output = array();

		foreach ($errors as $error) {

            if ( !is_numeric($error['property']) AND  
                ($error['property'] === '') OR 
                 ($error['property'] === '@context') OR 
                 ($error['property'] === '@type') OR 
                 ($error['property'] === '@id') OR 
                 ($error['property'] === 'describedBy') OR 
                 ($error['property'] === 'conformsTo')) {
                $error['property'] = 'catalog.' . $error['property'];
            }

			if(is_numeric($error['property']) OR strpos($error['property'], '.') === false OR $error['property'] === 'catalog.') {
				$key = ($error['property'] === 'catalog.') ? 'catalog' : $error['property'];
				$field = 'ALL';
			} 
            else {

            	if (strpos($error['property'], 'dataset[') !== false) {
            		$dataset_key 	= substr($error['property'], 0, strpos($error['property'], '.'));
					$key 			= get_between($dataset_key, '[', ']');
					$full_field 	= substr($error['property'], strpos($error['property'], '.') + 1);
            	} else {
					$key = substr($error['property'], 0, strpos($error['property'], '.'));
					$full_field = substr($error['property'], strpos($error['property'], '.') + 1);            		
            	}


				if (strpos($full_field, '[')) {
					$field 		= substr($full_field, 0, strpos($full_field, '[') );
					$subfield 	= 'child-' . get_between($full_field, '[', ']');
				} else {
					$field = $full_field;
				}

			}

			if ($offset) {
				$key = $key + $offset;
			}

			if (isset($subfield)) {
				$output[$key][$field]['sub_fields'][$subfield][] = $error['message'];
			} else {
				$output[$key][$field]['errors'][] = $error['message'];
			}

			unset($subfield);



		}

		return $output;

	}





	public function datajson_qa($json, $schema = null, $quality = true, $component = null) {

		$programCode = array();
		$bureauCode = array();

		$this->validation_counts = $this->validation_count_model();

		$accessLevel_public			= 0;
		$accessLevel_restricted		= 0;
		$accessLevel_nonpublic		= 0;

		$accessURL_total			= 0;
		$API_total					= 0;
		$downloadURL_total			= 0;		
		$accessURL_present 			= 0;
		$downloadURL_present		= 0;
		$license_present			= 0;
		$redaction_present			= 0;	
		$redaction_no_explanation	= 0;	

		$json = json_decode($json);

		if ($schema == 'federal-v1.1' OR $schema == 'non-federal-v1.1') {
			$json = $json->dataset;
		}

		foreach ($json as $dataset) {

			if(!empty($dataset->accessLevel)) {


				if ($dataset->accessLevel == 'public') {
					$accessLevel_public++;
				} else if ($dataset->accessLevel == 'restricted public') {
					$accessLevel_restricted++;
				} else if ($dataset->accessLevel == 'non-public') {
					$accessLevel_nonpublic++;
				}

			} 

			if($schema == 'federal' OR $schema == 'federal-v1.1') {				


				if(!empty($dataset->programCode) && is_array($dataset->programCode)) {

					foreach ($dataset->programCode as $program) {
						$programCode[$program] = true;	
					}
					
				}

				if(!empty($dataset->bureauCode) && is_array($dataset->bureauCode)) {

					foreach ($dataset->bureauCode as $bureau) {
						$bureauCode[$bureau] = true;	
					}
				}				
			}
		


			$has_accessURL = false;
			$has_downloadURL = false;

			if( ($schema == 'federal' OR $schema == 'non-federal')
				&& !empty($dataset->accessURL) 
				&& filter_var($dataset->accessURL, FILTER_VALIDATE_URL)) {

				$accessURL_total++;
				$has_accessURL = true;
				$dataset_format = (!empty($dataset->format)) ? $dataset->format : null;

				if($component === 'full-scan') $this->validation_check($dataset->identifier, $dataset->title, $dataset->accessURL, $dataset_format);

			}

			if( ($schema == 'federal' OR $schema == 'non-federal') 
				&& !empty($dataset->webService) 
				&& filter_var($dataset->webService, FILTER_VALIDATE_URL)) {

				$accessURL_total++;
				$API_total++;
				$has_accessURL = true;

				if($component === 'full-scan') $this->validation_check($dataset->identifier, $dataset->title, $dataset->webService);

			}			

			if(!empty($dataset->distribution) && is_array($dataset->distribution)) {
				
				foreach ($dataset->distribution as $distribution) {

					if ($schema == 'federal-v1.1' OR $schema == 'non-federal-v1.1') {
						$media_type = (!empty($distribution->mediaType)) ? $distribution->mediaType : null;
					} else {
						$media_type = (!empty($distribution->format)) ? $distribution->format : null;
					}

				   if(!empty($distribution->accessURL) && filter_var($distribution->accessURL, FILTER_VALIDATE_URL)) {
				   		
				   		if (($schema == 'federal-v1.1' OR $schema == 'non-federal-v1.1') 
				   			&& !empty($distribution->format) 
				   			&& strtolower($distribution->format) == 'api' ) {
				   			$API_total++;

				   		}

				   		if($component === 'full-scan') $this->validation_check($dataset->identifier, $dataset->title, $distribution->accessURL, $media_type);
						$accessURL_total++;
						$has_accessURL = true;					   		
				   }

				   if(!empty($distribution->downloadURL) && filter_var($distribution->downloadURL, FILTER_VALIDATE_URL)) {
				   		if($component === 'full-scan') $this->validation_check($dataset->identifier, $dataset->title, $distribution->downloadURL, $media_type);
						$accessURL_total++;
						$downloadURL_total++;
						$has_accessURL = true;	
						$has_downloadURL = true;	
				   }	
			
				}

			}

			// Track presence of redactions and rights info
			$json_text = json_encode($dataset);
			if (strpos($json_text, '[[REDACTED-EX') !== false) {
				$redaction_present++;

				if(empty($dataset->rights)) {
					$redaction_no_explanation++;	
				}
				
			}
			unset($json_text);

			// Track presence of license info
			if(!empty($dataset->license) && filter_var($dataset->license, FILTER_VALIDATE_URL)) {
				$license_present++;
			}

			if($has_accessURL) $accessURL_present++;
			if($has_downloadURL) $downloadURL_present++;


		}

		$qa = array();

		if($schema == 'federal' OR $schema == 'federal-v1.1') {

			$qa['programCodes'] 				= $programCode;
			$qa['bureauCodes'] 					= $bureauCode;

		}

		$qa['accessLevel_public']			= $accessLevel_public;
		$qa['accessLevel_restricted']		= $accessLevel_restricted;
		$qa['accessLevel_nonpublic']		= $accessLevel_nonpublic;

		$qa['accessURL_present'] 			= $accessURL_present;
		$qa['accessURL_total'] 				= $accessURL_total;
		$qa['API_total'] 					= $API_total;	
		$qa['validation_counts']			= $this->validation_counts;
		$qa['license_present'] 				= $license_present;
		$qa['redaction_present'] 			= $redaction_present;
		$qa['redaction_no_explanation'] 	= $redaction_no_explanation;

		if ($schema == 'federal-v1.1' OR $schema == 'non-federal-v1.1') {
			$qa['downloadURL_present'] 	= $downloadURL_present;	
			$qa['downloadURL_total'] 	= $downloadURL_total;				
		}


		return $qa;

	}

	public function validation_check($id, $title, $url, $format = null) {

		$tmp_dir = $this->config->item('archive_dir');

		$header = curl_header($url, false, $tmp_dir);
		$good_link = false;
		$good_format = true;

		if(!empty($header['info']['http_code']) && preg_match('/[5]\d{2}\z/', $header['info']['http_code']) ){
			$this->validation_counts['http_5xx']++;
		}	

		if(!empty($header['info']['http_code']) && preg_match('/[4]\d{2}\z/', $header['info']['http_code']) ){
			$this->validation_counts['http_4xx']++;
		}	

		if(!empty($header['info']['http_code']) && preg_match('/[3]\d{2}\z/', $header['info']['http_code']) ){
			$this->validation_counts['http_3xx']++;
		}

		if(!empty($header['info']['http_code']) && preg_match('/[2]\d{2}\z/', $header['info']['http_code']) ){
			$this->validation_counts['http_2xx']++;
			$good_link = true;
		}	

		if(empty($header['info']['http_code'])){
			$this->validation_counts['http_0']++;
		}			

		if($good_link && !empty($format) && !empty($header['info']['content_type']) && stripos($header['info']['content_type'], $format) === false){
			$this->validation_counts['format_mismatch']++;
			$good_format = false;
		}		

		if($good_link && !empty($header['info']['content_type']) && stripos($header['info']['content_type'], 'application/pdf') !== false){
			$this->validation_counts['pdf']++;
		}

		if($good_link && !empty($format) && !empty($header['info']['content_type']) && stripos($header['info']['content_type'], 'text/html') !== false){
			$this->validation_counts['html']++;
		}	

		if($good_link === false OR $good_format === false) {
			$error_report = $this->error_report_model();
			$error_report['id'] = $id;
			$error_report['title'] = $title;
			$error_report['error_type'] = (!$good_link) ? 'broken_link' : 'format_mismatch' ;
			$error_report['url'] = $url;
			$error_report['http_status'] = $header['info']['http_code'];
			$error_report['format_served'] = $header['info']['content_type'];
			$error_report['format_datajson'] = $format;
			$error_report['crawl_date'] = date(DATE_W3C);

			// ######## Log this to a CSV ##########

			// if this is the first record to log, prepare the file
			if($this->validation_pointer == 0) {

				$download_dir = $this->config->item('archive_dir');
				$directory = "$download_dir/error_log";

				// create error log directory if needed
				if(!file_exists($directory)) {
					mkdir($directory);
				}

				$backup_path = $directory . '/' . $this->current_office_id . '_backup.csv';
				$filepath = $directory . '/' . $this->current_office_id . '.csv';

				// check to see if there's already a file
				if (file_exists($filepath)) {
					rename($filepath, $backup_path);
				}

				// Open new file
				$this->validation_log = fopen($filepath, 'w');

				if ($this->environment == 'terminal' OR $this->environment == 'cron') {
					echo 'Creating new file at ' . $filepath . PHP_EOL;
				}

				// Set file headings
				$headings = array_keys($error_report);
				fputcsv($this->validation_log, $headings);

				// Write first row of data to log
				fputcsv($this->validation_log, $error_report);

			} else {

				// open existing file pointer
				fputcsv($this->validation_log, $error_report);

			}

			$this->validation_pointer++;

		} else {
			return true;
		}

	}



	public function error_report_model() {

		$error = array(
			'error_type' => null,
			'id' => null,
			'title' => null,
			'url' => null,
			'http_status' => null,
			'format_served' => null,
			'format_datajson' => null,
			'crawl_date' => null
			);

		return $error;

	}	



	public function datajson_schema($version = '') {

		if (!empty($version)) {			
			$version_path = $version;
		} else {
			$version_path = 'catalog.json';
		}

		$path = './schema/' . $version_path; 

		// Get the schema and data as objects
        $retriever = new JsonSchema\Uri\UriRetriever;
        $schema = $retriever->retrieve('file://' . realpath($path));	

        $refResolver = new JsonSchema\RefResolver($retriever);
        $refResolver->resolve($schema, 'file://' . __DIR__ . '/../../schema/' . $version_path);

		return $schema;


	}



	public function schema_to_model($schema) {

		$model = new stdClass();

		
		foreach ($schema as $key => $value) {


			if(!empty($value->type) && $value->type == 'object') {
	
				// This is just hard coded to prevent recursion, but should be replaced with proper recursion detection
				if($key == 'subOrganizationOf') {
					$model->$key = null;
				} else {
					$model->$key = $this->schema_to_model($value->properties);	
				}

			} else if(!empty($value->items) && $value->type == 'array') {

				 $model->$key = array();
	
				if (!empty($value->items->properties)) {
					$model->$key = array($this->schema_to_model($value->items->properties));
				}


			} else if(!empty($value->anyOf)) {

				foreach ($value->anyOf as $anyOptions) {

					if (!empty($anyOptions->type) && $anyOptions->type == 'array') {

						$model->$key = array();

						if (!empty($anyOptions->items) && !empty($anyOptions->items->type) && $anyOptions->items->type == 'object') {							
							$model->$key = array($this->schema_to_model($anyOptions->items->properties));

						}
					}
				}

				if(!isset($model->$key)) {
					$model->$key = null;
				}

			} else {
				
				if($key == '@type' && !empty($value->enum)) {
					$model->$key = $value->enum[0];
				} else {
					$model->$key = null;
				}

			}

		}

		return $model;

	}




	function unset_nulls($object) {

		foreach($object as $key => $property) {
			
			if (is_null($property)) {
				unset($object->$key);
			}

			if(is_object($property)) {
				$object->$key = $this->unset_nulls($property);
			}

			if(is_array($property)) {

				if(empty($property)) {
					unset($object->$key);
				} else {
					foreach ($property as $row => $value) {
						if(is_object($value) OR is_array($value)) {
							$property[$row] = $this->unset_nulls($value);	
						}						
					}
				}

			}			
			
		}

		return $object;

	}



}

?>