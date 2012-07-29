<?php

/**
 * Create a Facade
 */
class SphinxSearchAdmin {

    public $Setup;
    public $Install;
    public $Service;
    public $Wizard;
    protected $Settings;
    public $Observable;

    public function __construct($Sender, $View) {

        $this->Setup = SphinxFactory::BuildSettings();
        $this->Settings = $this->Setup->GetAllSettings();

        $this->Install = SphinxFactory::BuildInstall($this->Settings);
        $this->Install->Attach(new SphinxStatusLogger($Sender, $View));

        $this->Service = SphinxFactory::BuildService($this->Settings);
        $this->Service->Attach(new SphinxStatusLogger($Sender, $View));

        $this->Service = SphinxFactory::BuildService($this->Settings);
        $this->Service->Attach(new SphinxStatusLogger($Sender, $View));

        $this->Wizard = SphinxFactory::BuildWizard($this->Settings);
        $this->Wizard->Attach(new SphinxStatusLogger($Sender, $View));
    }

    public function ToggleWizard() {
        $this->Wizard->ToggleWizard();
    }

    public function Detect() {
        $this->Wizard->DetectionAction();    //attempt to find prescense of sphinx
    }

    public function GetSettings() {
        //return $this->Settings;
        $Setup = SphinxFactory::BuildSettings();
        return $Setup->GetAllSettings();
    }

    public function ValidateInstall() {
        $this->Service->ValidateInstall();
    }

    public function SetupCron(){
        $this->Install->SetupCron();
    }

    public function WriteConfigFile(){
        $this->Install->InstallWriteConfig();
    }

    public function InstallConfig(){
        //First check if sphinx is installed
        $this->Service->ValidateInstall();
        $this->Install->InstallWriteConfig();
        $this->SetupCron();
    }

    public function InstallAction($InstallAction){
        if($this->CheckSphinxRunning())
            $this->Stop(); //stop if it is running before a new install is made
        return $this->Wizard->InstallAction($InstallAction, $this->Service, $this->Install);
    }

    public function CheckSphinxRunning() {
        return $this->Service->CheckSphinxRunning();
    }

    public function Status() {
        $this->Service->Status();
    }

    public function Start() {
        $this->Service->Start();
    }

    public function Stop() {
        $this->Service->Stop();
    }

    public function ReIndexMain($Background) {
        $this->Service->ReIndexMain($Background);
    }

    public function ReIndexDelta($Background) {
        $this->Service->ReIndexDelta($Background);
    }

    public function ReIndexStats($Background) {
        $this->Service->ReIndexStats($Background);
    }

    public function CheckPort() {
        $this->Service->CheckPort();
    }

}