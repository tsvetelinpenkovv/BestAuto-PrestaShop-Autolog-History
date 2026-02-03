<?php
/**
 * Back-office entry under Catalog that redirects to the module configuration page.
 * Compatible with PrestaShop 1.7.7.5.
 */
class AdminBestautoAutologHistoryController extends ModuleAdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->bootstrap = true;
    }

    public function initContent()
    {
        $adminModules = $this->context->link->getAdminLink('AdminModules', true);
        Tools::redirectAdmin($adminModules.'&configure=bestautoautologhistory');
    }
}
