<?php
require_once 'modules/Vtiger/CRMEntity.php';

class RMMDevices extends CRMEntity {
    public string $table_name  = 'vtiger_account';
    public string $table_index = 'accountid';
}
