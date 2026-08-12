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

    protected function readSnipeITPartForAssetTag($assetTag)
    {
        //
        $url = env('SNIPEIT_URL') . '/api/v1/hardware/bytag/' . urlencode($assetTag);
        Log::info('LaptopGereedmelden: SnipeIT url ' . $url . '. ');

        $token = env('SNIPEIT_TOKEN');

        $response = Http::withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $token,
        ])->get($url);

        $data = [];
        if ($response->successful()) {
            //$responseData = $response->json();
            //$data = $responseData['rows'][0] ?? [];
            $data = $response->json();
            Log::info('LaptopGereedmelden: SnipeIT data response for read hardware by asset tag ' . $assetTag . '.');
        } else {
            Log::error('LaptopGereedmelden: SnipeIT Failed to read hardware by asset tag ' . $assetTag . ': ' . $response->status());
        }

        if (count($data) !== 0) {
            $assetId = $data['id'] ?? 0;
            Log::info('LaptopGereedmelden: SnipeIT assetId=' . $assetId . ' found for Hardware assetTag ' . $assetTag . '.');
        } else {
            $assetId = 0; // default value if not found
            Log::info('LaptopGereedmelden: SnipeIT assetId not found for Hardware assetTag ' . $assetTag . '.');
        }       
        return $data;
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

        $locationName = $laptop['naam'] ?? 'Naam Onbekend';
        $locationName .= "  (" . $laptop['woonplaats'] . ")";

        $locationId = $this->verwerkSnipeITPart('locations', $locationName, 1, [  // wel default id
            'name' => $locationName,
            'company_id' => $companyId,
            'notes' => 'Locatie aangemaakt door API'
        ]);

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
        } 

        // stap 5: opzoeken asset met asset tag in SnipeIT en haal het id op.
        $assetTag = $laptop['lrnummer'] ?? '';
        $data = [];
        if (!$withError) {
            $data = $this->readSnipeITPartForAssetTag($assetTag);
            $assetId = $data['id'] ?? 0;
            if ($assetId === 0) {
                $errorMsg = "Asset met tag " . $assetTag . " niet gevonden in SnipeIT";
                $withError = true;
                Log::error('LaptopGereedmelden error: ' . $errorMsg);
            } 
        }

        if (!$withError) {
            // Stap 6: update hardware.   
            try {
                $assetId = $data['id'] ?? 0;
                $assetTag = $data['asset_tag'] ?? '';
                $modelId = $data['model']['id'] ?? 0;
                $notes = $data['notes'] ?? '';
                Log::info('LaptopGereedmelden: SnipeIT model id found: ' . $modelId . 
                            ' for asset id: ' . $assetId.' with asset tag: ' . $assetTag . '.');

                $result = $this->updateSnipeITPart('hardware', $assetId,
                    [
                        "asset_tag" => $assetTag,
                        "status_id" => $statusLabelId,  // 'Op Voorraad' zetten
                        // "model_id" => $modelId,
                        "assigned_user" => $userId, 
                        "location_id" => $locationId,
                        "rtd_location_id" => $locationId,
                        "notes" => $notes . "\nAsset gereedgemeld door API"
                    ]);
            } catch (\Exception $e) {
                $errorMsg = "Fout bij updaten asset in SnipeIT: " . $e->getMessage();
                Log::error('LaptopGereedmelden: ' . $errorMsg);
                $withError = true;
            }


        }

        if ($withError) {
            $laptop['error'] = $errorMsg;
            $laptop['cc'] =  'CC gestuurd naar info@laptoprevive.nl';
            Mail::to($laptop['email'])->send(new GereedmeldingMailError($laptop));
            $mailhost = env('MAIL_HOST');
            Log::info('LaptopGereedmelden: gevonden mailhost: -' . $mailhost. '-');
            if ($mailhost == 'sandbox.smtp.mailtrap.io') {
                Log::info('LaptopGereedmelden: Mailtrap detected, waiting 15 seconds after sending');
                sleep(15); // wacht 15 seconden voor mailtrap
            }
            $laptop['error'] = $errorMsg;
            $laptop['cc'] =  'CC voor info@laptoprevive.nl';   
            Mail::to('info@laptoprevive.nl')->send(new GereedmeldingMailError($laptop));
            Log::info('LaptopGereedmelden: SnipeIT asset not created, error: ' . $laptop['error']);
            if ($mailhost == 'sandbox.smtp.mailtrap.io') {
                Log::info('LaptopGereedmelden: Mailtrap detected, waiting 15 seconds after sending');
                sleep(15); // wacht 15 seconden voor mailtrap
            }
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

