import "./styles/admin-app.scss";
import jQuery from "jquery";
import filesize from "file-size";

jQuery(function($) {
    if ($("#BackupConfiguration_type").length) {
        const hideShowBackupConfigurationFields = function() {
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

    if ($("#BackupConfiguration_minimumBackupSize").length) {
        const $spanHelper = $("<span/>");

        const showHumanBackupConfiguration_minimumBackupSize = function() {
            const minimumBackupSize = $(
                "#BackupConfiguration_minimumBackupSize"
            ).val();

            $spanHelper.text(
                " (" + filesize(parseInt(minimumBackupSize)).human() + ")"
            );
        };

        $("#BackupConfiguration_minimumBackupSize")
            .parents(".backupConfigurationType-field")
            .find(".form-help")
            .append($spanHelper);

        $("#BackupConfiguration_minimumBackupSize").on(
            "change",
            showHumanBackupConfiguration_minimumBackupSize
        );

        $("#BackupConfiguration_minimumBackupSize").on(
            "keyup",
            showHumanBackupConfiguration_minimumBackupSize
        );

        showHumanBackupConfiguration_minimumBackupSize();
    }

    if ($("#Storage_type").length) {
        const hideShowStorageTypeConfigurations = function() {
            const type = $("#Storage_type").val();

            $(".storage-type-panel").hide();
            $(".storage-type-panel.type-" + type).show();
        };

        $("#Storage_type").on("change", hideShowStorageTypeConfigurations);

        hideShowStorageTypeConfigurations();
    }
});
