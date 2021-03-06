<?php
/**
 * Patient history posting for the WordPress Patient Portal.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Rod Roark <rod@sunsetsystems.com>
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2014 Rod Roark <rod@sunsetsystems.com>
 * @copyright Copyright (c) 2017 Brady Miller <brady.g.miller@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */


require_once("../globals.php");
require_once("$srcdir/patient.inc");
require_once("$srcdir/options.inc.php");
require_once("portal.inc.php");

$postid = intval($_REQUEST['postid']);
$ptid   = intval($_REQUEST['ptid'  ]);

if ($_POST['bn_save']) {
    $newdata = array();
    $fres = sqlStatement("SELECT * FROM layout_options WHERE " .
    "form_id = 'HIS' AND field_id != '' AND uor > 0 " .
    "ORDER BY group_id, seq");
    while ($frow = sqlFetchArray($fres)) {
        $data_type = $frow['data_type'];
        $field_id  = $frow['field_id'];
        if (isset($_POST["form_$field_id"])) {
            $newdata[$field_id] = get_layout_form_value($frow);
        }
    }

    updateHistoryData($ptid, $newdata);
  // Finally, delete the request from the portal.
    $result = cms_portal_call(array('action' => 'delpost', 'postid' => $postid));
    if ($result['errmsg']) {
        die(text($result['errmsg']));
    }

    echo "<html><body><script language='JavaScript'>\n";
    echo "if (top.restoreSession) top.restoreSession(); else opener.top.restoreSession();\n";
    echo "document.location.href = 'list_requests.php';\n";
    echo "</script></body></html>\n";
    exit();
}

// Get the portal request data.
if (!$postid) {
    die(xlt('Request ID is missing!'));
}

$result = cms_portal_call(array('action' => 'getpost', 'postid' => $postid));
if ($result['errmsg']) {
    die(text($result['errmsg']));
}

// Look up the patient in OpenEMR.
$ptid = lookup_openemr_patient($result['post']['user']);

// Get patient's current history data in OpenEMR.
$hyrow = getHistoryData($ptid, "*");
?>
<html>
<head>
<?php html_header_show(); ?>
<link rel=stylesheet href="<?php echo $css_header; ?>" type="text/css">
<link rel="stylesheet" href="<?php echo $GLOBALS['assets_static_relative']; ?>/jquery-datetimepicker-2-5-4/build/jquery.datetimepicker.min.css">

<style>

tr.head   { font-size:10pt; background-color:#cccccc; text-align:center; }
tr.detail { font-size:10pt; background-color:#ddddff; }
td input  { background-color:transparent; }

</style>

<script type="text/javascript" src="../../library/textformat.js?v=<?php echo $v_js_includes; ?>"></script>
<script type="text/javascript" src="<?php echo $GLOBALS['assets_static_relative']; ?>/jquery-min-1-7-2/index.js"></script>
<script type="text/javascript" src="<?php echo $GLOBALS['assets_static_relative']; ?>/jquery-datetimepicker-2-5-4/build/jquery.datetimepicker.full.min.js"></script>

<script language="JavaScript">

function myRestoreSession() {
 if (top.restoreSession) top.restoreSession(); else opener.top.restoreSession();
 return true;
}

// This capitalizes the first letter of each word in the passed input
// element.  It also strips out extraneous spaces.
// Copied from demographics_full.php.
function capitalizeMe(elem) {
 var a = elem.value.split(' ');
 var s = '';
 for(var i = 0; i < a.length; ++i) {
  if (a[i].length > 0) {
   if (s.length > 0) s += ' ';
   s += a[i].charAt(0).toUpperCase() + a[i].substring(1);
  }
 }
 elem.value = s;
}

function validate() {
 var f = document.forms[0];
 // TBD
 return true;
}

$(document).ready(function() {
    $('.datepicker').datetimepicker({
        <?php $datetimepicker_timepicker = false; ?>
        <?php $datetimepicker_showseconds = false; ?>
        <?php $datetimepicker_formatInput = true; ?>
        <?php require($GLOBALS['srcdir'] . '/js/xl/jquery-datetimepicker-2-5-4.js.php'); ?>
        <?php // can add any additional javascript settings to datetimepicker here; need to prepend first setting with a comma ?>
    });
    $('.datetimepicker').datetimepicker({
        <?php $datetimepicker_timepicker = true; ?>
        <?php $datetimepicker_showseconds = false; ?>
        <?php $datetimepicker_formatInput = true; ?>
        <?php require($GLOBALS['srcdir'] . '/js/xl/jquery-datetimepicker-2-5-4.js.php'); ?>
        <?php // can add any additional javascript settings to datetimepicker here; need to prepend first setting with a comma ?>
    });
});

</script>
</head>

<body class="body_top">
<center>

<form method='post' action='history_form.php' onsubmit='return validate()'>

<input type='hidden' name='ptid'   value='<?php echo attr($ptid);   ?>' />
<input type='hidden' name='postid' value='<?php echo attr($postid); ?>' />

<table width='100%' cellpadding='1' cellspacing='2'>
 <tr class='head'>
  <th align='left'><?php echo xlt('Field'); ?></th>
  <th align='left'><?php echo xlt('Current Value'); ?></th>
  <th align='left'><?php echo xlt('New Value'); ?></th>
 </tr>

<?php
$lores = sqlStatement(
    "SELECT * FROM layout_options " .
    "WHERE form_id = ? AND uor > 0 ORDER BY group_id, seq",
    array('HIS')
);

while ($lorow = sqlFetchArray($lores)) {
    $data_type  = $lorow['data_type'];
    $field_id   = $lorow['field_id'];
  // Check for field name match in portal results, case insensitive.
    $reskey = $field_id;
    $gotfield = false;
    foreach ($result['fields'] as $key => $dummy) {
        // For Exam Results the field ID has a colon and list item ID appended.
        if (($i = strpos($key, ':')) !== false) {
            $key = substr($key, 0, $i);
        }

        if (strcasecmp($key, $field_id) == 0) {
            $reskey = $key;
            $gotfield = true;
        }
    }

  // Generate form fields for items that are either from the WordPress form
  // or are mandatory.
    if ($gotfield || $lorow['uor'] > 1) {
        $list_id = $lorow['list_id'];
        $field_title = $lorow['title'];
        if ($field_title === '') {
            $field_title = '(' . $field_id . ')';
        }

        $currvalue = '';
        if (isset($hyrow[$field_id])) {
            $currvalue = $hyrow[$field_id];
        }

        $newvalue = cms_field_to_lbf($data_type, $reskey, $result['fields']);

        echo " <tr class='detail'>\n";
        echo "  <td class='bold'>" . text($field_title) . "</td>\n";
        echo "  <td>" . generate_display_field($lorow, $currvalue) . "</td>\n";
        echo "  <td>";
        generate_form_field($lorow, $newvalue);
        echo "</td>\n";
        echo " </tr>\n";
    }
}

echo "</table>\n";
?>

<p>
<input type='submit' name='bn_save' value='<?php echo xla('Save and Delete Request'); ?>' />
&nbsp;
<input type='button' value='<?php echo xla('Back'); ?>'
 onclick="myRestoreSession();location='list_requests.php'" />
</p>

</form>

<script language="JavaScript">

randompass();

// This is a by-product of generate_form_field().
<?php echo $date_init; ?>

</script>

<!-- include support for the list-add selectbox feature -->
<?php include $GLOBALS['fileroot'] . "/library/options_listadd.inc"; ?>

</center>
</body>
</html>

