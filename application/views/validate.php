<?php include 'header_meta_inc_view.php';?>

<?php include 'header_inc_view.php';?>

    <div class="container">
      <!-- Example row of columns -->

      <div class="row">
        <div class="col-lg-12">
          <h2>Validator</h2>


            <h3 style="margin-top : 3em;">Validate Endpoint</h3>

            <form action="<?php echo site_url(); ?>campaign/endpoint" method="get" role="form">

                <div class="form-group">
                    <label for="jurisdiction_id">Jurisdiction ID</label>
                    <div class="input-group">
                        <input name="jurisdiction_id" id="jurisdiction_id" class="form-control">
                    </div>
                </div>


                <div class="form-group">
                    <label class="">
                        <input type="checkbox" id="json_output" name="json_output" value="true"> JSON Output
                    </label>
                </div>


                <label for="endpoint_url">Endpoint URL</label>
                <div class="input-group">
                    <input name="endpoint_url" id="endpoint_url" class="form-control"  placeholder="e.g. http://311api.cityofchicago.org/open311/v2/" >
                    <input name="qa" value="false" type="hidden">
                    <span class="input-group-btn">
                        <button type="submit" class="btn btn-primary">Validate Endpoint</button>
                    </span>

                </div>
            </form>

            <hr>



            <h3 style="margin-top : 3em;">Validate JSON Output</h3>

            <form action="<?php echo site_url(); ?>validate" method="get" role="form">

                <div class="form-group">
                    <label for="datajson">Schema</label>
                    <select name="schema">                        
                       <option value="georeport-v2/requests.json">GeoReport Requests</option>                        
                       <option value="georeport-v2/services.json">GeoReport Services</option>                        
                       <option value="georeport-v2/service-definition.json">GeoReport Service Definition</option>                        
                    </select>
                </div>


                <div class="form-group">
                    <label class="">
                        <input checked="checked" type="checkbox" id="totals" name="totals" value="true"> Only show error totals
                    </label>
                </div>

                <div class="form-group">
                    <label class="radio-inline">
                        <input checked="checked" type="radio" id="output-browser" name="output" value="browser"> View in Browser
                    </label>

                    <label class="radio-inline">
                        <input type="radio" id="output-browser" name="output" value="json"> Output JSON
                    </label>
                </div>

                <label for="datajson_url">JSON URL</label>
                <div class="input-group">
                    <input name="datajson_url" id="datajson_url" class="form-control"  placeholder="e.g. http://311api.cityofchicago.org/open311/v2/requests.json" >
                    <input name="qa" value="false" type="hidden">
                    <span class="input-group-btn">
                        <button type="submit" class="btn btn-primary">Validate URL</button>
                    </span>

                </div>
            </form>

            <hr>

            <h3 style="margin-top : 3em;">Validate JSON file upload</h3>

            <form action="<?php echo site_url(); ?>validate" method="post" enctype="multipart/form-data" role="form">

                <div class="form-group">
                    <label for="datajson">Schema</label>
                    <select name="schema">
                       <option value="georeport-v2/requests.json">GeoReport Requests</option>                        
                       <option value="georeport-v2/services.json">GeoReport Services</option>                        
                       <option value="georeport-v2/service-definition.json">GeoReport Service Definition</option>   
                    </select>
                </div>

                 <div class="form-group">
                    <label for="datajson_upload">Upload a JSON file</label>
                    <input type="file" name="datajson_upload">
                </div>

                <div class="form-group">
                    <input name="qa" value="true" type="hidden">
                    <input type="hidden" name="output" value="browser">
                    <input type="submit" value="Validate File" class="btn btn-primary">
                </div>

            </form>

            <hr>

            <h3 style="margin-top : 3em;">Validate raw JSON</h3>

            <form action="<?php echo site_url(); ?>validate" method="post" role="form">
                <div class="form-group">
                    <label for="datajson">JSON</label>
                    <textarea class="form-control" id="datajson" name="datajson" style="height : 30em; width: 100%"></textarea>
                </div>

                <div class="form-group">
                    <label for="datajson">Schema</label>
                    <select name="schema">
                       <option value="georeport-v2/requests.json">GeoReport Requests</option>                        
                       <option value="georeport-v2/services.json">GeoReport Services</option>                        
                       <option value="georeport-v2/service-definition.json">GeoReport Service Definition</option>   
                    </select>
                </div>

                <div class="form-group">
                    <input name="qa" value="true" type="hidden">
                    <input type="hidden" name="output" value="browser">
                    <input type="submit" value="Validate JSON" class="btn btn-primary">
                </div>

            </form>

        </div>
    </div>

<?php include 'footer.php'; ?>