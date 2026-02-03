<?php
if (!defined('_PS_VERSION_')) { exit; }

class BestautoAutologHistory extends Module
{
    /** Guard against recursive logging in same request */
    private static $baalLogging = false;

    /** Lightweight anti-spam for Admin VIEW/POST logs (same controller, same employee, short window) */
    private static $baalLastAdminSig = null;
    private static $baalLastAdminTs = 0;

    /** Snapshots for *_UpdateBefore hooks (to capture exact field diffs) */
    private static $baalSnapshots = [
        'customer' => [],
        'address' => [],
        'product' => [],
        'category' => [],
        // Generic snapshots for any ObjectModel class (keyed by class name)
        'obj' => [],
    ];

    /**
     * Employee session timeout (minutes) used to auto-close sessions when there is no activity.
     * Set to 0 to disable auto-close and rely on explicit login/logout.
     */
    const BAAL_SESSION_TIMEOUT_MIN = 0;

    /**
     * In some update flows PrestaShop does not re-run install(),
     * so newly introduced hooks might not be registered.
     * This helper is idempotent and safe to call occasionally.
     */
    private function ensureRequiredHooksRegistered()
    {
        try {
            if (!(int)$this->id) return;
            $need = [
                'actionDispatcherBefore',
                'actionObjectProductUpdateAfter',
                'actionObjectProductUpdateBefore',
                'actionObjectCustomerUpdateAfter',
                'actionObjectCustomerUpdateBefore',
                'actionUpdateQuantity',
            ];
            foreach ($need as $h) {
                try { $this->registerHook($h); } catch (Exception $e) {} catch (Exception $t) {}
            }
        } catch (Exception $t) {}
    }

    public function __construct()
    {
        $this->name = 'bestautoautologhistory';
        $this->tab = 'administration';
        // Keep module version at 1.0.0 per project requirement
        $this->version = '1.0.0';
        $this->author = 'Tsvetelin Penkov';
        $this->bootstrap = true;
        $this->is_configurable = 1;
        parent::__construct();

        // IMPORTANT: Title must remain Autolog History
        $this->displayName = $this->l('BestAuto Autolog History');
        $this->description = $this->l('Проследяване на действия на екипа + Git история + Timeline.');
    }

    public function install()
    {
        if (!parent::install()) { return false; }
        if (!$this->installDb()) { return false; }

        Configuration::updateValue('BAAL_ENABLED', 1);
        Configuration::updateValue('BAAL_GIT_PATH', _PS_ROOT_DIR_);
        Configuration::updateValue('BAAL_GIT_LIMIT', 100);
        Configuration::updateValue('BAAL_GIT_BRANCH', '');
        Configuration::updateValue('BAAL_GIT_HEAD', '');
        Configuration::updateValue('BAAL_PER_PAGE', 20);
        Configuration::updateValue('BAAL_TIMELINE_LIMIT', 200);
        Configuration::updateValue('BAAL_RETENTION_DAYS', 365);

        $hooks = [
            // Products / Categories / Customers / Orders
            'actionObjectProductAddAfter',
            'actionObjectProductUpdateBefore',
            'actionObjectProductUpdateAfter',
            'actionObjectProductDeleteAfter',
            'actionObjectCategoryAddAfter',
            'actionObjectCategoryUpdateBefore',
            'actionObjectCategoryUpdateAfter',
            'actionObjectCategoryDeleteAfter',
            // CMS
            'actionObjectCmsAddAfter',
            'actionObjectCmsUpdateBefore',
            'actionObjectCmsUpdateAfter',
            'actionObjectCmsDeleteAfter',
            'actionObjectCmsCategoryAddAfter',
            'actionObjectCmsCategoryUpdateBefore',
            'actionObjectCmsCategoryUpdateAfter',
            'actionObjectCmsCategoryDeleteAfter',
            // Manufacturers / Suppliers
            'actionObjectManufacturerAddAfter',
            'actionObjectManufacturerUpdateBefore',
            'actionObjectManufacturerUpdateAfter',
            'actionObjectManufacturerDeleteAfter',
            'actionObjectSupplierAddAfter',
            'actionObjectSupplierUpdateBefore',
            'actionObjectSupplierUpdateAfter',
            'actionObjectSupplierDeleteAfter',
            // Carriers / Cart rules
            'actionObjectCarrierAddAfter',
            'actionObjectCarrierUpdateBefore',
            'actionObjectCarrierUpdateAfter',
            'actionObjectCarrierDeleteAfter',
            'actionObjectCartRuleAddAfter',
            'actionObjectCartRuleUpdateBefore',
            'actionObjectCartRuleUpdateAfter',
            'actionObjectCartRuleDeleteAfter',
            // Discounts / Specific prices
            'actionObjectSpecificPriceAddAfter',
            'actionObjectSpecificPriceUpdateBefore',
            'actionObjectSpecificPriceUpdateAfter',
            'actionObjectSpecificPriceDeleteAfter',
            'actionObjectSpecificPriceRuleAddAfter',
            'actionObjectSpecificPriceRuleUpdateBefore',
            'actionObjectSpecificPriceRuleUpdateAfter',
            'actionObjectSpecificPriceRuleDeleteAfter',
            'actionObjectCustomerAddAfter',
            'actionObjectCustomerUpdateBefore',
            'actionObjectCustomerUpdateAfter',
            // Addresses (customer details)
            'actionObjectAddressAddAfter',
            'actionObjectAddressUpdateBefore',
            'actionObjectAddressUpdateAfter',
            'actionObjectAddressDeleteAfter',
            'actionObjectOrderAddAfter',
            'actionObjectOrderUpdateAfter',
            'actionObjectOrderHistoryAddAfter',
            'actionObjectOrderSlipAddAfter',
            'actionObjectOrderReturnAddAfter',

            // Employees
            'actionObjectEmployeeAddAfter',
            'actionObjectEmployeeUpdateAfter',
            'actionObjectEmployeeDeleteAfter',
            // Login / Logout (PS 1.7)
            'actionEmployeeLoginAfter',
            'actionEmployeeLogoutAfter',

            // Stock
            'actionUpdateQuantity',

            // Modules
            'actionModuleInstallAfter',
            'actionModuleEnableAfter',
            'actionModuleDisableAfter',

            // "Everything" in BO: page visits / post actions
            'actionDispatcherBefore',
        ];

        foreach ($hooks as $h) {
            try { $this->registerHook($h); } catch (Exception $e) {} catch (Exception $t) {}
        }
        // Add BO menu entry under Catalog
        $this->installTab();

        return true;
    }

    public function uninstall()
    {
        Configuration::deleteByName('BAAL_ENABLED');
        Configuration::deleteByName('BAAL_GIT_PATH');
        Configuration::deleteByName('BAAL_GIT_LIMIT');
        Configuration::deleteByName('BAAL_GIT_BRANCH');
        Configuration::deleteByName('BAAL_GIT_HEAD');
        Configuration::deleteByName('BAAL_PER_PAGE');
        Configuration::deleteByName('BAAL_TIMELINE_LIMIT');
        Configuration::deleteByName('BAAL_RETENTION_DAYS');
        // Remove BO tab
        $this->uninstallTab();
        // DB is intentionally kept (history). If you want full wipe, add uninstall SQL.
        return parent::uninstall();
    }

    
    private function installTab()
    {
        try {
            if (!class_exists('Tab')) return;
            $class = 'AdminBestautoAutologHistory';
            $id = (int)Tab::getIdFromClassName($class);
            if ($id > 0) return true;

            $parent = (int)Tab::getIdFromClassName('AdminCatalog');
            if ($parent <= 0) {
                // fallback
                $parent = 0;
            }

            $tab = new Tab();
            $tab->active = 1;
            $tab->class_name = $class;
            $tab->module = $this->name;
            $tab->id_parent = $parent;

            foreach (Language::getLanguages(false) as $lang) {
                $tab->name[(int)$lang['id_lang']] = 'Проследяване на действия';
            }

            return (bool)$tab->add();
        } catch (Exception $e) {
            return false;
        } catch (Exception $t) {
            return false;
        }
    }

    private function uninstallTab()
    {
        try {
            if (!class_exists('Tab')) return true;
            $id = (int)Tab::getIdFromClassName('AdminBestautoAutologHistory');
            if ($id > 0) {
                $tab = new Tab($id);
                return (bool)$tab->delete();
            }
            return true;
        } catch (Exception $e) {
            return true;
        } catch (Exception $t) {
            return true;
        }
    }

private function installDb()
    {
        $ok = true;

        // If sql/install.sql exists, execute it (requested: "Готов SQL")
        $installSqlFile = dirname(__FILE__).'/sql/install.sql';
        if (is_file($installSqlFile)) {
            $sql = Tools::file_get_contents($installSqlFile);
            if ($sql) {
                $queries = preg_split('/;\s*\n/', str_replace(['PREFIX_', 'ENGINE_TYPE'], [_DB_PREFIX_, _MYSQL_ENGINE_], $sql));
                foreach ($queries as $q) {
                    $q = trim($q);
                    if ($q !== '') {
                        $ok = $ok && Db::getInstance()->execute($q);
                    }
                }
            }
        } else {
            // Fallback (should not happen)
            $ok = $ok && Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'baal_logs` (
                `id_log` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `employee_id` INT UNSIGNED NULL,
                `employee` VARCHAR(255) NULL,
                `action` VARCHAR(50) NOT NULL,
                `object_type` VARCHAR(80) NULL,
                `object_id` INT UNSIGNED NULL,
                `parent_id` INT UNSIGNED NULL,
                `controller` VARCHAR(80) NULL,
                `http_method` VARCHAR(10) NULL,
                `request_uri` TEXT NULL,
                `ip` VARCHAR(45) NULL,
                `details` TEXT NULL,
                `changes_json` MEDIUMTEXT NULL,
                `created_at` DATETIME NOT NULL,
                `group_last_at` DATETIME NULL,
                PRIMARY KEY (`id_log`)
            ) ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8mb4;');
        }

        $ok = $ok && Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'baal_git_commits` (
            `id_git` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `commit_hash` VARCHAR(64) NOT NULL,
            `author_name` VARCHAR(255) NOT NULL,
            `author_email` VARCHAR(255) NOT NULL,
            `commit_date` VARCHAR(64) NOT NULL,
            `commit_message` TEXT NOT NULL,
            `synced_at` DATETIME NOT NULL,
            PRIMARY KEY (`id_git`),
            UNIQUE KEY `uniq_hash` (`commit_hash`),
            INDEX `idx_author` (`author_name`)
        ) ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8mb4;');

        $ok = $ok && $this->ensureColumns();
        return $ok;
    }

    private function ensureColumns()
    {
        try {
            $table = _DB_PREFIX_.'baal_logs';
            $cols = Db::getInstance()->executeS('SHOW COLUMNS FROM `'.pSQL($table).'`');
            $have = [];
            foreach ($cols as $c) { $have[$c['Field']] = true; }

            $add = [];
            if (!isset($have['employee_id'])) $add[] = 'ADD COLUMN `employee_id` INT UNSIGNED NULL AFTER `id_log`';
            if (!isset($have['session_id'])) $add[] = 'ADD COLUMN `session_id` INT UNSIGNED NULL AFTER `employee_id`';
            if (!isset($have['git_branch'])) $add[] = 'ADD COLUMN `git_branch` VARCHAR(80) NULL AFTER `employee`';
            if (!isset($have['git_head'])) $add[] = 'ADD COLUMN `git_head` VARCHAR(64) NULL AFTER `git_branch`';
            if (!isset($have['parent_id'])) $add[] = 'ADD COLUMN `parent_id` INT UNSIGNED NULL AFTER `object_id`';
            if (!isset($have['controller'])) $add[] = 'ADD COLUMN `controller` VARCHAR(80) NULL AFTER `parent_id`';
            if (!isset($have['http_method'])) $add[] = 'ADD COLUMN `http_method` VARCHAR(10) NULL AFTER `controller`';
            if (!isset($have['request_uri'])) $add[] = 'ADD COLUMN `request_uri` TEXT NULL AFTER `http_method`';
            if (!isset($have['group_last_at'])) $add[] = 'ADD COLUMN `group_last_at` DATETIME NULL AFTER `created_at`';

            if ($add) {
                Db::getInstance()->execute('ALTER TABLE `'.pSQL($table).'` '.implode(', ', $add));
            }

            // Ensure TEXT columns
            $detailsCol = Db::getInstance()->getRow('SHOW COLUMNS FROM `'.pSQL($table).'` WHERE Field = "details"');
            if ($detailsCol && strpos($detailsCol['Type'], 'varchar') !== false) {
                Db::getInstance()->execute('ALTER TABLE `'.pSQL($table).'` MODIFY COLUMN `details` TEXT NULL');
            }

            // Indexes for performance (requested: optimize)
            $this->ensureIndex($table, 'idx_parent', '(`parent_id`)');
            $this->ensureIndex($table, 'idx_empid', '(`employee_id`)');
            $this->ensureIndex($table, 'idx_sess', '(`session_id`)');
            $this->ensureIndex($table, 'idx_obj_action', '(`object_type`, `object_id`, `action`, `parent_id`)');
            $this->ensureIndex($table, 'idx_group_last', '(`group_last_at`)');
            $this->ensureIndex($table, 'idx_admin_throttle', '(`employee_id`, `object_type`, `action`, `controller`, `created_at`)');

            // Backfill employee_id for legacy rows (if any)
            try {
                Db::getInstance()->execute(
                    'UPDATE `'.pSQL($table).'` l '
                    .'INNER JOIN `'._DB_PREFIX_.'employee` e ON CONCAT(e.firstname, " ", e.lastname) = l.employee '
                    .'SET l.employee_id = e.id_employee '
                    .'WHERE (l.employee_id IS NULL OR l.employee_id = 0) AND l.employee IS NOT NULL AND l.employee <> ""'
                );
            } catch (Exception $e) {} catch (Exception $t) {}

            // Employee sessions table
            Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'baal_employee_sessions` (
                `id_session` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `employee_id` INT UNSIGNED NOT NULL,
                `employee` VARCHAR(255) NULL,
                `login_at` DATETIME NOT NULL,
                `last_activity` DATETIME NULL,
                `logout_at` DATETIME NULL,
                `duration_sec` INT UNSIGNED NULL,
                `ip` VARCHAR(45) NULL,
                `user_agent` VARCHAR(255) NULL,
                `actions_count` INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`id_session`),
                INDEX `idx_emp_login` (`employee_id`, `login_at`),
                INDEX `idx_last_activity` (`last_activity`),
                INDEX `idx_logout` (`logout_at`)
            ) ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8mb4;');

            // Add missing columns to sessions table for older installs
            $sessTable = _DB_PREFIX_.'baal_employee_sessions';
            $sessCols = Db::getInstance()->executeS('SHOW COLUMNS FROM `'.pSQL($sessTable).'`');
            $haveS = [];
            foreach ($sessCols as $c) { $haveS[$c['Field']] = true; }
            $alterS = [];
            if (!isset($haveS['last_activity'])) $alterS[] = 'ADD COLUMN `last_activity` DATETIME NULL AFTER `login_at`';
            if ($alterS) {
                Db::getInstance()->execute('ALTER TABLE `'.pSQL($sessTable).'` '.implode(', ', $alterS));
            }

            // Ensure index for last_activity
            $this->ensureIndex($sessTable, 'idx_last_activity', '(`last_activity`)');

            return true;
        } catch (Exception $e) {
            return true;
        } catch (Exception $t) {
            return true;
        }
    }

    private function ensureIndex($table, $name, $colsSql)
    {
        try {
            $exists = Db::getInstance()->getValue(
                'SELECT COUNT(1) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "'.pSQL($table).'" AND INDEX_NAME = "'.pSQL($name).'"'
            );
            if (!(int)$exists) {
                Db::getInstance()->execute('CREATE INDEX `'.pSQL($name).'` ON `'.pSQL($table).'` '.$colsSql);
            }
        } catch (Exception $e) {
        } catch (Exception $t) {
        }
    }

    private function baalIsEnabled()
    {
        return (int)Configuration::get('BAAL_ENABLED') === 1;
    }

    private function pruneOldLogsIfNeeded()
    {
        try {
            $days = (int)Configuration::get('BAAL_RETENTION_DAYS');
            if ($days <= 0) return;
            // Light pruning: once per request at most, and only when module page is opened.
            $cutoff = date('Y-m-d H:i:s', time() - ($days * 86400));
            // Delete children first, then parents
            Db::getInstance()->execute('DELETE FROM `'._DB_PREFIX_.'baal_logs` WHERE `created_at` < "'.pSQL($cutoff).'"');
        } catch (Exception $e) {
        } catch (Exception $t) {
        }
    }

    /**
     * Enforce retention even if the module page is not opened often.
     * Runs at most once per 24h (best-effort).
     */
    private function baalMaybeAutoPrune()
    {
        try {
            $last = (string)Configuration::get('BAAL_LAST_PRUNE');
            $lastTs = $last ? strtotime($last) : 0;
            if ($lastTs && (time() - $lastTs) < 86400) {
                return;
            }
            $this->pruneOldLogsIfNeeded();
            Configuration::updateValue('BAAL_LAST_PRUNE', date('Y-m-d H:i:s'));
        } catch (Exception $e) {
        } catch (Exception $t) {
        }
    }


    private function getLangValue($value, $langId)
    {
        if (is_array($value)) {
            return (string)(isset($value[(int)$langId]) ? $value[(int)$langId] : '');
        }
        return (string)$value;
    }

    /* =====================
     * Generic ObjectModel diff engine
     * ===================== */

    /**
     * Fetch a lightweight snapshot of an ObjectModel from DB (base + default lang fields).
     * Returns associative array of field => value.
     */
    private function baalSnapshotFromDb($class, $id, $idLang = null)
    {
        try {
            if (!class_exists($class)) return null;
            $def = ObjectModel::getDefinition($class);
            if (empty($def['table']) || empty($def['primary'])) return null;

            $table = _DB_PREFIX_.bqSQL($def['table']);
            $primary = bqSQL($def['primary']);
            $row = Db::getInstance()->getRow('SELECT * FROM `'.bqSQL($table).'` WHERE `'.$primary.'`='.(int)$id);
            if (!$row) return null;

            $snap = [];
            foreach ($row as $k => $v) {
                $snap[$k] = $v;
            }

            $idLang = $idLang ? (int)$idLang : (int)Configuration::get('PS_LANG_DEFAULT');
            if (!empty($def['multilang'])) {
                $langTable = $table.'_lang';
                $langRow = Db::getInstance()->getRow(
                    'SELECT * FROM `'.bqSQL($langTable).'` WHERE `'.$primary.'`='.(int)$id.' AND `id_lang`='.(int)$idLang
                );
                if ($langRow) {
                    foreach ($langRow as $k => $v) {
                        // Skip technical keys
                        if ($k === $primary || $k === 'id_lang' || $k === 'id_shop') continue;
                        $snap[$k] = $v;
                    }
                }
            }

            // Multishop (optional): include shop fields for current shop
            if (!empty($def['multishop'])) {
                $idShop = (int)Context::getContext()->shop->id;
                $shopTable = $table.'_shop';
                $shopRow = Db::getInstance()->getRow(
                    'SELECT * FROM `'.bqSQL($shopTable).'` WHERE `'.$primary.'`='.(int)$id.' AND `id_shop`='.(int)$idShop
                );
                if ($shopRow) {
                    foreach ($shopRow as $k => $v) {
                        if ($k === $primary || $k === 'id_shop') continue;
                        // Shop fields override base when relevant
                        $snap[$k] = $v;
                    }
                }
            }

            return $snap;
        } catch (Exception $e) {
            return null;
        } catch (Exception $t) {
            return null;
        }
    }

    /** Human-friendly field labels (Bulgarian) */
    private function baalFieldLabel($objectType, $field)
    {
        $field = (string)$field;
        $map = [
            // Common
            'active' => 'Статус',
            'name' => 'Име',
            'title' => 'Заглавие',
            'description' => 'Описание',
            'description_short' => 'Кратко описание',
            'meta_title' => 'Мета заглавие',
            'meta_description' => 'Мета описание',
            'meta_keywords' => 'Мета ключови думи',
            'link_rewrite' => 'SEO URL',
            'position' => 'Позиция',
            // Product-ish
            'price' => 'Цена',
            'wholesale_price' => 'Цена на едро',
            'reference' => 'Каталожен №',
            'ean13' => 'EAN13',
            'upc' => 'UPC',
            'isbn' => 'ISBN',
            'quantity' => 'Количество',
            'id_category_default' => 'Категория по подразбиране',
            'visibility' => 'Видимост',
            'available_for_order' => 'Достъпен за поръчка',
            'show_price' => 'Показвай цена',
            'online_only' => 'Само онлайн',
            'id_tax_rules_group' => 'Данъчна група',
            'condition' => 'Състояние',
            // Orders
            'current_state' => 'Статус',
            'shipping_number' => 'Товарителница',
            // Customer/Employee
            'firstname' => 'Име',
            'lastname' => 'Фамилия',
            'email' => 'Имейл',
            'passwd' => 'Парола',
            'id_profile' => 'Роля',
            // Category/CMS
            'id_parent' => 'Родител',
        'AdminAjaxPsAccounts' => 'PS Accounts',
        'AdminAjaxPsMbo' => 'Marketplace',
        'AdminAjax' => 'AJAX',
        'AdminTranslations' => 'Преводи',
        'AdminEmployees' => 'Служители',
        'AdminProfiles' => 'Роли',
        'AdminAccess' => 'Права',
        ];

        if (isset($map[$field])) return $map[$field];
        // Fallback: nicer spacing
        return Tools::ucfirst(str_replace('_', ' ', $field));
    }

    /** Format values for display */
    private function baalFormatValue($field, $value)
    {
        if ($value === null) return '—';
        if ($value === '') return '—';

        $f = (string)$field;

        // Mask passwords / secrets
        if (in_array($f, ['passwd', 'password', 'secure_key', 'api_key', 'secret'], true)) {
            return '••••••';
        }

        // Booleans
        if (in_array($f, ['active', 'available_for_order', 'show_price', 'online_only', 'indexed'], true)) {
            return ((int)$value ? 'Да' : 'Не');
        }

        // Prices
        if (in_array($f, ['price', 'wholesale_price', 'unit_price', 'reduction'], true)) {
            return number_format((float)$value, 2).' лв';
        }

        // Dates
        if (preg_match('/^date_/', $f) || in_array($f, ['created_at', 'updated_at'], true)) {
            return (string)$value;
        }

        // IDs
        if (preg_match('/^id_/', $f) && is_numeric($value)) {
            return 'ID: '.(int)$value;
        }

        $s = (string)$value;
        // Compact long texts
        if (Tools::strlen($s) > 140) {
            $s = Tools::substr(strip_tags($s), 0, 140).'…';
        }
        return $s;
    }

    /** Build changes array from old/new snapshots */
    private function baalBuildChangesFromSnapshots($objectType, $old, $new)
    {
        if (!is_array($old) || !is_array($new)) return null;
        $ignore = [
            'date_upd', 'date_add',
            'id_shop', 'id_lang',
        ];

        $changes = [];
        $keys = array_unique(array_merge(array_keys($old), array_keys($new)));
        foreach ($keys as $k) {
            if (in_array($k, $ignore, true)) continue;
            // Skip technical primary keys
            if (preg_match('/^id_/', $k) && isset($old[$k]) && isset($new[$k]) && (string)$old[$k] === (string)$new[$k]) {
                // allow id_* diffs only when actually changed
            }

            $ov = array_key_exists($k, $old) ? $old[$k] : null;
            $nv = array_key_exists($k, $new) ? $new[$k] : null;

            // Normalize whitespace
            if (is_string($ov)) $ov = trim($ov);
            if (is_string($nv)) $nv = trim($nv);

            // Treat numeric strings consistently
            if (is_numeric($ov) && is_numeric($nv)) {
                if ((string)$ov === (string)$nv) continue;
                // float comparisons
                if (abs((float)$ov - (float)$nv) < 0.00001) continue;
            } else {
                if ((string)$ov === (string)$nv) continue;
            }

            $changes[] = [
                'field' => $this->baalFieldLabel($objectType, $k),
                'old' => $this->baalFormatValue($k, $ov),
                'new' => $this->baalFormatValue($k, $nv),
            ];
        }

        // Keep it readable: sort by field label
        usort($changes, function ($a, $b) {
            return strcmp((string)$a['field'], (string)$b['field']);
        });

        return $changes;
    }

/* =====================
     * Change capture helpers
     * ===================== */

    /**
     * Normalize rich text so we can compare semantic content and avoid false "changed" logs
     * caused by tiny HTML/whitespace differences.
     */
    private function baalNormalizeText($s)
    {
        if ($s === null) return '';
        $s = (string)$s;
        // Convert &nbsp; and similar
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = str_replace(["\xC2\xA0", "\u{00A0}"], ' ', $s);
        // Strip tags and collapse whitespace
        $s = strip_tags($s);
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim($s);
    }

    /**
     * Normalize simple strings (titles, meta fields) - whitespace only.
     */
    private function baalNormalizeString($s)
    {
        if ($s === null) return '';
        $s = (string)$s;
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim($s);
    }

    /** Float compare with tolerance. */
    private function baalFloatChanged($a, $b, $eps = 0.00001)
    {
        return abs((float)$a - (float)$b) > (float)$eps;
    }

    private function captureProductChanges($productId)
    {
        try {
            // Only log REAL product changes (whitelisted fields) with robust normalization.
            $lang = (int)Configuration::get('PS_LANG_DEFAULT');
            $p = new Product((int)$productId, false, $lang);
            if (!Validate::isLoadedObject($p)) return null;

            $snap = null;
            if (isset(self::$baalSnapshots['product'][(int)$productId])) {
                $snap = self::$baalSnapshots['product'][(int)$productId];
                unset(self::$baalSnapshots['product'][(int)$productId]);
            }
            if (!is_array($snap)) {
                // Without snapshot we can't guarantee "real" diffs -> do not log.
                return null;
            }
            if (isset($snap['lang'])) $lang = (int)$snap['lang'];

            // Build NEW values
            $new = [
                'price' => (float)$p->price,
                'reference' => (string)$p->reference,
                'name' => (string)$this->getLangValue($p->name, $lang),
                'description_short' => (string)$this->getLangValue($p->description_short, $lang),
                'description' => (string)$this->getLangValue($p->description, $lang),
                'meta_description' => (string)$this->getLangValue($p->meta_description, $lang),
                'quantity' => null,
            ];
            try {
                if (class_exists('StockAvailable')) {
                    $new['quantity'] = (int)StockAvailable::getQuantityAvailableByProduct((int)$productId);
                }
            } catch (Exception $t) {
                $new['quantity'] = null;
            }

            // Whitelist of fields you requested.
            $labels = [
                'price' => 'Цена',
                'reference' => 'Код',
                'name' => 'Заглавие',
                'quantity' => 'Количество',
                'description_short' => 'Кратко описание',
                'description' => 'Описание',
                'meta_description' => 'Мета описание',
            ];

            $changes = [];
            foreach ($labels as $k => $label) {
                $ov = array_key_exists($k, $snap) ? $snap[$k] : null;
                $nv = array_key_exists($k, $new) ? $new[$k] : null;

                $changed = false;
                if ($k === 'price') {
                    $changed = $this->baalFloatChanged($ov, $nv);
                } elseif ($k === 'quantity') {
                    // If qty is unknown, do not log it.
                    if ($ov === null || $nv === null) {
                        $changed = false;
                    } else {
                        $changed = ((int)$ov !== (int)$nv);
                    }
                } elseif (in_array($k, ['description', 'description_short'], true)) {
                    $changed = ($this->baalNormalizeText($ov) !== $this->baalNormalizeText($nv));
                } else {
                    $changed = ($this->baalNormalizeString($ov) !== $this->baalNormalizeString($nv));
                }

                if (!$changed) continue;

                // Format display values
                if ($k === 'price') {
                    $oldDisp = number_format((float)$ov, 2).' лв';
                    $newDisp = number_format((float)$nv, 2).' лв';
                } elseif ($k === 'quantity') {
                    $oldDisp = (string)(int)$ov;
                    $newDisp = (string)(int)$nv;
                } elseif (in_array($k, ['description', 'description_short'], true)) {
                    $oldDisp = Tools::substr($this->baalNormalizeText($ov), 0, 160);
                    $newDisp = Tools::substr($this->baalNormalizeText($nv), 0, 160);
                } else {
                    $oldDisp = Tools::substr((string)$ov, 0, 160);
                    $newDisp = Tools::substr((string)$nv, 0, 160);
                }

                $changes[] = ['field' => $label, 'old' => ($oldDisp === '' ? '—' : $oldDisp), 'new' => ($newDisp === '' ? '—' : $newDisp)];
            }

            return $changes ? $changes : null;
        } catch (Exception $t) {
            return null;
        }
    }

    private 
function captureProductChangesFromRequest($idProduct)
{
    $idLang = (int)Context::getContext()->language->id;
    $p = new Product((int)$idProduct, false, $idLang);
    $changes = [];

    $newName = Tools::getValue('name_'.$idLang);
    $newPrice = Tools::getValue('price');
    $newActive = Tools::getValue('active');

    $productForm = Tools::getValue('product');
    if (is_array($productForm)) {
        if (isset($productForm['name']) && is_array($productForm['name']) && isset($productForm['name'][$idLang])) {
            $newName = $productForm['name'][$idLang];
        }
        if (isset($productForm['price'])) $newPrice = $productForm['price'];
        if (isset($productForm['active'])) $newActive = $productForm['active'];
    }

    if ($newName !== null) {
        $old = $this->getLangValue($p->name, $idLang);
        if ((string)$old !== (string)$newName) $changes[] = ['field'=>'Име','old'=>$old,'new'=>(string)$newName];
    }
    if ($newPrice !== null) {
        $old = (string)$p->price;
        if ((string)$old !== (string)$newPrice) $changes[] = ['field'=>'Цена','old'=>$old,'new'=>(string)$newPrice];
    }
    if ($newActive !== null) {
        $old = (int)$p->active;
        $new = (int)$newActive;
        if ($old !== $new) $changes[] = ['field'=>'Статус','old'=>($old? 'Активен':'Неактивен'),'new'=>($new? 'Активен':'Неактивен')];
    }
    return $changes;
}

function captureOrderChanges($orderId)
    {
        try {
            $changes = [];
            $order = new Order($orderId);
            if (!Validate::isLoadedObject($order)) return null;

            $lang = (int)Configuration::get('PS_LANG_DEFAULT');

            if (isset($_POST['id_order_state']) && (int)$_POST['id_order_state'] != (int)$order->current_state) {
                $oldState = new OrderState((int)$order->current_state, $lang);
                $newState = new OrderState((int)$_POST['id_order_state'], $lang);
                $changes[] = ['field' => 'Статус', 'old' => is_array($oldState->name) ? $oldState->name[$lang] : $oldState->name, 'new' => is_array($newState->name) ? $newState->name[$lang] : $newState->name];
            }
            if (isset($_POST['id_carrier']) && (int)$_POST['id_carrier'] != (int)$order->id_carrier) {
                $changes[] = ['field' => 'Куриер', 'old' => 'ID: '.(int)$order->id_carrier, 'new' => 'ID: '.(int)$_POST['id_carrier']];
            }
            if (isset($_POST['id_address_delivery']) && (int)$_POST['id_address_delivery'] != (int)$order->id_address_delivery) {
                $changes[] = ['field' => 'Адрес за доставка', 'old' => 'ID: '.(int)$order->id_address_delivery, 'new' => 'ID: '.(int)$_POST['id_address_delivery']];
            }
            if (isset($_POST['id_address_invoice']) && (int)$_POST['id_address_invoice'] != (int)$order->id_address_invoice) {
                $changes[] = ['field' => 'Адрес за фактура', 'old' => 'ID: '.(int)$order->id_address_invoice, 'new' => 'ID: '.(int)$_POST['id_address_invoice']];
            }
            if (isset($_POST['total_paid']) && (string)$_POST['total_paid'] !== '' && (float)$_POST['total_paid'] != (float)$order->total_paid) {
                $changes[] = ['field' => 'Обща сума', 'old' => number_format((float)$order->total_paid, 2).' лв', 'new' => number_format((float)$_POST['total_paid'], 2).' лв'];
            }

            return $changes;
        } catch (Exception $e) {
            return null;
        } catch (Exception $t) {
            return null;
        }
    }

    private function captureCustomerChanges($customerId)
    {
        try {
            $changes = [];
            $customer = new Customer($customerId);
            if (!Validate::isLoadedObject($customer)) return null;

            $snap = null;
            if (isset(self::$baalSnapshots['customer'][(int)$customerId])) {
                $snap = self::$baalSnapshots['customer'][(int)$customerId];
                unset(self::$baalSnapshots['customer'][(int)$customerId]);
            }

            // Compare snapshot (old) -> current (new)
            if (is_array($snap)) {
                if ((string)$snap['firstname'] !== (string)$customer->firstname) {
                    $changes[] = ['field' => 'Име', 'old' => (string)$snap['firstname'], 'new' => (string)$customer->firstname];
                }
                if ((string)$snap['lastname'] !== (string)$customer->lastname) {
                    $changes[] = ['field' => 'Фамилия', 'old' => (string)$snap['lastname'], 'new' => (string)$customer->lastname];
                }
                if ((string)$snap['email'] !== (string)$customer->email) {
                    $changes[] = ['field' => 'Имейл', 'old' => (string)$snap['email'], 'new' => (string)$customer->email];
                }
                if ((int)$snap['active'] !== (int)$customer->active) {
                    $changes[] = ['field' => 'Статус', 'old' => ((int)$snap['active'] ? 'Активен' : 'Неактивен'), 'new' => ((int)$customer->active ? 'Активен' : 'Неактивен')];
                }
                if ((int)$snap['newsletter'] !== (int)$customer->newsletter) {
                    $changes[] = ['field' => 'Бюлетин', 'old' => ((int)$snap['newsletter'] ? 'Да' : 'Не'), 'new' => ((int)$customer->newsletter ? 'Да' : 'Не')];
                }
                if ((int)$snap['optin'] !== (int)$customer->optin) {
                    $changes[] = ['field' => 'Маркетинг', 'old' => ((int)$snap['optin'] ? 'Да' : 'Не'), 'new' => ((int)$customer->optin ? 'Да' : 'Не')];
                }
            } else {
                // Fallback: compare POST (best-effort)
                if (isset($_POST['firstname']) && (string)$_POST['firstname'] !== (string)$customer->firstname) {
                    $changes[] = ['field' => 'Име', 'old' => (string)$customer->firstname, 'new' => (string)$_POST['firstname']];
                }
            }

            return $changes;
        } catch (Exception $e) {
            return null;
        } catch (Exception $t) {
            return null;
        }
    }


    /* =====================
     * Core logger
     * ===================== */

    private function getObjectIcon($type)
    {
        $map = [
            'product' => 'icon-cube',
            'order' => 'icon-shopping-cart',
            'order_status' => 'icon-shopping-cart',
            'customer' => 'icon-user',
            'category' => 'icon-tags',
            'stock' => 'icon-database',
            'login' => 'icon-sign-in',
            'logout' => 'icon-sign-out',
            'admin' => 'icon-desktop',
            'system' => 'icon-cogs',
        ];
        return isset($map[$type]) ? $map[$type] : 'icon-info';
    }

    /**
     * Map internal object_type keys to Bulgarian labels.
     * Used in UI (tables, stats, timeline) to avoid English keys.
     */
    private function getObjectTypeMap()
    {
        return [
            'product' => 'Продукт',
            'order' => 'Поръчка',
            // legacy key that may exist in older rows
            'order_status' => 'Поръчка',
            'customer' => 'Клиент',
            'category' => 'Категория',
            'stock' => 'Склад',
            'employee' => 'Служител',
            'login' => 'Служител',
            'logout' => 'Служител',
            'admin' => 'Админ панел',
            'system' => 'Система',
            'git' => 'Git',
        ];
    }

    /**
     * Human-friendly object suffix (e.g. " - Тестова категория") for log details.
     * Safe for PHP 5.6 / PS 1.7.7.5.
     */
    private function getObjectTitleSuffix($type, $id, $params = null)
    {
        $type = (string)$type;
        $id = (int)$id;
        if ($id <= 0) return '';

        // Try to use passed object first (useful on delete hooks)
        if (is_array($params) && isset($params['object']) && is_object($params['object'])) {
            $obj = $params['object'];
            try {
                if ($type === 'category' && isset($obj->name)) {
                    $name = $this->getLangValue($obj->name, (int)$this->context->language->id);
                    if ($name !== '') return ' - '.$name;
                }
                if ($type === 'product' && isset($obj->name)) {
                    $name = $this->getLangValue($obj->name, (int)$this->context->language->id);
                    if ($name !== '') return ' - '.$name;
                }
                if ($type === 'customer') {
                    $fn = isset($obj->firstname) ? trim((string)$obj->firstname) : '';
                    $ln = isset($obj->lastname) ? trim((string)$obj->lastname) : '';
                    $em = isset($obj->email) ? trim((string)$obj->email) : '';
                    $label = trim($fn.' '.$ln);
                    if ($label === '' && $em !== '') $label = $em;
                    if ($label !== '') return ' - '.$label;
                }
                if ($type === 'order') {
                    if (isset($obj->reference) && (string)$obj->reference !== '') return ' - '.(string)$obj->reference;
                }
            } catch (Exception $e) {}
        }

        // Fallback to load from DB/ObjectModel
        try {
            if ($type === 'category') {
                $cat = new Category($id, (int)$this->context->language->id);
                if (Validate::isLoadedObject($cat)) {
                    $name = (string)$cat->name;
                    if ($name !== '') return ' - '.$name;
                }
            } elseif ($type === 'product') {
                $prod = new Product($id, false, (int)$this->context->language->id);
                if (Validate::isLoadedObject($prod)) {
                    $name = (string)$prod->name;
                    if ($name !== '') return ' - '.$name;
                }
            } elseif ($type === 'customer') {
                $c = new Customer($id);
                if (Validate::isLoadedObject($c)) {
                    $label = trim((string)$c->firstname.' '.(string)$c->lastname);
                    if ($label === '' && (string)$c->email !== '') $label = (string)$c->email;
                    if ($label !== '') return ' - '.$label;
                }
            } elseif ($type === 'order') {
                $o = new Order($id);
                if (Validate::isLoadedObject($o) && (string)$o->reference !== '') {
                    return ' - '.(string)$o->reference;
                }
            }
        } catch (Exception $e) {}

        return '';
    }


    private function getActionMeta($action)
    {
        $action = strtoupper((string)$action);

        // We return CUSTOM badge class names (baal-b-*) so every action has a unique color.
        $meta = [
            'ADD'      => ['label' => 'Създаване',  'badge' => 'baal-b-add',    'icon' => 'icon-plus'],
            'UPDATE'   => ['label' => 'Промяна',    'badge' => 'baal-b-update', 'icon' => 'icon-pencil'],
            'DELETE'   => ['label' => 'Изтриване',  'badge' => 'baal-b-delete', 'icon' => 'icon-trash'],

            'LOGIN'    => ['label' => 'Вход',       'badge' => 'baal-b-login',  'icon' => 'icon-sign-in'],
            'LOGOUT'   => ['label' => 'Изход',      'badge' => 'baal-b-logout', 'icon' => 'icon-sign-out'],

            'VIEW'     => ['label' => 'Преглед',    'badge' => 'baal-b-view',   'icon' => 'icon-eye'],
            'POST'     => ['label' => 'Действие',   'badge' => 'baal-b-post',   'icon' => 'icon-bolt'],

            'STOCK'    => ['label' => 'Склад',      'badge' => 'baal-b-stock',  'icon' => 'icon-database'],
            'INSTALL'  => ['label' => 'Инсталиране','badge' => 'baal-b-system', 'icon' => 'icon-cogs'],
            'SYSTEM'   => ['label' => 'Система',    'badge' => 'baal-b-system', 'icon' => 'icon-cogs'],
            'NAV'      => ['label' => 'Админ панел','badge' => 'baal-b-nav',    'icon' => 'icon-exchange'],
            'STATUS'   => ['label' => 'Статус',     'badge' => 'baal-b-status', 'icon' => 'icon-refresh'],
        ];

        return isset($meta[$action]) ? $meta[$action] : ['label' => $action, 'badge' => 'baal-b-other', 'icon' => 'icon-info'];
    }

    private 
/**
 * Translate BO controller/route keys to Bulgarian-friendly names.
 */
function translateAdminController($key)
{
    $map = [
        'AdminDashboard' => 'Табло',
        'AdminOrders' => 'Поръчки',
        'AdminProducts' => 'Продукти',
        'AdminCategories' => 'Категории',
        'AdminCustomers' => 'Клиенти',
        'AdminStockManagement' => 'Склад',
        'AdminCarriers' => 'Доставка',
        'AdminPayment' => 'Плащания',
        'AdminModules' => 'Модули',
        'AdminModulesManage' => 'Модули',
        'AdminLogin' => 'Вход',
        'AdminLogout' => 'Изход',
        'AdminCommon' => 'Общ екран',
        'AdminGformrequest' => 'Форми',
        // Symfony routes (best effort)
        'sell_catalog_products_index' => 'Продукти',
        'sell_catalog_products_edit' => 'Продукти',
        'sell_orders_index' => 'Поръчки',
        'sell_customers_index' => 'Клиенти',
                'AdminBestautoAutologHistory' => 'Проследяване на действия',
            'AdminStats' => 'Статистики',
            'AdminStockInstantState' => 'Склад (Наличности)',
            'AdminSuppliers' => 'Доставчици',
            'AdminManufacturers' => 'Производители',
            'AdminCartRules' => 'Ваучери',
            'AdminCatalog' => 'Каталог',
            'AdminAttributesGroups' => 'Атрибути',
            'AdminFeatures' => 'Характеристики',
            'AdminCmsContent' => 'CMS',
            'sell_catalog_products_create' => 'Продукти (създаване)',
            'sell_orders' => 'Поръчки',
];
    return isset($map[$key]) ? $map[$key] : 'Админ панел';
}

/**
 * Explanation of admin controller/route technical keys (shown in parentheses).
 * Helps non-technical users understand what "AdminModulesManage" etc. means.
 */
function explainAdminController($key)
{
    $map = [
        'AdminModulesManage'   => 'Управление на модулите',
        'AdminModules'         => 'Модули (страница за конфигуриране/списък)',
        'AdminAjaxPsAccounts'  => 'PrestaShop Accounts (AJAX)',
        'AdminAjaxPsMbo'       => 'Marketplace / Addons (AJAX)',
        'AdminAjax'            => 'AJAX заявка (общо)',
        'AdminCommon'          => 'Общи/междинни заявки в админа (AJAX/redirect)',
        'AdminLogin'           => 'Вход на служител',
        'AdminLogout'          => 'Изход на служител',
        'AdminDashboard'       => 'Табло (Dashboard)',
        'AdminProducts'        => 'Продукти',
        'AdminOrders'          => 'Поръчки',
        'AdminCustomers'       => 'Клиенти',
        'AdminStockManagement' => 'Склад и наличности',
        // Symfony routes (PS 1.7/1.7.7 mixed areas)
        'sell_catalog_products_index'  => 'Каталог → Продукти (списък)',
        'sell_catalog_products_edit'   => 'Каталог → Продукт (редакция)',
        'sell_catalog_products_create' => 'Каталог → Продукт (създаване)',
        'sell_orders_index'            => 'Поръчки (списък)',
        'sell_customers_index'         => 'Клиенти (списък)',
    ];
    return isset($map[$key]) ? $map[$key] : '';
}

/** Verbose label: "Модули (AdminModulesManage – Управление на модулите)" */
function translateAdminControllerVerbose($key)
{
    $k = (string)$key;
    $label = $this->translateAdminController($k);
    $exp = $this->explainAdminController($k);

    if ($k === '') return $label;
    if ($exp !== '') return $label.' ('.$k.' – '.$exp.')';
    return $label.' ('.$k.')';
}



function getScreenMeta($key)
{
    $label = $this->translateAdminController((string)$key);
    $badge = 'baal-b-info';
    $icon = 'icon-desktop';
    $k = (string)$key;
    if (strpos($k, 'Orders') !== false) { $badge = 'baal-b-primary'; $icon = 'icon-shopping-cart'; }
    elseif (strpos($k, 'Products') !== false || strpos($k, 'catalog_products') !== false) { $badge = 'baal-b-success'; $icon = 'icon-tags'; }
    elseif (strpos($k, 'Customers') !== false) { $badge = 'baal-b-purple'; $icon = 'icon-user'; }
    elseif (strpos($k, 'Categories') !== false) { $badge = 'baal-b-warning'; $icon = 'icon-folder-open'; }
    elseif (strpos($k, 'Stock') !== false) { $badge = 'baal-b-teal'; $icon = 'icon-archive'; }
    elseif (strpos($k, 'Login') !== false) { $badge = 'baal-b-success'; $icon = 'icon-sign-in'; }
    elseif (strpos($k, 'Logout') !== false) { $badge = 'baal-b-danger'; $icon = 'icon-sign-out'; }
    elseif ($k === 'AdminCommon') { $badge = 'baal-b-muted'; $icon = 'icon-cogs'; }
    return ['label'=>$label, 'badge'=>$badge, 'icon'=>$icon];
}

function translateDetails($details, $controller)
{
    $d = (string)$details;
    if ($d === '') return $d;

    if (strpos($d, 'Админ екран:') === 0) {
        $parts = explode('|', $d);
        $suffixParts = [];
        if (count($parts) > 1) {
            for ($i=1; $i<count($parts); $i++) {
                $p = trim($parts[$i]);
                if ($p !== '') $suffixParts[] = $p;
            }
        }

        $label = $this->translateAdminControllerVerbose((string)$controller);

        // Extract UI action
        $uiAction = '';
        foreach ($suffixParts as $p) {
            if (stripos($p, 'ui_action=') === 0) {
                $uiAction = trim(substr($p, strlen('ui_action=')));
                break;
            }
        }
        if ($uiAction === '') {
            foreach ($suffixParts as $p) {
                if (stripos($p, 'submitAction=') === 0) {
                    $v = trim(substr($p, strlen('submitAction=')));
                    $uiAction = $this->baalTranslateUiToken('submitAction', $v);
                    break;
                }
            }
        }

        if ($uiAction !== '') {
            $label .= ' ('.$uiAction.')';
        } else {
            foreach ($suffixParts as $p) {
                if (stripos($p, 'method=') === 0) {
                    $mth = trim(substr($p, strlen('method=')));
                    if ($mth !== '') $label .= ' ('.$mth.')';
                    break;
                }
            }
        }

        // Pretty suffix (keep only 2)
        $suffix = '';
        if (!empty($suffixParts)) {
            $clean = [];
            foreach ($suffixParts as $p) {
                if (stripos($p, 'ui_action=') === 0) continue;
                $clean[] = $p;
            }
            $clean = array_slice($clean, 0, 2);
            if (!empty($clean)) {
                $pretty = [];
                foreach ($clean as $p) {
                    if (stripos($p, 'method=') === 0) {
                        $pretty[] = 'Метод: '.trim(substr($p, strlen('method=')));
                    } elseif (stripos($p, 'uri=') === 0) {
                        $pretty[] = 'URL: '.trim(substr($p, strlen('uri=')));
                    } elseif (stripos($p, 'submitAction=') === 0) {
                        $pretty[] = 'Код: '.trim(substr($p, strlen('submitAction=')));
                    } else {
                        $pretty[] = $p;
                    }
                }
                $suffix = ' | '.implode(' | ', $pretty);
            }
        }

        return 'Админ екран: '.$label.$suffix;
    }

    return $d;
}

    

    /**
     * Extract "what button/action was used" on BO POST requests (AdminController & Symfony BO),
     * so logs show meaningful context instead of just "Админ панел".
     *
     * Returns a changes array compatible with the existing UI renderer:
     * [ ['field'=>'Действие','old'=>'Админ панел','new'=>'submitAction=...'] ]
     */
    
/** Remove sensitive params (token, security_token, etc.) from a URI before storing in logs */
private function baalSanitizeUri($uri)
{
    $u = (string)$uri;
    if ($u === '') return '';
    $parts = @parse_url($u);
    if (!is_array($parts)) return $u;

    $path = isset($parts['path']) ? $parts['path'] : $u;
    $query = '';
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $q);
        foreach (['token','security_token','_token'] as $k) {
            if (isset($q[$k])) unset($q[$k]);
        }
        // keep only a few safe params that help understanding
        $safe = [];
        foreach (['id_product','productId','id_order','id_customer','id_category','id','submitAction','action','ajax'] as $k) {
            if (isset($q[$k])) $safe[$k] = $q[$k];
        }
        if ($safe) $query = http_build_query($safe);
    }
    return $query ? ($path.'?'.$query) : $path;
}

/** Translate common submit/action tokens to Bulgarian, to show "какво е направено". */
private function baalTranslateUiToken($key, $value)
{
    $k = (string)$key;
    $v = is_scalar($value) ? (string)$value : '1';

    if ($k === 'submitAction') {
        $vv = strtolower($v);
        if (in_array($vv, ['save','saveandstay','save-and-stay','save_and_stay','saveandstay'], true)) return 'Запазване';
        if (in_array($vv, ['delete'], true)) return 'Изтриване';
        if (in_array($vv, ['duplicate'], true)) return 'Дублиране';
        if (in_array($vv, ['export'], true)) return 'Експорт';
        if (strpos($vv, 'status') !== false) return 'Промяна на статус';
        return 'Действие: '.$v;
    }

    if ($k === 'action') {
        $vv = strtolower($v);
        if (in_array($vv, ['delete','del'], true)) return 'Изтриване';
        if (strpos($vv, 'bulk') === 0 && strpos($vv, 'delete') !== false) return 'Масово изтриване';
        if (in_array($vv, ['filter'], true)) return 'Филтриране';
        if (in_array($vv, ['export'], true)) return 'Експорт';
        return 'Действие: '.$v;
    }

    if (strpos($k, 'submit') === 0) {
        $kk = strtolower($k);
        if (strpos($kk, 'submitadd') === 0) return 'Създаване';
        if (strpos($kk, 'submitupdate') === 0) return 'Запазване';
        if (strpos($kk, 'submitdel') === 0) return 'Изтриване';
        if (strpos($kk, 'submitbulk') === 0 && strpos($kk, 'delete') !== false) return 'Масово изтриване';
        if (strpos($kk, 'submitfilter') === 0) return 'Филтриране';
        if (strpos($kk, 'resetfilter') === 0) return 'Изчистване на филтри';
        if (strpos($kk, 'submitexport') === 0) return 'Експорт';
        if (strpos($kk, 'status') !== false) return 'Промяна на статус';
        return 'Действие';
    }

    if (strpos($k, 'status') === 0) return 'Промяна на статус';

    return '';
}

/**
 * Detect a human-friendly UI action on BO requests.
 * Returns: ['label'=>..., 'raw'=>...]
 */
private function baalDetectAdminUiAction()
{
    $candidates = [];

    foreach (['submitAction','action'] as $k) {
        $v = Tools::getValue($k);
        if ($v !== false && $v !== null && $v !== '') {
            $label = $this->baalTranslateUiToken($k, $v);
            $candidates[] = ['label'=>$label, 'raw'=>$k.'='.$v];
        }
    }

    foreach ($_REQUEST as $k => $v) {
        if (strpos($k, 'submit') === 0 && $v !== null && $v !== '') {
            $label = $this->baalTranslateUiToken($k, $v);
            $candidates[] = ['label'=>$label ?: 'Действие', 'raw'=>$k.'='.(is_scalar($v)?(string)$v:'1')];
        }
        if (strpos($k, 'resetFilter') === 0 && $v !== null && $v !== '') {
            $candidates[] = ['label'=>'Изчистване на филтри', 'raw'=>$k.'=1'];
        }
    }

    foreach (['status','active'] as $k) {
        $v = Tools::getValue($k);
        if ($v !== false && $v !== null && $v !== '') {
            $candidates[] = ['label'=>'Промяна на статус', 'raw'=>$k.'='.$v];
        }
    }

    if (!$candidates) return null;

    foreach ($candidates as $c) {
        if (!empty($c['label']) && $c['label'] !== 'Действие') return $c;
    }
    return $candidates[0];
}

private function buildAdminUiActionChanges()
    {
        $ui = $this->baalDetectAdminUiAction();
        if (!$ui || empty($ui['label'])) return [];

        $raw = (!empty($ui['raw'])) ? (' ('.$ui['raw'].')') : '';
        return [[
            'field' => 'Действие',
            'old'   => 'Админ панел',
            'new'   => (string)$ui['label'].$raw,
        ]];
    }



    private function buildHumanSummary(array $row)
    {
        $type = isset($row['object_type']) ? (string)$row['object_type'] : '';
        $id   = isset($row['object_id']) ? (int)$row['object_id'] : 0;
        $changes = isset($row['changes']) && is_array($row['changes']) ? $row['changes'] : [];
        $details = isset($row['details']) ? (string)$row['details'] : '';

        // Status change summary
        foreach ($changes as $c) {
            if (isset($c['field']) && (string)$c['field'] === 'Статус') {
                $old = isset($c['old']) ? (string)$c['old'] : '';
                $new = isset($c['new']) ? (string)$c['new'] : '';
                if ($type === 'order' && $id > 0) {
                    return 'Поръчка #'.$id.': '.$old.' → '.$new;
                }
                return 'Статус: '.$old.' → '.$new;
            }
        }

        $count = count($changes);
        if ($count > 0) {
            if ($type !== '' && $id > 0) {
                return ucfirst($type).' #'.$id.': '.$count.' промени';
            }
            return $count.' промени';
        }

        // Admin navigation hints
        if (strpos($details, 'Админ екран:') === 0) {
            return trim(str_replace('Админ екран:', '', $details));
        }

        // System hints
        if (stripos($details, 'Модулът е инсталиран') !== false || stripos($details, 'INSTALL') !== false) {
            return 'Инсталация на модул';
        }

        return '';
    }

private function normalizeChangesArray($decoded)
    {
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $c) {
            if (!is_array($c)) {
                continue;
            }
            $field = '';
            if (isset($c['field'])) {
                $field = (string)$c['field'];
            } elseif (isset($c['name'])) {
                $field = (string)$c['name'];
            }

            $old = null;
            $new = null;

            if (array_key_exists('old', $c)) {
                $old = $c['old'];
            } elseif (array_key_exists('from', $c)) {
                $old = $c['from'];
            } elseif (array_key_exists('old_value', $c)) {
                $old = $c['old_value'];
            }

            if (array_key_exists('new', $c)) {
                $new = $c['new'];
            } elseif (array_key_exists('to', $c)) {
                $new = $c['to'];
            } elseif (array_key_exists('new_value', $c)) {
                $new = $c['new_value'];
            }

            // Keep "0" values, but replace null / empty string with a dash
            $oldStr = ($old === null) ? '—' : (string)$old;
            $newStr = ($new === null) ? '—' : (string)$new;

            if ($oldStr === '') $oldStr = '—';
            if ($newStr === '') $newStr = '—';

            $out[] = [
                'field' => ($field !== '' ? $field : 'Поле'),
                'old'   => $oldStr,
                'new'   => $newStr,
            ];
        }
        return $out;
    }


function fmtDt($dt)
    {
        // Desired BG format: dd-mm-YYYY HH:ii:ss
        if ($dt === null) return '—';
        $s = trim((string)$dt);
        if ($s === '' || $s === '0') return '—';
        // Handle MySQL zero dates
        if (strpos($s, '0000-00-00') === 0) return '—';
        $ts = strtotime($s);
        if (!$ts) return '—';
        return date('d-m-Y H:i:s', $ts);
    }

    private function resolveEmployee($employeeOverride = null)
    {
        $emp = null;
        if ($employeeOverride && is_object($employeeOverride) && isset($employeeOverride->id)) {
            $emp = $employeeOverride;
        } else {
            $emp = Context::getContext()->employee;
        }
        if (!$emp || !(int)$emp->id) return null;
        return $emp;
    }

    private function logAction($action, $objectType, $objectId = null, $details = null, $changesArray = null, $employeeOverride = null)
    {
        try {
            if (self::$baalLogging) return;
            if (!$this->baalIsEnabled()) return;

            $emp = $this->resolveEmployee($employeeOverride);
            if (!$emp) return;

            self::$baalLogging = true;

            // Grouping: Order UPDATE logs go under one parent row with dropdown
            $parentId = null;
            $now = date('Y-m-d H:i:s');

            if ($objectType === 'order' && $action === 'UPDATE' && (int)$objectId > 0) {
                $parentId = (int)Db::getInstance()->getValue(
                    'SELECT id_log FROM `'._DB_PREFIX_.'baal_logs` WHERE object_type="order" AND object_id='.(int)$objectId.' AND action="UPDATE" AND parent_id IS NULL ORDER BY id_log ASC LIMIT 1'
                );
                if ($parentId <= 0) {
                    $parentId = null; // this will be the parent
                }
            }

            $changesJson = null;
            if ($changesArray && is_array($changesArray) && count($changesArray) > 0) {
                $changesJson = json_encode($changesArray, JSON_UNESCAPED_UNICODE);
            }

            $controller = Tools::getValue('controller');
            if (!$controller) { $controller = Tools::getValue('_route'); }
            if (!$controller) {
                $ctx2 = Context::getContext();
                if ($ctx2 && isset($ctx2->controller) && is_object($ctx2->controller) && property_exists($ctx2->controller, 'controller_name')) {
                    $controller = (string)$ctx2->controller->controller_name;
                }
            }
            $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : null;
            // Never store raw admin tokens / security params from URLs in the database.
            $uri = isset($_SERVER['REQUEST_URI']) ? $this->baalSanitizeUri((string)$_SERVER['REQUEST_URI']) : null;

            // Make details more descriptive (append object title + key field diffs)
            if ($details) {
                $details = $this->enrichDetailsWithTitle($details, $objectType, $objectId);
                $sum = $this->buildChangesSummary($changesArray);
                if ($sum !== '') { $details .= ' | '.$sum; }
            }

            // Employee sessions: ensure there is an active session and attach it to each log row
            $this->ensureEmployeeSessionActive();
            $sid = 0;
            $ctxSess = Context::getContext();
            if ($ctxSess && isset($ctxSess->cookie) && isset($ctxSess->cookie->baal_session_id)) {
                $sid = (int)$ctxSess->cookie->baal_session_id;
            }
            // Optimization: throttle extremely frequent BO VIEW/POST logs (same employee+controller+action) within 10 seconds.
            // This keeps "абсолютно всяко действие" but removes accidental duplicates from redirects / double loads.
            if ($objectType === 'admin' && ($action === 'VIEW' || $action === 'POST')) {
                $sig = (int)$emp->id.'|'.(string)$action.'|'.(string)$controller;
                $nowTs = time();
                if (self::$baalLastAdminSig === $sig && (self::$baalLastAdminTs > 0) && ($nowTs - self::$baalLastAdminTs) < 2) {
                    // Same-request or near-same moment duplicate
                    self::$baalLogging = false;
                    return;
                }

                // Cross-request throttle: check last record in DB (fast because of indexes)
                $last = Db::getInstance()->getRow(
                    'SELECT id_log, created_at FROM `'._DB_PREFIX_.'baal_logs`'
                    .' WHERE employee_id='.(int)$emp->id
                    .' AND object_type="admin"'
                    .' AND action="'.pSQL($action).'"'
                    .' AND controller="'.pSQL($controller).'"'
                    .' ORDER BY id_log DESC LIMIT 1'
                );
                if ($last && !empty($last['created_at'])) {
                    $lastTs = strtotime($last['created_at']);
                    if ($lastTs && ($nowTs - $lastTs) < 10) {
                        self::$baalLastAdminSig = $sig;
                        self::$baalLastAdminTs = $nowTs;
                        self::$baalLogging = false;
                        return;
                    }
                }

                self::$baalLastAdminSig = $sig;
                self::$baalLastAdminTs = $nowTs;
            }

            $row = [
                'employee_id'   => (int)$emp->id,
                'session_id'    => ($sid > 0 ? (int)$sid : null),
                'employee'      => pSQL(trim($emp->firstname.' '.$emp->lastname)),
                'git_branch'    => pSQL((string)Configuration::get('BAAL_GIT_BRANCH')),
                'git_head'      => pSQL((string)Configuration::get('BAAL_GIT_HEAD')),
                'action'        => pSQL($action),
                'object_type'   => pSQL($objectType),
                'object_id'     => ($objectId !== null ? (int)$objectId : null),
                'parent_id'     => ($parentId ? (int)$parentId : null),
                'controller'    => ($controller ? pSQL($controller) : null),
                'http_method'   => ($method ? pSQL($method) : null),
                'request_uri'   => ($uri ? pSQL($uri, true) : null),
                'ip'            => pSQL(Tools::getRemoteAddr()),
                'details'       => ($details ? pSQL($details, true) : null),
                'changes_json'  => ($changesJson ? pSQL($changesJson, true) : null),
                'created_at'    => $now,
            ];

            // parent rows get group_last_at for ordering; children don't need it
            if (!$parentId) {
                $row['group_last_at'] = $now;
            } else {
                $row['group_last_at'] = null;
            }

            Db::getInstance()->insert('baal_logs', $row);
            $insertId = (int)Db::getInstance()->Insert_ID();

            // Touch session activity & increment action counter
            if ($sid > 0) {
                try {
                    Db::getInstance()->update('baal_employee_sessions', [
                        'last_activity' => pSQL($now),
                        'actions_count' => ['type' => 'sql', 'value' => 'actions_count + 1'],
                    ], 'id_session='.(int)$sid.' AND employee_id='.(int)$emp->id);
                } catch (Exception $tSess) {}
            }


            // If this is a child of a grouped order UPDATE, update parent group_last_at
            if ($parentId) {
                Db::getInstance()->update('baal_logs', ['group_last_at' => pSQL($now)], 'id_log='.(int)$parentId);
            }

            self::$baalLogging = false;
        } catch (Exception $e) {
            self::$baalLogging = false;
        } catch (Exception $t) {
            self::$baalLogging = false;
        }
    }

    private function getObjId($params)
    {
        try {
            if (isset($params['object']) && is_object($params['object']) && isset($params['object']->id)) {
                return (int)$params['object']->id;
            }
        } catch (Exception $e) {} catch (Exception $t) {}
        return null;
    }


    /* =====================
     * Helpers: human-readable object titles (for detailed history lines)
     * ===================== */
    private function getObjectTitle($objectType, $objectId)
    {
        try {
            $id = (int)$objectId;
            if ($id <= 0) return '';
            $lang = (int)Configuration::get('PS_LANG_DEFAULT');

            if ($objectType === 'product') {
                $p = new Product($id, false, $lang);
                if (Validate::isLoadedObject($p)) {
                    $name = $this->getLangValue($p->name, $lang);
                    return trim((string)$name);
                }
            } elseif ($objectType === 'category') {
                $c = new Category($id, $lang);
                if (Validate::isLoadedObject($c)) {
                    $name = $this->getLangValue($c->name, $lang);
                    return trim((string)$name);
                }
            } elseif ($objectType === 'customer') {
                $cu = new Customer($id);
                if (Validate::isLoadedObject($cu)) {
                    return trim($cu->firstname.' '.$cu->lastname);
                }
            } elseif ($objectType === 'order') {
                $o = new Order($id);
                if (Validate::isLoadedObject($o)) {
                    return trim((string)$o->reference);
                }
            } elseif ($objectType === 'address') {
                $a = new Address($id);
                if (Validate::isLoadedObject($a)) {
                    $parts = [];
                    if (!empty($a->firstname) || !empty($a->lastname)) $parts[] = trim($a->firstname.' '.$a->lastname);
                    if (!empty($a->address1)) $parts[] = trim($a->address1);
                    if (!empty($a->city)) $parts[] = trim($a->city);
                    return trim(implode(', ', $parts));
                }
            } elseif ($objectType === 'cms') {
                $cms = new CMS($id, $lang);
                if (Validate::isLoadedObject($cms)) {
                    return trim($this->getLangValue($cms->meta_title, $lang));
                }
            } elseif ($objectType === 'cms_category') {
                $cc = new CMSCategory($id, $lang);
                if (Validate::isLoadedObject($cc)) {
                    return trim($this->getLangValue($cc->name, $lang));
                }
            } elseif ($objectType === 'manufacturer') {
                $m = new Manufacturer($id, $lang);
                if (Validate::isLoadedObject($m)) {
                    return trim((string)$m->name);
                }
            } elseif ($objectType === 'supplier') {
                $s = new Supplier($id, $lang);
                if (Validate::isLoadedObject($s)) {
                    return trim((string)$s->name);
                }
            } elseif ($objectType === 'carrier') {
                $carr = new Carrier($id);
                if (Validate::isLoadedObject($carr)) {
                    return trim((string)$carr->name);
                }
            } elseif ($objectType === 'cart_rule') {
                $cr = new CartRule($id);
                if (Validate::isLoadedObject($cr)) {
                    return trim((string)$cr->name[(int)$lang] ?? '');
                }
            }
        } catch (Exception $e) {
        } catch (Exception $t) {
        }
        return '';
    }

    private function enrichDetailsWithTitle($details, $objectType, $objectId)
    {
        $details = (string)$details;
        $title = $this->getObjectTitle($objectType, $objectId);
        if ($title === '') return $details;
        if (strpos($details, ' - ') !== false) return $details;
        return $details.' - '.$title;
    }

    private function buildChangesSummary($changesArray)
    {
        if (!$changesArray || !is_array($changesArray)) return '';
        $summaryParts = [];
        foreach ($changesArray as $ch) {
            if (!is_array($ch)) continue;
            $field = isset($ch['field']) ? trim((string)$ch['field']) : '';
            $old = isset($ch['old']) ? (string)$ch['old'] : '';
            $new = isset($ch['new']) ? (string)$ch['new'] : '';
            if ($field === '' || $old === $new) continue;

            $important = ['name','meta_title','reference','ean13','price','active','visibility','id_category_default','firstname','lastname','email'];
            if (!in_array($field, $important, true)) continue;

            $map = [
                'name' => 'Заглавие',
                'meta_title' => 'Meta title',
                'reference' => 'Референция',
                'ean13' => 'EAN',
                'price' => 'Цена',
                'active' => 'Статус',
                'visibility' => 'Видимост',
                'id_category_default' => 'Основна категория',
                'firstname' => 'Име',
                'lastname' => 'Фамилия',
                'email' => 'Email',
            ];
            $label = isset($map[$field]) ? $map[$field] : $field;
            $summaryParts[] = $label.': '.$old.' -> '.$new;
        }
        if (!$summaryParts) return '';
        $summaryParts = array_slice($summaryParts, 0, 3);
        return implode(' | ', $summaryParts);
    }

    /* =====================
     * Hooks: CRUD
     * ===================== */

    public function hookActionObjectProductAddAfter($params)
    {
        $id = $this->getObjId($params);
        $this->logAction('ADD', 'product', $id, 'Създаден продукт #'.$id.$this->getObjectTitleSuffix('product', $id, $params));
    }

    public function hookActionObjectProductUpdateAfter($params)
    {
        $id = $this->getObjId($params);
        $changes = $this->captureProductChanges($id);
        // Only log when we have real, whitelisted diffs.
        if (!$changes || !is_array($changes) || count($changes) === 0) {
            return;
        }
        $this->logAction('UPDATE', 'product', $id, 'Редактиран продукт #'.$id.$this->getObjectTitleSuffix('product', $id, $params), $changes);
    }

    /** Capture old product values before update so we can build accurate diffs after update. */
    public function hookActionObjectProductUpdateBefore($params)
    {
        try {
            $id = $this->getObjId($params);
            if (!(int)$id) return;
            $lang = (int)Configuration::get('PS_LANG_DEFAULT');
            $p = new Product((int)$id, false, $lang);
            if (!Validate::isLoadedObject($p)) return;
            // Snapshot ONLY the fields we want to track as "real" changes.
            $qty = null;
            try {
                if (class_exists('StockAvailable')) {
                    $qty = (int)StockAvailable::getQuantityAvailableByProduct((int)$id);
                }
            } catch (Exception $t) {
                $qty = null;
            }
            self::$baalSnapshots['product'][(int)$id] = [
                'price' => (float)$p->price,
                'reference' => (string)$p->reference,
                'name' => (string)$this->getLangValue($p->name, $lang),
                'description_short' => (string)$this->getLangValue($p->description_short, $lang),
                'description' => (string)$this->getLangValue($p->description, $lang),
                'meta_description' => (string)$this->getLangValue($p->meta_description, $lang),
                'quantity' => $qty,
                'lang' => $lang,
            ];
        } catch (Exception $t) {}
    }

    public function hookActionObjectProductDeleteAfter($params)
    {
        $id = $this->getObjId($params);
        $this->logAction('DELETE', 'product', $id, 'Изтрит продукт #'.$id.$this->getObjectTitleSuffix('product', $id, $params));
    }

    public function hookActionObjectCategoryAddAfter($params)
    {
        $id = $this->getObjId($params);
        $this->logAction('ADD', 'category', $id, 'Създадена категория #'.$id.$this->getObjectTitleSuffix('category', $id, $params));
    }

    public function hookActionObjectCategoryUpdateAfter($params)
    {
        $id = $this->getObjId($params);
        $changes = null;
        try {
            $old = null;
            if (isset(self::$baalSnapshots['obj']['Category'][(int)$id])) {
                $old = self::$baalSnapshots['obj']['Category'][(int)$id];
                unset(self::$baalSnapshots['obj']['Category'][(int)$id]);
            }
            $new = $this->baalSnapshotFromDb('Category', (int)$id);
            if ($old && $new) {
                $changes = $this->baalBuildChangesFromSnapshots('category', $old, $new);
            }
        } catch (Exception $e) {} catch (Exception $t) {}

        $this->logAction('UPDATE', 'category', $id, 'Редактирана категория #'.$id.$this->getObjectTitleSuffix('category', $id, $params), $changes);
    }

    /** Capture old category values before update so we can build accurate diffs after update. */
    public function hookActionObjectCategoryUpdateBefore($params)
    {
        try {
            $id = $this->getObjId($params);
            if (!(int)$id) return;
            self::$baalSnapshots['obj']['Category'][(int)$id] = $this->baalSnapshotFromDb('Category', (int)$id);
        } catch (Exception $e) {} catch (Exception $t) {}
    }

    public function hookActionObjectCategoryDeleteAfter($params)
    {
        $id = $this->getObjId($params);
        $this->logAction('DELETE', 'category', $id, 'Изтрита категория #'.$id.$this->getObjectTitleSuffix('category', $id, $params));
    }

    /* =====================
     * CMS pages / CMS categories
     * ===================== */

    public function hookActionObjectCmsAddAfter($params)
    {
        $id = $this->getObjId($params);
        $this->logAction('ADD', 'cms', $id, 'Създадена CMS страница #'.$id.$this->getObjectTitleSuffix('cms', $id, $params));
    }

    public function hookActionObjectCmsUpdateBefore($params)
    {
        try {
            $id = $this->getObjId($params);
            if (!(int)$id) return;
            self::$baalSnapshots['obj']['CMS'][(int)$id] = $this->baalSnapshotFromDb('CMS', (int)$id);
        } catch (Exception $e) {} catch (Exception $t) {}
    }

    public function hookActionObjectCmsUpdateAfter($params)
    {
        $id = $this->getObjId($params);
        $changes = null;
        try {
            $old = null;
            if (isset(self::$baalSnapshots['obj']['CMS'][(int)$id])) {
                $old = self::$baalSnapshots['obj']['CMS'][(int)$id];
                unset(self::$baalSnapshots['obj']['CMS'][(int)$id]);
            }
            $new = $this->baalSnapshotFromDb('CMS', (int)$id);
            if ($old && $new) {
                $changes = $this->baalBuildChangesFromSnapshots('cms', $old, $new);
            }
        } catch (Exception $e) {} catch (Exception $t) {}
        $this->logAction('UPDATE', 'cms', $id, 'Редактирана CMS страница #'.$id.$this->getObjectTitleSuffix('cms', $id, $params), $changes);
    }

    public function hookActionObjectCmsDeleteAfter($params)
    {
        $id = $this->getObjId($params);
        $this->logAction('DELETE', 'cms', $id, 'Изтрита CMS страница #'.$id.$this->getObjectTitleSuffix('cms', $id, $params));
    }

    public function hookActionObjectCmsCategoryAddAfter($params)
    {
        $id = $this->getObjId($params);
        $this->logAction('ADD', 'cms_category', $id, 'Създадена CMS категория #'.$id.$this->getObjectTitleSuffix('cms_category', $id, $params));
    }

    public function hookActionObjectCmsCategoryUpdateBefore($params)
    {
        try {
            $id = $this->getObjId($params);
            if (!(int)$id) return;
            self::$baalSnapshots['obj']['CMSCategory'][(int)$id] = $this->baalSnapshotFromDb('CMSCategory', (int)$id);
        } catch (Exception $e) {} catch (Exception $t) {}
    }

    public function hookActionObjectCmsCategoryUpdateAfter($params)
    {
        $id = $this->getObjId($params);
        $changes = null;
        try {
            $old = null;
            if (isset(self::$baalSnapshots['obj']['CMSCategory'][(int)$id])) {
                $old = self::$baalSnapshots['obj']['CMSCategory'][(int)$id];
                unset(self::$baalSnapshots['obj']['CMSCategory'][(int)$id]);
            }
            $new = $this->baalSnapshotFromDb('CMSCategory', (int)$id);
            if ($old && $new) {
                $changes = $this->baalBuildChangesFromSnapshots('cms_category', $old, $new);
            }
        } catch (Exception $e) {} catch (Exception $t) {}
        $this->logAction('UPDATE', 'cms_category', $id, 'Редактирана CMS категория #'.$id.$this->getObjectTitleSuffix('cms_category', $id, $params), $changes);
    }

    public function hookActionObjectCmsCategoryDeleteAfter($params)
    {
        $id = $this->getObjId($params);
        $this->logAction('DELETE', 'cms_category', $id, 'Изтрита CMS категория #'.$id.$this->getObjectTitleSuffix('cms_category', $id, $params));
    }

    /* =====================
     * Manufacturers / Suppliers
     * ===================== */

    public function hookActionObjectManufacturerAddAfter($params)
    {
        $id = $this->getObjId($params);
        $this->logAction('ADD', 'manufacturer', $id, 'Създаден производител #'.$id.$this->getObjectTitleSuffix('manufacturer', $id, $params));
    }

    public function hookActionObjectManufacturerUpdateBefore($params)
    {
        try {
            $id = $this->getObjId($params);
            if (!(int)$id) return;
            self::$baalSnapshots['obj']['Manufacturer'][(int)$id] = $this->baalSnapshotFromDb('Manufacturer', (int)$id);
        } catch (Exception $e) {} catch (Exception $t) {}
    }

    public function hookActionObjectManufacturerUpdateAfter($params)
    {
        $id = $this->getObjId($params);
        $changes = null;
        try {
            $old = null;
            if (isset(self::$baalSnapshots['obj']['Manufacturer'][(int)$id])) {
                $old = self::$baalSnapshots['obj']['Manufacturer'][(int)$id];
                unset(self::$baalSnapshots['obj']['Manufacturer'][(int)$id]);
            }
            $new = $this->baalSnapshotFromDb('Manufacturer', (int)$id);
            if ($old && $new) {
                $changes = $this->baalBuildChangesFromSnapshots('manufacturer', $old, $new);
            }
        } catch (Exception $e) {} catch (Exception $t) {}
        $this->logAction('UPDATE', 'manufacturer', $id, 'Редактиран производител #'.$id.$this->getObjectTitleSuffix('manufacturer', $id, $params), $changes);
    }

    public function hookActionObjectManufacturerDeleteAfter($params)
    {
        $id = $this->getObjId($params);
        $this->logAction('DELETE', 'manufacturer', $id, 'Изтрит производител #'.$id.$this->getObjectTitleSuffix('manufacturer', $id, $params));
    }

    public function hookActionObjectSupplierAddAfter($params)
    {
        $id = $this->getObjId($params);
        $this->logAction('ADD', 'supplier', $id, 'Създаден доставчик #'.$id.$this->getObjectTitleSuffix('supplier', $id, $params));
    }

    public function hookActionObjectSupplierUpdateBefore($params)
    {
        try {
            $id = $this->getObjId($params);
            if (!(int)$id) return;
            self::$baalSnapshots['obj']['Supplier'][(int)$id] = $this->baalSnapshotFromDb('Supplier', (int)$id);
        } catch (Exception $e) {} catch (Exception $t) {}
    }

    public function hookActionObjectSupplierUpdateAfter($params)
    {
        $id = $this->getObjId($params);
        $changes = null;
        try {
            $old = null;
            if (isset(self::$baalSnapshots['obj']['Supplier'][(int)$id])) {
                $old = self::$baalSnapshots['obj']['Supplier'][(int)$id];
                unset(self::$baalSnapshots['obj']['Supplier'][(int)$id]);
            }
            $new = $this->baalSnapshotFromDb('Supplier', (int)$id);
            if ($old && $new) {
                $changes = $this->baalBuildChangesFromSnapshots('supplier', $old, $new);
            }
        } catch (Exception $e) {} catch (Exception $t) {}
        $this->logAction('UPDATE', 'supplier', $id, 'Редактиран доставчик #'.$id.$this->getObjectTitleSuffix('supplier', $id, $params), $changes);
    }

    public function hookActionObjectSupplierDeleteAfter($params)
    {
        $id = $this->getObjId($params);
        $this->logAction('DELETE', 'supplier', $id, 'Изтрит доставчик #'.$id.$this->getObjectTitleSuffix('supplier', $id, $params));
    }

    /* =====================
     * Carriers / Cart rules
     * ===================== */

    public function hookActionObjectCarrierAddAfter($params)
    {
        $id = $this->getObjId($params);
        $this->logAction('ADD', 'carrier', $id, 'Създаден превозвач #'.$id.$this->getObjectTitleSuffix('carrier', $id, $params));
    }

    public function hookActionObjectCarrierUpdateBefore($params)
    {
        try {
            $id = $this->getObjId($params);
            if (!(int)$id) return;
            self::$baalSnapshots['obj']['Carrier'][(int)$id] = $this->baalSnapshotFromDb('Carrier', (int)$id);
        } catch (Exception $e) {} catch (Exception $t) {}
    }

    public function hookActionObjectCarrierUpdateAfter($params)
    {
        $id = $this->getObjId($params);
        $changes = null;
        try {
            $old = null;
            if (isset(self::$baalSnapshots['obj']['Carrier'][(int)$id])) {
                $old = self::$baalSnapshots['obj']['Carrier'][(int)$id];
                unset(self::$baalSnapshots['obj']['Carrier'][(int)$id]);
            }
            $new = $this->baalSnapshotFromDb('Carrier', (int)$id);
            if ($old && $new) {
                $changes = $this->baalBuildChangesFromSnapshots('carrier', $old, $new);
            }
        } catch (Exception $e) {} catch (Exception $t) {}
        $this->logAction('UPDATE', 'carrier', $id, 'Редактиран превозвач #'.$id.$this->getObjectTitleSuffix('carrier', $id, $params), $changes);
    }

    public function hookActionObjectCarrierDeleteAfter($params)
    {
        $id = $this->getObjId($params);
        $this->logAction('DELETE', 'carrier', $id, 'Изтрит превозвач #'.$id.$this->getObjectTitleSuffix('carrier', $id, $params));
    }

    public function hookActionObjectCartRuleAddAfter($params)
    {
        $id = $this->getObjId($params);
        $this->logAction('ADD', 'cart_rule', $id, 'Създаден ваучер #'.$id.$this->getObjectTitleSuffix('cart_rule', $id, $params));
    }

    public function hookActionObjectCartRuleUpdateBefore($params)
    {
        try {
            $id = $this->getObjId($params);
            if (!(int)$id) return;
            self::$baalSnapshots['obj']['CartRule'][(int)$id] = $this->baalSnapshotFromDb('CartRule', (int)$id);
        } catch (Exception $e) {} catch (Exception $t) {}
    }

    public function hookActionObjectCartRuleUpdateAfter($params)
    {
        $id = $this->getObjId($params);
        $changes = null;
        try {
            $old = null;
            if (isset(self::$baalSnapshots['obj']['CartRule'][(int)$id])) {
                $old = self::$baalSnapshots['obj']['CartRule'][(int)$id];
                unset(self::$baalSnapshots['obj']['CartRule'][(int)$id]);
            }
            $new = $this->baalSnapshotFromDb('CartRule', (int)$id);
            if ($old && $new) {
                $changes = $this->baalBuildChangesFromSnapshots('cart_rule', $old, $new);
            }
        } catch (Exception $e) {} catch (Exception $t) {}
        $this->logAction('UPDATE', 'cart_rule', $id, 'Редактиран ваучер #'.$id.$this->getObjectTitleSuffix('cart_rule', $id, $params), $changes);
    }

    public function hookActionObjectCartRuleDeleteAfter($params)
    {
        $id = $this->getObjId($params);
        $this->logAction('DELETE', 'cart_rule', $id, 'Изтрит ваучер #'.$id.$this->getObjectTitleSuffix('cart_rule', $id, $params));
    }

    /* =====================
     * Specific prices / discount rules
     * ===================== */

    public function hookActionObjectSpecificPriceAddAfter($params)
    {
        $id = $this->getObjId($params);
        $this->logAction('ADD', 'specific_price', $id, 'Създадена отстъпка (specific price) #'.$id.$this->getObjectTitleSuffix('specific_price', $id, $params));
    }

    public function hookActionObjectSpecificPriceUpdateBefore($params)
    {
        try {
            $id = $this->getObjId($params);
            if (!(int)$id) return;
            self::$baalSnapshots['obj']['SpecificPrice'][(int)$id] = $this->baalSnapshotFromDb('SpecificPrice', (int)$id);
        } catch (Exception $e) {} catch (Exception $t) {}
    }

    public function hookActionObjectSpecificPriceUpdateAfter($params)
    {
        $id = $this->getObjId($params);
        $changes = null;
        try {
            $old = null;
            if (isset(self::$baalSnapshots['obj']['SpecificPrice'][(int)$id])) {
                $old = self::$baalSnapshots['obj']['SpecificPrice'][(int)$id];
                unset(self::$baalSnapshots['obj']['SpecificPrice'][(int)$id]);
            }
            $new = $this->baalSnapshotFromDb('SpecificPrice', (int)$id);
            if ($old && $new) {
                $changes = $this->baalBuildChangesFromSnapshots('specific_price', $old, $new);
            }
        } catch (Exception $e) {} catch (Exception $t) {}
        $this->logAction('UPDATE', 'specific_price', $id, 'Редактирана отстъпка (specific price) #'.$id.$this->getObjectTitleSuffix('specific_price', $id, $params), $changes);
    }

    public function hookActionObjectSpecificPriceDeleteAfter($params)
    {
        $id = $this->getObjId($params);
        $this->logAction('DELETE', 'specific_price', $id, 'Изтрита отстъпка (specific price) #'.$id.$this->getObjectTitleSuffix('specific_price', $id, $params));
    }

    public function hookActionObjectSpecificPriceRuleAddAfter($params)
    {
        $id = $this->getObjId($params);
        $this->logAction('ADD', 'specific_price_rule', $id, 'Създадено правило за отстъпка #'.$id.$this->getObjectTitleSuffix('specific_price_rule', $id, $params));
    }

    public function hookActionObjectSpecificPriceRuleUpdateBefore($params)
    {
        try {
            $id = $this->getObjId($params);
            if (!(int)$id) return;
            self::$baalSnapshots['obj']['SpecificPriceRule'][(int)$id] = $this->baalSnapshotFromDb('SpecificPriceRule', (int)$id);
        } catch (Exception $e) {} catch (Exception $t) {}
    }

    public function hookActionObjectSpecificPriceRuleUpdateAfter($params)
    {
        $id = $this->getObjId($params);
        $changes = null;
        try {
            $old = null;
            if (isset(self::$baalSnapshots['obj']['SpecificPriceRule'][(int)$id])) {
                $old = self::$baalSnapshots['obj']['SpecificPriceRule'][(int)$id];
                unset(self::$baalSnapshots['obj']['SpecificPriceRule'][(int)$id]);
            }
            $new = $this->baalSnapshotFromDb('SpecificPriceRule', (int)$id);
            if ($old && $new) {
                $changes = $this->baalBuildChangesFromSnapshots('specific_price_rule', $old, $new);
            }
        } catch (Exception $e) {} catch (Exception $t) {}
        $this->logAction('UPDATE', 'specific_price_rule', $id, 'Редактирано правило за отстъпка #'.$id.$this->getObjectTitleSuffix('specific_price_rule', $id, $params), $changes);
    }

    public function hookActionObjectSpecificPriceRuleDeleteAfter($params)
    {
        $id = $this->getObjId($params);
        $this->logAction('DELETE', 'specific_price_rule', $id, 'Изтрито правило за отстъпка #'.$id.$this->getObjectTitleSuffix('specific_price_rule', $id, $params));
    }

    public function hookActionObjectCustomerAddAfter($params)
    {
        $id = $this->getObjId($params);
        $this->logAction('ADD', 'customer', $id, 'Създаден клиент #'.$id.$this->getObjectTitleSuffix('customer', $id, $params));
    }

    public function hookActionObjectCustomerUpdateBefore($params)
    {
        try {
            $id = $this->getObjId($params);
            if (!$id) return;
            $c = new Customer($id);
            if (!Validate::isLoadedObject($c)) return;
            self::$baalSnapshots['customer'][$id] = [
                'firstname' => (string)$c->firstname,
                'lastname' => (string)$c->lastname,
                'email' => (string)$c->email,
                'active' => (int)$c->active,
                'newsletter' => (int)$c->newsletter,
                'optin' => (int)$c->optin,
            ];
        } catch (Exception $t) {}
    }

    public function hookActionObjectCustomerUpdateAfter($params)
    {
        $id = $this->getObjId($params);
        $changes = $this->captureCustomerChanges($id);
        $this->logAction('UPDATE', 'customer', $id, 'Редактиран клиент #'.$id.$this->getObjectTitleSuffix('customer', $id, $params), $changes);
        if ($id) unset(self::$baalSnapshots['customer'][$id]);
    }



    /* =====================
     * Hooks: Customer Addresses
     * ===================== */

    public function hookActionObjectAddressUpdateBefore($params)
    {
        try {
            $id = $this->getObjId($params);
            if (!$id) return;
            $a = new Address($id);
            if (!Validate::isLoadedObject($a)) return;

            self::$baalSnapshots['address'][(int)$id] = [
                'address1' => (string)$a->address1,
                'address2' => (string)$a->address2,
                'postcode' => (string)$a->postcode,
                'city' => (string)$a->city,
                'phone' => (string)$a->phone,
                'phone_mobile' => (string)$a->phone_mobile,
                'company' => (string)$a->company,
                'vat_number' => (string)$a->vat_number,
                'dni' => (string)$a->dni,
                'id_country' => (int)$a->id_country,
            ];
        } catch (Exception $t) {}
    }

    public function hookActionObjectAddressAddAfter($params)
    {
        try {
            $id = $this->getObjId($params);
            if (!$id) return;
            $a = new Address($id);
            if (!Validate::isLoadedObject($a)) return;
            $custId = (int)$a->id_customer;

            $this->logAction('ADD', 'customer', $custId, 'Добавен адрес към клиент #'.$custId, [
                ['field' => 'Адрес', 'old' => '', 'new' => trim($a->address1.' '.$a->address2)],
                ['field' => 'Град', 'old' => '', 'new' => (string)$a->city],
                ['field' => 'Пощенски код', 'old' => '', 'new' => (string)$a->postcode],
            ]);
        } catch (Exception $t) {}
    }

    public function hookActionObjectAddressUpdateAfter($params)
    {
        try {
            $id = $this->getObjId($params);
            if (!$id) return;
            $a = new Address($id);
            if (!Validate::isLoadedObject($a)) return;

            $custId = (int)$a->id_customer;
            $changes = $this->captureAddressChanges($id);
            if ($changes && count($changes)) {
                $this->logAction('UPDATE', 'customer', $custId, 'Редактиран адрес/данни за фактура на клиент #'.$custId, $changes);
            } else {
                $this->logAction('UPDATE', 'customer', $custId, 'Редактиран адрес/данни за фактура на клиент #'.$custId);
            }
        } catch (Exception $t) {}
    }

    public function hookActionObjectAddressDeleteAfter($params)
    {
        try {
            $id = $this->getObjId($params);
            // After delete, object may not be loadable; best-effort read id_customer from params
            $custId = null;
            if (isset($params['object']) && is_object($params['object']) && isset($params['object']->id_customer)) {
                $custId = (int)$params['object']->id_customer;
            }
            $this->logAction('DELETE', 'customer', (int)$custId, 'Изтрит адрес на клиент #'.(int)$custId);
        } catch (Exception $t) {}
    }

    private function captureAddressChanges($addressId)
    {
        try {
            $changes = [];
            $a = new Address($addressId);
            if (!Validate::isLoadedObject($a)) return null;

            $snap = null;
            if (isset(self::$baalSnapshots['address'][(int)$addressId])) {
                $snap = self::$baalSnapshots['address'][(int)$addressId];
                unset(self::$baalSnapshots['address'][(int)$addressId]);
            }
            if (!is_array($snap)) return null;

            $map = [
                'address1' => 'Адрес',
                'address2' => 'Доп. адрес',
                'postcode' => 'Пощенски код',
                'city' => 'Град',
                'phone' => 'Телефон',
                'phone_mobile' => 'Мобилен телефон',
                'company' => 'Фирма',
                'vat_number' => 'ДДС номер',
                'dni' => 'ЕГН/ЛНЧ',
                'id_country' => 'Държава (ID)',
            ];

            foreach ($map as $field => $label) {
                $old = isset($snap[$field]) ? $snap[$field] : null;
                $new = isset($a->$field) ? $a->$field : null;
                if ((string)$old !== (string)$new) {
                    $changes[] = ['field' => $label, 'old' => (string)$old, 'new' => (string)$new];
                }
            }

            return $changes;
        } catch (Exception $e) {
            return null;
        } catch (Exception $t) {
            return null;
        }
    }

    public function hookActionObjectOrderAddAfter($params)
    {
        $id = $this->getObjId($params);
        $this->logAction('ADD', 'order', $id, 'Създадена поръчка #'.$id);
    }

    public function hookActionObjectOrderUpdateAfter($params)
    {
        $id = $this->getObjId($params);
        $changes = $this->captureOrderChanges($id);
        // Grouped automatically under one row for this order (parent/children)
        $this->logAction('UPDATE', 'order', $id, 'Редактирана поръчка #'.$id, $changes);
    }

    public function hookActionObjectOrderHistoryAddAfter($params)
    {
        try {
            // $params['object'] is OrderHistory
            $oh = isset($params['object']) ? $params['object'] : null;
            if (!is_object($oh) || !isset($oh->id_order) || !isset($oh->id_order_state)) {
                $id = $this->getObjId($params);
                $this->logAction('UPDATE', 'order', $id, 'Променен статус на поръчка #'.$id);
                return;
            }

            $orderId = (int)$oh->id_order;
            $newStateId = (int)$oh->id_order_state;
            $lang = (int)Configuration::get('PS_LANG_DEFAULT');

            // Find previous state from history (second latest)
            $prevStateId = (int)Db::getInstance()->getValue(
                'SELECT id_order_state FROM `'._DB_PREFIX_.'order_history` WHERE id_order='.(int)$orderId.' ORDER BY date_add DESC LIMIT 1,1'
            );

            $changes = [];
            if ($newStateId > 0) {
                $newState = new OrderState($newStateId, $lang);
                $newName = is_array($newState->name) ? (isset($newState->name[$lang]) ? $newState->name[$lang] : reset($newState->name)) : $newState->name;
                $oldName = '';
                if ($prevStateId > 0) {
                    $oldState = new OrderState($prevStateId, $lang);
                    $oldName = is_array($oldState->name) ? (isset($oldState->name[$lang]) ? $oldState->name[$lang] : reset($oldState->name)) : $oldState->name;
                }
                $changes[] = ['field' => 'Статус', 'old' => (string)$oldName, 'new' => (string)$newName];
            }

            $this->logAction('UPDATE', 'order', $orderId, 'Променен статус на поръчка #'.$orderId, $changes);
        } catch (Exception $t) {
            $id = $this->getObjId($params);
            $this->logAction('UPDATE', 'order', $id, 'Променен статус на поръчка #'.$id);
        }
    }

    public function hookActionObjectOrderSlipAddAfter($params)
    {
        $id = $this->getObjId($params);
        $this->logAction('ADD', 'order_slip', $id, 'Създаден кредитен фиш');
    }

    public function hookActionObjectOrderReturnAddAfter($params)
    {
        $id = $this->getObjId($params);
        $this->logAction('ADD', 'order_return', $id, 'Създадено връщане');
    }

    public function hookActionObjectEmployeeAddAfter($params)
    {
        try {
            $e = $params['object'];
            $this->logAction('ADD', 'system', (int)$e->id, 'Добавен служител: '.$e->firstname.' '.$e->lastname);
        } catch (Exception $t) {}
    }

    public function hookActionObjectEmployeeUpdateAfter($params)
    {
        try {
            $e = $params['object'];
            $this->logAction('UPDATE', 'system', (int)$e->id, 'Редактиран служител: '.$e->firstname.' '.$e->lastname);
        } catch (Exception $t) {}
    }

    public function hookActionObjectEmployeeDeleteAfter($params)
    {
        try {
            $e = $params['object'];
            $this->logAction('DELETE', 'system', (int)$e->id, 'Изтрит служител ID '.(int)$e->id);
        } catch (Exception $t) {}
    }

    /* =====================
     * Hooks: Login / Logout
     * ===================== */


    private function startEmployeeSession($emp)
    {
        try {
            if (!$emp || !(int)$emp->id) return;
            $now = date('Y-m-d H:i:s');
            $ctx = Context::getContext();

            // If there is already an open session for this employee, reuse it
            try {
                $open = (int)Db::getInstance()->getValue(
                    'SELECT id_session FROM `'._DB_PREFIX_.'baal_employee_sessions`'
                    .' WHERE employee_id='.(int)$emp->id.' AND (logout_at IS NULL OR logout_at = "0000-00-00 00:00:00")'
                    .' ORDER BY id_session DESC LIMIT 1'
                );
                if ($open > 0) {
                    if ($ctx && isset($ctx->cookie)) {
                        $ctx->cookie->baal_session_id = $open;
                        if (method_exists($ctx->cookie, 'write')) { $ctx->cookie->write(); }
                    }
                    Db::getInstance()->update('baal_employee_sessions', ['last_activity' => pSQL($now)], 'id_session='.(int)$open);
                    return;
                }
            } catch (Exception $t2) {}
            $ip = Tools::getRemoteAddr();
            $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 255) : null;

            Db::getInstance()->insert('baal_employee_sessions', [
                'employee_id' => (int)$emp->id,
                'employee' => pSQL(trim($emp->firstname.' '.$emp->lastname)),
                'login_at' => pSQL($now),
                'last_activity' => pSQL($now),
                'logout_at' => null,
                'duration_sec' => null,
                'ip' => pSQL($ip),
                'user_agent' => ($ua ? pSQL($ua, true) : null),
                'actions_count' => 0,
            ]);
            $sid = (int)Db::getInstance()->Insert_ID();
            if ($ctx && isset($ctx->cookie)) {
                $ctx->cookie->baal_session_id = $sid;
                if (method_exists($ctx->cookie, 'write')) { $ctx->cookie->write(); }
            }
        } catch (Exception $t) {}
    }

    /**
     * Ensure there is an active employee session for the current BO employee.
     * This does NOT rely on login/logout hooks (which are unreliable in PS 1.7).
     */
    private function ensureEmployeeSessionActive()
    {
        try {
            $ctx = Context::getContext();
            if (!$ctx || !isset($ctx->employee) || !is_object($ctx->employee) || !(int)$ctx->employee->id || !$ctx->employee->isLoggedBack()) {
                // Employee not logged in BO: close any open session referenced by cookie
                if ($ctx && isset($ctx->cookie) && isset($ctx->cookie->baal_session_id)) {
                    $sid = (int)$ctx->cookie->baal_session_id;
                    if ($sid > 0) {
                        try {
                            Db::getInstance()->update('baal_employee_sessions', ['logout_at' => pSQL(date('Y-m-d H:i:s'))], 'id_session='.(int)$sid.' AND (logout_at IS NULL OR logout_at = "0000-00-00 00:00:00")');
                        } catch (Exception $t2) {}
                    }
                    $ctx->cookie->baal_session_id = null;
                }
                return;
            }

            $emp = $ctx->employee;

            // Close stale sessions (per employee) before opening/touching
            $this->closeStaleEmployeeSessions((int)$emp->id);

            $sid = 0;
            if (isset($ctx->cookie) && isset($ctx->cookie->baal_session_id)) {
                $sid = (int)$ctx->cookie->baal_session_id;
            }

            // Validate cookie session
            if ($sid > 0) {
                $ok = (int)Db::getInstance()->getValue(
                    'SELECT COUNT(*) FROM `'._DB_PREFIX_.'baal_employee_sessions`'
                    .' WHERE id_session='.(int)$sid.' AND employee_id='.(int)$emp->id.' AND (logout_at IS NULL OR logout_at = "0000-00-00 00:00:00")'
                );
                if ($ok > 0) {
                    // Touch activity
                    Db::getInstance()->update('baal_employee_sessions', ['last_activity' => pSQL(date('Y-m-d H:i:s'))], 'id_session='.(int)$sid);
                    return;
                }
            }

            // Find latest open session for employee
            $sid = (int)Db::getInstance()->getValue(
                'SELECT id_session FROM `'._DB_PREFIX_.'baal_employee_sessions`'
                .' WHERE employee_id='.(int)$emp->id.' AND (logout_at IS NULL OR logout_at = "0000-00-00 00:00:00")'
                .' ORDER BY id_session DESC LIMIT 1'
            );

            if ($sid > 0) {
                Db::getInstance()->update('baal_employee_sessions', ['last_activity' => pSQL(date('Y-m-d H:i:s'))], 'id_session='.(int)$sid);
                if (isset($ctx->cookie)) { $ctx->cookie->baal_session_id = $sid; if (method_exists($ctx->cookie, 'write')) { $ctx->cookie->write(); } }
                return;
            }

            // Start a new session
            $this->startEmployeeSession($emp);
        } catch (Exception $t) {}
    }

    /** Auto-close sessions with no activity for BAAL_SESSION_TIMEOUT_MIN minutes */
    private function closeStaleEmployeeSessions($employeeId = 0)
    {
        try {
            $timeoutMin = (int)self::BAAL_SESSION_TIMEOUT_MIN;
            // Disabled: rely on explicit login/logout
            if ($timeoutMin <= 0) return;
            $cutoff = date('Y-m-d H:i:s', time() - ($timeoutMin * 60));
            $where = '(logout_at IS NULL OR logout_at = "0000-00-00 00:00:00") AND last_activity IS NOT NULL AND last_activity < "'.pSQL($cutoff).'"';
            if ((int)$employeeId > 0) {
                $where .= ' AND employee_id='.(int)$employeeId;
            }
            $rows = Db::getInstance()->executeS('SELECT id_session, login_at, last_activity FROM `'._DB_PREFIX_.'baal_employee_sessions` WHERE '.$where.' LIMIT 200');
            if (!$rows) return;
            foreach ($rows as $r) {
                $sid = (int)$r['id_session'];
                $end = $r['last_activity'] ? $r['last_activity'] : date('Y-m-d H:i:s');
                $dur = null;
                if (!empty($r['login_at']) && !empty($end)) {
                    $dur = max(0, strtotime($end) - strtotime($r['login_at']));
                }
                Db::getInstance()->update('baal_employee_sessions', [
                    'logout_at' => pSQL($end),
                    'duration_sec' => ($dur !== null ? (int)$dur : null),
                ], 'id_session='.(int)$sid);
            }
        } catch (Exception $t) {}
    }

    private function endEmployeeSession($emp)
    {
        try {
            if (!$emp || !(int)$emp->id) return;
            $now = date('Y-m-d H:i:s');
            $ctx = Context::getContext();
            $sid = 0;
            if ($ctx && isset($ctx->cookie) && isset($ctx->cookie->baal_session_id)) {
                $sid = (int)$ctx->cookie->baal_session_id;
            }

            if ($sid <= 0) {
                $sid = (int)Db::getInstance()->getValue(
                    'SELECT id_session FROM `'._DB_PREFIX_.'baal_employee_sessions` WHERE employee_id='.(int)$emp->id.' AND (logout_at IS NULL OR logout_at = "0000-00-00 00:00:00") ORDER BY id_session DESC LIMIT 1'
                );
            }
            if ($sid <= 0) return;

            $loginAt = Db::getInstance()->getValue('SELECT login_at FROM `'._DB_PREFIX_.'baal_employee_sessions` WHERE id_session='.(int)$sid);
            $dur = null;
            if ($loginAt) {
                $dur = max(0, strtotime($now) - strtotime($loginAt));
            }

            Db::getInstance()->update('baal_employee_sessions', [
                'logout_at' => pSQL($now),
                'duration_sec' => ($dur !== null ? (int)$dur : null),
            ], 'id_session='.(int)$sid);

            if ($ctx && isset($ctx->cookie)) {
                $ctx->cookie->baal_session_id = null;
                if (method_exists($ctx->cookie, 'write')) { $ctx->cookie->write(); }
            }
        } catch (Exception $t) {}
    }

    public function hookActionEmployeeLoginAfter($params)
    {
        try {
            $emp = isset($params['employee']) ? $params['employee'] : null;
            $this->logAction('LOGIN', 'login', null, 'Служителят влезе в профила си', null, $emp);
        } catch (Exception $t) {}
    }

    public function hookActionEmployeeLogoutAfter($params)
    {
        try {
            $emp = isset($params['employee']) ? $params['employee'] : Context::getContext()->employee;
            $this->logAction('LOGOUT', 'logout', null, 'Служителят излезе от профила си', null, $emp);
        } catch (Exception $t) {}
    }

    /* =====================
     * Hooks: Stock
     * ===================== */

    public function hookActionUpdateQuantity($params)
    {
        try {
            // Common params: id_product, id_product_attribute, quantity, delta_quantity
            $idProduct = isset($params['id_product']) ? (int)$params['id_product'] : 0;
            if ($idProduct <= 0) return;

            $newQty = null;
            $delta = null;
            if (isset($params['quantity'])) $newQty = (int)$params['quantity'];
            if (isset($params['delta_quantity'])) $delta = (int)$params['delta_quantity'];

            // Ignore no-op stock updates.
            if ($delta !== null && (int)$delta === 0) return;

            // Best-effort old qty
            $oldQty = null;
            if ($newQty !== null && $delta !== null) {
                $oldQty = (int)$newQty - (int)$delta;
            }

            // If we cannot compute anything meaningful, skip.
            if ($newQty === null && $delta === null) return;

            $changes = [];
            $changes[] = [
                'field' => 'Наличност',
                'old' => ($oldQty === null ? '—' : (string)$oldQty),
                'new' => ($newQty === null ? '—' : (string)$newQty),
            ];
            if ($delta !== null) {
                $changes[] = ['field' => 'Промяна', 'old' => '—', 'new' => ($delta > 0 ? '+'.$delta : (string)$delta)];
            }

            $details = 'Промяна в склада за продукт #'.$idProduct;
            $this->logAction('STOCK', 'stock', $idProduct, $details, $changes);
        } catch (Exception $t) {}
    }

    /* =====================
     * Hooks: Modules
     * ===================== */

    public function hookActionModuleInstallAfter($params)
    {
        try {
            $m = $params['module'];
            $this->logAction('ADD', 'system', 0, 'Инсталиран модул: '.$m->name);
        } catch (Exception $t) {}
    }

    public function hookActionModuleEnableAfter($params)
    {
        try {
            $m = $params['module'];
            $this->logAction('UPDATE', 'system', 0, 'Активиран модул: '.$m->name);
        } catch (Exception $t) {}
    }

    public function hookActionModuleDisableAfter($params)
    {
        try {
            $m = $params['module'];
            $this->logAction('UPDATE', 'system', 0, 'Деактивиран модул: '.$m->name);
        } catch (Exception $t) {}
    }

    /* =====================
     * Hooks: "Everything" in Back Office
     * ===================== */

    public function hookActionDispatcherBefore($params)
    {
        try {
            if (self::$baalLogging) return;
            if (!$this->baalIsEnabled()) return;

            // Back Office only (works for both legacy AdminController pages and Symfony BO pages)
            $ctx = Context::getContext();
            if (!$ctx || !isset($ctx->employee) || !is_object($ctx->employee)) return;
            if (!$ctx->employee->isLoggedBack()) return;

            // Make sure hooks are registered even after "replace module files" updates.
            $this->ensureRequiredHooksRegistered();
            // Extra guard: avoid FO requests where an employee might be...unlikely, but safe
            if (isset($_SERVER['REQUEST_URI']) && strpos((string)$_SERVER['REQUEST_URI'], '/admin') === false && defined('_PS_ADMIN_DIR_')) {
                // If admin dir constant exists we are in BO context; otherwise rely on isLoggedBack
            }

            // Ensure employee session tracking is reliable (does not depend on login/logout hooks)
            $this->ensureEmployeeSessionActive();
            // Enforce retention periodically even if the module page is not opened often.
            $this->baalMaybeAutoPrune();

            // Avoid logging our own rendering too aggressively (still logs real actions via other hooks)
            $controller = (string)Tools::getValue('controller');
            if (!$controller) {
                // Symfony BO often has _route
                $controller = (string)Tools::getValue('_route');
            }
            if (!$controller && isset($ctx->controller) && is_object($ctx->controller) && property_exists($ctx->controller, 'controller_name')) {
                $controller = (string)$ctx->controller->controller_name;
            }
            $configure = (string)Tools::getValue('configure');
            if ($controller === 'AdminModules' && $configure === $this->name) {
                return;
            }

            // Skip ajax noise only when it is NOT related to real object actions
            if ((int)Tools::getValue('ajax') === 1) {
                $aidProduct = (int)Tools::getValue('id_product');
                if (!$aidProduct) { $aidProduct = (int)Tools::getValue('productId'); }
                $aidOrder = (int)Tools::getValue('id_order');
                $aidCustomer = (int)Tools::getValue('id_customer');
                if (!$aidCustomer) { $aidCustomer = (int)Tools::getValue('customerId'); }
                if ($aidProduct<=0 && $aidOrder<=0 && $aidCustomer<=0) {
                    return;
                }
            }

            $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
            $action = ($method === 'POST') ? 'POST' : 'VIEW';

            // Add request context for BO screen actions (safe, no sensitive tokens stored)
            $details = $controller ? ('Админ екран: '.$this->translateAdminController($controller)) : 'Админ действие';

            $details .= ' | method='.$method;
            $uri = (isset($_SERVER['REQUEST_URI']) ? $this->baalSanitizeUri((string)$_SERVER['REQUEST_URI']) : '');
            if ($uri) $details .= ' | uri='.$uri;

            $ui = ($method === 'POST') ? $this->baalDetectAdminUiAction() : null;
            if ($ui && !empty($ui['label'])) {
                $details .= ' | ui_action='.(string)$ui['label'];
            }

            $submitAction = (string)Tools::getValue('submitAction');
            if ($submitAction) $details .= ' | submitAction='.$submitAction;



// BAAL_FALLBACK_ENTITY_POST: capture entity changes even on Symfony BO pages where ObjectModel hooks may not trigger
	try {
	    if ($method === 'POST') {
	        // Only run the entity-change fallback on Symfony BO pages.
	        // On legacy AdminController pages the ObjectModel hooks usually fire and this would duplicate logs.
	        $route = (string)Tools::getValue('_route');
	        $uriRaw = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
	        $isSymfonyBo = false;
	        if ($route && strpos($route, 'sell_') !== false) { $isSymfonyBo = true; }
	        if (!$isSymfonyBo && $uriRaw && strpos($uriRaw, '/sell/') !== false) { $isSymfonyBo = true; }

	        if ($isSymfonyBo) {
        $idProduct = (int)Tools::getValue('id_product');
        if (!$idProduct && isset($_SERVER['REQUEST_URI'])) {
            if (preg_match('~\/sell\/catalog\/products\/(\d+)~', $_SERVER['REQUEST_URI'], $m)) { $idProduct = (int)$m[1]; }
        }

        if (!$idProduct) { $idProduct = (int)Tools::getValue('productId'); }
        if (!$idProduct) {
            // Symfony routes sometimes use `id`
            $route = (string)Tools::getValue('_route');
            if ($route && strpos($route, 'sell_catalog_products') !== false) {
                $idProduct = (int)Tools::getValue('id');
            }
        }
if ($idProduct > 0) {
            $changesP = $this->captureProductChanges($idProduct);
            if (!$changesP || !is_array($changesP) || count($changesP) === 0) {
                $changesP = $this->captureProductChangesFromRequest($idProduct);
            }
            $this->logAction('UPDATE', 'product', $idProduct, 'Редактиран продукт #'.$idProduct, $changesP, null);
        }

        $idCustomer = (int)Tools::getValue('id_customer');
        if (!$idCustomer) { $idCustomer = (int)Tools::getValue('customerId'); }
        if ($idCustomer > 0) {
            $changesC = $this->captureCustomerChanges($idCustomer);
            $this->logAction('UPDATE', 'customer', $idCustomer, 'Редактиран клиент #'.$idCustomer, $changesC, null);
        }

	        $idOrder = (int)Tools::getValue('id_order');
	        if ($idOrder > 0) {
	            $changesO = $this->captureOrderChanges($idOrder);
	            $this->logAction('UPDATE', 'order', $idOrder, 'Редактирана поръчка #'.$idOrder, $changesO, null);
	        }
	        }
	    }
} catch (Exception $t3) {}
            $uiChanges = ($method === 'POST') ? $this->buildAdminUiActionChanges() : [];
            $this->logAction($action, 'admin', null, $details, !empty($uiChanges) ? $uiChanges : null, null);
        } catch (Exception $t) {}
    }

    /* =====================
     * Git helpers (kept)
     * ===================== */

    private function disabledFunctions()
    {
        $d = (string)ini_get('disable_functions');
        $d = array_filter(array_map('trim', explode(',', $d)));
        return array_flip($d);
    }

    private function safeExec($cmd, &$out = null, &$code = null)
    {
        $out = [];
        $code = 0;
        try {
            if (!function_exists('exec')) return false;
            $dis = $this->disabledFunctions();
            if (isset($dis['exec'])) return false;
            @exec($cmd, $out, $code);
            return $code === 0;
        } catch (Exception $e) {
            return false;
        } catch (Exception $t) {
            return false;
        }
    }

    private function syncGit($path, $limit, &$error = null)
    {
        $error = null;
        $path = rtrim((string)$path, '/');
        if (!$path) { $error = 'Моля, задайте Git път.'; return; }
        if (!is_dir($path.'/.git')) { $error = 'Не е намерена .git папка в: '.$path; return; }

        // Capture current branch + HEAD (Git Trace)
        $branch = '';
        $head = '';
        $outB = []; $codeB = 0;
        if ($this->safeExec('cd '.escapeshellarg($path).' && git rev-parse --abbrev-ref HEAD 2>&1', $outB, $codeB) && !empty($outB[0])) {
            $branch = trim((string)$outB[0]);
        }
        $outH = []; $codeH = 0;
        if ($this->safeExec('cd '.escapeshellarg($path).' && git rev-parse --short HEAD 2>&1', $outH, $codeH) && !empty($outH[0])) {
            $head = trim((string)$outH[0]);
        }
        if ($branch !== '') Configuration::updateValue('BAAL_GIT_BRANCH', $branch);
        if ($head !== '') Configuration::updateValue('BAAL_GIT_HEAD', $head);

        $limit = max(1, min(200, (int)$limit));
        $cmd = 'cd '.escapeshellarg($path).' && git --no-pager log -n '.$limit.' --date=iso --pretty=format:"%h|%an|%ae|%ad|%s" 2>&1';
        $out = [];
        $code = 0;

        if (!$this->safeExec($cmd, $out, $code)) {
            $error = 'Не може да се изпълни git (възможно exec() да е забранен на хостинга).';
            return;
        }

        foreach ($out as $line) {
            $p = explode('|', $line, 5);
            if (count($p) !== 5) continue;

            $hash = pSQL($p[0]);
            $an   = pSQL($p[1]);
            $ae   = pSQL($p[2]);
            $dt   = pSQL($p[3]);
            $msg  = pSQL($p[4], true);

            $sql = 'INSERT INTO `'._DB_PREFIX_.'baal_git_commits`
                    (`commit_hash`,`author_name`,`author_email`,`commit_date`,`commit_message`,`synced_at`)
                    VALUES ("'.$hash.'","'.$an.'","'.$ae.'","'.$dt.'","'.$msg.'","'.pSQL(date('Y-m-d H:i:s')).'")
                    ON DUPLICATE KEY UPDATE
                      `author_name`=VALUES(`author_name`),
                      `author_email`=VALUES(`author_email`),
                      `commit_date`=VALUES(`commit_date`),
                      `commit_message`=VALUES(`commit_message`),
                      `synced_at`=VALUES(`synced_at`)';
            Db::getInstance()->execute($sql);
        }

        // Log the sync itself for traceability
        $d = 'Git синхронизация';
        if ($branch !== '' || $head !== '') {
            $d .= ': '.($branch !== '' ? ('branch '.$branch) : '');
            if ($head !== '') $d .= ($branch !== '' ? ', ' : ': ').'commit '.$head;
        }
        $this->logAction('UPDATE', 'system', 0, $d);
    }

    private function autoDetectGitPath()
    {
        $candidates = [
            _PS_ROOT_DIR_,
            dirname(_PS_ROOT_DIR_),
            dirname(dirname(_PS_ROOT_DIR_)),
        ];
        foreach ($candidates as $dir) {
            if (is_dir($dir.'/.git')) {
                Configuration::updateValue('BAAL_GIT_PATH', $dir);
                return $dir;
            }
        }
        return Configuration::get('BAAL_GIT_PATH');
    }

    /* =====================
     * Admin UI
     * ===================== */

    public function getContent()
    {
        $this->ensureColumns();
        $this->pruneOldLogsIfNeeded();

        try {
            $this->context->controller->addCSS($this->_path.'views/css/admin.css');
            $this->context->controller->addJS($this->_path.'views/js/admin.js');
        } catch (Exception $e) {} catch (Exception $t) {}


        // === AJAX endpoints for real-time employee actions (admin.js polling) ===
        if ((int)Tools::getValue('baal_ajax') === 1) {
            $token = (string)Tools::getValue('token');
            $expected = Tools::getAdminTokenLite('AdminModules');
            if ($token !== $expected) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(403);
                die(json_encode(['ok'=>false,'error'=>'Invalid token']));
            }

            $action = (string)Tools::getValue('baal_action');
            if ($action === 'employee_actions') {
                $employeeId = (int)Tools::getValue('employee_id');
                $limit = max(10, min(300, (int)Tools::getValue('limit', 120)));

                // Rebuild WHERE from current filters (same as UI), but force employee_id if provided
                $employee   = trim((string)Tools::getValue('employee'));
                $from       = trim((string)Tools::getValue('from'));
                $to         = trim((string)Tools::getValue('to'));
                $objectId   = (int)Tools::getValue('object_id');
                $objectType = trim((string)Tools::getValue('object_type'));
        $onlyStatus = (int)Tools::getValue('only_status', 0);

                $where = ' WHERE 1=1';
                if ($employee !== '')   { $where .= " AND employee = '".pSQL($employee)."'"; }
                if ($from !== '')       { $where .= " AND COALESCE(group_last_at, created_at) >= '".pSQL($from)." 00:00:00'"; }
                if ($to !== '')         { $where .= " AND COALESCE(group_last_at, created_at) <= '".pSQL($to)." 23:59:59'"; }
                if ($objectId > 0)      { $where .= ' AND object_id = '.(int)$objectId; }
                if ($objectType !== '') { $where .= " AND object_type = '".pSQL($objectType)."'"; }
        if ($onlyStatus === 1) {
            $where .= " AND ( (object_type='order' AND (changes_json LIKE '%\"field\":\"Статус\"%' OR details LIKE '%Статус:%' OR details LIKE '%смени статуса%')) )";
        }
                if ($employeeId > 0)    { $where .= ' AND employee_id = '.(int)$employeeId; }

                // Parents = main actions, children = grouped events (same logic as main page)
                $parents = Db::getInstance()->executeS(
                    'SELECT * FROM `'._DB_PREFIX_.'baal_logs`'
                    .$where
                    .' AND (parent_id IS NULL OR parent_id = 0)'
                    .' ORDER BY COALESCE(group_last_at, created_at) DESC'
                    .' LIMIT '.(int)$limit
                );
                if (!$parents) { $parents = []; }

                $parentIds = [];
                foreach ($parents as $p) { $parentIds[] = (int)$p['id_log']; }

                $childrenByParent = [];
                if (!empty($parentIds)) {
                    $children = Db::getInstance()->executeS(
                        'SELECT * FROM `'._DB_PREFIX_.'baal_logs` WHERE parent_id IN ('.implode(',', array_map('intval', $parentIds)).') ORDER BY created_at DESC'
                    );
                    if ($children) {
                        foreach ($children as $c) {
                            $pid = (int)$c['parent_id'];
                            if (!isset($childrenByParent[$pid])) $childrenByParent[$pid] = [];
                            $childrenByParent[$pid][] = $c;
                        }
                    }
                }

                $typeMap = $this->getObjectTypeMap();
                foreach ($parents as &$log) {
                    $meta = $this->getActionMeta($log['action']);
                    $log['action_label'] = $meta['label'];
                    $log['action_badge'] = $meta['badge'];
                    $log['action_icon'] = $meta['icon'];
                    $log['created_at_fmt'] = $this->fmtDt($log['created_at']);
                    $log['group_last_at_fmt'] = $this->fmtDt($log['group_last_at']);
                    $otype = (string)$log['object_type'];
                    $log['object_type_label'] = isset($typeMap[$otype]) ? $typeMap[$otype] : $otype;
                    $ctrl = isset($log['controller']) ? (string)$log['controller'] : '';
                    $log['details'] = $this->translateDetails((string)$log['details'], $ctrl);

                    $log['changes'] = [];
                    $log['is_status_change'] = false;
                    if (!empty($log['changes_json'])) {
                        $decoded = json_decode($log['changes_json'], true);
                        if (is_array($decoded)) $log['changes'] = $this->normalizeChangesArray($decoded);
                    }
                    if (!empty($log['changes'])) {
                        foreach ($log['changes'] as $cc) {
                            if (isset($cc['field']) && (string)$cc['field'] === 'Статус') { $log['is_status_change'] = true; break; }
                        }
                    }
                    if ($log['is_status_change']) {
                        $log['action_label'] = 'Промяна на статус';
                        $log['action_icon'] = 'icon-refresh';
                        $log['action_badge'] = 'baal-b-status';
                    }

                    $log['summary'] = $this->buildHumanSummary($log);

                    $events = [$log];
                    if (isset($childrenByParent[(int)$log['id_log']])) {
                        foreach ($childrenByParent[(int)$log['id_log']] as $c) {
                            $cm = $this->getActionMeta($c['action']);
                            $c['action_label'] = $cm['label'];
                            $c['action_badge'] = $cm['badge'];
                            $c['action_icon'] = $cm['icon'];
                            $c['created_at_fmt'] = $this->fmtDt($c['created_at']);
                            $c['group_last_at_fmt'] = $this->fmtDt($c['group_last_at']);
                            $otype2 = (string)$c['object_type'];
                            $c['object_type_label'] = isset($typeMap[$otype2]) ? $typeMap[$otype2] : $otype2;
                            $ctrl2 = isset($c['controller']) ? (string)$c['controller'] : '';
                            $c['details'] = $this->translateDetails((string)$c['details'], $ctrl2);
                            $c['changes'] = [];
                            $c['is_status_change'] = false;
                            if (!empty($c['changes_json'])) {
                                $decoded2 = json_decode($c['changes_json'], true);
                                if (is_array($decoded2)) $c['changes'] = $this->normalizeChangesArray($decoded2);
                            }
                            if (!empty($c['changes'])) {
                                foreach ($c['changes'] as $cc2) {
                                    if (isset($cc2['field']) && (string)$cc2['field'] === 'Статус') { $c['is_status_change'] = true; break; }
                                }
                            }
                            if ($c['is_status_change']) {
                                $c['action_label'] = 'Промяна на статус';
                                $c['action_icon'] = 'icon-refresh';
                                $c['action_badge'] = 'baal-b-status';
                            }
                            $c['summary'] = $this->buildHumanSummary($c);
                            $c['summary'] = $this->buildHumanSummary($c);
                            $events[] = $c;
                        }
                    }
                    usort($events, function($a, $b){ return strcmp($b['created_at'], $a['created_at']); });
                    $log['events'] = $events;
                    $log['events_count'] = count($events);
                }
                unset($log);

                $employeeName = '';
                $lastAt = '';
                if (!empty($parents)) {
                    $employeeName = (string)$parents[0]['employee'];
                    $lastAt = (string)$parents[0]['group_last_at_fmt'];
                }

                // Render HTML via Smarty so the output matches existing layout
                $this->context->smarty->assign([
                    'ajax_items' => $parents,
                ]);
                $html = $this->context->smarty->fetch($this->local_path.'views/templates/admin/ajax_employee_actions.tpl');

                header('Content-Type: application/json; charset=utf-8');
                die(json_encode([
                    'ok' => true,
                    'employee' => $employeeName,
                    'last_at_fmt' => $lastAt,
                    'items_html' => $html,
                    'count' => count($parents),
                ]));
            }

            header('Content-Type: application/json; charset=utf-8');
            die(json_encode(['ok'=>false,'error'=>'Unknown action']));
        }

        if ((int)Configuration::get('BAAL_GIT_LIMIT') < 100) {
            Configuration::updateValue('BAAL_GIT_LIMIT', 100);
        }
        if (!Configuration::get('BAAL_PER_PAGE')) {
            Configuration::updateValue('BAAL_PER_PAGE', 20);
        }
        if (!Configuration::get('BAAL_GIT_PATH')) {
            $this->autoDetectGitPath();
        }
        if (!Configuration::get('BAAL_TIMELINE_LIMIT')) {
            Configuration::updateValue('BAAL_TIMELINE_LIMIT', 200);
        }

        if (Tools::isSubmit('submitBAALSettings')) {
            Configuration::updateValue('BAAL_ENABLED', (int)Tools::getValue('BAAL_ENABLED'));
            Configuration::updateValue('BAAL_GIT_PATH', (string)Tools::getValue('BAAL_GIT_PATH'));
            Configuration::updateValue('BAAL_GIT_LIMIT', (int)Tools::getValue('BAAL_GIT_LIMIT'));
            // Client request: keep 20 logs per page (fixed)
            Configuration::updateValue('BAAL_PER_PAGE', 20);
            Configuration::updateValue('BAAL_TIMELINE_LIMIT', (int)Tools::getValue('BAAL_TIMELINE_LIMIT'));
            Configuration::updateValue('BAAL_RETENTION_DAYS', (int)Tools::getValue('BAAL_RETENTION_DAYS'));
        }

        // Manual cleanup by period (requested)
        $cleanMsg = null;
        $cleanErr = null;
        if (Tools::isSubmit('submitBAALCleanLogs')) {
            $fromClean = trim((string)Tools::getValue('BAAL_CLEAN_FROM'));
            $toClean = trim((string)Tools::getValue('BAAL_CLEAN_TO'));
            $typeClean = trim((string)Tools::getValue('BAAL_CLEAN_TYPE'));

            if ($fromClean === '' || $toClean === '') {
                $cleanErr = 'Моля, изберете начална и крайна дата.';
            } else {
                $fromDt = $fromClean.' 00:00:00';
                $toDt = $toClean.' 23:59:59';
                $w = 'created_at BETWEEN "'.pSQL($fromDt).'" AND "'.pSQL($toDt).'"';
                if ($typeClean !== '') {
                    $w .= ' AND object_type = "'.pSQL($typeClean).'"';
                }

                // Children first then parents (same WHERE)
                $deleted = Db::getInstance()->execute('DELETE FROM `'._DB_PREFIX_.'baal_logs` WHERE '.$w);
                $cleanMsg = 'Логовете са изчистени за периода '.$fromClean.' - '.$toClean.($typeClean!=='' ? (' ('.$typeClean.')') : '').'.';
            }
        }

        $tab = Tools::getValue('baal_tab', 'team');
        $page = max(1, (int)Tools::getValue('page', 1));

        // Client request: show 20 employees per page (fixed)
        $perPage = 20;
        if ((int)Configuration::get('BAAL_PER_PAGE') !== 20) {
            Configuration::updateValue('BAAL_PER_PAGE', 20);
        }

        $base = AdminController::$currentIndex.'&configure='.$this->name.'&token='.Tools::getAdminTokenLite('AdminModules');
        $linkTeam = $base.'&baal_tab=team';
        $linkTimeline = $base.'&baal_tab=timeline';
        $linkGit  = $base.'&baal_tab=git';
        $linkCompare = $base.'&baal_tab=compare';

        // Filters
        $employee   = trim((string)Tools::getValue('employee'));
        $from       = trim((string)Tools::getValue('from'));
        $to         = trim((string)Tools::getValue('to'));
        $objectId   = (int)Tools::getValue('object_id');
        $objectType = trim((string)Tools::getValue('object_type'));

        // Always safe to append AND ...
        $where = ' WHERE 1=1';
        if ($employee !== '')   { $where .= " AND employee = '".pSQL($employee)."'"; }
        if ($from !== '')       { $where .= " AND COALESCE(group_last_at, created_at) >= '".pSQL($from)." 00:00:00'"; }
        if ($to !== '')         { $where .= " AND COALESCE(group_last_at, created_at) <= '".pSQL($to)." 23:59:59'"; }
        if ($objectId > 0)      { $where .= ' AND object_id = '.(int)$objectId; }
        if ($objectType !== '') { $where .= " AND object_type = '".pSQL($objectType)."'"; }
        if ($onlyStatus === 1) {
            $where .= " AND ( (object_type='order' AND (changes_json LIKE '%\"field\":\"Статус\"%' OR details LIKE '%Статус:%' OR details LIKE '%смени статуса%')) )";
        }

        // Same filters but usable in queries that alias baal_logs as `l`
        $whereL = ' WHERE 1=1';
        if ($employee !== '')   { $whereL .= " AND l.employee = '".pSQL($employee)."'"; }
        if ($from !== '')       { $whereL .= " AND COALESCE(l.group_last_at, l.created_at) >= '".pSQL($from)." 00:00:00'"; }
        if ($to !== '')         { $whereL .= " AND COALESCE(l.group_last_at, l.created_at) <= '".pSQL($to)." 23:59:59'"; }
        if ($objectId > 0)      { $whereL .= ' AND l.object_id = '.(int)$objectId; }
        if ($objectType !== '') { $whereL .= " AND l.object_type = '".pSQL($objectType)."'"; }
        if ($onlyStatus === 1) {
            $whereL .= " AND ( (l.object_type='order' AND (l.changes_json LIKE '%\"field\":\"Статус\"%' OR l.details LIKE '%Статус:%' OR l.details LIKE '%смени статуса%')) )";
        }

        // Statistics pagination (always 10 per page)
        $statPerPage  = 10;
        $topPage      = max(1, (int)Tools::getValue('top_page', 1));
        $fieldsPage   = max(1, (int)Tools::getValue('fields_page', 1));


        // CSV Export: statistics (top employees + object types + actions + changed fields snapshot)
        if ($tab === 'team' && Tools::getValue('export') === 'stats_csv') {
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="bestauto-autolog-stats-'.date('Y-m-d_H-i').'.csv"');
            $out = fopen('php://output','w');
            fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

            fputcsv($out, ['Секция','Ключ','Стойност']);
            // top employees (first 200)
            $rows = Db::getInstance()->executeS('SELECT employee, COUNT(*) c FROM `'._DB_PREFIX_.'baal_logs`'.$where.' GROUP BY employee ORDER BY c DESC LIMIT 200');
            foreach ($rows as $r) { fputcsv($out, ['Топ служители', (string)$r['employee'], (int)$r['c']]); }

            $rows = Db::getInstance()->executeS('SELECT object_type, COUNT(*) c FROM `'._DB_PREFIX_.'baal_logs`'.$where.' GROUP BY object_type ORDER BY c DESC LIMIT 200');
            $typeMap = $this->getObjectTypeMap();
            foreach ($rows as $r) {
                $ot = (string)$r['object_type'];
                fputcsv($out, ['По тип обект', isset($typeMap[$ot]) ? $typeMap[$ot] : $ot, (int)$r['c']]);
            }

            $rows = Db::getInstance()->executeS('SELECT action, COUNT(*) c FROM `'._DB_PREFIX_.'baal_logs`'.$where.' GROUP BY action ORDER BY c DESC LIMIT 200');
            foreach ($rows as $r) { fputcsv($out, ['По действие', (string)$r['action'], (int)$r['c']]); }

            fclose($out);
            exit;
        }

// CSV Export (parents only, with grouped events appended)
        if ($tab === 'team' && Tools::getValue('export') === 'csv') {
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="autolog-team-logs-'.date('Y-m-d_H-i').'.csv"');
            $out = fopen('php://output','w');
            fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($out, ['Служител','Действие','Обект','ID','IP','Дата/Час','Детайли','Промени / История']);

            $fromSqlCsv = ' FROM `'._DB_PREFIX_.'baal_logs` l LEFT JOIN `'._DB_PREFIX_.'baal_logs` p ON (l.parent_id = p.id_log)';
            $whereCsv = $whereL.' AND (l.parent_id IS NULL OR l.parent_id = 0 OR p.id_log IS NULL)';
            $parents = Db::getInstance()->executeS('SELECT l.*'.$fromSqlCsv.$whereCsv.' ORDER BY COALESCE(l.group_last_at, l.created_at) DESC LIMIT 50000');
            if (!$parents) $parents = [];

            $parentIds = array_map(function($r){ return (int)$r['id_log']; }, $parents);
            $childrenByParent = [];
            if ($parentIds) {
                $rows = Db::getInstance()->executeS('SELECT * FROM `'._DB_PREFIX_.'baal_logs` WHERE parent_id IN ('.implode(',', $parentIds).') ORDER BY created_at DESC');
                foreach ($rows as $r) {
                    $pid = (int)$r['parent_id'];
                    if (!isset($childrenByParent[$pid])) $childrenByParent[$pid] = [];
                    $childrenByParent[$pid][] = $r;
                }
            }

            foreach ($parents as $r) {
                $meta = $this->getActionMeta($r['action']);
                $changesText = '';
                $histParts = [];

                $allEvents = array_merge([$r], isset($childrenByParent[(int)$r['id_log']]) ? $childrenByParent[(int)$r['id_log']] : []);
                foreach ($allEvents as $ev) {
                    $line = $ev['created_at'].' | '.$ev['employee'].' | '.$ev['details'];
                    if (!empty($ev['changes_json'])) {
                        $ch = json_decode($ev['changes_json'], true);
                        if (is_array($ch)) {
                            $parts = [];
                            foreach ($ch as $c) {
                                if (isset($c['field'])) {
                                    $parts[] = $c['field'].': '.(isset($c['old']) ? $c['old'] : '').' → '.(isset($c['new']) ? $c['new'] : '');
                                }
                            }
                            if ($parts) $line .= ' | '.implode('; ', $parts);
                        }
                    }
                    $histParts[] = $line;
                }

                $changesText = implode(' || ', $histParts);

                fputcsv($out, [$r['employee'], $meta['label'], $r['object_type'], $r['object_id'], $r['ip'], ($r['group_last_at'] ?: $r['created_at']), $r['details'], $changesText]);
            }
            fclose($out);
            exit;
        }

        $gitError = null;
        if ($tab === 'git' && Tools::isSubmit('submitBAALGitSync')) {
            $this->syncGit(Configuration::get('BAAL_GIT_PATH'), (int)Configuration::get('BAAL_GIT_LIMIT'), $gitError);
        }

        $logs = [];
        $employees = [];
        $stats = [];
        $stats_by_type = [];
        $stats_by_action = [];
        $stats_by_day = [];
        $heatmap_by_hour = [];
        $top_changed_fields = [];
        $kpi = ['total'=>0,'unique'=>0,'top'=>null];
        $objectStats = null;
        $totalLogs = 0;
        $totalPages = 1;

        if ($tab === 'team') {
            // Team history grouped by employee (1 row per employee + dropdown with all actions)
            $totalLogs = (int)Db::getInstance()->getValue('SELECT COUNT(DISTINCT l.employee) FROM `'._DB_PREFIX_.'baal_logs` l'.$whereL);
            $totalPages = max(1, (int)ceil($totalLogs / $perPage));
            $page = min($page, $totalPages);
            $offset = ($page - 1) * $perPage;

            $empPage = Db::getInstance()->executeS(
                'SELECT l.employee, MAX(l.employee_id) employee_id, MAX(COALESCE(l.group_last_at, l.created_at)) last_at, COUNT(*) c'
                .' FROM `'._DB_PREFIX_.'baal_logs` l'
                .$whereL
                .' GROUP BY l.employee'
                .' ORDER BY last_at DESC'
                .' LIMIT '.(int)$offset.', '.(int)$perPage
            );
            if (!$empPage) $empPage = [];

            $empNames = [];
            $empMeta = [];
            foreach ($empPage as $er) {
                $nm = (string)$er['employee'];
                if ($nm === '') continue;
                $empNames[] = $nm;
                $empMeta[$nm] = [
                    'employee' => $nm,
                    'employee_id' => (int)$er['employee_id'],
                    'last_at_fmt' => $this->fmtDt($er['last_at']),
                    'actions_count' => (int)$er['c'],
                ];
            }

            $parents = [];
            $childrenByParent = [];
            if ($empNames) {
                $in = '"'.implode('","', array_map('pSQL', $empNames)).'"';
                $fromSql = ' FROM `'._DB_PREFIX_.'baal_logs` l LEFT JOIN `'._DB_PREFIX_.'baal_logs` p ON (l.parent_id = p.id_log)';
                $whereEmp = $whereL.' AND l.employee IN ('.$in.') AND (l.parent_id IS NULL OR l.parent_id = 0 OR p.id_log IS NULL)';

                $maxFetch = max(500, (int)$perPage * 300);
                $parents = Db::getInstance()->executeS(
                    'SELECT l.*'.$fromSql.$whereEmp.' ORDER BY COALESCE(l.group_last_at, l.created_at) DESC LIMIT '.$maxFetch
                );
                if (!$parents) $parents = [];

                $parentIds = [];
                foreach ($parents as $r) { $parentIds[] = (int)$r['id_log']; }
                if ($parentIds) {
                    $children = Db::getInstance()->executeS(
                        'SELECT * FROM `'._DB_PREFIX_.'baal_logs` WHERE parent_id IN ('.implode(',', $parentIds).') ORDER BY created_at DESC LIMIT '.(int)($maxFetch * 2)
                    );
                    if ($children) {
                        foreach ($children as $c) {
                            $pid = (int)$c['parent_id'];
                            if (!isset($childrenByParent[$pid])) $childrenByParent[$pid] = [];
                            $childrenByParent[$pid][] = $c;
                        }
                    }
                }

                foreach ($parents as &$log) {
                    $meta = $this->getActionMeta($log['action']);
                    $ctrl = isset($log['controller']) ? (string)$log['controller'] : '';
                    $log['controller_label'] = $this->translateAdminController($ctrl);
                    $log['details'] = $this->translateDetails((string)$log['details'], $ctrl);
                    $log['action_label'] = $meta['label'];
                    $log['action_badge'] = $meta['badge'];
                    $log['action_icon'] = $meta['icon'];
                    $log['object_icon'] = $this->getObjectIcon($log['object_type']);
                    $log['created_at_fmt'] = $this->fmtDt($log['created_at']);
                    $log['group_last_at_fmt'] = $this->fmtDt($log['group_last_at']);

                    $log['changes'] = [];
                    if (!empty($log['changes_json'])) {
                        $decoded = json_decode($log['changes_json'], true);
                        if (is_array($decoded)) $log['changes'] = $this->normalizeChangesArray($decoded);
                    }
                    $log['changes_steps'] = array_slice($log['changes'], 0, 3);
                    $log['changes_more'] = array_slice($log['changes'], 3);

                    $events = [$log];
                    if (isset($childrenByParent[(int)$log['id_log']])) {
                        foreach ($childrenByParent[(int)$log['id_log']] as $c) {
                            $cm = $this->getActionMeta($c['action']);
                            $c['action_label'] = $cm['label'];
                            $c['action_badge'] = $cm['badge'];
                            $c['action_icon'] = $cm['icon'];
                            $c['object_icon'] = $this->getObjectIcon($c['object_type']);
                            $c['created_at_fmt'] = $this->fmtDt($c['created_at']);
                            $c['group_last_at_fmt'] = $this->fmtDt($c['group_last_at']);
                            $c['changes'] = [];
                            if (!empty($c['changes_json'])) {
                                $decoded = json_decode($c['changes_json'], true);
                                if (is_array($decoded)) $c['changes'] = $this->normalizeChangesArray($decoded);
                            }
                            $c['changes_steps'] = array_slice($c['changes'], 0, 3);
                            $c['changes_more'] = array_slice($c['changes'], 3);
                            $events[] = $c;
                        }
                    }
                    usort($events, function($a, $b){ return strcmp($b['created_at'], $a['created_at']); });
                    $log['events'] = $events;
                    $log['events_count'] = count($events);
                }
                unset($log);
            }

            $logs = [];
            $idxByEmp = [];
            foreach ($empNames as $nm) {
                $logs[] = [
                    'employee' => $empMeta[$nm]['employee'],
                    'employee_id' => $empMeta[$nm]['employee_id'],
                    'last_at_fmt' => $empMeta[$nm]['last_at_fmt'],
                    'actions_count' => $empMeta[$nm]['actions_count'],
                    'items' => [],
                ];
                $idxByEmp[$nm] = count($logs) - 1;
            }

            $typeMap = $this->getObjectTypeMap();
            foreach ($parents as $p) {
                $nm = (string)$p['employee'];
                if (!isset($idxByEmp[$nm])) continue;
                $otype = (string)$p['object_type'];
                $p['object_type_label'] = isset($typeMap[$otype]) ? $typeMap[$otype] : $otype;
                $ctrl = isset($p['controller']) ? (string)$p['controller'] : '';
                $p['details'] = $this->translateDetails((string)$p['details'], $ctrl);
                $logs[$idxByEmp[$nm]]['items'][] = $p;
            }


            // === Statistics (computed from logs, not sessions) ===
            $kpi['total']  = (int)Db::getInstance()->getValue('SELECT COUNT(*) FROM `'._DB_PREFIX_.'baal_logs`'.$where);
            $kpi['unique'] = (int)Db::getInstance()->getValue('SELECT COUNT(DISTINCT employee) FROM `'._DB_PREFIX_.'baal_logs`'.$where);
            $kpi['top']    = Db::getInstance()->getValue('SELECT employee FROM `'._DB_PREFIX_.'baal_logs`'.$where.' GROUP BY employee ORDER BY COUNT(*) DESC LIMIT 1');

            // Top employees (paged)
            $topTotal = (int)Db::getInstance()->getValue('SELECT COUNT(DISTINCT employee) FROM `'._DB_PREFIX_.'baal_logs`'.$where);
            $topTotalPages = max(1, (int)ceil($topTotal / $statPerPage));
            $topPage = min($topPage, $topTotalPages);
            $topOffset = ($topPage - 1) * $statPerPage;
            $stats = Db::getInstance()->executeS(
                'SELECT employee, COUNT(*) c'
                .' FROM `'._DB_PREFIX_.'baal_logs`'
                .$where
                .' GROUP BY employee'
                .' ORDER BY c DESC'
                .' LIMIT '.(int)$topOffset.', '.(int)$statPerPage
            );
            if (!$stats) $stats = [];

            // By object type
            $stats_by_type = Db::getInstance()->executeS(
                'SELECT object_type, COUNT(*) c'
                .' FROM `'._DB_PREFIX_.'baal_logs`'
                .$where
                .' GROUP BY object_type'
                .' ORDER BY c DESC'
                .' LIMIT 10'
            );
            if (!$stats_by_type) $stats_by_type = [];
            $typeMap = $this->getObjectTypeMap();
            foreach ($stats_by_type as &$r) {
                $ot = (string)$r['object_type'];
                $r['object_type_label'] = isset($typeMap[$ot]) ? $typeMap[$ot] : $ot;
            }
            unset($r);

            // By action
            $stats_by_action = Db::getInstance()->executeS(
                'SELECT action, COUNT(*) c'
                .' FROM `'._DB_PREFIX_.'baal_logs`'
                .$where
                .' GROUP BY action'
                .' ORDER BY c DESC'
                .' LIMIT 10'
            );
            if (!$stats_by_action) $stats_by_action = [];

            // Last 14 days (daily counts)
            $where14 = $where.' AND created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)';
            $rows14 = Db::getInstance()->executeS(
                'SELECT DATE(created_at) d, COUNT(*) c'
                .' FROM `'._DB_PREFIX_.'baal_logs`'
                .$where14
                .' GROUP BY d'
                .' ORDER BY d ASC'
            );
            $map14 = [];
            if ($rows14) {
                foreach ($rows14 as $r) { $map14[(string)$r['d']] = (int)$r['c']; }
            }
            $stats_by_day = [];
            $today = strtotime(date('Y-m-d'));
            for ($i = 13; $i >= 0; $i--) {
                $d = date('Y-m-d', strtotime('-'.$i.' day', $today));
                $stats_by_day[] = ['d' => $d, 'c' => isset($map14[$d]) ? $map14[$d] : 0];
            }

            // Heatmap by hour (last 14 days)
            $rowsH = Db::getInstance()->executeS(
                'SELECT HOUR(created_at) h, COUNT(*) c'
                .' FROM `'._DB_PREFIX_.'baal_logs`'
                .$where14
                .' GROUP BY h'
                .' ORDER BY h ASC'
            );
            $mapH = [];
            if ($rowsH) { foreach ($rowsH as $r) { $mapH[(int)$r['h']] = (int)$r['c']; } }
            $heatmap_by_hour = [];
            $maxH = 0;
            for ($h = 0; $h <= 23; $h++) {
                $c = isset($mapH[$h]) ? (int)$mapH[$h] : 0;
                if ($c > $maxH) { $maxH = $c; }
                $heatmap_by_hour[$h] = ['h' => $h, 'c' => $c];
            }
            // Levels 0..4 based on ratio to max (0=none). Smooth enough for BO usage.
            for ($h = 0; $h <= 23; $h++) {
                $c = (int)$heatmap_by_hour[$h]['c'];
                $lvl = 0;
                if ($maxH > 0 && $c > 0) {
                    $r = $c / (float)$maxH;
                    if ($r <= 0.25) { $lvl = 1; }
                    elseif ($r <= 0.50) { $lvl = 2; }
                    elseif ($r <= 0.75) { $lvl = 3; }
                    else { $lvl = 4; }
                }
                $heatmap_by_hour[$h]['lvl'] = $lvl;
            }
            $heatmap_by_hour = array_values($heatmap_by_hour);

            // Top changed fields (last 30 days) - computed in PHP from changes_json
            $where30 = $where.' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND changes_json IS NOT NULL AND changes_json <> ""';
            $rowsCh = Db::getInstance()->executeS('SELECT changes_json FROM `'._DB_PREFIX_.'baal_logs`'.$where30.' LIMIT 5000');
            $fieldCounts = [];
            if ($rowsCh) {
                foreach ($rowsCh as $row) {
                    $cj = (string)$row['changes_json'];
                    if ($cj === '') continue;
                    $decoded = json_decode($cj, true);
                    if (!is_array($decoded)) continue;
                    $norm = $this->normalizeChangesArray($decoded);
                    foreach ($norm as $c) {
                        if (!isset($c['field'])) continue;
                        $f = trim((string)$c['field']);
                        if ($f === '') continue;
                        if (!isset($fieldCounts[$f])) $fieldCounts[$f] = 0;
                        $fieldCounts[$f]++;
                    }
                }
            }
            arsort($fieldCounts);
            $allFields = array_keys($fieldCounts);
            $fieldsTotalPages = max(1, (int)ceil(count($allFields) / $statPerPage));
            $fieldsPage = min($fieldsPage, $fieldsTotalPages);
            $fieldsOffset = ($fieldsPage - 1) * $statPerPage;
            $slice = array_slice($allFields, $fieldsOffset, $statPerPage);
            $top_changed_fields = [];
            foreach ($slice as $f) { $top_changed_fields[] = ['field' => $f, 'c' => (int)$fieldCounts[$f]]; }

            $employees = Db::getInstance()->executeS('SELECT DISTINCT employee FROM `'._DB_PREFIX_.'baal_logs` ORDER BY employee ASC');
        }

        if (!$employees) {
            $employees = Db::getInstance()->executeS('SELECT DISTINCT employee FROM `'._DB_PREFIX_.'baal_logs` ORDER BY employee ASC');
        }

        
        // Dashboard: employee sessions & activity (default: last 7 days total)
        $dashRange = (string)Tools::getValue('dash_range', 'all');
        if (!preg_match('/^(all|[0-7])$/', $dashRange)) { $dashRange = 'all'; }



$dashboard = [];
$dashSessions = [];
try {
    $whereDash = '';

    // Robust calculation in PHP (avoids TIMESTAMPDIFF/NOW() quirks on some hosts)
    $nowPhp = date('Y-m-d H:i:s');
    $timeoutMin = (int)self::BAAL_SESSION_TIMEOUT_MIN;
    // If timeout is disabled (0), still use a sane inactivity window for reporting
    if ($timeoutMin <= 0) { $timeoutMin = 30; }
    $timeoutSec = $timeoutMin * 60;

    if ($dashRange === 'all') {
        // last 7 days incl. today (from midnight 6 days ago)
        $fromDash = date('Y-m-d', strtotime('-6 days')).' 00:00:00';
        $whereDash = ' WHERE login_at >= "'.pSQL($fromDash).'"';
    } else {
        $daysAgo = (int)$dashRange;
        $day = date('Y-m-d', strtotime('-'.$daysAgo.' days'));
        $fromDash = $day.' 00:00:00';
        $toDash = $day.' 23:59:59';
               $whereDash = ' WHERE login_at BETWEEN "'.pSQL($fromDash).'" AND "'.pSQL($toDash).'"';
    }

    // Load sessions for the period and aggregate per employee in PHP
    $rowsList = Db::getInstance()->executeS(
        'SELECT id_session, employee_id, employee, login_at, last_activity, logout_at, duration_sec, ip, actions_count'
        .' FROM `'._DB_PREFIX_.'baal_employee_sessions`'
        .$whereDash
        .' ORDER BY login_at DESC'
        .' LIMIT 2000'
    );

    $agg = [];
    if ($rowsList) {
        foreach ($rowsList as $s) {
            $empId = (int)$s['employee_id'];
            $empName = (string)$s['employee'];

            $loginAt = (string)$s['login_at'];
            $logoutAt = (string)$s['logout_at'];
            $lastAct = (string)$s['last_activity'];

            $dur = (int)$s['duration_sec'];

            if ($dur <= 0) {
                $end = '';
                if (!empty($logoutAt) && $logoutAt !== '0000-00-00 00:00:00') {
                    $end = $logoutAt;
                } else {
                    $la = (!empty($lastAct) && $lastAct !== '0000-00-00 00:00:00') ? $lastAct : $loginAt;
                    $tLa = strtotime($la);
                    $tNow = strtotime($nowPhp);
                    if ($tLa && $tNow && ($tNow - $tLa) <= $timeoutSec) {
                        $end = $nowPhp;
                    } else {
                        $end = $la;
                    }
                }

                $tLogin = strtotime($loginAt);
                $tEnd = strtotime($end);
                if ($tLogin && $tEnd) {
                    $dur = max(0, $tEnd - $tLogin);
                } else {
                    $dur = 0;
                }
            }

            // Raw sessions list (UI helper)
            $h = floor($dur/3600);
            $m = floor(($dur%3600)/60);
            $dashSessions[] = [
                'id_session' => (int)$s['id_session'],
                'employee_id' => $empId,
                'employee' => $empName,
                'login_at' => $this->fmtDt((string)$s['login_at']),
                'last_activity' => $this->fmtDt((string)$s['last_activity']),
                'logout_at' => $this->fmtDt((string)$s['logout_at']),
                'duration_hm' => sprintf('%dh %02dm', $h, $m),
                'ip' => (string)$s['ip'],
                'actions' => (int)$s['actions_count'],
            ];

            if (!isset($agg[$empId])) {
                $agg[$empId] = [
                    'employee_id' => $empId,
                    'employee' => $empName,
                    'sessions' => 0,
                    'actions' => 0,
                    'total_sec' => 0,
                    'last_activity_ts' => 0,
                    'last_activity_raw' => '',
                ];
            }

            $agg[$empId]['sessions'] += 1;
            $agg[$empId]['actions'] += (int)$s['actions_count'];
            $agg[$empId]['total_sec'] += (int)$dur;

            // Track last activity for display
            $cand = '';
            if (!empty($logoutAt) && $logoutAt !== '0000-00-00 00:00:00') {
                $cand = $logoutAt;
            } elseif (!empty($lastAct) && $lastAct !== '0000-00-00 00:00:00') {
                $cand = $lastAct;
            } else {
                $cand = $loginAt;
            }
            $tCand = strtotime($cand);
            if ($tCand && $tCand > (int)$agg[$empId]['last_activity_ts']) {
                $agg[$empId]['last_activity_ts'] = (int)$tCand;
                $agg[$empId]['last_activity_raw'] = $cand;
            }
        }
    }

    if (count($dashSessions) > 300) {
        $dashSessions = array_slice($dashSessions, 0, 300);
    }

    if ($agg) {
        $aggList = array_values($agg);
        usort($aggList, function($a, $b) {
            if ((int)$a['actions'] === (int)$b['actions']) {
                if ((int)$a['total_sec'] === (int)$b['total_sec']) return 0;
                return ((int)$b['total_sec'] < (int)$a['total_sec']) ? -1 : 1;
            }
            return ((int)$b['actions'] < (int)$a['actions']) ? -1 : 1;
        });

        foreach ($aggList as $r) {
            $total = (int)$r['total_sec'];
            $hh = floor($total/3600);
            $mm = floor(($total%3600)/60);
            $dashboard[] = [
                'employee_id' => (int)$r['employee_id'],
                'employee' => (string)$r['employee'],
                'sessions' => (int)$r['sessions'],
                'actions' => (int)$r['actions'],
                'total_sec' => $total,
                'total_hm' => sprintf('%dh %02dm', $hh, $mm),
                'last_activity' => $this->fmtDt((string)$r['last_activity_raw']),
            ];
        }
    }
} catch (Exception $tDash) {}

        // Compare employees tab
        $compare = [];
        $compare_by_type = [];
        $compare_by_hour = [];
        if ($tab === 'compare') {
            $sel = Tools::getValue('compare_employees');
            if (!is_array($sel)) $sel = [];
            $sel = array_values(array_filter(array_map('trim', $sel)));
            if (count($sel) > 3) $sel = array_slice($sel, 0, 3);

            if (!$sel) {
                $tmp = Db::getInstance()->executeS('SELECT employee, COUNT(*) c FROM `'._DB_PREFIX_.'baal_logs`'.$where.' GROUP BY employee ORDER BY c DESC LIMIT 2');
                foreach ($tmp as $t) { $sel[] = (string)$t['employee']; }
            }
            $compare = $sel;

            if ($sel) {
                $in = array_map(function($e){ return '"'.pSQL($e).'"'; }, $sel);
                $inList = implode(',', $in);
                $rowsT = Db::getInstance()->executeS('SELECT employee, object_type, COUNT(*) c FROM `'._DB_PREFIX_.'baal_logs`'.$where.' AND employee IN ('.$inList.') GROUP BY employee, object_type ORDER BY employee ASC, c DESC');
                $typeMap = $this->getObjectTypeMap();
                foreach ($rowsT as $r) {
                    $empN = (string)$r['employee'];
                    $ot = (string)$r['object_type'];
                    if (!isset($compare_by_type[$empN])) $compare_by_type[$empN] = [];
                    $compare_by_type[$empN][] = [
                        'type' => isset($typeMap[$ot]) ? $typeMap[$ot] : $ot,
                        'c' => (int)$r['c']
                    ];
                }

                $cut14 = date('Y-m-d', strtotime('-13 days')).' 00:00:00';
                $wh = $where ? ($where.' AND created_at >= "'.pSQL($cut14).'"') : (' WHERE created_at >= "'.pSQL($cut14).'"');
                $rowsH = Db::getInstance()->executeS('SELECT employee, HOUR(created_at) h, COUNT(*) c FROM `'._DB_PREFIX_.'baal_logs`'.$wh.' AND employee IN ('.$inList.') GROUP BY employee, h ORDER BY employee ASC, h ASC');
                foreach ($sel as $empN) { $compare_by_hour[$empN] = array_fill(0, 24, 0); }
                foreach ($rowsH as $r) {
                    $empN = (string)$r['employee'];
                    $h = (int)$r['h'];
                    if ($h>=0 && $h<=23 && isset($compare_by_hour[$empN])) $compare_by_hour[$empN][$h] = (int)$r['c'];
                }
            }
        }

        // Timeline tab: last N events (flattened, but grouped orders displayed as parent with nested events)
        $timeline = [];
        if ($tab === 'timeline') {
            $limit = max(50, min(2000, (int)Configuration::get('BAAL_TIMELINE_LIMIT')));
            $parents = Db::getInstance()->executeS('SELECT * FROM `'._DB_PREFIX_.'baal_logs`'.$where.' AND parent_id IS NULL ORDER BY COALESCE(group_last_at, created_at) DESC LIMIT '.(int)$limit);
            if (!$parents) $parents = [];
            $parentIds = array_map(function($r){ return (int)$r['id_log']; }, $parents);

            $childrenByParent = [];
            if ($parentIds) {
                $children = Db::getInstance()->executeS('SELECT * FROM `'._DB_PREFIX_.'baal_logs` WHERE parent_id IN ('.implode(',', $parentIds).') ORDER BY created_at DESC');
                foreach ($children as $c) {
                    $pid = (int)$c['parent_id'];
                    if (!isset($childrenByParent[$pid])) $childrenByParent[$pid] = [];
                    $childrenByParent[$pid][] = $c;
                }
            }

            foreach ($parents as $p) {
                $pm = $this->getActionMeta($p['action']);
                $p['action_label'] = $pm['label'];
                $p['action_badge'] = $pm['badge'];
                $p['action_icon'] = $pm['icon'];
                $p['object_icon'] = $this->getObjectIcon($p['object_type']);
                $p['created_at_fmt'] = $this->fmtDt($p['created_at']);
                $p['group_last_at_fmt'] = $this->fmtDt($p['group_last_at']);
                $p['changes'] = [];
                if (!empty($p['changes_json'])) {
                $p['is_status_change'] = false;

                    $decoded = json_decode($p['changes_json'], true);
                    if (is_array($decoded)) $p['changes'] = $this->normalizeChangesArray($decoded);
                }
                // Mark status change for nicer UI
                if (!empty($p['changes'])) {
                    foreach ($p['changes'] as $cc) {
                        if (isset($cc['field']) && (string)$cc['field'] === 'Статус') { $p['is_status_change'] = true; break; }
                    }
                }
                if ($p['is_status_change']) {
                    $p['action_label'] = 'Промяна на статус';
                    $p['action_icon'] = 'icon-refresh';
                    $p['action_badge'] = 'baal-b-status';
                }

                $p['summary'] = $this->buildHumanSummary($p);

                $events = [$p];
                if (isset($childrenByParent[(int)$p['id_log']])) {
                    foreach ($childrenByParent[(int)$p['id_log']] as $c) {
                        $cm = $this->getActionMeta($c['action']);
                        $c['action_label'] = $cm['label'];
                        $c['action_badge'] = $cm['badge'];
                        $c['action_icon'] = $cm['icon'];
                        $c['object_icon'] = $this->getObjectIcon($c['object_type']);
                        $c['created_at_fmt'] = $this->fmtDt($c['created_at']);
                        $c['group_last_at_fmt'] = $this->fmtDt($c['group_last_at']);
                        $c['changes'] = [];
                        $c['is_status_change'] = false;
                        if (!empty($c['changes_json'])) {
                            $decoded = json_decode($c['changes_json'], true);
                            if (is_array($decoded)) $c['changes'] = $this->normalizeChangesArray($decoded);
                        }
                        $c['changes_steps'] = array_slice($c['changes'], 0, 3);
                        $c['changes_more'] = array_slice($c['changes'], 3);
                        $events[] = $c;
                    }
                }
                usort($events, function($a, $b){ return strcmp($b['created_at'], $a['created_at']); });
                $p['events'] = $events;
                $p['events_count'] = count($events);
                $p['changes_steps'] = array_slice($p['changes'], 0, 3);
                $p['changes_more'] = array_slice($p['changes'], 3);

                $timeline[] = $p;
            }
        }

        $commits = [];
        if ($tab === 'git') {
            $commits = Db::getInstance()->executeS('SELECT commit_hash, author_name, author_email, commit_date, commit_message FROM `'._DB_PREFIX_.'baal_git_commits` ORDER BY commit_date DESC LIMIT '.(int)Configuration::get('BAAL_GIT_LIMIT'));
        }

        $csvLink = $base.'&baal_tab=team&export=csv'
            .($employee!=='' ? '&employee='.urlencode($employee) : '')
            .($from!=='' ? '&from='.urlencode($from) : '')
            .($to!=='' ? '&to='.urlencode($to) : '')
            .($objectId>0 ? '&object_id='.$objectId : '')
            .($objectType!=='' ? '&object_type='.urlencode($objectType) : '')
            .($onlyStatus===1 ? '&only_status=1' : '');

        $this->context->smarty->assign([
            'module_dir' => $this->_path,
            'version' => $this->version,
            'tab' => $tab,
            'enabled' => (int)Configuration::get('BAAL_ENABLED') === 1,
            'git_path' => (string)Configuration::get('BAAL_GIT_PATH'),
            'git_limit' => (int)Configuration::get('BAAL_GIT_LIMIT'),
            'per_page' => $perPage,
            'timeline_limit' => (int)Configuration::get('BAAL_TIMELINE_LIMIT'),
            'retention_days' => (int)Configuration::get('BAAL_RETENTION_DAYS'),
            'link_team' => $linkTeam,
            'link_timeline' => $linkTimeline,
            'link_git' => $linkGit,
            'link_compare' => $linkCompare,
            'filters' => [
                'employee'=>$employee,
                'from'=>$from,
                'to'=>$to,
                'object_id'=>$objectId,
                'object_type'=>$objectType,
                'only_status'=>(int)$onlyStatus],
            'employees' => $employees,
            'dashboard' => isset($dashboard) ? $dashboard : [],
            'dash_range' => $dashRange,
            'dash_sessions' => isset($dashSessions) ? $dashSessions : [],
            'logs' => $logs,
            'timeline' => $timeline,
            'stats' => $stats,
            'stats_by_type' => $stats_by_type,
            'stats_by_action' => $stats_by_action,
            'stats_by_day' => $stats_by_day,
            'heatmap_by_hour' => $heatmap_by_hour,
            'top_changed_fields' => $top_changed_fields,
            'compare' => $compare,
            'compare_by_type' => $compare_by_type,
            'compare_by_hour' => $compare_by_hour,
            'kpi' => $kpi,
            'object_stats' => $objectStats,
            'csv_link' => $csvLink,
            'commits' => $commits,
            'git_error' => $gitError,
            'git_branch' => (string)Configuration::get('BAAL_GIT_BRANCH'),
            'git_head' => (string)Configuration::get('BAAL_GIT_HEAD'),
            'clean_msg' => $cleanMsg,
            'clean_err' => $cleanErr,
            'admin_token' => Tools::getAdminTokenLite('AdminModules'),
            'current_page' => $page,
            'total_pages' => $totalPages,
            'top_page' => $topPage,
            'top_total_pages' => isset($topTotalPages) ? $topTotalPages : 1,
            'fields_page' => $fieldsPage,
            'fields_total_pages' => isset($fieldsTotalPages) ? $fieldsTotalPages : 1,
            'total_logs' => $totalLogs,
        ]);

        return $this->display(__FILE__, 'views/templates/admin/configure.tpl');
    }
}