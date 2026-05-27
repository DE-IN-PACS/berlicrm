<?php
require_once 'modules/RMMDevices/views/InRelation.php';

class Accounts_RMMTab_View extends Vtiger_Base_View {

    public function process(Vtiger_Request $request)
    {
        $inner = new RMMDevices_InRelation_View();
        $inner->process($request);
        // process() calls sendAndExit() — outputs HTML fragment and exits
    }

    public function validateRequest(Vtiger_Request $request): void
    {
        $request->validateReadAccess();
    }
}
