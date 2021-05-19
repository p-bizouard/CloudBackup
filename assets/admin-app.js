import "./styles/admin-app.scss";
import "jquery";

jQuery(function ($) {
    if ($(".ea-edit-BackupConfiguration").length) {
        const hideShowBackupConfigurationFields = function () {
            const type = $("#BackupConfiguration_type").val();

            $(".backupConfigurationType-field").hide();
            $(".backupConfigurationType-field." + type).show();
        };

        $("#BackupConfiguration_type").on(
            "change",
            hideShowBackupConfigurationFields
        );

        hideShowBackupConfigurationFields();
    }
});
