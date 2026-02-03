<?php
if (!defined('_PS_VERSION_')) { exit; }

/**
 * Upgrade to 1.8.0
 * - Adds table for field-level diffs (baal_log_details)
 */
function upgrade_module_1_8_0($module)
{
    $ok = true;

    $ok = $ok && Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'baal_log_details` (
        `id_detail` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `id_log` INT UNSIGNED NOT NULL,
        `object_type` VARCHAR(80) NULL,
        `object_id` INT UNSIGNED NULL,
        `field_key` VARCHAR(128) NOT NULL,
        `field_label` VARCHAR(255) NULL,
        `old_value` TEXT NULL,
        `new_value` TEXT NULL,
        `created_at` DATETIME NOT NULL,
        PRIMARY KEY (`id_detail`),
        INDEX `idx_log` (`id_log`),
        INDEX `idx_object` (`object_type`, `object_id`)
    ) ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8mb4;');

    // Register new hooks (safe to call multiple times)
    $hooks = [
        'actionObjectProductUpdateBefore',
        'actionObjectOrderUpdateBefore',
    ];
    foreach ($hooks as $h) {
        try { $module->registerHook($h); } catch (Exception $e) {} catch (Exception $t) {}
    }

    return (bool)$ok;
}
