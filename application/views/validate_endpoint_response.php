<?php include 'header_meta_inc_view.php';?>

<link rel="stylesheet" href="css/highlight.css">
<script src="js/vendor/highlight.pack.js"></script>
<script>hljs.initHighlightingOnLoad();</script>

<?php include 'header_inc_view.php';?>

    <div class="container">
      <!-- Example row of columns -->

      <div class="row">
        <div class="col-lg-12">

            <h2>Validation Results</h2>

            <?php foreach ($models as $model_name => $model): ?>

            <div class="panel panel-default">
                <div class="panel-heading">
                    Metadata Validation for <?php echo $model_name; ?>
                </div>

                <table class="table table-striped table-hover">
                    <tbody>

                        <?php if(!empty($model['url'])) : ?>
                            <tr>
                                <th>Source</th> <td><?php echo $model['url']; ?> </td>
                            </tr>
                        <?php endif; ?>

                        <?php if(!empty($model['schema_version'])) : ?>
                            <tr>
                                <th>Schema</th> <td><?php echo $model['schema_version']; ?></td>
                            </tr>
                        <?php endif; ?> 

                        <?php if(!empty($model['valid'])) : ?>
                            <tr>
                                <th>Valid</th> <td><?php echo $model['valid']; ?></td>
                            </tr>
                        <?php endif; ?>                         

                        <?php if(!empty($model['total_records'])) : ?>
                            <tr>
                                <th>Total Records</th> <td><?php echo $model['total_records']; ?></td>
                            </tr>
                        <?php endif; ?>    

                        <?php if(!empty($model['valid_count'])) : ?>
                            <tr>
                                <th>Valid Records</th> <td><?php echo $model['valid_count']; ?></td>
                            </tr>
                        <?php endif; ?>  


                        <?php if(!empty($model['error_totals'])) : ?>  
                            <tr>
                                <th>Errors</th>
                                <td>
                                    <?php foreach ($model['error_totals'] as $field => $error_totals) {   

                                            foreach ($error_totals as $error_type) {
                                                $error_type_count = $error_type['count'];
                                                break;
                                            }
                                        ?>

                                        <p><?php echo 'validation error on ' . $field . ' for ' . $error_type_count . ' records ' ?></p>

                                    <?php } ?>
                                </td>
                            </tr>

                        <?php endif; ?>


                    </tbody>
                </table>

            </div>

        <?php endforeach; ?>


        </div>
    </div>
  </div>

<?php include 'footer.php'; ?>