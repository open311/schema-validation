<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class Campaign extends CI_Controller {

    function __construct()
    {
        parent::__construct();

        $this->load->helper('api');
        $this->load->helper('url');

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


    }


    /**
     * Index Page for this controller.
     *
     * Maps to the following URL
     *      http://example.com/index.php/welcome
     *  - or -
     *      http://example.com/index.php/welcome/index
     *  - or -
     * Since this controller is set as the default controller in
     * config/routes.php, it's displayed at http://example.com/
     *
     * So any other public methods not prefixed with an underscore will
     * map to /index.php/welcome/<method_name>
     * @see http://codeigniter.com/user_guide/general/urls.html
     */
    public function index()
    {


    }

    public function convert($orgs = null, $geospatial = null, $harvest = null, $from_export = null) {
        $this->load->model('campaign_model', 'campaign');

        $orgs           = (!empty($orgs)) ? $orgs : $this->input->get('orgs', TRUE);
        $geospatial     = (!empty($geospatial)) ? $geospatial : $this->input->get('geospatial', TRUE);
        $harvest        = (!empty($harvest)) ? $harvest : $this->input->get('harvest', TRUE);
        $from_export    = (!empty($from_export)) ? $from_export : $this->input->get('from_export', TRUE);


        $row_total = 100;
        $row_count = 0;

        $row_pagesize = 100;
        $raw_data = array();

        while($row_count < $row_total) {
            $result     = $this->campaign->get_datagov_json($orgs, $geospatial, $row_pagesize, $row_count, true, $harvest);

            if(!empty($result->result)) {

                $row_total = $result->result->count;
                $row_count = $row_count + $row_pagesize;

                $raw_data = array_merge($raw_data, $result->result->results);

                if($from_export == 'true') break;

            } else {
                break;
            }

        }

        if(!empty($raw_data)) {

            $json_schema = $this->campaign->datajson_schema();
            $datajson_model = $this->campaign->schema_to_model($json_schema->items->properties);

            $convert = array();
            foreach ($raw_data as $ckan_data) {
                $model = clone $datajson_model;
                $convert[] = $this->campaign->datajson_crosswalk($ckan_data, $model);
            }

            if($this->environment == 'terminal') {
                $filepath = 'export.json';

                echo 'Creating file at ' . $filepath . PHP_EOL . PHP_EOL;

                $export_file = fopen($filepath, 'w');
                fwrite($export_file, json_encode($convert));
                fclose($export_file);
            } else {

                header('Content-type: application/json');
                print json_encode($convert);
                exit;
            }

        } else {

            if($this->environment == 'terminal') {
                echo 'No results found for ' . $orgs;
            } else {
                header('Content-type: application/json');
                print json_encode(array("error" => "no results"));
                exit;
            }
        }

    }

    public function csv_to_json($schema = null) {

        $schema         = ($this->input->post('schema', TRUE)) ? $this->input->post('schema', TRUE) : $schema;
        $csv_id         = ($this->input->post('csv_id', TRUE)) ? $this->input->post('csv_id', TRUE) : null;
        $prefix         = 'fitara';

        if (substr($schema, 0, strlen($prefix)) == $prefix) {
            $prefix_model = substr($schema, strlen($prefix)+1);
        }

        // Initial file upload
        if(!empty($_FILES)) {

            $this->load->library('upload');

            if($this->do_upload('csv_upload')) {

                $data = $this->upload->data();

                ini_set("auto_detect_line_endings", true);
                $csv_handle = fopen($data['full_path'], 'r');
                $headings = fgetcsv($csv_handle);

                // Sanitize input
                $headings = $this->security->xss_clean($headings);

                // Provide mapping between csv headings and POD schema
                $this->load->model('campaign_model', 'campaign');
                $json_schema = $this->campaign->datajson_schema($schema);

                if ($schema) {
                    if (!empty($prefix_model)) {
                        $datajson_model = $this->campaign->schema_to_model($json_schema->properties->$prefix_model->items->properties);
                    } else {
                        $datajson_model = $this->campaign->schema_to_model($json_schema->properties->dataset->items->properties);
                    }                    
                } else {
                    $datajson_model = $this->campaign->schema_to_model($json_schema->items->properties);    
                }

                $output = array();
                $output['headings']         = $headings;
                $output['datajson_model']   = $datajson_model;
                $output['csv_id']           = $data['file_name'];
                $output['select_mapping']   = $this->csv_field_mapper($headings, $datajson_model);
                $output['schema']           = $schema;
                $this->load->view('csv_mapping', $output);

            }

        }

        // Apply mapping and convert file to JSON
        else if (!empty($csv_id)) {

            $mapping = ($this->input->post('mapping', TRUE)) ? $this->input->post('mapping', TRUE) : null;
            $schema  =  ($this->input->post('schema', TRUE)) ? $this->input->post('schema', TRUE) : 'federal';

            $this->config->load('upload', TRUE);
            $upload_config = $this->config->item('upload');

            $full_path = $upload_config['upload_path'] . $csv_id;

            $this->load->helper('csv');
            ini_set("auto_detect_line_endings", true);

            $importer = new CsvImporter($full_path, $parse_header = true, $delimiter = ",");
            $csv = $importer->get();

            $json = array();

            if ($schema == 'federal-v1.1') {

                // Provide mapping between csv headings and POD schema
                $this->load->model('campaign_model', 'campaign');
                $json_schema = $this->campaign->datajson_schema($schema);
                $datajson_model = $this->campaign->schema_to_model($json_schema->properties);    
                $datajson_model->dataset = array();        

                $dataset_model = clone $this->campaign->schema_to_model($json_schema->properties->dataset->items->properties);
                $datasets = array();

                foreach ($csv as $row) {

                    $count = 0;
                    $json_row = clone $dataset_model;
                    $distribution_row = clone $dataset_model->distribution[0];                    
                    foreach($row as $key => $value) {
                        if($mapping[$count] !== 'null') {

                            $value = $this->schema_map_filter($mapping[$count], $value, $schema);

                            if(strpos($mapping[$count], '.') !== false) {

                                $field_path = explode('.', $mapping[$count]);  

                                if (array_key_exists($field_path[0], $json_row) && array_key_exists($field_path[1], $json_row->$field_path[0])) {
                                    $json_row->$field_path[0]->$field_path[1] = $value;       
                                }

                                if ($field_path[0] == 'distribution') {
                                    if (array_key_exists($field_path[1], $distribution_row)) {
                                        $distribution_row->$field_path[1] = $value;       
                                    }                                    
                                }
                                
                            }

                            if(array_key_exists($mapping[$count], $json_row)) {
                                $json_row->$mapping[$count] = $value;    
                            }
                            
                        }

                        $count++;
                    }
                    $json_row->distribution = array($distribution_row);
                    $this->campaign->unset_nulls($json_row);                    
                    $datasets[] = $json_row;
                
                } 

                $id_field      = '@id';
                $context_field = '@context';
                unset($datajson_model->$id_field);

                $datajson_model->$context_field = 'https://project-open-data.cio.gov/v1.1/schema/catalog.jsonld';
                $datajson_model->conformsTo     = 'https://project-open-data.cio.gov/v1.1/schema';
                $datajson_model->describedBy    = 'https://project-open-data.cio.gov/v1.1/schema/catalog.json';

                $datajson_model->dataset = $datasets;
                $json = $datajson_model;

            } else {
                
                foreach ($csv as $row) {

                    $count = 0;
                    $json_row = array();
                    foreach($row as $key => $value) {
                        if($mapping[$count] !== 'null') {

                            $value = $this->schema_map_filter($mapping[$count], $value, $schema);

                            // Convert ints to strings for FITARA
                            if(!empty($prefix_model)) {
                                $value = (is_int($value)) ? (string)$value : $value;
                            }

                            $json_row[$mapping[$count]] = $value;
                        }

                        $count++;
                    }

                    $json[] = $json_row;

                }         

                if(!empty($prefix_model)) {

                    $container = new stdClass();
                    $container->$prefix_model = $json;
                    $json = $container;

                }

            }



            // delete temporary uploaded csv file
            unlink($full_path);

            // provide json for download
            header("Pragma: public");
            header("Expires: 0");
            header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
            header("Cache-Control: private",false);
            header('Content-type: application/json');
            header("Content-Disposition: attachment; filename=\"$csv_id.json\";" );
            header("Content-Transfer-Encoding: binary");

            print json_encode($json);
            exit;
        }

        // Show upload form
        else {
            $this->load->view('csv_upload');
        }

    }

    public function schema_map_filter($field, $value, $schema = null) {

        if(is_json($value)){
            $value = json_decode($value);
        } else if ($field == 'keyword' |
                   $field == 'language' |
                   $field == 'references' |
                   $field == 'theme' |
                   $field == 'programCode' |
                   $field == 'bureauCode') {
            $value = str_getcsv($value);
        } else if ($field == 'dataQuality' && !empty($value)) {
            $value = (bool) $value;
        }

        if(is_array($value)) {
            $value = array_map("make_utf8", $value);
            $value = array_map("trim", $value);
            $value = array_filter($value); // removes any empty elements in an array
            $value = array_values($value); // ensures array_filter doesn't create an associative array
        } else if (is_string($value)) {
            $value = trim($value);
            $value = make_utf8($value);
        }

        $value = (!is_bool($value) && empty($value)) ? null : $value;

        return $value;

    }

    public function csv_field_mapper($headings, $datajson_model, $inception = false) {

        $matched = array();
        $match = false; 
        $count = 0; 
        $selected = '';

        ob_start();
        foreach ($headings as $field) {
        ?>
        <div class="form-group">
            <label class="col-sm-2" for="<?php echo $field; ?>"><?php echo $field; ?></label>
            <div class="col-sm-3">
                <select id="<?php echo $field; ?>" type="text" name="mapping[<?php echo $count; ?>]">
                    <option value="null">Select a corresponding field</option>
                    <?php //var_dump($datajson_model); ?>
                    <?php foreach ($datajson_model as $pod_field => $pod_value): ?>
                        <?php
                            
                            if (is_object($pod_value) OR (is_array($pod_value) && count($pod_value) > 0)) {                                

                                    foreach ($pod_value as $parent_field => $pod_value_child) {

                                        if(is_object($pod_value_child)) {
                                            foreach ($pod_value_child as $child_field => $child_value) {

                                                if (strtolower(trim($field)) == strtolower(trim("$pod_field.$child_field")) && !$matched[$field]) {
                                                    $selected = 'selected="selected"';
                                                    $match = true;
                                                } else {
                                                    $selected = '';
                                                } 
                                        ?>

                                        <option value="<?php echo "$pod_field.$child_field" ?>" <?php echo $selected ?>><?php echo $pod_field . ' - ' . $child_field ?></option>

                                        <?php
                                                if ($match) {                                            
                                                    $match = false;
                                                    $selected = '';                                               
                                                    $matched[$field] = true;
                                                }

                                            }
                                        } else {
                                            if (strtolower(trim($field)) == strtolower(trim("$pod_field.$parent_field")) && !$matched[$field]) {
                                                $selected = 'selected="selected"';
                                                $match = true;
                                            } else {
                                                $selected = '';
                                            } 
                                    ?>

                                    <option value="<?php echo "$pod_field.$parent_field" ?>" <?php echo $selected ?>><?php echo $pod_field . ' - ' . $parent_field ?></option>

                                    <?php
                                            if ($match) {                                            
                                                $match = false;
                                                $selected = '';                                               
                                                $matched[$field] = true;
                                            }                                           
                                        }

                                    }

                                


                            } else {

                                if (strtolower(trim($field)) == strtolower(trim($pod_field)) && !isset($matched[$field])) {
                                    $selected = 'selected="selected"';
                                    $match = true;
                                } else {
                                    $selected = '';
                                }



                            }

                        ?>
                        <option value="<?php echo $pod_field ?>" <?php echo $selected ?>><?php echo $pod_field ?></option>
                        <?php 

                            if ($match) {                                            
                                $match = false;
                                $selected = '';                                               
                                $matched[$field] = true;
                            }


                        ?>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-2">
                <?php
                    if (!($match OR (isset($matched[$field]) && isset($matched[$field]))))  echo '<span class="text-danger">No match found</span>';
                    $match = false;
                    $count++;
                ?>
            </div>
        </div>
        <?php
            reset($datajson_model); 
        }

    return ob_get_clean();

    }



    public function do_upload($field_name = null) {

        if (!$this->upload->do_upload($field_name)) {
            return false;
        } else {
            return true;
        }
    }


    public function table_schema() {
       $this->load->model('campaign_model', 'campaign');
       $this->campaign->json_to_table_schema(); 
    }


    /*
    $id can be all, cfo-act, or a specific id
    $component can be full-scan, all, datajson, datapage, digitalstrategy, download
    */
    public function status($id = null, $component = null, $selected_milestone = null) {

        // enforce explicit component selection
        if(empty($component)) {
            show_404('status', false);
        }

        if($component == 'full-scan' || $component == 'all' || $component == 'download' ) {
            $this->load->helper('file');
        }

        $this->load->model('campaign_model', 'campaign');

        // Determine current milestone
        $milestones             = $this->campaign->milestones_model();  
        $milestone              = $this->campaign->milestone_filter($selected_milestone, $milestones);

        // If it's the first day of a new milestone, finalize last results from previous milestone
        $yesterday = date("Y-m-d", time() - 60 * 60 * 24);
        if ($milestone->previous == $yesterday) { 
            $this->finalize_milestone($milestone->previous);
        }

        // Build query for list of offices to update
        $this->db->select('url, id');

        // Filter for certain offices
        if($id == 'cfo-act') {
            $this->db->where('cfo_act_agency', 'true');
        }

        if (is_numeric($id)) {
            $this->db->where('id', $id);
        }

        $query = $this->db->get('offices');

        if ($query->num_rows() > 0) {
            $offices = $query->result();

            foreach ($offices as $office) {

                // Set current office id
                $this->campaign->current_office_id = $office->id;
                $this->campaign->validation_pointer = 0;

                // initialize update object
                $update = $this->campaign->datagov_model();
                $update->office_id = $office->id;

                $update->crawl_status = 'in_progress';
                $update->crawl_start = gmdate("Y-m-d H:i:s");

                $url =  parse_url($office->url);
                $url = $url['scheme'] . '://' . $url['host'];



                /*
                ################ datapage ################
                */

               if ($component == 'full-scan' || $component == 'all' || $component == 'datapage') {


                    // Get status of html /data page
                    $page_status_url = $url . '/data';

                    if ($this->environment == 'terminal' OR $this->environment == 'cron') {
                        echo 'Attempting to request ' . $page_status_url . PHP_EOL;
                    }

                    $page_status = $this->campaign->uri_header($page_status_url);
                    $page_status['expected_url'] = $page_status_url;
                    $page_status['last_crawl']  = mktime();

                    $update->datapage_status = (!empty($page_status)) ? json_encode($page_status) : null;

                    if ($this->environment == 'terminal' OR $this->environment == 'cron') {
                        echo 'Attempting to set ' . $update->office_id . ' with ' . $update->datapage_status . PHP_EOL . PHP_EOL;
                    }

                    if ($component == 'datapage') {
                        $update->crawl_status = 'current';
                        $update->crawl_end = gmdate("Y-m-d H:i:s");
                    }

                    $update->status_id = $this->campaign->update_status($update);

                }


                 /*
                 ################ digitalstrategy ################
                 */

                if ($component == 'full-scan' || $component == 'all' || $component == 'digitalstrategy' || $component == 'download') {


                     // Get status of html /data page
                    $digitalstrategy_status_url = $url . '/digitalstrategy.json';

                    if ($this->environment == 'terminal' OR $this->environment == 'cron') {
                        echo 'Attempting to request ' . $digitalstrategy_status_url . PHP_EOL;
                    }

                    $page_status = $this->campaign->uri_header($digitalstrategy_status_url);
                    $page_status['expected_url'] = $digitalstrategy_status_url;
                    $page_status['last_crawl']  = mktime();

                    $update->digitalstrategy_status = (!empty($page_status)) ? json_encode($page_status) : null;

                    if ($this->environment == 'terminal' OR $this->environment == 'cron') {
                        echo 'Attempting to set ' . $update->office_id . ' with ' . $update->digitalstrategy_status . PHP_EOL . PHP_EOL;
                    }

                    if ($component == 'digitalstrategy') {
                        $update->crawl_status = 'current';
                        $update->crawl_end = gmdate("Y-m-d H:i:s");
                    }                    

                    $update->status_id = $this->campaign->update_status($update);

                    // download and version this json file.
                    if ($component == 'all' || $component == 'download') {                      
                        $digitalstrategy_archive_status = $this->campaign->archive_file('digitalstrategy', $office->id, $digitalstrategy_status_url);
                    }

                }


                /*
                ################ datajson ################
                */

                if ($component == 'full-scan' || $component == 'all' || $component == 'datajson' || $component == 'download') {

                    $expected_datajson_url = $url . '/data.json';

                    // attempt to break any caching
                    $expected_datajson_url_refresh = $expected_datajson_url . '?refresh=' . time();

                    if ($this->environment == 'terminal' OR $this->environment == 'cron') {
                        echo 'Attempting to request ' . $expected_datajson_url . ' and ' . $expected_datajson_url_refresh . PHP_EOL;
                    }

                    // Try to force refresh the cache, follow redirects and get headers
                    $json_refresh = true;
                    $status = $this->campaign->uri_header($expected_datajson_url_refresh);

                    if(!$status OR $status['http_code'] != 200) {
                        $json_refresh = false;
                        $status = $this->campaign->uri_header($expected_datajson_url);
                    }

                    //$status['url']          = $expected_datajson_url;
                    $status['expected_url'] = $expected_datajson_url;



                    $real_url = ($json_refresh) ? $expected_datajson_url_refresh : $expected_datajson_url;


                    /*
                    ################ download ################
                    */
                    if ($component == 'full-scan' || $component == 'all' || $component == 'download') {

                        if(!($status['http_code'] == 200)) {

                            if ($this->environment == 'terminal' OR $this->environment == 'cron') {
                                echo 'Resource ' . $real_url . ' not available' . PHP_EOL;
                            }

                            continue;
                        }

                        // download and version this data.json file.
                        $datajson_archive_status = $this->campaign->archive_file('datajson', $office->id, $real_url);

                    }

                    /*
                    ################ datajson ################
                    */
                    if ($component == 'full-scan' || $component == 'all' || $component == 'datajson') {

                        // Save current update status in case things break during json_status
                        $update->datajson_status = (!empty($status)) ? json_encode($status) : null;

                        if ($this->environment == 'terminal' OR $this->environment == 'cron') {
                            echo 'Attempting to set ' . $update->office_id . ' with ' . $update->datajson_status . PHP_EOL . PHP_EOL;
                        }

                        $update->status_id = $this->campaign->update_status($update);

                        // Check JSON status
                        $status                 = $this->json_status($status, $real_url, $component);                   

                        // Set correct URL
                        if(!empty($status['url'])) {
                            if(strpos($status['url'], '?refresh=')) {
                                $status['url'] = substr($status['url'], 0, strpos($status['url'], '?refresh='));
                            } 
                        } else {
                            $status['url'] = $expected_datajson_url;
                        }

                        $status['expected_url'] = $expected_datajson_url;
                        $status['last_crawl']   = mktime();

        
                        if(is_array($status['schema_errors']) && !empty($status['schema_errors'])) {
                            $status['error_count'] = count($status['schema_errors']);
                        } else if ($status['schema_errors'] === false) {
                            $status['error_count'] = 0;
                        } else {
                            $status['error_count'] = null;
                        }

                        $status['schema_errors'] = (!empty($status['schema_errors'])) ? array_slice($status['schema_errors'], 0, 10, true) : null;

                        $update->datajson_status = (!empty($status)) ? json_encode($status) : null;
                        //$update->datajson_errors = (!empty($status) && !empty($status['schema_errors'])) ? json_encode(array_slice($status['schema_errors'], 0, 10, true)) : null;
                        if(!empty($status) && !empty($status['schema_errors'])) unset($status['schema_errors']);


                        if ($this->environment == 'terminal' OR $this->environment == 'cron') {
                            echo 'Attempting to set ' . $update->office_id . ' with ' . $update->datajson_status . PHP_EOL . PHP_EOL;
                        }
                        
                        $update->crawl_status = 'current';
                        $update->crawl_end = gmdate("Y-m-d H:i:s");

                        $this->campaign->update_status($update);
                    }

                }



                if(!empty($id) && $this->environment != 'terminal' && $this->environment != 'cron') {
                    $this->load->helper('url');
                    redirect('/offices/detail/' . $id, 'location');
                }

            }

            // Close file connections that are still open 
            if(is_resource($this->campaign->validation_log)) {
                fclose($this->campaign->validation_log);
            }

        }

    }

    public function json_status($status, $real_url = null, $component = null) {

        // if this isn't an array, assume it's a urlencoded URI
        if(is_string($status)) {
            $this->load->model('campaign_model', 'campaign');

            $expected_datajson_url = urldecode($status);

            $status = $this->campaign->uri_header($expected_datajson_url);
            $status['url'] = (!empty($status['url'])) ? $status['url'] : $expected_datajson_url;
        }

        $status['url'] = (!empty($status['url'])) ? $status['url'] : $real_url;
        
        if($status['http_code'] == 200) {

            $qa = ($this->environment == 'terminal' OR $this->environment == 'cron') ? 'all' : true;

            $validation = $this->campaign->validate_datajson($status['url'], null, null, 'federal', false, $qa, $component);

            if(!empty($validation)) {
                $status['valid_json'] = $validation['valid_json'];
                $status['valid_schema'] = $validation['valid'];
                $status['total_records'] = (!empty($validation['total_records'])) ? $validation['total_records'] : null;

                $status['schema_version'] = (!empty($validation['schema_version'])) ? $validation['schema_version'] : null;

                if(isset($validation['errors']) && is_array($validation['errors']) && !empty($validation['errors'])) {
                    $status['schema_errors'] = $validation['errors'];
                } else if (isset($validation['errors']) && $validation['errors'] === false) {
                    $status['schema_errors'] = false;
                } else {
                    $status['schema_errors'] = null;
                }

                $status['qa'] = (!empty($validation['qa'])) ? $validation['qa'] : null;

                $status['download_content_length'] = (!empty($status['download_content_length'])) ? $status['download_content_length'] : null;
                $status['download_content_length'] = (!empty($validation['download_content_length'])) ? $validation['download_content_length'] : $status['download_content_length'];

            } else {
                // data.json was not valid json
                $status['valid_json'] = false;
            }

        }

        return $status;
    }





    public function validate($datajson_url = null, $datajson = null, $headers = null, $schema = null, $output = 'browser') {

        $this->load->model('campaign_model', 'campaign');

        $datajson       = ($this->input->post('datajson')) ? $this->input->post('datajson') : $datajson;
        $schema         = ($this->input->get_post('schema')) ? $this->input->get_post('schema', TRUE) : $schema;

        $datajson_url   = ($this->input->get_post('datajson_url')) ? $this->input->get_post('datajson_url', TRUE) : $datajson_url;
        $output_type    = ($this->input->get_post('output')) ? $this->input->get_post('output', TRUE) : $output;

        if ($this->input->get_post('qa')) {
            $qa = $this->input->get_post('qa');
        } else {
            $qa = false;
        }

        if ($qa == 'true') $qa = true;

        if(!empty($_FILES)) {

            $this->load->library('upload');

            if($this->do_upload('datajson_upload')) {

                $data = $this->upload->data();

                $datajson = file_get_contents($data['full_path']);
                unlink($data['full_path']);

            } else {

                $errors = array("Could not upload file (it may be larger than PHP or application allows)"); // for more details see $this->upload->display_errors()
                $validation = array(
                                'valid_json' => false, 
                                'valid' => false, 
                                'fail' => $errors 
                                );
            }
        }

        $return_source  = ($output_type == 'browser') ? true : false;

        if($datajson OR $datajson_url) {
            $validation = $this->campaign->validate_datajson($datajson_url, $datajson, $headers, $schema, $return_source, $qa);
        }



        if(!empty($validation)) {


            if ($output_type == 'browser' && (!empty($validation['source']) || !empty($validation['fail']) )) {

                $validate_response = array(
                                            'validation' => $validation, 
                                            'schema'    => $schema,
                                            'datajson_url' => $datajson_url
                                            );

                if($schema == 'federal-v1.1') {
                    $validate_response['schema_v1_permalinks'] = $this->campaign->schema_v1_permalinks();
                }

                $this->load->view('validate_response', $validate_response);

            } else {

                header('Content-type: application/json');
                print json_encode($validation);
                exit;

            }

        } else {
            $this->load->view('validate');
        }

    }

    public function assert_api_schema() {
        $this->load->library('SwaggerAssert');

        $assertion = new SwaggerAssert();
        $assertion->setSchema('http://validate.open311.org/schema/georeport-v2/swagger.json');

        $schema_path = 'services.json';
        $api_url = 'http://labs.data.gov/crm/open311/v2/' . $schema_path;
        $assertion->testBodyMatchDefinition($api_url, $schema_path);

    }



}