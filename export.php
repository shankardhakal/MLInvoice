<?php
/*******************************************************************************
 MLInvoice: web-based invoicing application.
 Copyright (C) 2010-2017 Ere Maijala

 This program is free software. See attached LICENSE.

 *******************************************************************************/

/*******************************************************************************
 MLInvoice: web-pohjainen laskutusohjelma.
 Copyright (C) 2010-2017 Ere Maijala

 Tämä ohjelma on vapaa. Lue oheinen LICENSE.

 *******************************************************************************/
require_once 'translator.php';
require_once 'miscfuncs.php';
require_once 'import.php';

class ExportData
{
    protected $importer;

    public function __construct()
    {
        $this->importer = new ImportFile();
    }

    public function launch()
    {
        $charset = getRequest('charset', 'UTF-8');
        $table = getRequest('table', '');
        $format = getRequest('format', '');
        $fieldDelimiter = getRequest('field_delim', ',');
        $enclosureChar = getRequest('enclosure_char', '"');
        $rowDelimiter = getRequest('row_delim', "\n");
        $columns = getRequest('column', '');
        $childRows = getRequest('child_rows', '');
        $deletedRecords = getRequest('deleted', false);

        if ($table && $format && $columns) {
            if (!table_valid($table)) {
                die('Invalid table name');
            }

            $res = mysqli_query_check("show fields from {prefix}$table");
            $field_count = mysqli_num_rows($res);
            $field_defs = [];
            while ($row = mysqli_fetch_assoc($res)) {
                $field_defs[$row['Field']] = $row;
            }
            if ('company' === $table || 'company_contact' === $table) {
                $field_defs['tags'] = ['Type' => 'text'];
            } elseif ('custom_price_map' === $table) {
                $field_defs['company_id'] = ['Type' => 'text'];
            }

            foreach ($columns as $key => $column) {
                if (!$column) {
                    unset($columns[$key]);
                } elseif (!isset($field_defs[$column])) {
                    die('Invalid column name');
                }
            }

            ob_clean();
            $filename = Translator::translate("Table_$table", null, $table);
            switch ($format) {
            case 'csv' :
                $field_delims = $this->importer->get_field_delims();
                $enclosure_chars = $this->importer->get_enclosure_chars();
                $row_delims = $this->importer->get_row_delims();

                if (!isset($field_delims[$fieldDelimiter])) {
                    die('Invalid field delimiter');
                }
                $fieldDelimiter = $field_delims[$fieldDelimiter]['char'];
                if (!isset($enclosure_chars[$enclosureChar])) {
                    die('Invalid enclosure character');
                }
                $enclosureChar = $enclosure_chars[$enclosureChar]['char'];
                if (!isset($row_delims[$rowDelimiter])) {
                    die('Invalid field delimiter');
                }
                $rowDelimiter = $row_delims[$rowDelimiter]['char'];

                header('Content-type: text/csv');
                header("Content-Disposition: attachment; filename=\"$filename.csv\"");
                if ($charset == 'UTF-16') {
                    echo iconv($charset, 'UTF-16', ''); // output BOM
                }
                $this->output_str(
                    $this->str_putcsv($columns, $fieldDelimiter, $enclosureChar)
                    . $rowDelimiter,
                    $charset
                );
                break;

            case 'xml' :
                header('Content-type: text/xml');
                header("Content-Disposition: attachment; filename=\"$filename.xml\"");
                if ($charset == 'UTF-16') {
                    echo iconv($charset, 'UTF-16', ''); // output BOM
                }
                $this->output_str("<?xml version=\"1.0\"?>\n<records>\n", $charset);
                break;

            case 'json' :
                header('Content-type: application/json');
                header(
                    "Content-Disposition: attachment; filename=\"$filename.json\""
                );
                if ($charset == 'UTF-16') {
                    echo iconv($charset, 'UTF-16', ''); // output BOM
                }
                echo "{\"$table\":[\n";
                break;
            }

            $select = "{prefix}$table.*";
            if ('custom_price_map' === $table) {
                $select .= <<<EOT
, (SELECT company_id FROM {prefix}custom_price WHERE id = {prefix}$table.custom_price_id) company_id
EOT;
            }

            $query = "SELECT $select FROM {prefix}$table";
            if (!$deletedRecords) {
                if (isset($field_defs['deleted'])) {
                    $query .= ' where deleted=0';
                }
                if ($table == 'company_contact') {
                    $query .= ' and company_id not in (select id from'
                        . ' {prefix}company where deleted=1)';
                } elseif ($table == 'invoice_row') {
                    $query .= ' and invoice_id not in (select id from'
                        . ' {prefix}invoice where deleted=1)';
                }
            }
            $res = mysqli_query_check($query);
            $first = true;
            while ($row = mysqli_fetch_assoc($res)) {
                if (in_array($table, ['company', 'company_contact'])
                    && in_array('tags', $columns)
                ) {
                    $type = $table === 'company' ? 'company' : 'contact';
                    $row['tags'] = getTags($type, $row['id']);
                }
                $data = [];
                foreach ($columns as $column) {
                    $value = $row[$column];
                    if (is_null($value)) {
                        $data[$column] = '';
                    }
                    if ($value
                        && substr($field_defs[$column]['Type'], 0, 8) == 'longblob'
                    ) {
                        $data[$column] = '0x' . bin2hex($value);
                    } else {
                        $data[$column] = $value;
                    }
                }
                switch ($format) {
                case 'csv' :
                    $this->output_str(
                        $this->str_putcsv($data, $fieldDelimiter, $enclosureChar)
                        . $rowDelimiter,
                        $charset
                    );
                    break;

                case 'xml' :
                    $str = "  <$table>\n";
                    foreach ($columns as $column) {
                        $str .= "    <$column>" . xml_encode($data[$column]) .
                             "</$column>\n";
                    }
                    if ($childRows) {
                        $children = $this->getChildRows($table, $row['id']);
                        foreach ($children as $tag => $crows) {
                            if (is_array($crows)) {
                                foreach ($crows as $crow) {
                                    $str .= "    <$tag>\n";
                                    foreach ($crow as $column => $value) {
                                        $str .= "      <$column>"
                                            . xml_encode($value)
                                            . "</$column>\n";
                                    }
                                    $str .= "    </$tag>\n";
                                }
                            } else {
                                $str .= "    <$tag>" . xml_encode($crows)
                                    . "</$tag>\n";

                            }
                        }
                    }
                    $str .= "  </$table>\n";
                    $this->output_str($str, $charset);
                    break;

                case 'json' :
                    if ($childRows) {
                        $children = $this->getChildRows($table, $row['id']);
                        foreach ($children as $tag => $crows) {
                            $data[$tag] = $crows;
                        }
                    }
                    if ($first) {
                        $first = false;
                    } else {
                        echo (",\n");
                    }
                    $this->output_str(json_encode($data), $charset);
                    break;
                }
            }
            switch ($format) {
            case 'xml' :
                $this->output_str("</records>\n");
                break;
            case 'json' :
                echo ("\n]}\n");
                break;
            }
            exit();
        }

        ?>
<script type="text/javascript">

  $(document).ready(function() {
    $('#imessage').ajaxStart(function() {
      $('#spinner').css('visibility', 'visible');
    });
    $('#imessage').ajaxStop(function() {
      $('#spinner').css('visibility', 'hidden');
    });
    $('#imessage').ajaxError(function(event, request, settings) {
      alert('Server request failed: ' + request.status + ' - ' + request.statusText);
      $('#spinner').css('visibility', 'hidden');
    });
    update_field_states();
    reset_columns();
  });

  var g_column_id = 0;

  function reset_columns()
  {
    $("#columns > select").remove();
    g_column_id = 0;
    add_column();
  }

  function add_column()
  {
    var table = document.getElementById("sel_table").value;
    $.getJSON("json.php?func=get_table_columns&table=" + table, function(json) {
      var index = ++g_column_id;
      var columns = document.getElementById("columns");
      var select = document.createElement("select");
      select.id = "column" + index;
      select.name = "column[]";
      select.onchange = update_columns;
      var option = document.createElement("option");
      option.value = "";
      option.text = "<?php echo Translator::translate('ImportExportColumnNone')?>";
      select.options.add(option);
      for (var i = 0; i < json.columns.length; i++)
      {
        var option = document.createElement("option");
        option.value = json.columns[i].name;
        option.text = json.columns[i].name;
        select.options.add(option);
      }
      columns.appendChild(document.createTextNode(' '));
      columns.appendChild(select);
    });
  }

  function update_columns()
  {
    if (this.value == "" && $("#columns > select").size() > 1)
      $(this).remove();
    else if (this.id == "column" + g_column_id)
      add_column();
  }

  function update_field_states()
  {
    var type = document.getElementById('format').value;
    document.getElementById('field_delim').disabled = type != 'csv';
    document.getElementById('enclosure_char').disabled = type != 'csv';
    document.getElementById('row_delim').disabled = type != 'csv';
    document.getElementById('child_rows').disabled = type == 'csv';
  }

  function add_all_columns()
  {
    var options = document.getElementById("column" + g_column_id).options;

    $("#columns > select").remove();
    g_column_id = 0;

    var columns = document.getElementById("columns");
    for (var i = 1; i < options.length; i++)
    {
      var index = ++g_column_id;
      var select = document.createElement("select");
      select.id = "column" + index;
      select.name = "column[]";
      select.onchange = update_columns;
      var option = document.createElement("option");
      for (var opt = 0; opt < options.length; opt++)
        select.options.add(options[opt].cloneNode(true));
      select.selectedIndex = i;
      columns.appendChild(document.createTextNode(' '));
      columns.appendChild(select);
    }
  }

  </script>

<div class="form_container">
    <h1><?php echo Translator::translate('Export')?></h1>
    <span id="imessage" style="display: none"></span> <span id="spinner"
        style="visibility: hidden"><img src="images/spinner.gif" alt=""></span>
    <form id="export_form" name="export_form" method="GET">
        <input type="hidden" name="func" value="system"> <input type="hidden"
            name="operation" value="export">

        <div class="medium_label"><?php echo Translator::translate('ImportExportCharacterSet')?></div>
        <div class="field">
            <select id="charset" name="charset">
                <option value="UTF-8">UTF-8</option>
                <option value="ISO-8859-1">ISO-8859-1</option>
                <option value="ISO-8859-15">ISO-8859-15</option>
                <option value="Windows-1251">Windows-1251</option>
                <option value="UTF-16">UTF-16</option>
                <option value="UTF-16LE">UTF-16 LE</option>
                <option value="UTF-16BE">UTF-16 BE</option>
            </select>
        </div>

        <div class="medium_label"><?php echo Translator::translate('ImportExportTable')?></div>
        <div class="field">
            <select id="sel_table" name="table" onchange="reset_columns()">
                <option value="company"><?php echo Translator::translate('ImportExportTableCompanies')?></option>
                <option value="company_contact"><?php echo Translator::translate('ImportExportTableCompanyContacts')?></option>
                <option value="base"><?php echo Translator::translate('ImportExportTableBases')?></option>
                <option value="invoice"><?php echo Translator::translate('ImportExportTableInvoices')?></option>
                <option value="invoice_row"><?php echo Translator::translate('ImportExportTableInvoiceRows')?></option>
                <option value="product"><?php echo Translator::translate('ImportExportTableProducts')?></option>
                <option value="row_type"><?php echo Translator::translate('ImportExportTableRowTypes')?></option>
                <option value="invoice_state"><?php echo Translator::translate('ImportExportTableInvoiceStates')?></option>
                <option value="delivery_terms"><?php echo Translator::translate('ImportExportTableDeliveryTerms')?></option>
                <option value="delivery_method"><?php echo Translator::translate('ImportExportTableDeliveryMethods')?></option>
                <option value="stock_balance_log"><?php echo Translator::translate('ImportExportTableStockBalanceLog')?></option>
                <option value="default_value"><?php echo Translator::translate('ImportExportTableDefaultValues')?></option>
                <option value="custom_price"><?php echo Translator::translate('ImportExportTableCustomPrices')?></option>
                <option value="custom_price_map"><?php echo Translator::translate('ImportExportTableCustomPriceMaps')?></option>
            </select>
        </div>

        <div class="medium_label"><?php echo Translator::translate('ImportExportFormat')?></div>
        <div class="field">
            <select id="format" name="format" onchange="update_field_states()">
                <option value="csv">CSV</option>
                <option value="xml">XML</option>
                <option value="json">JSON</option>
            </select>
        </div>

        <div class="medium_label"><?php echo Translator::translate('ImportExportFieldDelimiter')?></div>
        <div class="field">
            <select id="field_delim" name="field_delim">
        <?php
        $field_delims = $this->importer->get_field_delims();
        foreach ($field_delims as $key => $delim) {
            echo "<option value=\"$key\">" . $delim['name'] . "</option>\n";
        }
        ?>
          </select>
        </div>

        <div class="medium_label"><?php echo Translator::translate('ImportExportEnclosureCharacter')?></div>
        <div class="field">
            <select id="enclosure_char" name="enclosure_char">
        <?php
        $enclosure_chars = $this->importer->get_enclosure_chars();
        foreach ($enclosure_chars as $key => $delim) {
            echo "<option value=\"$key\">" . $delim['name'] . "</option>\n";
        }
        ?>
          </select>
        </div>

        <div class="medium_label"><?php echo Translator::translate('ImportExportRowDelimiter')?></div>
        <div class="field">
            <select id="row_delim" name="row_delim">
        <?php
        $row_delims = $this->importer->get_row_delims();
        foreach ($row_delims as $key => $delim) {
            echo "<option value=\"$key\">" . $delim['name'] . "</option>\n";
        }
        ?>
          </select>
        </div>

        <div class="medium_label"><?php echo Translator::translate('ExportIncludeChildRows')?></div>
        <div class="field">
            <input id="child_rows" name="child_rows" type="checkbox"
                checked="checked">
        </div>

        <div class="medium_label"><?php echo Translator::translate('ExportIncludeDeletedRecords')?></div>
        <div class="field">
            <input id="deleted" name="deleted" type="checkbox">
        </div>

        <div class="medium_label"><?php echo Translator::translate('ExportColumns')?> <input
                type="button"
                value="<?php echo Translator::translate('ExportAddAllColumns')?>"
                onclick="add_all_columns()">
        </div>
        <div id="columns" class="field"></div>

        <div class="form_buttons" style="clear: both">
            <input type="submit" value="<?php echo Translator::translate('ExportDo')?>">
        </div>
    </form>
</div>
    <?php
    }

    protected function str_putcsv($data, $delimiter = ',', $enclosure = '"')
    {
        $fp = fopen('php://temp', 'r+');
        fputcsv($fp, $data, $delimiter, $enclosure);
        rewind($fp);
        $data = '';
        while (!feof($fp)) {
            $data .= fread($fp, 1024);
        }
        fclose($fp);
        return rtrim($data, "\n");
    }

    protected function output_str($str, $charset = '')
    {
        if ($charset && $charset != _CHARSET_) {
            $str = iconv(_CHARSET_, $charset, $str);
            // No need for BOM here, this is just a simple string
            if (substr($str, 0, 2) == "\xFE\xFF" || substr($str, 0, 2) == "\xFF\xFE"
            ) {
                $str = substr($str, 2);
            }
        }
        echo $str;
    }

    protected function getChildRows($table, $parentId)
    {
        $children = [];
        if ($table == 'invoice') {
            $children['invoice_row'] = [
                'sql' => 'select * from {prefix}invoice_row where invoice_id=?',
                'params' => [$parentId]
            ];
        } elseif ($table == 'company') {
            $children['company_contact'] = [
                'sql' => <<<EOT
select ct.*, (
  select GROUP_CONCAT(t.tag) from {prefix}contact_tag t where t.id in
    (select tl.tag_id from {prefix}contact_tag_link tl where tl.contact_id=ct.id)
  ) tags from {prefix}company_contact ct
  where ct.company_id=?
EOT
                ,
                'params' => [$parentId]
            ];
        }
        $result = [];
        foreach ($children as $tag => $settings) {
            $rows = db_param_query(
                $settings['sql'], $settings['params']
            );
            foreach ($rows as $crow) {
                $result[$tag][] = $crow;
            }
        }
        return $result;
    }
}
