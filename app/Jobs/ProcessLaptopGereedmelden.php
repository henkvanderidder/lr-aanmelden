<?php

namespace App\Jobs;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Mail\GereedmeldingMail;
use App\Mail\GereedmeldingMailError;
use Illuminate\Support\Facades\Http;


class ProcessLaptopGereedmelden extends ProcessBaseJob
{
    // use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
        // Log::info('LaptopGereedmelden: job created.');
    }

    // hier de code om de laptop aan te melden in SnipeIT, 
    // met behulp van de SnipeIT API. 
    // zie: https://snipe-it.readme.io/reference/api-overview
    //
    public function VerwerkGereedmeldenInSnipeIT($laptop)
    {

        // Stap 1: zoek Status "Op Voorraad" in SnipeIT en haal het id op.
        $statusLabelId = $this->verwerkSnipeITPart('statuslabels', 'Op Voorraad', 1, [
            'name' => 'Op Voorraad',
            'type' => 'pending',
            'notes' => 'Status aangemaakt door API'
        ]);

        // Stap 2: zoek Company "Laptop Revive" in SnipeIT en haal het id op.
        $companyId = $this->verwerkSnipeITPart('companies', 'Laptop Revive', 1, [
            'name' => 'Laptop Revive',
            'notes' => 'Bedrijf aangemaakt door API'
        ]);


        // Stap 3: zoek Location "naam (woonplaats)" in SnipeIT en haal het id op.
        //$locationName = ucfirst(strtolower($laptop['naam'])) ?? 'naam_onbekend';
        //$locationName .= "  (" . ucfirst(strtolower($laptop['woonplaats'])) . ")";

        $locationId = 0;
        $withError = false;
        $errorMsg = "";

        // Stap 4: opzoeken gebruiker met emailadres in SnipeIT en haal het id op. 
        // Als niet gevonden, maak foutmelding in $errorMsg 
        $userEmail = $laptop['email'] ?? '';
        $userId = $this->readSnipeITPartforId('users', $userEmail, 'email', 'asc', 'id');
        if ($userId === 0) {
            $errorMsg = "Gebruiker met emailadres " . $userEmail . " niet gevonden in SnipeIT";
            $withError = true;
            Log::error('LaptopGereedmelden: ' . $errorMsg);
        } else {
            // stap 5: opzoeken locatie
            $userData = $this->readSnipeITUserForId($userId);

            $locationId = 0;
            if (isset($userData['location']['id'])) {
                $locationId = $userData['location']['id'];
                Log::info('LaptopAanmelden: SnipeIT user location id found: ' . $locationId);
            } else {
                if (isset($userData['city'])) {
                    $firstName = ucfirst(strtolower($userData['first_name']));
                    $city = ucfirst(strtolower($userData['city']));
                    $city = str_replace("'", '', $city); // ' van 's Gravenhage

                    $locationName = preg_replace('/\s+/','',$firstName.".".$city); // [voornaam].[plaats]
                    $locationId = $this->readSnipeITPartforId('locations', $locationName, '', '', 'id');
                    // kan 0 geven
                }
            }            
            
        } 

        // stap 6: opzoeken asset met asset tag in SnipeIT en haal het id op.
        $assetTag = $laptop['lrnummer'] ?? '';
        $assetData = [];
        if (!$withError) {
            $assetData = $this->readSnipeITHardwareForAssetTag($assetTag);
            $assetId = $assetData['id'] ?? 0;
            if ($assetId === 0) {
                $errorMsg = "Asset met tag " . $assetTag . " niet gevonden in SnipeIT";
                $withError = true;
                Log::error('LaptopGereedmelden error: ' . $errorMsg);
            } 
        }

        $userdisplayname = $laptop['userdisplayname'] ?? 'Onbekende user';

        if (!$withError) {
            // Stap 7: update hardware.   
            try {
                $assetId = $assetData['id'] ?? 0;
                $assetTag = $assetData['asset_tag'] ?? '';
                $modelId = $assetData['model']['id'] ?? 0;
                $notes = $assetData['notes'] ?? '';
                Log::info('LaptopGereedmelden: SnipeIT model id found: ' . $modelId . 
                            ' for asset id: ' . $assetId.' with asset tag: ' . $assetTag . '.');
                
                $beperkingen = $laptop['beperkingen'] ?? '';
                if ($beperkingen != "") {
                    if ($notes != "") $notes .= "\n";
                    $notes .= "Beperkingen: ".$beperkingen;
                }

                if ($notes != "") $notes .= "\n";
                $notes .= "Asset gereedgemeld door $userdisplayname";
                $hardwareArray = [
                    "asset_tag" => $assetTag,
                    "status_id" => $statusLabelId,  // 'Op Voorraad' zetten
                    // "model_id" => $modelId,
                    "assigned_user" => $userId, 
                    "notes" => $notes 
                ];

                if ($locationId > 0) {
                    $hardwareArray["location_id"] = $locationId;
                    $hardwareArray["rtd_location_id"] = $hardwareArray["location_id"];
                }
                $result = $this->updateSnipeITPart('hardware', $assetId, $hardwareArray); // OK / ERROR

            } catch (\Exception $e) {
                $errorMsg = "Fout bij updaten asset in SnipeIT: " . $e->getMessage();
                Log::error('LaptopGereedmelden: ' . $errorMsg);
                $withError = true;
            }


        }

        if ($withError) {
            $laptop['error'] = $errorMsg;
            // $laptop['cc'] =  'CC gestuurd naar info@laptoprevive.nl';
            Mail::to($laptop['email'])->send(new GereedmeldingMailError($laptop));
            $mailhost = env('MAIL_HOST');
            Log::info('LaptopGereedmelden: gevonden mailhost: -' . $mailhost. '-');
            if ($mailhost == 'sandbox.smtp.mailtrap.io') {
                Log::info('LaptopGereedmelden: Mailtrap detected, waiting 15 seconds after sending');
                sleep(15); // wacht 15 seconden voor mailtrap
            }
            /*
            $laptop['error'] = $errorMsg;
            $laptop['cc'] =  'CC voor info@laptoprevive.nl';   
            Mail::to('info@laptoprevive.nl')->send(new GereedmeldingMailError($laptop));
            Log::info('LaptopGereedmelden: SnipeIT asset not created, error: ' . $laptop['error']);
            if ($mailhost == 'sandbox.smtp.mailtrap.io') {
                Log::info('LaptopGereedmelden: Mailtrap detected, waiting 15 seconds after sending');
                sleep(15); // wacht 15 seconden voor mailtrap
            }
            */
        } else {
            $laptop['error'] = "";
            Mail::to($laptop['email'])->send(new GereedmeldingMail($laptop));
            Log::info('LaptopGereedmelden: SnipeIT asset updated with asset tag: ' . $assetTag);
            $mailhost = env('MAIL_HOST');
            Log::info('LaptopGereedmelden: gevonden mailhost: -' . $mailhost. '-');
            if ($mailhost == 'sandbox.smtp.mailtrap.io') {
                Log::info('LaptopGereedmelden: Mailtrap detected, waiting 15 seconds after sending');
                sleep(15); // wacht 15 seconden voor mailtrap
            }
        }

    }

    public function handle(): void {
        Log::info('LaptopGereedmelden: job gestart '.date("Y-m-d H:i:s").'.');
        $laptops = $this->readNextCloud('gereedmelden');
        Log::info('LaptopGereedmelden: $laptops: ' . count($laptops));
        foreach ($laptops as $laptop) {
            Log::info('LaptopGereedmelden: laptop: ' . print_r($laptop, true) );
            $this->VerwerkGereedmeldenInSnipeIT($laptop);

            //$lrNummer = $this->VerwerkInSnipeIT($laptop);
            //$laptop['lrnummer'] = $lrNummer;
            //Mail::to($laptop['email'])->send(new AanmeldingMail($laptop));
        }   
        Log::info('LaptopGereedmelden: job finished '.date("Y-m-d H:i:s").'.');
    }
}

