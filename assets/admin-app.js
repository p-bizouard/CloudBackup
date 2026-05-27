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

    if ($("#RestoreDestination_type").length) {
        const $typeSelect = $("#RestoreDestination_type");
        const $modeSelect = $("#RestoreDestination_connectionMode");
        const $byoCheckbox = $("#RestoreDestination_bringOwnCredentials");

        const matchesCsv = function(csv, value) {
            if (!csv) return true;
            return csv.split(",").map(s => s.trim()).includes(value);
        };

        const applyVisibility = function() {
            const type = $typeSelect.val();
            const mode = $modeSelect.length ? $modeSelect.val() : "";
            const byo = $byoCheckbox.length && $byoCheckbox.is(":checked") ? "true" : "false";

            $(".restore-dest-field").each(function() {
                const $row = $(this);
                const showType = $row.attr("data-show-type") || "";
                const showMode = $row.attr("data-show-mode") || "";
                const showByo = $row.attr("data-show-byo") || "";

                let visible = true;
                if (showType && !matchesCsv(showType, type)) visible = false;
                if (visible && showMode && !matchesCsv(showMode, mode)) visible = false;
                if (visible && showByo && !matchesCsv(showByo, byo)) visible = false;

                $row.toggle(visible);
            });
        };

        $typeSelect.on("change", applyVisibility);
        $modeSelect.on("change", applyVisibility);
        $byoCheckbox.on("change", applyVisibility);

        applyVisibility();
    }
});
