<?php
/*
 * *****************************************************************************
 * Contributions to this work were made on behalf of the GÉANT project, a 
 * project that has received funding from the European Union’s Framework 
 * Programme 7 under Grant Agreements No. 238875 (GN3) and No. 605243 (GN3plus),
 * Horizon 2020 research and innovation programme under Grant Agreements No. 
 * 691567 (GN4-1) and No. 731122 (GN4-2).
 * On behalf of the aforementioned projects, GEANT Association is the sole owner
 * of the copyright in all material which was developed by a member of the GÉANT
 * project. GÉANT Vereniging (Association) is registered with the Chamber of 
 * Commerce in Amsterdam with registration number 40535155 and operates in the 
 * UK as a branch of GÉANT Vereniging.
 * 
 * Registered office: Hoekenrode 3, 1102BR Amsterdam, The Netherlands. 
 * UK branch address: City House, 126-130 Hills Road, Cambridge CB2 1PQ, UK
 *
 * License: see the web/copyright.inc.php file in the file structure or
 *          <base_url>/copyright.php after deploying the software
 */

/**
 * This page is used to edit a RADIUS profile by its administrator.
 * 
 * @author Stefan Winter <stefan.winter@restena.lu>
 */
?>
<?php
require_once dirname(dirname(dirname(__FILE__))) . "/config/_config.php";

$deco = new \web\lib\admin\PageDecoration();
$validator = new \web\lib\common\InputValidation();
$uiElements = new web\lib\admin\UIElements();
// initialize inputs
$my_inst = $validator->IdP($_GET['inst_id'], $_SESSION['user']);

if (!isset($_GET['deployment_id'])) {
    $my_inst->newDeployment(\core\AbstractDeployment::DEPLOYMENTTYPE_MANAGED);
    header("Location: overview_sp.php?inst_id=" . $my_inst->identifier);
    exit(0);
}

// if we have come this far, we are editing an existing deployment

$deployment = $validator->DeploymentManaged($_GET['deployment_id'], $my_inst);
        
if (isset($_POST['submitbutton'])) {
    if ($_POST['submitbutton'] == web\lib\common\FormElements::BUTTON_DELETE) {
        $deployment->deactivate();
        header("Location: overview_sp.php?inst_id=" . $my_inst->identifier);
        exit(0);
    }

    if ($_POST['submitbutton'] == web\lib\common\FormElements::BUTTON_ACTIVATE) {
        $deployment->activate();
        header("Location: overview_sp.php?inst_id=" . $my_inst->identifier);
        exit(0);
    }
    if ($_POST['submitbutton'] == web\lib\common\FormElements::BUTTON_SAVE) {
        $optionParser = new web\lib\admin\OptionParser();
        $optionParser->processSubmittedFields($deployment, $_POST, $_FILES);
        
        header("Location: overview_sp.php?inst_id=" . $my_inst->identifier);
        exit(0);
    }
}
echo $deco->defaultPagePrelude(sprintf(_("%s: Enrollment Wizard (Step 3)"), CONFIG['APPEARANCE']['productname']));
require_once "inc/click_button_js.php";
?>
<script src="js/XHR.js" type="text/javascript"></script>
<script src="js/option_expand.js" type="text/javascript"></script>

</head>
<body>
    <?php
    echo $deco->productheader("ADMIN-SP");
    ?>
    <h1>
        <?php
        printf(_("Editing %s deployment"), $uiElements->nomenclatureHotspot);
        ?>
    </h1>
    <?php
    echo $uiElements->instLevelInfoBoxes($my_inst);
    $deploymentOptions = $deployment->getAttributes();
    echo "<form enctype='multipart/form-data' action='edit_hotspot.php?inst_id=$my_inst->identifier&amp;deployment_id=$deployment->identifier' method='post' accept-charset='UTF-8'>
                <input type='hidden' name='MAX_FILE_SIZE' value='" . CONFIG['MAX_UPLOAD_SIZE'] . "'>";
    $optionDisplay = new \web\lib\admin\OptionDisplay($deploymentOptions, "Profile");

    $fields = [
        "managedsp" => _("Options for this deployment"),
    ];

    foreach ($fields as $name => $description) {
        echo "<fieldset class='option_container' id='" . $name . "_override'>
    <legend><strong>$description</strong></legend>";
        echo $optionDisplay->prefilledOptionTable($name);
        echo "<button type='button' class='newoption' onclick='getXML(\"$name\")'>" . _("Add new option") . "</button>";
        echo "</fieldset>";
    }

    echo "<p><button type='submit' name='submitbutton' value='" . web\lib\common\FormElements::BUTTON_SAVE . "'>" . _("Save data") . "</button><button type='button' class='delete' name='abortbutton' value='abort' onclick='javascript:window.location = \"overview_sp.php?inst_id=$my_inst->identifier\"'>" . _("Discard changes") . "</button></p></form>";
    echo $deco->footer();
    